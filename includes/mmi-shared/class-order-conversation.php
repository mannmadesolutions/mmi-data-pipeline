<?php
/**
 * MMI_Order_Conversation
 *
 * One message thread per WooCommerce order between the store and its
 * customer, Reverb-style: on-site and asynchronous, with each new message
 * delivered to the other side (batched over NOTIFY_DELAY). Shared so any
 * MMI plugin can turn it on (init() is idempotent) and every order kind —
 * XChange, subscriptions, Reverb — uses one thread.
 *
 * Storage: its own table ({prefix}mmi_order_messages), created on first use.
 * Not comments: WooCommerce hides order-note comments from comment queries,
 * feeds and counts only through filters on their exact type, so a message
 * stored as a comment would surface in the Comments screen, feeds or the
 * REST API whenever the plugin carrying those filters was inactive. These
 * are customers' private messages; a table of their own has no such path.
 *
 * Where each side reads and writes:
 *  - Customer: a signed per-order link (customer_url(), admin-post.php —
 *    anonymous front-end HTML may be edge-cached, /wp-admin isn't), the
 *    Messages section on My Account → View order, the My Account Messages
 *    tab (every thread, unread first), and — when a reply mailbox is set up
 *    (MMI_Order_Conversation_Gmail) — a reply to the notification email.
 *  - Staff: a Conversation box on the order screen (AJAX reply), and an
 *    unread badge after the order number in the Orders list.
 *
 * Channels: by default a staff message reaches the customer by email. A
 * plugin can route an order elsewhere — mmi-reverb-integration sends a
 * Reverb order's messages to the buyer's Reverb inbox — through the
 * 'mmi_order_conversation_channel' filter plus a
 * 'mmi_order_conversation_deliver_{channel}' filter that sends one message,
 * and can pull the other side's messages in on the
 * 'mmi_order_conversation_sync' action with import_external() (deduplicated
 * by external_id).
 *
 * Drafted answers: when a plugin filters 'mmi_order_conversation_ai_draft',
 * the staff box gets a "Draft answer" button. The draft is built from the
 * conversation and numbered sources (ai_context(); more via
 * 'mmi_order_conversation_ai_sources'), the provider returns the answer and
 * the numbers it used, and only those sources' own links are appended, so
 * a provider can't put an address in front of a customer that wasn't given
 * to it. The draft lands in the reply box; staff send it. Nothing here
 * calls any AI service itself. A provider that works in the background
 * returns ['pending' => id]; the box then asks again with that id until the
 * draft (or an error) comes back.
 *
 * One look on both sides (owner, 2026-10-24): staff see the thread exactly as
 * the customer does — store replies on the left as the store's name, the
 * customer's messages on the right — with staff-only details (who sent it,
 * "by email", "on Reverb", system lines) in small grey text the customer
 * never gets. Customers see the store's name on every reply, in My Account
 * and in email; system lines are staff-only.
 *
 * Attachments (MMI_Order_Conversation_Attachments): staff can put a product
 * (or, on a Reverb order, its Reverb listing) and a coupon — existing or made
 * in the composer — into a message, like Reverb's own composer. They are
 * stored as snapshots and shown as cards in every view and email.
 *
 * Orders handled elsewhere: a plugin can turn the thread off for an order
 * ('mmi_order_conversation_enabled'); nothing is stored or shown for it, and
 * the order's box shows that plugin's pointer instead
 * ('mmi_order_conversation_elsewhere'). mmi-reverb-integration does this for
 * Reverb orders: their messages stay in Reverb → Messages (owner, 2026-10-24).
 *
 * Plain text only: bodies are sanitize_textarea_field()'d, capped at
 * MAX_LENGTH, escaped on output; links in customer messages stay plain text.
 * A customer may post RATE_LIMIT messages per order per hour.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Order_Conversation', false ) ) {

	class MMI_Order_Conversation {

		const DB_VERSION = '3';
		const DB_OPTION  = 'mmi_order_messages_db_version';

		const SENDER_CUSTOMER = 'customer';
		const SENDER_STAFF    = 'staff';
		const SENDER_SYSTEM   = 'system';

		const CHANNEL_EMAIL = 'email';

		/** Per-order secret for the customer's link. */
		const META_TOKEN           = '_mmi_conv_token';
		/** Customer messages the staff hasn't opened the order to see. */
		const META_UNREAD          = '_mmi_conv_unread_staff';
		/** Staff messages the customer hasn't opened the thread to see. */
		const META_UNREAD_CUSTOMER = '_mmi_conv_unread_customer';
		/** Unix time the customer last opened the thread. */
		const META_SEEN            = '_mmi_conv_customer_seen';
		/** Unix time a reply-by-email address last went out for this order. */
		const META_REPLY_ISSUED    = '_mmi_conv_reply_issued';

		const MAX_LENGTH   = 2000;
		const RATE_LIMIT   = 10;
		const NOTIFY_DELAY = 300;

		const HOOK_NOTIFY = 'mmi_order_conversation_notify';
		const AS_GROUP    = 'mmi-order-conversation';

		/** admin-post.php: the customer's token page (GET shows, POST sends). */
		const ACTION_PAGE    = 'mmi_order_conversation';
		/** admin-post.php: the My Account form (logged in, nonce). */
		const ACTION_ACCOUNT = 'mmi_order_conversation_account';
		/** admin-ajax.php: staff reply from the order screen. */
		const AJAX_STAFF     = 'mmi_order_conversation_staff_reply';
		/** admin-ajax.php: drafted answer for the staff reply box. */
		const AJAX_DRAFT     = 'mmi_order_conversation_ai_draft';

		const DRAFT_MESSAGES   = 12;
		const SOURCE_TEXT_MAX  = 4000;

		/** HTML id of the thread on every screen; links end in #ANCHOR. */
		const ANCHOR = 'mmi-order-conversation';

		/** My Account endpoint: /my-account/messages/. */
		const ENDPOINT         = 'messages';
		const REWRITE_OPTION   = 'mmi_order_conversation_rewrite';
		const REWRITE_VERSION  = '1';

		/**
		 * First line of a customer email that can be answered by replying;
		 * strip_quoted() cuts a reply here (it sits above everything quoted).
		 */
		const REPLY_MARKER = '## Reply above this line ##';

		private static bool $initialized    = false;
		private static bool $table_ready    = false;
		private static bool $styles_printed = false;

		public static function init(): void {
			if ( self::$initialized ) {
				return;
			}
			self::$initialized = true;

			add_action( 'woocommerce_view_order', array( __CLASS__, 'render_account_section' ), 20 );
			add_action( 'admin_post_nopriv_' . self::ACTION_PAGE, array( __CLASS__, 'handle_page' ) );
			add_action( 'admin_post_' . self::ACTION_PAGE, array( __CLASS__, 'handle_page' ) );
			add_action( 'admin_post_' . self::ACTION_ACCOUNT, array( __CLASS__, 'handle_account_post' ) );
			add_action( 'wp_ajax_' . self::AJAX_STAFF, array( __CLASS__, 'ajax_staff_reply' ) );
			add_action( 'wp_ajax_' . self::AJAX_DRAFT, array( __CLASS__, 'ajax_ai_draft' ) );

			add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
			add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
			add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_item' ), 20 );
			add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_account_inbox' ) );
			add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'endpoint_title' ) );

			add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_list_badge' ), 20, 2 );
			add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_list_badge' ), 20, 2 );

			add_action( self::HOOK_NOTIFY, array( __CLASS__, 'run_notify' ), 10, 2 );
			add_action( 'woocommerce_delete_order', array( __CLASS__, 'delete_for_order' ) );

			add_filter( 'mmi_email_customizer_samples', array( __CLASS__, 'register_email_samples' ) );
			add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );

			if ( class_exists( 'MMI_Order_Conversation_Gmail' ) ) {
				MMI_Order_Conversation_Gmail::init();
			}
			if ( class_exists( 'MMI_Order_Conversation_Attachments' ) ) {
				MMI_Order_Conversation_Attachments::init();
			}
		}

		/* ── Storage ────── */

		public static function table(): string {
			global $wpdb;
			$table = $wpdb->prefix . 'mmi_order_messages';
			if ( ! self::$table_ready ) {
				self::$table_ready = true;
				if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
					require_once ABSPATH . 'wp-admin/includes/upgrade.php';
					dbDelta( "CREATE TABLE {$table} (
						id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						order_id bigint(20) unsigned NOT NULL,
						sender varchar(10) NOT NULL,
						user_id bigint(20) unsigned NOT NULL DEFAULT 0,
						author varchar(190) NOT NULL DEFAULT '',
						body text NOT NULL,
						created_at datetime NOT NULL,
						notified tinyint(1) NOT NULL DEFAULT 0,
						external_id varchar(100) NOT NULL DEFAULT '',
						attachments longtext NULL,
						PRIMARY KEY  (id),
						KEY order_id (order_id),
						KEY external_id (external_id)
					) " . $wpdb->get_charset_collate() . ';' );
					update_option( self::DB_OPTION, self::DB_VERSION );
				}
			}
			return $table;
		}

		/**
		 * Adds a message. Customer and staff messages reach the other side
		 * (batched) unless $args['notify'] is false; system messages never do.
		 *
		 * @param array $args {
		 *     author?: string, user_id?: int, time?: int (unix), notify?: bool,
		 *     external_id?: string — where the message came from (e.g.
		 *     'gmail:<id>', 'reverb:<id>'); a second add with the same id is a no-op,
		 *     attachments?: array — snapshots from MMI_Order_Conversation_Attachments::build();
		 *     a message may be attachments only.
		 * }
		 * @return int Message id, 0 when nothing was stored (empty body, bad sender, duplicate).
		 */
		public static function add( \WC_Order $order, string $sender, string $body, array $args = array() ): int {
			global $wpdb;
			if ( ! self::enabled( $order ) ) {
				return 0;
			}
			if ( ! in_array( $sender, array( self::SENDER_CUSTOMER, self::SENDER_STAFF, self::SENDER_SYSTEM ), true ) ) {
				return 0;
			}
			$body = trim( sanitize_textarea_field( $body ) );
			$body        = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, self::MAX_LENGTH ) : substr( $body, 0, self::MAX_LENGTH );
			$attachments = $sender === self::SENDER_STAFF ? array_values( (array) ( $args['attachments'] ?? array() ) ) : array();
			if ( $body === '' && ! $attachments ) {
				return 0;
			}
			$external_id = substr( (string) ( $args['external_id'] ?? '' ), 0, 100 );
			if ( $external_id !== '' && self::has_external( $external_id ) ) {
				return 0;
			}

			$user_id = (int) ( $args['user_id'] ?? ( $sender === self::SENDER_SYSTEM ? 0 : get_current_user_id() ) );
			$author  = (string) ( $args['author'] ?? '' );
			if ( $author === '' && $sender === self::SENDER_CUSTOMER ) {
				$author = trim( $order->get_formatted_billing_full_name() ) ?: $order->get_billing_email();
			} elseif ( $author === '' && $sender === self::SENDER_STAFF ) {
				$user   = $user_id ? get_userdata( $user_id ) : null;
				$author = $user ? $user->display_name : get_bloginfo( 'name' );
			}
			$notify = $sender !== self::SENDER_SYSTEM && ( $args['notify'] ?? true );

			$wpdb->insert( self::table(), array(
				'order_id'    => $order->get_id(),
				'sender'      => $sender,
				'user_id'     => $user_id,
				'author'      => sanitize_text_field( $author ),
				'body'        => $body,
				'created_at'  => gmdate( 'Y-m-d H:i:s', (int) ( $args['time'] ?? time() ) ),
				'notified'    => $notify ? 0 : 1,
				'external_id' => $external_id,
				'attachments' => $attachments ? wp_json_encode( $attachments ) : null,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) {
				return 0;
			}

			if ( $sender === self::SENDER_CUSTOMER && ( $args['unread'] ?? true ) ) {
				$order->update_meta_data( self::META_UNREAD, self::unread_count( $order ) + 1 );
				$order->save_meta_data();
			} elseif ( $sender === self::SENDER_STAFF && $external_id === '' ) {
				$order->update_meta_data( self::META_UNREAD_CUSTOMER, self::customer_unread_count( $order ) + 1 );
				$order->save_meta_data();
			}
			if ( $notify ) {
				self::schedule_notify( $order, $sender === self::SENDER_CUSTOMER ? self::SENDER_STAFF : self::SENDER_CUSTOMER );
			}

			do_action( 'mmi_order_conversation_message_added', $id, $order, $sender );
			return $id;
		}

		/**
		 * A message that already reached the other side somewhere else (a
		 * Reverb message, an email reply): stored without notifying anyone.
		 * A staff message sent from here and seen again on its channel gets
		 * the channel's id instead of a second copy (same body, still
		 * without an id, within a day).
		 *
		 * @param array $args { author?: string, time?: int, unread?: bool (customer messages; default true) }
		 */
		public static function import_external( \WC_Order $order, string $sender, string $body, string $external_id, array $args = array() ): int {
			global $wpdb;
			if ( $external_id === '' || self::has_external( $external_id ) ) {
				return 0;
			}
			if ( $sender === self::SENDER_STAFF ) {
				$table = self::table();
				$time  = (int) ( $args['time'] ?? time() );
				// Compared as sent: the text plus any attachments' lines (message_text()).
				$want  = self::normalize( sanitize_textarea_field( $body ) );
				$match = 0;
				foreach ( (array) $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$table} WHERE order_id = %d AND sender = %s AND external_id = '' AND created_at BETWEEN %s AND %s ORDER BY id ASC LIMIT 50",
					$order->get_id(),
					self::SENDER_STAFF,
					gmdate( 'Y-m-d H:i:s', $time - DAY_IN_SECONDS ),
					gmdate( 'Y-m-d H:i:s', $time + DAY_IN_SECONDS )
				) ) as $candidate ) {
					if ( self::normalize( self::message_text( $candidate ) ) === $want ) {
						$match = (int) $candidate->id;
						break;
					}
				}
				if ( $match ) {
					$wpdb->update( $table, array( 'external_id' => substr( $external_id, 0, 100 ) ), array( 'id' => $match ) );
					return $match;
				}
			}
			return self::add( $order, $sender, $body, array_merge( $args, array(
				'external_id' => $external_id,
				'notify'      => false,
				'user_id'     => 0,
			) ) );
		}

		/** Whitespace-insensitive form, for matching a message seen again on its channel. */
		private static function normalize( string $text ): string {
			return trim( preg_replace( '/\s+/', ' ', $text ) );
		}

		/** A message's attachment snapshots. */
		public static function attachments_of( $m ): array {
			$raw = (string) ( $m->attachments ?? '' );
			$out = $raw !== '' ? json_decode( $raw, true ) : null;
			return is_array( $out ) ? $out : array();
		}

		/** The message as plain text: body, then its attachments as lines (Reverb, previews). */
		public static function message_text( $m ): string {
			$atts = self::attachments_of( $m );
			$text = trim( (string) $m->body );
			if ( $atts && class_exists( 'MMI_Order_Conversation_Attachments' ) ) {
				$text = trim( $text . "\n\n" . MMI_Order_Conversation_Attachments::text( $atts ) );
			}
			return $text;
		}

		public static function has_external( string $external_id ): bool {
			global $wpdb;
			$table = self::table();
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE external_id = %s LIMIT 1", $external_id ) );
		}

		/** @return array<int,object{id:string,order_id:string,sender:string,user_id:string,author:string,body:string,created_at:string,notified:string,external_id:string}> Oldest first. */
		public static function get_messages( int $order_id ): array {
			global $wpdb;
			$table = self::table();
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY created_at ASC, id ASC", $order_id ) );
		}

		public static function unread_count( \WC_Order $order ): int {
			return max( 0, (int) $order->get_meta( self::META_UNREAD ) );
		}

		public static function customer_unread_count( \WC_Order $order ): int {
			return max( 0, (int) $order->get_meta( self::META_UNREAD_CUSTOMER ) );
		}

		/** The customer opened the thread: mark staff messages read, note when. */
		private static function customer_viewed( \WC_Order $order ): void {
			$order->update_meta_data( self::META_SEEN, time() );
			$order->update_meta_data( self::META_UNREAD_CUSTOMER, 0 );
			$order->save_meta_data();
		}

		public static function delete_for_order( $order_id ): void {
			global $wpdb;
			$wpdb->delete( self::table(), array( 'order_id' => (int) $order_id ), array( '%d' ) );
		}

		/** The customer's link to this order's thread (creates its token on first use). */
		public static function customer_url( \WC_Order $order ): string {
			$token = (string) $order->get_meta( self::META_TOKEN );
			if ( $token === '' ) {
				$token = wp_generate_password( 32, false );
				$order->update_meta_data( self::META_TOKEN, $token );
				$order->save_meta_data();
			}
			return add_query_arg( array(
				'action' => self::ACTION_PAGE,
				'order'  => $order->get_id(),
				'token'  => $token,
			), admin_url( 'admin-post.php' ) ) . '#' . self::ANCHOR;
		}

		public static function staff_url( \WC_Order $order ): string {
			return $order->get_edit_order_url() . '#' . self::ANCHOR;
		}

		/** Whether this order has a thread here (false: a plugin handles its messages elsewhere). */
		public static function enabled( \WC_Order $order ): bool {
			return (bool) apply_filters( 'mmi_order_conversation_enabled', true, $order );
		}

		/** The order box's note for an order whose messages live elsewhere. */
		private static function elsewhere_html( \WC_Order $order ): string {
			$html = (string) apply_filters( 'mmi_order_conversation_elsewhere', '', $order );
			return $html !== '' ? wp_kses_post( $html ) : '<p class="mmi-conv-hint">' . esc_html__( 'Messages for this order are handled outside this thread.', 'mmi-shared' ) . '</p>';
		}

		/** How staff messages reach this order's customer: 'email' or a plugin's channel. */
		public static function channel( \WC_Order $order ): string {
			$channel = (string) apply_filters( 'mmi_order_conversation_channel', self::CHANNEL_EMAIL, $order );
			return $channel !== '' ? sanitize_key( $channel ) : self::CHANNEL_EMAIL;
		}

		/**
		 * Reply-To for an email to this order's customer, so a plain reply
		 * lands in the thread — empty when no reply mailbox is set up.
		 *
		 * @return string[] Mail headers ([] or one "Reply-To: …").
		 */
		public static function reply_headers( \WC_Order $order ): array {
			$address = (string) apply_filters( 'mmi_order_conversation_reply_address', '', $order );
			if ( $address === '' || ! is_email( $address ) ) {
				return array();
			}
			$order->update_meta_data( self::META_REPLY_ISSUED, time() );
			$order->save_meta_data();
			return array( 'Reply-To: ' . $address );
		}

		/**
		 * The new text of an email reply: cut at REPLY_MARKER, at the
		 * "On … wrote:" / "Original Message" line that starts the quoted
		 * email, or at a "-- " signature; quoted ">" lines dropped.
		 */
		public static function strip_quoted( string $text ): string {
			$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
			$pos  = stripos( $text, self::REPLY_MARKER );
			if ( $pos !== false ) {
				$text = substr( $text, 0, $pos );
			}
			$lines = explode( "\n", $text );
			$out   = array();
			foreach ( $lines as $i => $line ) {
				$t    = trim( $line );
				$next = trim( $lines[ $i + 1 ] ?? '' );
				$wrote = preg_match( '/^On\s.+wrote:$/i', $t )
					|| ( preg_match( '/^On\s/i', $t ) && preg_match( '/wrote:$/i', $next ) );
				if ( $wrote || preg_match( '/^-{2,}\s*Original Message\s*-{2,}$/i', $t ) || preg_match( '/^_{10,}$/', $t ) || $t === '--' ) {
					break;
				}
				if ( strpos( $t, '>' ) === 0 ) {
					continue;
				}
				$out[] = rtrim( $line );
			}
			return trim( preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $out ) ) );
		}

		private static function rate_limited( \WC_Order $order ): bool {
			global $wpdb;
			$table = self::table();
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND sender = %s AND created_at > %s",
				$order->get_id(),
				self::SENDER_CUSTOMER,
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			) );
			return $count >= self::RATE_LIMIT;
		}

		/* ── Notifications and delivery ────── */

		private static function schedule_notify( \WC_Order $order, string $side ): void {
			$delay = (int) apply_filters( 'mmi_order_conversation_notify_delay', self::NOTIFY_DELAY, $order, $side );
			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				self::run_notify( $order->get_id(), $side );
				return;
			}
			$args = array( $order->get_id(), $side );
			if ( ! as_has_scheduled_action( self::HOOK_NOTIFY, $args, self::AS_GROUP ) ) {
				as_schedule_single_action( time() + max( 0, $delay ), self::HOOK_NOTIFY, $args, self::AS_GROUP );
			}
		}

		/**
		 * Action Scheduler: everything from the other side not yet passed on
		 * to $side — one email, or each message over the order's channel.
		 */
		public static function run_notify( $order_id, $side ): void {
			global $wpdb;
			$order = wc_get_order( (int) $order_id );
			if ( ! $order ) {
				return;
			}
			$from    = $side === self::SENDER_CUSTOMER ? self::SENDER_STAFF : self::SENDER_CUSTOMER;
			$pending = array_values( array_filter( self::get_messages( $order->get_id() ), static fn( $m ) => $m->sender === $from && ! (int) $m->notified ) );
			if ( ! $pending ) {
				return;
			}
			$ids   = array_map( static fn( $m ) => (int) $m->id, $pending );
			$table = self::table();
			$wpdb->query( "UPDATE {$table} SET notified = 1 WHERE id IN (" . implode( ',', $ids ) . ')' ); // Ints only.

			if ( $side === self::SENDER_STAFF ) {
				$sent = self::email_staff( $order, $pending );
			} elseif ( ( $channel = self::channel( $order ) ) !== self::CHANNEL_EMAIL ) {
				$sent = self::deliver_over_channel( $order, $channel, $pending );
			} else {
				$sent = self::email_customer( $order, $pending );
			}
			if ( ! $sent && class_exists( 'MMI_Logger' ) ) {
				MMI_Logger::warn( "Order conversation: {$side} notification for order {$order->get_id()} was not sent", array(), 'order-conversation', __CLASS__ );
			}
		}

		/** Each staff message on its own over the channel; a failure shows in the thread. */
		private static function deliver_over_channel( \WC_Order $order, string $channel, array $messages ): bool {
			global $wpdb;
			$all_sent = true;
			foreach ( $messages as $m ) {
				$result = apply_filters( 'mmi_order_conversation_deliver_' . $channel, new \WP_Error( 'no_channel', 'No plugin delivers this channel.' ), $order, $m );
				if ( is_wp_error( $result ) || ! $result ) {
					$all_sent = false;
					$reason   = is_wp_error( $result ) ? $result->get_error_message() : '';
					self::add( $order, self::SENDER_SYSTEM, sprintf( __( 'The message above was not delivered (%1$s). %2$s', 'mmi-shared' ), $channel, $reason ) );
					continue;
				}
				if ( is_string( $result ) && $result !== '' ) {
					$wpdb->update( self::table(), array( 'external_id' => substr( $result, 0, 100 ) ), array( 'id' => (int) $m->id ) );
				}
			}
			return $all_sent;
		}

		private static function email_customer( \WC_Order $order, array $messages ): bool {
			$to = sanitize_email( $order->get_billing_email() );
			if ( ! is_email( $to ) || ( class_exists( 'MMI_Guest_Customer_Converter' ) && MMI_Guest_Customer_Converter::looks_like_relay_email( $to ) ) ) {
				$order->add_order_note( __( 'A message to the customer was not emailed: the order has no real customer email address. They can still read it in My Account.', 'mmi-shared' ) );
				return false;
			}
			$site    = get_bloginfo( 'name' );
			$number  = $order->get_order_number();
			$headers = array_merge( array( 'Content-Type: text/html; charset=UTF-8' ), self::reply_headers( $order ) );
			$html    = self::render_customer_email( array(
				'first_name' => trim( $order->get_billing_first_name() ),
				'number'     => $number,
				'url'        => self::customer_url( $order ),
				'by_mail'    => count( $headers ) > 1,
			), $messages );
			$subject = sprintf( __( 'New message about your order #%1$s — %2$s', 'mmi-shared' ), $number, $site );

			return (bool) wp_mail( $to, $subject, $html, $headers );
		}

		/**
		 * The customer's notification email. Store replies are signed with
		 * the store's name, as in My Account.
		 *
		 * @param array{first_name:string, number:string, url:string, by_mail:bool} $d
		 * @param object[] $messages Staff messages (author, created_at, body).
		 */
		public static function render_customer_email( array $d, array $messages ): string {
			$site  = get_bloginfo( 'name' );
			$first = $d['first_name'] !== '' ? $d['first_name'] : __( 'there', 'mmi-shared' );
			$body  = ( $d['by_mail'] ? '<p style="margin:0 0 14px;font-size:11px;color:#999;">' . esc_html( self::REPLY_MARKER ) . '</p>' : '' )
				. '<p>' . esc_html( sprintf( __( 'Hi %1$s, you have a new message from %2$s about order #%3$s:', 'mmi-shared' ), $first, $site, $d['number'] ) ) . '</p>'
				. self::email_quotes( $messages, $site )
				. '<p>' . esc_html( $d['by_mail']
					? __( 'Reply to this email, or use the button. Your messages stay with the order, so you can also find them in My Account under Messages.', 'mmi-shared' )
					: __( 'Use the button to reply. Your messages stay with the order, so you can also find them in My Account under Messages.', 'mmi-shared' ) ) . '</p>';

			return class_exists( 'MMI_Email_Templates' )
				? MMI_Email_Templates::render_customer_notice( array(
					'box_heading' => sprintf( __( 'Order #%s', 'mmi-shared' ), $d['number'] ),
					'body_html'   => $body,
					'cta_url'     => $d['url'],
					'cta_label'   => esc_html__( 'Reply', 'mmi-shared' ),
				) )
				: $body . '<p><a href="' . esc_url( $d['url'] ) . '">' . esc_html__( 'Reply', 'mmi-shared' ) . '</a></p>';
		}

		private static function email_staff( \WC_Order $order, array $messages ): bool {
			$to = get_option( 'admin_email' );
			if ( ! is_email( $to ) ) {
				return false;
			}
			$head = sprintf( __( 'New message from %1$s on order #%2$s', 'mmi-shared' ), (string) $messages[0]->author, $order->get_order_number() );
			$html = self::render_staff_email( array(
				'author' => (string) $messages[0]->author,
				'number' => $order->get_order_number(),
				'email'  => $order->get_billing_email(),
				'url'    => self::staff_url( $order ),
			), $messages );

			return (bool) wp_mail( $to, '[' . get_bloginfo( 'name' ) . '] ' . $head, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}

		/**
		 * The staff notification email.
		 *
		 * @param array{author:string, number:string, email:string, url:string} $d
		 * @param object[] $messages Customer messages (author, created_at, body).
		 */
		public static function render_staff_email( array $d, array $messages ): string {
			$head = sprintf( __( 'New message from %1$s on order #%2$s', 'mmi-shared' ), $d['author'], $d['number'] );
			$body = self::email_quotes( $messages )
				. '<p>' . esc_html( sprintf( __( 'Customer email: %s', 'mmi-shared' ), $d['email'] ) ) . '</p>';
			return class_exists( 'MMI_Email_Templates' )
				? MMI_Email_Templates::render_notice( array(
					'headline'  => $head,
					'body_html' => $body,
					'cta_url'   => $d['url'],
					'cta_label' => __( 'Reply on the order &rarr;', 'mmi-shared' ),
				) )
				: $body . '<p><a href="' . esc_url( $d['url'] ) . '">' . esc_html__( 'Reply on the order', 'mmi-shared' ) . '</a></p>';
		}

		/** @param string $as Name to show on every quote ('' = each message's author). */
		private static function email_quotes( array $messages, string $as = '' ): string {
			$html = '';
			foreach ( $messages as $m ) {
				$html .= '<div style="margin:0 0 14px;padding:10px 14px;border-left:3px solid #d0d0d0;background:#f8f8f8;">'
					. '<div style="font-size:12px;color:#767676;margin-bottom:4px;">' . esc_html( ( $as !== '' ? $as : $m->author ) . ' · ' . self::format_time( $m->created_at ) ) . '</div>'
					. '<div>' . nl2br( esc_html( $m->body ) ) . '</div>'
					. ( class_exists( 'MMI_Order_Conversation_Attachments' ) ? MMI_Order_Conversation_Attachments::html( self::attachments_of( $m ), 'email' ) : '' )
					. '</div>';
			}
			return $html;
		}

		/** mmi_email_customizer_samples: both conversation emails, with made-up content. */
		public static function register_email_samples( array $samples ): array {
			$msg = static function ( string $author, string $body ): object {
				return (object) array( 'author' => $author, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'body' => $body );
			};
			$samples[] = array(
				'id'     => 'order_conversation_customer',
				'group'  => 'Order messages',
				'label'  => 'New message (to the customer)',
				'render' => static fn() => self::render_customer_email(
					array( 'first_name' => 'Jamie', 'number' => '123456', 'url' => home_url( '/' ), 'by_mail' => true ),
					array( $msg( 'Staff', "Hi,\n\nYes, it runs as an Audio Unit inside Logic Pro on your Mac. You'll need macOS 13 or later.\n\nSources:\nSample Studio Suite: " . home_url( '/' ) ) )
				),
			);
			$samples[] = array(
				'id'     => 'order_conversation_staff',
				'group'  => 'Order messages',
				'label'  => 'New message (to staff)',
				'render' => static fn() => self::render_staff_email(
					array( 'author' => 'Jamie Sample', 'number' => '123456', 'email' => 'jamie@example.com', 'url' => admin_url( 'admin.php?page=wc-orders' ) ),
					array( $msg( 'Jamie Sample', 'Will this work in Logic Pro on my Mac?' ) )
				),
			);
			return $samples;
		}

		/* ── Rendering ────── */

		private static function format_time( string $gmt ): string {
			return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $gmt . ' UTC' ) );
		}

		/** Where a message came in, shown beside its time. */
		private static function source_label( string $external_id ): string {
			$source = strtok( $external_id, ':' );
			if ( $source === 'gmail' ) {
				return __( 'by email', 'mmi-shared' );
			}
			if ( $source === 'reverb' ) {
				return __( 'on Reverb', 'mmi-shared' );
			}
			return '';
		}

		/**
		 * @param string $viewer SENDER_CUSTOMER or SENDER_STAFF — decides whose
		 *                       messages are "mine" and how names show.
		 */
		public static function render_thread( \WC_Order $order, string $viewer ): string {
			$messages = self::get_messages( $order->get_id() );
			if ( $viewer !== self::SENDER_STAFF ) {
				$messages = array_filter( $messages, static fn( $m ) => $m->sender !== self::SENDER_SYSTEM );
			}
			if ( ! $messages ) {
				return '<div class="mmi-conv-thread"><p class="mmi-conv-empty">' . esc_html__( 'No messages yet.', 'mmi-shared' ) . '</p></div>';
			}
			$site  = get_bloginfo( 'name' );
			$staff = $viewer === self::SENDER_STAFF;
			$html  = '<div class="mmi-conv-thread">';
			foreach ( $messages as $m ) {
				// System lines are notes for staff; the customer never sees them.
				if ( $m->sender === self::SENDER_SYSTEM ) {
					if ( $staff ) {
						$html .= '<div class="mmi-conv-msg mmi-conv-msg--system"><div class="mmi-conv-meta">' . esc_html( self::format_time( $m->created_at ) ) . '</div>'
							. '<div class="mmi-conv-body">' . esc_html( $m->body ) . '</div><div class="mmi-conv-staffnote">' . esc_html__( 'Only staff see this', 'mmi-shared' ) . '</div></div>';
					}
					continue;
				}
				// The customer's view on both sides: their messages on the right.
				$customer = $m->sender === self::SENDER_CUSTOMER;
				$name     = $customer ? ( $staff ? $m->author : __( 'You', 'mmi-shared' ) ) : $site;
				$note     = array();
				if ( $staff && ! $customer && $m->author !== '' ) {
					$note[] = sprintf( __( 'sent by %s', 'mmi-shared' ), $m->author );
				}
				if ( $staff ) {
					$source = self::source_label( (string) ( $m->external_id ?? '' ) );
					if ( $source !== '' ) {
						$note[] = $source;
					}
				}
				$body  = $customer ? esc_html( $m->body ) : make_clickable( esc_html( $m->body ) );
				$cards = class_exists( 'MMI_Order_Conversation_Attachments' ) ? MMI_Order_Conversation_Attachments::html( self::attachments_of( $m ), 'thread' ) : '';
				$html .= '<div class="mmi-conv-msg mmi-conv-msg--' . esc_attr( $m->sender ) . ( $customer ? ' is-mine' : '' ) . '">'
					. '<div class="mmi-conv-meta"><strong>' . esc_html( $name ) . '</strong> · ' . esc_html( self::format_time( $m->created_at ) ) . '</div>'
					. ( trim( (string) $m->body ) !== '' ? '<div class="mmi-conv-body">' . $body . '</div>' : '' )
					. $cards
					. ( $note ? '<div class="mmi-conv-staffnote">' . esc_html( implode( ' · ', $note ) ) . '</div>' : '' )
					. '</div>';
			}
			return $html . '</div>';
		}

		/** Once per page. */
		public static function styles(): string {
			if ( self::$styles_printed ) {
				return '';
			}
			self::$styles_printed = true;
			return '<style>'
				. '.mmi-conv-thread{display:flex;flex-direction:column;gap:10px;margin:0 0 16px}'
				. '.mmi-conv-msg{max-width:85%;padding:10px 12px;border-radius:10px;background:#f1f1f1;color:#1d2327;overflow-wrap:anywhere}'
				. '.mmi-conv-msg.is-mine{align-self:flex-end;background:#e7effa}'
				. '.mmi-conv-msg--system{align-self:center;max-width:100%;padding:2px 8px;background:none;color:#646970;font-size:13px;text-align:center}'
				. '.mmi-conv-meta{font-size:12px;color:#646970;margin-bottom:4px}'
				. '.mmi-conv-body{white-space:pre-wrap;line-height:1.5}'
				. '.mmi-conv-empty{color:#646970;margin:0}'
				. '.mmi-conv-body a{color:inherit;text-decoration:underline}'
				. '.mmi-conv-staffnote{margin-top:6px;font-size:11px;color:#8c8f94;font-style:italic}'
				. '.mmi-conv-form label{display:block;font-weight:600;margin-bottom:6px}'
				. '.mmi-conv-form textarea{box-sizing:border-box;width:100%;min-height:90px;padding:10px;font:inherit}'
				. '.mmi-conv-form .mmi-conv-actions{display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap}'
				. '.mmi-conv-hint{font-size:13px;color:#646970}'
				. '.mmi-conv-inbox .mmi-conv-unread{display:inline-block;margin-left:6px;padding:0 7px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:18px;font-weight:600}'
				. '.mmi-conv-inbox .mmi-conv-preview{color:#646970}'
				. ( class_exists( 'MMI_Order_Conversation_Attachments' ) ? MMI_Order_Conversation_Attachments::styles() : '' )
				. '.mmi-conv-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px}'
				. '.mmi-conv-tools .dashicons{font-size:16px;width:16px;height:16px;vertical-align:text-bottom}'
				. '.mmi-conv-tool[aria-expanded="true"]{background:#f0f6fc;border-color:#2271b1}'
				. '.mmi-conv-pane{margin-top:8px;padding:10px;border:1px solid #dcdcde;border-radius:8px;background:#fcfcfc}'
				. '.mmi-conv-pane[hidden],.mmi-conv-chips[hidden]{display:none}'
				. '.mmi-conv-search{width:100%;max-width:420px}'
				. '.mmi-conv-results{margin:8px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:4px;max-width:560px}'
				. '.mmi-conv-result{display:flex;justify-content:space-between;gap:12px;width:100%;padding:6px 10px;border:1px solid #dcdcde;border-radius:6px;background:#fff;text-align:left;cursor:pointer;font:inherit}'
				. '.mmi-conv-result:hover:not([disabled]){border-color:#2271b1}'
				. '.mmi-conv-result[disabled]{opacity:.55;cursor:not-allowed}'
				. '.mmi-conv-result span,.mmi-conv-result-note{color:#646970;font-size:12px}'
				. '.mmi-conv-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px}'
				. '.mmi-conv-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 4px 2px 8px;border:1px solid #c3c4c7;border-radius:12px;background:#f6f7f7;font-size:12px}'
				. '.mmi-conv-chip .dashicons{font-size:14px;width:14px;height:14px}'
				. '.mmi-conv-chip-remove{border:0;background:none;cursor:pointer;font-size:14px;line-height:1;padding:0 4px;color:#646970}'
				. '.mmi-conv-coupon-tabs{display:flex;gap:6px;margin-bottom:8px}'
				. '.mmi-conv-coupon-tab{padding:3px 10px;border:1px solid #c3c4c7;border-radius:12px;background:#fff;cursor:pointer;font:inherit;font-size:12px}'
				. '.mmi-conv-coupon-tab.active{background:#2271b1;border-color:#2271b1;color:#fff}'
				. '.mmi-conv-coupon-tab[hidden],.mmi-conv-coupon-new[hidden],.mmi-conv-search[hidden],.mmi-conv-results[hidden]{display:none}'
				. '.mmi-conv-coupon-grid{display:grid;grid-template-columns:max-content 1fr;gap:8px 12px;align-items:center}'
				. '.mmi-conv-coupon-grid > label{font-weight:600;margin:0}'
				. '.mmi-conv-coupon-checks{display:flex;flex-direction:column;gap:4px}'
				. '.mmi-conv-coupon-checks label{font-weight:400}'
				. '</style>';
		}

		private static function form_fields_html( string $submit_label ): string {
			return '<label for="mmi-conv-body">' . esc_html__( 'Your message', 'mmi-shared' ) . '</label>'
				. '<textarea id="mmi-conv-body" name="body" maxlength="' . (int) self::MAX_LENGTH . '" required></textarea>'
				. '<div class="mmi-conv-actions"><button type="submit" class="button">' . esc_html( $submit_label ) . '</button></div>';
		}

		/* ── Customer: token page (admin-post.php) ────── */

		public static function handle_page(): void {
			nocache_headers();

			// phpcs:disable WordPress.Security.NonceVerification -- the per-order token is the credential; customers are usually logged out.
			$order_id = absint( $_REQUEST['order'] ?? 0 );
			$token    = is_string( $_REQUEST['token'] ?? null ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			$stored   = $order instanceof \WC_Order ? (string) $order->get_meta( self::META_TOKEN ) : '';

			if ( $stored === '' || $token === '' || ! hash_equals( $stored, $token ) || ! self::enabled( $order ) ) {
				self::render_page( __( 'This link is not valid', 'mmi-shared' ), '<p>' . esc_html__( 'If you need help with an order, reply to the email we sent you.', 'mmi-shared' ) . '</p>', 403 );
			}

			$notice = '';
			if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
				$body = is_string( $_POST['body'] ?? null ) ? wp_unslash( $_POST['body'] ) : '';
				if ( self::rate_limited( $order ) ) {
					$notice = __( 'You have sent several messages in the last hour. Please wait for our reply before sending more.', 'mmi-shared' );
				} elseif ( self::add( $order, self::SENDER_CUSTOMER, $body, array( 'user_id' => 0 ) ) ) {
					wp_redirect( add_query_arg( 'sent', '1', explode( '#', self::customer_url( $order ) )[0] ) . '#' . self::ANCHOR, 303 );
					exit;
				} else {
					$notice = __( 'Write a message first.', 'mmi-shared' );
				}
			} elseif ( ! empty( $_GET['sent'] ) ) {
				$notice = __( 'Message sent. We will email you when we reply.', 'mmi-shared' );
			}
			// phpcs:enable

			self::customer_viewed( $order );

			$number  = $order->get_order_number();
			$created = $order->get_date_created();
			$content = '<p class="mmi-conv-hint">' . esc_html( sprintf(
				/* translators: 1: order date, 2: order total */
				__( 'Placed %1$s · %2$s', 'mmi-shared' ),
				$created ? wc_format_datetime( $created ) : '',
				html_entity_decode( wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' )
			) ) . '</p>'
				. ( $notice !== '' ? '<p class="mmi-conv-notice">' . esc_html( $notice ) . '</p>' : '' )
				. '<div id="' . esc_attr( self::ANCHOR ) . '">' . self::render_thread( $order, self::SENDER_CUSTOMER ) . '</div>'
				. '<form class="mmi-conv-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_PAGE ) . '">'
				. '<input type="hidden" name="order" value="' . esc_attr( (string) $order->get_id() ) . '">'
				. '<input type="hidden" name="token" value="' . esc_attr( $stored ) . '">'
				. self::form_fields_html( __( 'Send message', 'mmi-shared' ) )
				. '</form>';

			self::render_page( sprintf( __( 'Messages about order #%s', 'mmi-shared' ), $number ), $content );
		}

		/** Standalone page in the store's email colors, then exit. */
		private static function render_page( string $title, string $content, int $status = 200 ): void {
			$accent = get_option( 'woocommerce_email_base_color', '#720eec' );
			$dark   = get_option( 'woocommerce_email_text_color', '#3c3c3c' );
			$bg     = get_option( 'woocommerce_email_background_color', '#f7f7f7' );
			$logo   = get_option( 'woocommerce_email_header_image', '' );
			$site   = get_bloginfo( 'name' );

			status_header( $status );
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );

			echo '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
				. '<title>' . esc_html( $title . ' · ' . $site ) . '</title><style>'
				. 'body{margin:0;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $dark ) . ';font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
				. '.card{box-sizing:border-box;max-width:640px;margin:32px auto;padding:28px 24px;background:#fff;border-radius:8px}'
				. '@media (max-width:600px){.card{margin:16px;padding:22px 16px}}'
				. '.logo{text-align:center;margin-bottom:16px}.logo img{max-height:60px;max-width:220px}'
				. 'h1{font-size:22px;line-height:1.3;margin:0 0 4px}'
				. '.mmi-conv-notice{padding:10px 12px;border-radius:6px;background:#edfaef;border:1px solid #b8e6bf}'
				. '.mmi-conv-form .button{padding:11px 20px;font:inherit;font-weight:600;color:#fff;background:' . esc_attr( $accent ) . ';border:1px solid ' . esc_attr( $accent ) . ';border-radius:6px;cursor:pointer}'
				. '.mmi-conv-form textarea{border:1px solid #ccc;border-radius:6px}'
				. '</style>' . self::styles() . '</head><body><main class="card">'
				. ( $logo !== '' ? '<div class="logo"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $site ) . '"></div>' : '' )
				. '<h1>' . esc_html( $title ) . '</h1>'
				. $content // Built from escaped parts by the caller.
				. '</main></body></html>';
			exit;
		}

		/* ── Customer: My Account → View order ────── */

		/** woocommerce_view_order — WooCommerce already checked view_order for this user. */
		public static function render_account_section( $order_id ): void {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order || ! current_user_can( 'view_order', $order->get_id() ) || ! self::enabled( $order ) ) {
				return;
			}
			self::customer_viewed( $order );

			// phpcs:disable WordPress.Security.NonceVerification -- display flags only.
			$notice = '';
			if ( ! empty( $_GET['mmi_conv_sent'] ) ) {
				$notice = '<p class="woocommerce-message">' . esc_html__( 'Message sent. We will email you when we reply.', 'mmi-shared' ) . '</p>';
			} elseif ( ! empty( $_GET['mmi_conv_limited'] ) ) {
				$notice = '<p class="woocommerce-error">' . esc_html__( 'You have sent several messages in the last hour. Please wait for our reply before sending more.', 'mmi-shared' ) . '</p>';
			}
			// phpcs:enable
			echo self::styles() // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS.
				. '<section class="mmi-conv" id="' . esc_attr( self::ANCHOR ) . '"><h2>' . esc_html__( 'Messages', 'mmi-shared' ) . '</h2>'
				. $notice
				. self::render_thread( $order, self::SENDER_CUSTOMER ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
				. '<form class="mmi-conv-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_ACCOUNT ) . '">'
				. '<input type="hidden" name="order" value="' . esc_attr( (string) $order->get_id() ) . '">'
				. wp_nonce_field( self::ACTION_ACCOUNT . '_' . $order->get_id(), '_mmi_conv_nonce', true, false )
				. self::form_fields_html( __( 'Send message', 'mmi-shared' ) ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
				. '</form></section>';
		}

		public static function handle_account_post(): void {
			$order_id = absint( $_POST['order'] ?? 0 );
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			if ( ! $order || ! is_user_logged_in() || ! current_user_can( 'view_order', $order_id )
				|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) ( $_POST['_mmi_conv_nonce'] ?? '' ) ) ), self::ACTION_ACCOUNT . '_' . $order_id ) ) {
				wp_die( esc_html__( 'This message could not be sent. Please reload the page and try again.', 'mmi-shared' ), '', array( 'response' => 403 ) );
			}
			$body = is_string( $_POST['body'] ?? null ) ? wp_unslash( $_POST['body'] ) : '';
			if ( self::rate_limited( $order ) ) {
				$args = array( 'mmi_conv_limited' => '1' );
			} else {
				$args = self::add( $order, self::SENDER_CUSTOMER, $body ) ? array( 'mmi_conv_sent' => '1' ) : array();
			}
			wp_safe_redirect( add_query_arg( $args, $order->get_view_order_url() ) . '#' . self::ANCHOR, 303 );
			exit;
		}

		/* ── Customer: My Account → Messages (every thread) ────── */

		public static function register_endpoint(): void {
			add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
			if ( get_option( self::REWRITE_OPTION ) !== self::REWRITE_VERSION ) {
				flush_rewrite_rules( false );
				update_option( self::REWRITE_OPTION, self::REWRITE_VERSION );
			}
		}

		public static function add_query_var( array $vars ): array {
			$vars[ self::ENDPOINT ] = self::ENDPOINT;
			return $vars;
		}

		public static function endpoint_title(): string {
			return __( 'Messages', 'mmi-shared' );
		}

		/** After Orders; the label carries the unread count. */
		public static function account_menu_item( array $items ): array {
			$unread = self::account_unread_total( get_current_user_id() );
			$label  = $unread > 0 ? sprintf( __( 'Messages (%d)', 'mmi-shared' ), $unread ) : __( 'Messages', 'mmi-shared' );
			$out    = array();
			foreach ( $items as $key => $value ) {
				$out[ $key ] = $value;
				if ( $key === 'orders' ) {
					$out[ self::ENDPOINT ] = $label;
				}
			}
			if ( ! isset( $out[ self::ENDPOINT ] ) ) {
				$out[ self::ENDPOINT ] = $label;
			}
			return $out;
		}

		/** Unread staff messages across a customer's orders (one indexed meta query). */
		private static function account_unread_total( int $user_id ): int {
			if ( ! $user_id || ! function_exists( 'wc_get_orders' ) ) {
				return 0;
			}
			$total = 0;
			foreach ( (array) wc_get_orders( array(
				'customer_id' => $user_id,
				'limit'       => 50,
				'meta_query'  => array( array( 'key' => self::META_UNREAD_CUSTOMER, 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ) ),
			) ) as $order ) {
				$total += self::customer_unread_count( $order );
			}
			return $total;
		}

		/** woocommerce_account_messages_endpoint — the customer's threads, newest first. */
		public static function render_account_inbox(): void {
			global $wpdb;
			$user_id = get_current_user_id();
			$ids     = $user_id ? array_map( 'intval', (array) wc_get_orders( array( 'customer_id' => $user_id, 'limit' => 200, 'return' => 'ids' ) ) ) : array();
			$rows    = array();
			if ( $ids ) {
				$table = self::table();
				$in    = implode( ',', $ids ); // Ints only.
				$rows  = (array) $wpdb->get_results(
					"SELECT m.* FROM {$table} m JOIN (SELECT order_id, MAX(id) AS last_id FROM {$table} WHERE order_id IN ({$in}) AND sender <> 'system' GROUP BY order_id) l ON m.id = l.last_id ORDER BY m.id DESC"
				);
			}

			echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS.
			if ( ! $rows ) {
				echo '<p>' . esc_html__( 'No messages yet. You can message us about any order from its page under Orders.', 'mmi-shared' ) . '</p>';
				return;
			}
			echo '<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders mmi-conv-inbox"><thead><tr>'
				. '<th>' . esc_html__( 'Order', 'mmi-shared' ) . '</th><th>' . esc_html__( 'Last message', 'mmi-shared' ) . '</th><th>' . esc_html__( 'Date', 'mmi-shared' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Open', 'mmi-shared' ) . '</span></th>'
				. '</tr></thead><tbody>';
			foreach ( $rows as $m ) {
				$order = wc_get_order( (int) $m->order_id );
				if ( ! $order || ! self::enabled( $order ) ) {
					continue;
				}
				$unread  = self::customer_unread_count( $order );
				$preview = ( $m->sender === self::SENDER_CUSTOMER ? __( 'You: ', 'mmi-shared' ) : '' ) . wp_html_excerpt( self::message_text( $m ), 110, '…' );
				$url     = $order->get_view_order_url() . '#' . self::ANCHOR;
				echo '<tr>'
					. '<td data-title="' . esc_attr__( 'Order', 'mmi-shared' ) . '"><a href="' . esc_url( $url ) . '">#' . esc_html( $order->get_order_number() ) . '</a>'
					. ( $unread > 0 ? '<span class="mmi-conv-unread">' . esc_html( sprintf( _n( '%d new', '%d new', $unread, 'mmi-shared' ), $unread ) ) . '</span>' : '' ) . '</td>'
					. '<td data-title="' . esc_attr__( 'Last message', 'mmi-shared' ) . '" class="mmi-conv-preview">' . esc_html( $preview ) . '</td>'
					. '<td data-title="' . esc_attr__( 'Date', 'mmi-shared' ) . '">' . esc_html( self::format_time( $m->created_at ) ) . '</td>'
					. '<td><a class="woocommerce-button button view" href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'mmi-shared' ) . '</a></td>'
					. '</tr>';
			}
			echo '</tbody></table>';
		}

		/* ── Staff: order screen box, list badge ────── */

		public static function add_meta_box(): void {
			$screens = array( 'shop_order' );
			if ( function_exists( 'wc_get_page_screen_id' ) ) {
				$screens[] = wc_get_page_screen_id( 'shop-order' );
			}
			$current = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $current && in_array( $current->id, $screens, true ) ) {
				self::enqueue_staff_assets(); // Order screens only, not every editor.
			}
			foreach ( array_unique( $screens ) as $screen ) {
				add_meta_box( self::ANCHOR, __( 'Conversation with customer', 'mmi-shared' ), array( __CLASS__, 'render_meta_box' ), $screen, 'normal', 'high' );
			}
		}

		/** @param \WC_Order|\WP_Post $object */
		public static function render_meta_box( $object ): void {
			$order = $object instanceof \WC_Order ? $object : wc_get_order( $object->ID ?? 0 );
			if ( ! $order || ! $order->get_id() ) {
				echo '<p>' . esc_html__( 'Save the order first to message the customer.', 'mmi-shared' ) . '</p>';
				return;
			}
			if ( ! self::enabled( $order ) ) {
				echo self::elsewhere_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- kses'd inside.
				return;
			}
			echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS.
			// The storefront's own type, so the box reads like the customer's page (a site supplies it).
			$site_css = (string) apply_filters( 'mmi_order_conversation_staff_css', '', $order );
			if ( $site_css !== '' ) {
				echo '<style>' . wp_strip_all_tags( $site_css ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- CSS, tags stripped.
			}
			echo self::staff_panel_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		}

		/**
		 * One staff panel: the thread exactly as the customer sees it, the
		 * composer (text, product/listing and coupon attachments, Claude
		 * draft) and where messages go. Used by the order screen's box and
		 * by MMI Admin → Customer Messages; opening it reads the order's
		 * customer messages. Needs enqueue_staff_assets() and styles().
		 */
		public static function staff_panel_html( \WC_Order $order ): string {
			if ( ! self::enabled( $order ) ) {
				return self::elsewhere_html( $order );
			}
			do_action( 'mmi_order_conversation_sync', $order, self::SENDER_STAFF );
			if ( self::unread_count( $order ) > 0 ) {
				$order->update_meta_data( self::META_UNREAD, 0 );
				$order->save_meta_data();
			}

			$channel = self::channel( $order );
			if ( $channel !== self::CHANNEL_EMAIL ) {
				$hint = (string) apply_filters( 'mmi_order_conversation_channel_hint', sprintf( __( 'Messages go to the customer over %s.', 'mmi-shared' ), $channel ), $order, $channel );
			} else {
				$email = sanitize_email( $order->get_billing_email() );
				$hint  = ! is_email( $email ) || ( class_exists( 'MMI_Guest_Customer_Converter' ) && MMI_Guest_Customer_Converter::looks_like_relay_email( $email ) )
					? __( 'No real customer email on this order: they will not be emailed, only see messages in My Account.', 'mmi-shared' )
					: sprintf( __( 'Each message is emailed to %s (several sent within 5 minutes go as one email).', 'mmi-shared' ), $email );
				if ( has_filter( 'mmi_order_conversation_reply_address' ) ) {
					$hint .= ' ' . __( 'They can answer by replying to that email.', 'mmi-shared' );
				}
			}
			$seen = (int) $order->get_meta( self::META_SEEN );
			if ( $seen ) {
				$hint .= ' ' . sprintf( __( 'Customer last opened the thread %s.', 'mmi-shared' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $seen ) );
			}

			$id      = $order->get_id();
			$tools   = class_exists( 'MMI_Order_Conversation_Attachments' );
			$coupons = $tools && MMI_Order_Conversation_Attachments::coupons_allowed( $order );
			$field   = 'mmi-conv-staff-body-' . $id;
			$html    = '<div class="mmi-conv-staff" data-order="' . esc_attr( (string) $id ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::AJAX_STAFF . '_' . $id ) ) . '"'
				. ( $tools ? ' data-tools-nonce="' . esc_attr( wp_create_nonce( MMI_Order_Conversation_Attachments::NONCE ) ) . '"' : '' ) . ' data-channel="' . esc_attr( $channel ) . '">'
				. '<div class="mmi-conv-thread-wrap">' . self::render_thread( $order, self::SENDER_STAFF ) . '</div>'
				. '<div class="mmi-conv-form">'
				. '<label for="' . esc_attr( $field ) . '">' . esc_html__( 'Message to the customer', 'mmi-shared' ) . '</label>'
				. '<div class="mmi-conv-chips" hidden></div>'
				. '<textarea id="' . esc_attr( $field ) . '" class="mmi-conv-input" maxlength="' . (int) self::MAX_LENGTH . '"></textarea>';
			if ( $tools ) {
				$html .= '<div class="mmi-conv-tools">'
					. '<button type="button" class="button mmi-conv-tool" data-tool="product" aria-expanded="false"><span class="dashicons dashicons-products" aria-hidden="true"></span> '
					. esc_html( $channel === self::CHANNEL_EMAIL ? __( 'Link a product', 'mmi-shared' ) : __( 'Link a Reverb listing', 'mmi-shared' ) ) . '</button>'
					. ( $coupons
						? '<button type="button" class="button mmi-conv-tool" data-tool="coupon" aria-expanded="false"><span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span> ' . esc_html__( 'Coupon', 'mmi-shared' ) . '</button>'
						: '<span class="mmi-conv-hint">' . esc_html__( 'Coupons can\'t be sent to Reverb buyers: use a Reverb offer.', 'mmi-shared' ) . '</span>' )
					. '</div>'
					. '<div class="mmi-conv-pane" hidden>'
					. ( $coupons ? '<div class="mmi-conv-coupon-tabs"><button type="button" class="mmi-conv-coupon-tab active" data-tab="existing" hidden>' . esc_html__( 'Use an existing coupon', 'mmi-shared' ) . '</button>'
						. '<button type="button" class="mmi-conv-coupon-tab" data-tab="new" hidden>' . esc_html__( 'New coupon', 'mmi-shared' ) . '</button></div>' : '' )
					. '<input type="search" class="mmi-conv-search" aria-label="' . esc_attr__( 'Search', 'mmi-shared' ) . '">'
					. '<ul class="mmi-conv-results"></ul>'
					. ( $coupons ? self::coupon_form_html( $order ) : '<div class="mmi-conv-coupon-new" hidden></div>' )
					. '</div>';
			}
			$html .= '<div class="mmi-conv-actions"><button type="button" class="button button-primary mmi-conv-send">' . esc_html__( 'Send to customer', 'mmi-shared' ) . '</button>'
				. ( has_filter( 'mmi_order_conversation_ai_draft' ) ? '<button type="button" class="button mmi-conv-draft">' . esc_html( (string) apply_filters( 'mmi_order_conversation_ai_label', __( 'Draft answer', 'mmi-shared' ) ) ) . '</button>' : '' )
				. '<span class="mmi-conv-status" role="status"></span></div>'
				. '<p class="mmi-conv-hint">' . esc_html( $hint ) . '</p>'
				. '</div></div>';
			return $html;
		}

		/** The composer's "New coupon" form: stated terms, one click to make it. */
		private static function coupon_form_html( \WC_Order $order ): string {
			$email = sanitize_email( $order->get_billing_email() );
			$id    = 'mmi-conv-coupon-' . $order->get_id();
			return '<div class="mmi-conv-coupon-new" hidden><div class="mmi-conv-coupon-grid">'
				. '<label for="' . esc_attr( $id ) . '-type">' . esc_html__( 'Discount', 'mmi-shared' ) . '</label>'
				. '<span><input type="number" name="amount" min="0.01" step="0.01" value="10" class="small-text" aria-label="' . esc_attr__( 'Amount', 'mmi-shared' ) . '"> '
				. '<select name="discount_type" id="' . esc_attr( $id ) . '-type"><option value="percent">' . esc_html__( '% off the order', 'mmi-shared' ) . '</option><option value="fixed_cart">' . esc_html( sprintf( __( '%s off the order', 'mmi-shared' ), html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ) ) . '</option></select></span>'
				. '<label for="' . esc_attr( $id ) . '-days">' . esc_html__( 'Good for', 'mmi-shared' ) . '</label>'
				. '<span><input type="number" name="days" id="' . esc_attr( $id ) . '-days" min="1" max="365" value="30" class="small-text"> ' . esc_html__( 'days', 'mmi-shared' ) . '</span>'
				. '<label for="' . esc_attr( $id ) . '-code">' . esc_html__( 'Code', 'mmi-shared' ) . '</label>'
				. '<span><input type="text" name="code" id="' . esc_attr( $id ) . '-code" placeholder="' . esc_attr__( 'Made for you if left empty', 'mmi-shared' ) . '"></span>'
				. '<span></span><span class="mmi-conv-coupon-checks">'
				. '<label><input type="checkbox" name="only_customer" value="1"' . ( is_email( $email ) ? ' checked' : ' disabled' ) . '> ' . esc_html( is_email( $email ) ? sprintf( __( 'Only for %s', 'mmi-shared' ), $email ) : __( 'Only for this customer (no email on the order)', 'mmi-shared' ) ) . '</label>'
				. '<label><input type="checkbox" name="one_use" value="1" checked> ' . esc_html__( 'Can be used once', 'mmi-shared' ) . '</label></span>'
				. '<span></span><span><button type="button" class="button button-primary mmi-conv-coupon-create">' . esc_html__( 'Create coupon and attach it', 'mmi-shared' ) . '</button></span>'
				. '</div></div>';
		}

		/** The staff panel's script and its settings (any admin screen that shows panels). */
		public static function enqueue_staff_assets(): void {
			if ( ! wp_script_is( 'mmi-order-conversation', 'registered' ) ) {
				return;
			}
			wp_enqueue_script( 'mmi-order-conversation' );
			wp_localize_script( 'mmi-order-conversation', 'mmiOrderConversation', array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'maxAttachments' => class_exists( 'MMI_Order_Conversation_Attachments' ) ? MMI_Order_Conversation_Attachments::MAX : 0,
				'actions'        => array(
					'reply'  => self::AJAX_STAFF,
					'draft'  => self::AJAX_DRAFT,
					'find'   => class_exists( 'MMI_Order_Conversation_Attachments' ) ? MMI_Order_Conversation_Attachments::AJAX_FIND : '',
					'coupon' => class_exists( 'MMI_Order_Conversation_Attachments' ) ? MMI_Order_Conversation_Attachments::AJAX_COUPON : '',
				),
				'i18n'           => array(
					'sending'        => __( 'Sending…', 'mmi-shared' ),
					'notSent'        => __( 'Not sent. Check your connection and try again.', 'mmi-shared' ),
					'failed'         => __( 'That did not work. Reload the page and try again.', 'mmi-shared' ),
					'drafting'       => __( 'Drafting an answer from the order\'s sources…', 'mmi-shared' ),
					'draftSlow'      => __( 'Still drafting after 4 minutes. Try again later, or write the answer yourself.', 'mmi-shared' ),
					'noDraft'        => __( 'No draft. Check your connection and try again.', 'mmi-shared' ),
					'replaceDraft'   => __( 'Replace what is in the message box with a drafted answer?', 'mmi-shared' ),
					'searchProducts' => __( 'Search products by name, SKU or ID…', 'mmi-shared' ),
					'searchCoupons'  => __( 'Search coupons by code…', 'mmi-shared' ),
					'searching'      => __( 'Searching…', 'mmi-shared' ),
					'noMatch'        => __( 'Nothing matches.', 'mmi-shared' ),
					'typeToSearch'   => __( 'Type to search.', 'mmi-shared' ),
					'attached'       => __( 'Attached. It goes with your next message.', 'mmi-shared' ),
					'tooMany'        => __( 'That is the most one message can carry.', 'mmi-shared' ),
					'remove'         => __( 'Remove', 'mmi-shared' ),
					'creatingCoupon' => __( 'Creating the coupon…', 'mmi-shared' ),
					'couponCreated'  => __( 'Coupon %s created and attached.', 'mmi-shared' ),
				),
			) );
		}

		public static function ajax_staff_reply(): void {
			$order_id = absint( $_POST['order_id'] ?? 0 );
			if ( ! $order_id || ! check_ajax_referer( self::AJAX_STAFF . '_' . $order_id, 'nonce', false ) || ! current_user_can( 'edit_shop_orders' ) ) {
				wp_send_json_error( __( 'Not allowed. Reload the order and try again.', 'mmi-shared' ), 403 );
			}
			$order = wc_get_order( $order_id );
			$body  = is_string( $_POST['body'] ?? null ) ? wp_unslash( $_POST['body'] ) : '';
			$refs  = is_string( $_POST['attachments'] ?? null ) ? json_decode( wp_unslash( $_POST['attachments'] ), true ) : array();
			$atts  = array();
			if ( $order && is_array( $refs ) && $refs && class_exists( 'MMI_Order_Conversation_Attachments' ) ) {
				$atts = MMI_Order_Conversation_Attachments::build( $refs, $order );
				if ( is_wp_error( $atts ) ) {
					wp_send_json_error( $atts->get_error_message() );
				}
			}
			if ( ! $order || ! self::add( $order, self::SENDER_STAFF, $body, array( 'attachments' => $atts ) ) ) {
				wp_send_json_error( __( 'Nothing was sent: write a message or attach something first.', 'mmi-shared' ) );
			}
			$message = self::channel( $order ) === self::CHANNEL_EMAIL
				? __( 'Sent. The customer is emailed within 5 minutes.', 'mmi-shared' )
				: __( 'Sent. It reaches the customer within a minute.', 'mmi-shared' );
			wp_send_json_success( array(
				'thread'  => self::render_thread( $order, self::SENDER_STAFF ),
				'message' => $message,
			) );
		}

		/**
		 * What a drafted answer may use: the conversation (without names or
		 * addresses) and numbered sources, each with its own link.
		 *
		 * @return array{items:string[], messages:array<int,array{from:string,text:string}>, sources:array<int,array{title:string,url:string,text:string}>}
		 */
		public static function ai_context( \WC_Order $order ): array {
			$messages = array();
			foreach ( array_slice( array_values( array_filter( self::get_messages( $order->get_id() ), static fn( $m ) => $m->sender !== self::SENDER_SYSTEM ) ), -self::DRAFT_MESSAGES ) as $m ) {
				$messages[] = array( 'from' => $m->sender === self::SENDER_STAFF ? 'Store' : 'Customer', 'text' => (string) $m->body );
			}
			$items   = array();
			$sources = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_name();
				$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
				if ( ! $product ) {
					continue;
				}
				$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
				$text   = trim( wp_strip_all_tags( $product->get_short_description() . "\n" . ( $product->get_description() ?: ( $parent ? $parent->get_description() : '' ) ) ) );
				$sources[] = array(
					'title' => $product->get_name(),
					'url'   => (string) get_permalink( $product->get_parent_id() ?: $product->get_id() ),
					'text'  => self::clip( preg_replace( '/\s+/', ' ', $text ) ),
				);
			}
			$sources = (array) apply_filters( 'mmi_order_conversation_ai_sources', $sources, $order );
			$clean   = array();
			foreach ( $sources as $s ) {
				$url = esc_url_raw( (string) ( $s['url'] ?? '' ) );
				if ( $url === '' ) {
					continue;
				}
				$clean[] = array( 'title' => sanitize_text_field( (string) ( $s['title'] ?? $url ) ), 'url' => $url, 'text' => self::clip( (string) ( $s['text'] ?? '' ) ) );
			}
			return array( 'items' => $items, 'messages' => $messages, 'sources' => array_slice( $clean, 0, 12 ) );
		}

		private static function clip( string $text ): string {
			return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::SOURCE_TEXT_MAX ) : substr( $text, 0, self::SOURCE_TEXT_MAX );
		}

		/**
		 * Staff "Draft answer": the provider's answer plus the links of the
		 * sources it says it used. Never sent from here.
		 */
		public static function ajax_ai_draft(): void {
			$order_id = absint( $_POST['order_id'] ?? 0 );
			if ( ! $order_id || ! check_ajax_referer( self::AJAX_STAFF . '_' . $order_id, 'nonce', false ) || ! current_user_can( 'edit_shop_orders' ) ) {
				wp_send_json_error( __( 'Not allowed. Reload the order and try again.', 'mmi-shared' ), 403 );
			}
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_send_json_error( __( 'Order not found.', 'mmi-shared' ) );
			}
			$request = is_string( $_POST['request'] ?? null ) ? sanitize_key( wp_unslash( $_POST['request'] ) ) : '';
			if ( $request === '' ) {
				$context = self::ai_context( $order );
				if ( ! array_filter( $context['messages'], static fn( $m ) => $m['from'] === 'Customer' ) ) {
					wp_send_json_error( __( 'There is no customer message to answer yet.', 'mmi-shared' ) );
				}
			} else {
				$context = array( 'request' => $request ); // Asking after a background draft: the provider kept its own context.
			}
			$result = apply_filters( 'mmi_order_conversation_ai_draft', new \WP_Error( 'no_provider', __( 'No answer drafter is set up.', 'mmi-shared' ) ), $order, $context );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}
			if ( ! empty( $result['pending'] ) ) {
				wp_send_json_success( array(
					'pending' => sanitize_key( (string) $result['pending'] ),
					'message' => (string) ( $result['message'] ?? __( 'Drafting…', 'mmi-shared' ) ),
				) );
			}
			if ( isset( $result['context'] ) && is_array( $result['context'] ) ) {
				$context = $result['context']; // The sources the background draft was given, for its links.
			}
			$answer = trim( sanitize_textarea_field( (string) ( $result['answer'] ?? '' ) ) );
			if ( $answer === '' ) {
				wp_send_json_error( __( 'The drafter returned nothing. Try again or write the answer yourself.', 'mmi-shared' ) );
			}
			$links = array();
			foreach ( array_unique( array_map( 'intval', (array) ( $result['source_ids'] ?? array() ) ) ) as $n ) {
				if ( isset( $context['sources'][ $n - 1 ] ) ) {
					$links[] = $context['sources'][ $n - 1 ]['title'] . ': ' . $context['sources'][ $n - 1 ]['url'];
				}
			}
			$text = $answer . ( $links ? "\n\n" . __( 'Sources:', 'mmi-shared' ) . "\n" . implode( "\n", $links ) : '' );
			if ( class_exists( 'MMI_Logger' ) ) {
				MMI_Logger::info( "Order conversation: answer drafted for order {$order_id}", array( 'sources' => count( $links ), 'confident' => ! empty( $result['confident'] ) ), 'order-conversation', __CLASS__ );
			}
			wp_send_json_success( array(
				'text'    => $text,
				'message' => empty( $result['confident'] )
					? __( 'Draft ready, but the sources did not clearly answer the question: check every fact before sending.', 'mmi-shared' )
					: __( 'Draft ready. Read it, check the links, then send.', 'mmi-shared' ),
			) );
		}

		/** After the order number in the Orders list (HPOS and legacy). */
		public static function render_list_badge( $column, $order_or_id ): void {
			if ( $column !== 'order_number' ) {
				return;
			}
			$order  = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
			$unread = $order && self::enabled( $order ) ? self::unread_count( $order ) : 0;
			if ( $unread < 1 ) {
				return;
			}
			printf(
				' <a href="%1$s" title="%2$s" style="display:inline-block;margin-left:4px;padding:0 7px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:18px;text-decoration:none;">%3$s</a>',
				esc_url( self::staff_url( $order ) ),
				esc_attr( sprintf( _n( '%d unread customer message', '%d unread customer messages', $unread, 'mmi-shared' ), $unread ) ),
				esc_html( sprintf( _n( '%d message', '%d messages', $unread, 'mmi-shared' ), $unread ) )
			);
		}

		/* ── Privacy (Tools → Export / Erase Personal Data) ────── */

		public static function register_exporter( array $exporters ): array {
			$exporters['mmi-order-conversation'] = array(
				'exporter_friendly_name' => __( 'Order messages', 'mmi-shared' ),
				'callback'               => array( __CLASS__, 'export_personal_data' ),
			);
			return $exporters;
		}

		public static function register_eraser( array $erasers ): array {
			$erasers['mmi-order-conversation'] = array(
				'eraser_friendly_name' => __( 'Order messages', 'mmi-shared' ),
				'callback'             => array( __CLASS__, 'erase_personal_data' ),
			);
			return $erasers;
		}

		/** @return int[] */
		private static function order_ids_for_email( string $email ): array {
			return function_exists( 'wc_get_orders' )
				? array_map( 'intval', (array) wc_get_orders( array( 'billing_email' => $email, 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order' ) ) )
				: array();
		}

		public static function export_personal_data( $email, $page = 1 ): array {
			$data = array();
			foreach ( self::order_ids_for_email( (string) $email ) as $order_id ) {
				foreach ( self::get_messages( $order_id ) as $m ) {
					$data[] = array(
						'group_id'    => 'mmi-order-messages',
						'group_label' => __( 'Order messages', 'mmi-shared' ),
						'item_id'     => 'mmi-order-message-' . $m->id,
						'data'        => array(
							array( 'name' => __( 'Order', 'mmi-shared' ), 'value' => '#' . $order_id ),
							array( 'name' => __( 'From', 'mmi-shared' ), 'value' => $m->author !== '' ? $m->author : $m->sender ),
							array( 'name' => __( 'Sent', 'mmi-shared' ), 'value' => $m->created_at . ' UTC' ),
							array( 'name' => __( 'Message', 'mmi-shared' ), 'value' => $m->body ),
						),
					);
				}
			}
			return array( 'data' => $data, 'done' => true );
		}

		/** Removes what the customer wrote; staff and system messages stay. */
		public static function erase_personal_data( $email, $page = 1 ): array {
			global $wpdb;
			$table   = self::table();
			$removed = 0;
			foreach ( self::order_ids_for_email( (string) $email ) as $order_id ) {
				$removed += (int) $wpdb->query( $wpdb->prepare(
					"UPDATE {$table} SET body = %s, author = '' WHERE order_id = %d AND sender = %s",
					'[removed]',
					$order_id,
					self::SENDER_CUSTOMER
				) );
			}
			return array( 'items_removed' => $removed > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
	}
}
