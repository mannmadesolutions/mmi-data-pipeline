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

		const DB_VERSION = '2';
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

			add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );

			if ( class_exists( 'MMI_Order_Conversation_Gmail' ) ) {
				MMI_Order_Conversation_Gmail::init();
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
		 *     'gmail:<id>', 'reverb:<id>'); a second add with the same id is a no-op.
		 * }
		 * @return int Message id, 0 when nothing was stored (empty body, bad sender, duplicate).
		 */
		public static function add( \WC_Order $order, string $sender, string $body, array $args = array() ): int {
			global $wpdb;
			if ( ! in_array( $sender, array( self::SENDER_CUSTOMER, self::SENDER_STAFF, self::SENDER_SYSTEM ), true ) ) {
				return 0;
			}
			$body = trim( sanitize_textarea_field( $body ) );
			$body = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, self::MAX_LENGTH ) : substr( $body, 0, self::MAX_LENGTH );
			if ( $body === '' ) {
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
				$match = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table} WHERE order_id = %d AND sender = %s AND external_id = '' AND body = %s AND created_at BETWEEN %s AND %s ORDER BY id ASC LIMIT 1",
					$order->get_id(),
					self::SENDER_STAFF,
					trim( sanitize_textarea_field( $body ) ),
					gmdate( 'Y-m-d H:i:s', $time - DAY_IN_SECONDS ),
					gmdate( 'Y-m-d H:i:s', $time + DAY_IN_SECONDS )
				) );
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
			$first   = trim( $order->get_billing_first_name() ) ?: __( 'there', 'mmi-shared' );
			$headers = array_merge( array( 'Content-Type: text/html; charset=UTF-8' ), self::reply_headers( $order ) );
			$by_mail = count( $headers ) > 1;

			$body = ( $by_mail ? '<p style="margin:0 0 14px;font-size:11px;color:#999;">' . esc_html( self::REPLY_MARKER ) . '</p>' : '' )
				. '<p>' . esc_html( sprintf( __( 'Hi %1$s, you have a new message from %2$s about order #%3$s:', 'mmi-shared' ), $first, $site, $number ) ) . '</p>'
				. self::email_quotes( $messages )
				. '<p>' . esc_html( $by_mail
					? __( 'Reply to this email, or use the button. Your messages stay with the order, so you can also find them in My Account under Messages.', 'mmi-shared' )
					: __( 'Use the button to reply. Your messages stay with the order, so you can also find them in My Account under Messages.', 'mmi-shared' ) ) . '</p>';
			$subject = sprintf( __( 'New message about your order #%1$s — %2$s', 'mmi-shared' ), $number, $site );

			$html = class_exists( 'MMI_Email_Templates' )
				? MMI_Email_Templates::render_customer_notice( array(
					'box_heading' => sprintf( __( 'Order #%s', 'mmi-shared' ), $number ),
					'body_html'   => $body,
					'cta_url'     => self::customer_url( $order ),
					'cta_label'   => esc_html__( 'Reply', 'mmi-shared' ),
				) )
				: $body . '<p><a href="' . esc_url( self::customer_url( $order ) ) . '">' . esc_html__( 'Reply', 'mmi-shared' ) . '</a></p>';

			return (bool) wp_mail( $to, $subject, $html, $headers );
		}

		private static function email_staff( \WC_Order $order, array $messages ): bool {
			$to = get_option( 'admin_email' );
			if ( ! is_email( $to ) ) {
				return false;
			}
			$number = $order->get_order_number();
			$name   = (string) $messages[0]->author;
			$head   = sprintf( __( 'New message from %1$s on order #%2$s', 'mmi-shared' ), $name, $number );
			$body   = self::email_quotes( $messages )
				. '<p>' . esc_html( sprintf( __( 'Customer email: %s', 'mmi-shared' ), $order->get_billing_email() ) ) . '</p>';

			$html = class_exists( 'MMI_Email_Templates' )
				? MMI_Email_Templates::render_notice( array(
					'headline'  => $head,
					'body_html' => $body,
					'cta_url'   => self::staff_url( $order ),
					'cta_label' => __( 'Reply on the order &rarr;', 'mmi-shared' ),
				) )
				: $body . '<p><a href="' . esc_url( self::staff_url( $order ) ) . '">' . esc_html__( 'Reply on the order', 'mmi-shared' ) . '</a></p>';

			return (bool) wp_mail( $to, '[' . get_bloginfo( 'name' ) . '] ' . $head, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}

		private static function email_quotes( array $messages ): string {
			$html = '';
			foreach ( $messages as $m ) {
				$html .= '<div style="margin:0 0 14px;padding:10px 14px;border-left:3px solid #d0d0d0;background:#f8f8f8;">'
					. '<div style="font-size:12px;color:#767676;margin-bottom:4px;">' . esc_html( $m->author . ' · ' . self::format_time( $m->created_at ) ) . '</div>'
					. '<div>' . nl2br( esc_html( $m->body ) ) . '</div></div>';
			}
			return $html;
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
			if ( ! $messages ) {
				return '<div class="mmi-conv-thread"><p class="mmi-conv-empty">' . esc_html__( 'No messages yet.', 'mmi-shared' ) . '</p></div>';
			}
			$site = get_bloginfo( 'name' );
			$html = '<div class="mmi-conv-thread">';
			foreach ( $messages as $m ) {
				$mine = $m->sender === $viewer;
				if ( $m->sender === self::SENDER_SYSTEM ) {
					$name = '';
				} elseif ( $viewer === self::SENDER_CUSTOMER ) {
					$name = $mine ? __( 'You', 'mmi-shared' ) : $site;
				} else {
					$name = $m->sender === self::SENDER_STAFF ? sprintf( __( '%s (staff)', 'mmi-shared' ), $m->author ) : $m->author;
				}
				$meta   = self::format_time( $m->created_at );
				$source = $viewer === self::SENDER_STAFF ? self::source_label( (string) ( $m->external_id ?? '' ) ) : '';
				if ( $source !== '' ) {
					$meta .= ' · ' . $source;
				}
				$body = $m->sender === self::SENDER_STAFF ? make_clickable( esc_html( $m->body ) ) : esc_html( $m->body );
				$html .= '<div class="mmi-conv-msg mmi-conv-msg--' . esc_attr( $m->sender ) . ( $mine ? ' is-mine' : '' ) . '">'
					. '<div class="mmi-conv-meta">' . ( $name !== '' ? '<strong>' . esc_html( $name ) . '</strong> · ' : '' ) . esc_html( $meta ) . '</div>'
					. '<div class="mmi-conv-body">' . $body . '</div></div>';
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
				. '.mmi-conv-form label{display:block;font-weight:600;margin-bottom:6px}'
				. '.mmi-conv-form textarea{box-sizing:border-box;width:100%;min-height:90px;padding:10px;font:inherit}'
				. '.mmi-conv-form .mmi-conv-actions{display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap}'
				. '.mmi-conv-hint{font-size:13px;color:#646970}'
				. '.mmi-conv-inbox .mmi-conv-unread{display:inline-block;margin-left:6px;padding:0 7px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:18px;font-weight:600}'
				. '.mmi-conv-inbox .mmi-conv-preview{color:#646970}'
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

			if ( $stored === '' || $token === '' || ! hash_equals( $stored, $token ) ) {
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
			if ( ! $order || ! current_user_can( 'view_order', $order->get_id() ) ) {
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
				if ( ! $order ) {
					continue;
				}
				$unread  = self::customer_unread_count( $order );
				$preview = ( $m->sender === self::SENDER_CUSTOMER ? __( 'You: ', 'mmi-shared' ) : '' ) . wp_html_excerpt( $m->body, 110, '…' );
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
			do_action( 'mmi_order_conversation_sync', $order, self::SENDER_STAFF );
			// Opening the order is reading its messages.
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

			echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS.
			echo '<div class="mmi-conv-staff" data-order="' . esc_attr( (string) $order->get_id() ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::AJAX_STAFF . '_' . $order->get_id() ) ) . '">'
				. '<div class="mmi-conv-thread-wrap">' . self::render_thread( $order, self::SENDER_STAFF ) . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
				. '<div class="mmi-conv-form">'
				. '<label for="mmi-conv-staff-body">' . esc_html__( 'Message to the customer', 'mmi-shared' ) . '</label>'
				. '<textarea id="mmi-conv-staff-body" maxlength="' . (int) self::MAX_LENGTH . '"></textarea>'
				. '<div class="mmi-conv-actions"><button type="button" class="button button-primary mmi-conv-send">' . esc_html__( 'Send to customer', 'mmi-shared' ) . '</button>'
				. '<span class="mmi-conv-status" role="status"></span></div>'
				. '<p class="mmi-conv-hint">' . esc_html( $hint ) . '</p>'
				. '</div></div>';
			?>
			<script>
			( function () {
				var box = document.querySelector( '.mmi-conv-staff' );
				if ( ! box ) { return; }
				var btn = box.querySelector( '.mmi-conv-send' ), field = box.querySelector( 'textarea' ), status = box.querySelector( '.mmi-conv-status' );
				btn.addEventListener( 'click', function () {
					if ( ! field.value.trim() ) { field.focus(); return; }
					var data = new FormData();
					data.append( 'action', <?php echo wp_json_encode( self::AJAX_STAFF ); ?> );
					data.append( 'order_id', box.dataset.order );
					data.append( 'nonce', box.dataset.nonce );
					data.append( 'body', field.value );
					btn.disabled = true;
					status.textContent = <?php echo wp_json_encode( __( 'Sending…', 'mmi-shared' ) ); ?>;
					fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( ! res || ! res.success ) { throw new Error( res && res.data ? res.data : '' ); }
							box.querySelector( '.mmi-conv-thread-wrap' ).innerHTML = res.data.thread;
							field.value = '';
							status.textContent = res.data.message;
						} )
						.catch( function ( e ) {
							status.textContent = e.message || <?php echo wp_json_encode( __( 'Not sent. Check your connection and try again.', 'mmi-shared' ) ); ?>;
						} )
						.finally( function () { btn.disabled = false; } );
				} );
			} )();
			</script>
			<?php
		}

		public static function ajax_staff_reply(): void {
			$order_id = absint( $_POST['order_id'] ?? 0 );
			if ( ! $order_id || ! check_ajax_referer( self::AJAX_STAFF . '_' . $order_id, 'nonce', false ) || ! current_user_can( 'edit_shop_orders' ) ) {
				wp_send_json_error( __( 'Not allowed. Reload the order and try again.', 'mmi-shared' ), 403 );
			}
			$order = wc_get_order( $order_id );
			$body  = is_string( $_POST['body'] ?? null ) ? wp_unslash( $_POST['body'] ) : '';
			if ( ! $order || ! self::add( $order, self::SENDER_STAFF, $body ) ) {
				wp_send_json_error( __( 'Nothing was sent: the message is empty.', 'mmi-shared' ) );
			}
			$message = self::channel( $order ) === self::CHANNEL_EMAIL
				? __( 'Sent. The customer is emailed within 5 minutes.', 'mmi-shared' )
				: __( 'Sent. It reaches the customer within a minute.', 'mmi-shared' );
			wp_send_json_success( array(
				'thread'  => self::render_thread( $order, self::SENDER_STAFF ),
				'message' => $message,
			) );
		}

		/** After the order number in the Orders list (HPOS and legacy). */
		public static function render_list_badge( $column, $order_or_id ): void {
			if ( $column !== 'order_number' ) {
				return;
			}
			$order  = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
			$unread = $order ? self::unread_count( $order ) : 0;
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
