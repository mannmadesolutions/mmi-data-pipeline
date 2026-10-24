<?php
/**
 * MMI_Order_Conversation
 *
 * One message thread per WooCommerce order between the store and its
 * customer, Reverb-style: on-site and asynchronous, with an email to the
 * other side for each new message (batched over NOTIFY_DELAY). Shared so
 * any MMI plugin can turn it on (init() is idempotent) and every order kind
 * — XChange, subscriptions, Reverb — uses one thread.
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
 *    anonymous front-end HTML may be edge-cached, /wp-admin isn't), and a
 *    Messages section on My Account → View order (woocommerce_view_order).
 *  - Staff: a Conversation box on the order screen (AJAX reply), and an
 *    unread badge after the order number in the Orders list.
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

		const DB_VERSION = '1';
		const DB_OPTION  = 'mmi_order_messages_db_version';

		const SENDER_CUSTOMER = 'customer';
		const SENDER_STAFF    = 'staff';
		const SENDER_SYSTEM   = 'system';

		/** Per-order secret for the customer's link. */
		const META_TOKEN  = '_mmi_conv_token';
		/** Customer messages the staff hasn't opened the order to see. */
		const META_UNREAD = '_mmi_conv_unread_staff';
		/** Unix time the customer last opened the thread. */
		const META_SEEN   = '_mmi_conv_customer_seen';

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

		private static bool $initialized = false;
		private static bool $table_ready = false;
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

			add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_list_badge' ), 20, 2 );
			add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_list_badge' ), 20, 2 );

			add_action( self::HOOK_NOTIFY, array( __CLASS__, 'run_notify' ), 10, 2 );
			add_action( 'woocommerce_delete_order', array( __CLASS__, 'delete_for_order' ) );

			add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
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
						PRIMARY KEY  (id),
						KEY order_id (order_id)
					) " . $wpdb->get_charset_collate() . ';' );
					update_option( self::DB_OPTION, self::DB_VERSION );
				}
			}
			return $table;
		}

		/**
		 * Adds a message. Customer and staff messages email the other side
		 * (batched) unless $args['notify'] is false; system messages never do.
		 *
		 * @param array $args { author?: string, user_id?: int, time?: int (unix), notify?: bool }
		 * @return int Message id, 0 when nothing was stored (empty body, bad sender).
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
				'order_id'   => $order->get_id(),
				'sender'     => $sender,
				'user_id'    => $user_id,
				'author'     => sanitize_text_field( $author ),
				'body'       => $body,
				'created_at' => gmdate( 'Y-m-d H:i:s', (int) ( $args['time'] ?? time() ) ),
				'notified'   => $notify ? 0 : 1,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) {
				return 0;
			}

			if ( $sender === self::SENDER_CUSTOMER ) {
				$order->update_meta_data( self::META_UNREAD, self::unread_count( $order ) + 1 );
				$order->save_meta_data();
			}
			if ( $notify ) {
				self::schedule_notify( $order->get_id(), $sender === self::SENDER_CUSTOMER ? self::SENDER_STAFF : self::SENDER_CUSTOMER );
			}

			do_action( 'mmi_order_conversation_message_added', $id, $order, $sender );
			return $id;
		}

		/** @return array<int,object{id:string,order_id:string,sender:string,user_id:string,author:string,body:string,created_at:string,notified:string}> Oldest first. */
		public static function get_messages( int $order_id ): array {
			global $wpdb;
			$table = self::table();
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id ) );
		}

		public static function unread_count( \WC_Order $order ): int {
			return max( 0, (int) $order->get_meta( self::META_UNREAD ) );
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

		/* ── Notifications ────── */

		private static function schedule_notify( int $order_id, string $side ): void {
			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				self::run_notify( $order_id, $side );
				return;
			}
			$args = array( $order_id, $side );
			if ( ! as_has_scheduled_action( self::HOOK_NOTIFY, $args, self::AS_GROUP ) ) {
				as_schedule_single_action( time() + self::NOTIFY_DELAY, self::HOOK_NOTIFY, $args, self::AS_GROUP );
			}
		}

		/**
		 * Action Scheduler: one email to $side with every message from the
		 * other side not yet sent to them.
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

			$sent = $side === self::SENDER_CUSTOMER
				? self::email_customer( $order, $pending )
				: self::email_staff( $order, $pending );
			if ( ! $sent && class_exists( 'MMI_Logger' ) ) {
				MMI_Logger::warn( "Order conversation: {$side} notification for order {$order->get_id()} was not sent", array(), 'order-conversation', __CLASS__ );
			}
		}

		private static function email_customer( \WC_Order $order, array $messages ): bool {
			$to = sanitize_email( $order->get_billing_email() );
			if ( ! is_email( $to ) || ( class_exists( 'MMI_Guest_Customer_Converter' ) && MMI_Guest_Customer_Converter::looks_like_relay_email( $to ) ) ) {
				$order->add_order_note( __( 'A message to the customer was not emailed: the order has no real customer email address. They can still read it in My Account.', 'mmi-shared' ) );
				return false;
			}
			$site   = get_bloginfo( 'name' );
			$number = $order->get_order_number();
			$first  = trim( $order->get_billing_first_name() ) ?: __( 'there', 'mmi-shared' );

			$body = '<p>' . esc_html( sprintf( __( 'Hi %1$s, you have a new message from %2$s about order #%3$s:', 'mmi-shared' ), $first, $site, $number ) ) . '</p>'
				. self::email_quotes( $messages )
				. '<p>' . esc_html__( 'Use the button to reply. Your messages stay with the order, so you can also find them in My Account under Orders.', 'mmi-shared' ) . '</p>';
			$subject = sprintf( __( 'New message about your order #%1$s — %2$s', 'mmi-shared' ), $number, $site );

			$html = class_exists( 'MMI_Email_Templates' )
				? MMI_Email_Templates::render_customer_notice( array(
					'box_heading' => sprintf( __( 'Order #%s', 'mmi-shared' ), $number ),
					'body_html'   => $body,
					'cta_url'     => self::customer_url( $order ),
					'cta_label'   => esc_html__( 'Reply', 'mmi-shared' ),
				) )
				: $body . '<p><a href="' . esc_url( self::customer_url( $order ) ) . '">' . esc_html__( 'Reply', 'mmi-shared' ) . '</a></p>';

			return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
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
				$body = $m->sender === self::SENDER_STAFF ? make_clickable( esc_html( $m->body ) ) : esc_html( $m->body );
				$html .= '<div class="mmi-conv-msg mmi-conv-msg--' . esc_attr( $m->sender ) . ( $mine ? ' is-mine' : '' ) . '">'
					. '<div class="mmi-conv-meta">' . ( $name !== '' ? '<strong>' . esc_html( $name ) . '</strong> · ' : '' ) . esc_html( self::format_time( $m->created_at ) ) . '</div>'
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

			$order->update_meta_data( self::META_SEEN, time() );
			$order->save_meta_data();

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
			$order->update_meta_data( self::META_SEEN, time() );
			$order->save_meta_data();

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
			// Opening the order is reading its messages.
			if ( self::unread_count( $order ) > 0 ) {
				$order->update_meta_data( self::META_UNREAD, 0 );
				$order->save_meta_data();
			}

			$email = sanitize_email( $order->get_billing_email() );
			$hint  = ! is_email( $email ) || ( class_exists( 'MMI_Guest_Customer_Converter' ) && MMI_Guest_Customer_Converter::looks_like_relay_email( $email ) )
				? __( 'No real customer email on this order: they will not be emailed, only see messages in My Account.', 'mmi-shared' )
				: sprintf( __( 'Each message is emailed to %s (several sent within 5 minutes go as one email).', 'mmi-shared' ), $email );
			$seen  = (int) $order->get_meta( self::META_SEEN );
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
			wp_send_json_success( array(
				'thread'  => self::render_thread( $order, self::SENDER_STAFF ),
				'message' => __( 'Sent. The customer is emailed within 5 minutes.', 'mmi-shared' ),
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
