<?php
/**
 * MMI_Order_Conversation_Gmail
 *
 * Lets a customer answer an order message by replying to its email. Each
 * customer email carries a Reply-To of the site's sending mailbox with a
 * per-order plus-address (mike+order-224177-1a2b3c4d5e@…); Google Workspace
 * and Gmail deliver that to the mailbox itself, and this class reads it
 * back through the Gmail connection WP Mail SMTP already holds (its OAuth
 * grant is https://mail.google.com/), adds the new text to the order's
 * thread and files the email under LABEL_NAME, read and out of the inbox.
 *
 * Reads only what it asked for: each poll searches for the plus-addresses
 * issued in the last WINDOW_DAYS (MMI_Order_Conversation::META_REPLY_ISSUED),
 * never the rest of the mailbox. The address's signature (HMAC over the
 * order id) is checked, so a guessed address can't post into an order.
 * Auto-replies and the mailbox's own mail are skipped. If Gmail refuses
 * (revoked grant, quota), polling backs off for an hour; replies still reach
 * the mailbox as ordinary mail, so nothing is lost.
 *
 * Off unless SETTING is 'yes' and WP Mail SMTP sends through Gmail.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Order_Conversation_Gmail', false ) ) {

	class MMI_Order_Conversation_Gmail {

		/** MMI_Settings key: 'yes' turns reply-by-email on. */
		const SETTING        = 'mmi_order_conversation_email_replies';
		const OPTION_MAILBOX = 'mmi_order_conversation_gmail_mailbox';
		const OPTION_LABEL   = 'mmi_order_conversation_gmail_label';
		const BACKOFF        = 'mmi_order_conversation_gmail_backoff';

		const HOOK_POLL     = 'mmi_order_conversation_gmail_poll';
		const POLL_INTERVAL = 300;
		const WINDOW_DAYS   = 35;
		const MAX_PER_RUN   = 25;
		const CHUNK         = 15;
		const LABEL_NAME    = 'Order messages';

		const GMAIL_CLASS = '\\WPMailSMTP\\Vendor\\Google\\Service\\Gmail';

		public static function init(): void {
			add_action( self::HOOK_POLL, array( __CLASS__, 'poll' ) );
			add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
			if ( self::available() ) {
				add_filter( 'mmi_order_conversation_reply_address', array( __CLASS__, 'reply_address' ), 10, 2 );
			}
		}

		/** Turned on, and WP Mail SMTP sends through Gmail. */
		public static function available(): bool {
			return class_exists( 'MMI_Settings' ) && MMI_Settings::get( self::SETTING, 'no' ) === 'yes' && self::gmail_mailer_active();
		}

		public static function gmail_mailer_active(): bool {
			return class_exists( '\\WPMailSMTP\\Options' )
				&& class_exists( '\\WPMailSMTP\\Providers\\Gmail\\Auth' )
				&& \WPMailSMTP\Options::init()->get( 'mail', 'mailer' ) === 'gmail';
		}

		/** Recurring poll while turned on; removed when turned off. */
		public static function schedule(): void {
			if ( ! function_exists( 'as_has_scheduled_action' ) ) {
				return;
			}
			$scheduled = as_has_scheduled_action( self::HOOK_POLL, array(), MMI_Order_Conversation::AS_GROUP );
			if ( self::available() && ! $scheduled ) {
				as_schedule_recurring_action( time() + 60, self::POLL_INTERVAL, self::HOOK_POLL, array(), MMI_Order_Conversation::AS_GROUP );
			} elseif ( ! self::available() && $scheduled ) {
				as_unschedule_all_actions( self::HOOK_POLL, array(), MMI_Order_Conversation::AS_GROUP );
			}
		}

		/* ── Addresses ────── */

		/** The mailbox WP Mail SMTP is authorized as (asked once, then remembered). */
		public static function mailbox(): string {
			$mailbox = (string) get_option( self::OPTION_MAILBOX, '' );
			if ( $mailbox !== '' ) {
				return $mailbox;
			}
			try {
				MMI_API_Throttler::throttle( 'gmail' );
				$mailbox = (string) self::gmail()->users->getProfile( 'me' )->getEmailAddress();
			} catch ( \Throwable $e ) {
				self::failed( $e );
				return '';
			}
			if ( is_email( $mailbox ) ) {
				update_option( self::OPTION_MAILBOX, $mailbox, false );
				return $mailbox;
			}
			return '';
		}

		private static function signature( int $order_id ): string {
			return substr( hash_hmac( 'sha256', 'mmi-order-reply-' . $order_id, wp_salt( 'auth' ) ), 0, 10 );
		}

		/** Filter mmi_order_conversation_reply_address. */
		public static function reply_address( $address, $order ): string {
			$mailbox = $order instanceof \WC_Order ? self::mailbox() : '';
			if ( $mailbox === '' ) {
				return (string) $address;
			}
			list( $local, $domain ) = explode( '@', $mailbox, 2 );
			return strtok( $local, '+' ) . '+order-' . $order->get_id() . '-' . self::signature( $order->get_id() ) . '@' . $domain;
		}

		/** The order a header's plus-address names, 0 if none or the signature is wrong. */
		private static function order_from_header( string $header ): int {
			if ( ! preg_match_all( '/\+order-(\d+)-([a-f0-9]{10})@/i', $header, $matches, PREG_SET_ORDER ) ) {
				return 0;
			}
			foreach ( $matches as $m ) {
				if ( hash_equals( self::signature( (int) $m[1] ), strtolower( $m[2] ) ) ) {
					return (int) $m[1];
				}
			}
			return 0;
		}

		/* ── Polling ────── */

		private static function gmail() {
			$client = ( new \WPMailSMTP\Providers\Gmail\Auth() )->get_client();
			$class  = self::GMAIL_CLASS;
			return new $class( $client );
		}

		/** Action Scheduler (HOOK_POLL). */
		public static function poll(): void {
			if ( ! self::available() || get_transient( self::BACKOFF ) || ! function_exists( 'wc_get_orders' ) ) {
				return;
			}
			$orders = (array) wc_get_orders( array(
				'limit'      => 100,
				'return'     => 'ids',
				'meta_query' => array( array(
					'key'     => MMI_Order_Conversation::META_REPLY_ISSUED,
					'value'   => time() - self::WINDOW_DAYS * DAY_IN_SECONDS,
					'compare' => '>',
					'type'    => 'NUMERIC',
				) ),
			) );
			if ( ! $orders ) {
				return;
			}
			$mailbox = self::mailbox();
			if ( $mailbox === '' ) {
				return;
			}
			list( $local, $domain ) = explode( '@', $mailbox, 2 );
			$local = strtok( $local, '+' );

			try {
				$gmail     = self::gmail();
				$processed = 0;
				foreach ( array_chunk( array_map( 'intval', $orders ), self::CHUNK ) as $chunk ) {
					$terms = array_map( static fn( $id ) => 'to:' . $local . '+order-' . $id . '-' . self::signature( $id ) . '@' . $domain, $chunk );
					MMI_API_Throttler::throttle( 'gmail' );
					$list = $gmail->users_messages->listUsersMessages( 'me', array(
						'q'          => '{' . implode( ' ', $terms ) . '} newer_than:' . self::WINDOW_DAYS . 'd -label:' . sanitize_title( self::LABEL_NAME ),
						'maxResults' => 50,
					) );
					foreach ( (array) $list->getMessages() as $ref ) {
						if ( $processed >= self::MAX_PER_RUN ) {
							return;
						}
						if ( MMI_Order_Conversation::has_external( 'gmail:' . $ref->getId() ) ) {
							continue;
						}
						MMI_API_Throttler::throttle( 'gmail' );
						self::import( $gmail, $gmail->users_messages->get( 'me', $ref->getId(), array( 'format' => 'full' ) ), $mailbox );
						$processed++;
					}
				}
			} catch ( \Throwable $e ) {
				self::failed( $e );
			}
		}

		/** One reply into its order's thread, then filed out of the inbox. */
		private static function import( $gmail, $message, string $mailbox ): void {
			$headers = array();
			foreach ( (array) $message->getPayload()->getHeaders() as $h ) {
				$headers[ strtolower( $h->getName() ) ] = trim( ( $headers[ strtolower( $h->getName() ) ] ?? '' ) . ' ' . $h->getValue() );
			}
			$from_header = (string) ( $headers['from'] ?? '' );
			$from        = preg_match( '/<([^>]+)>/', $from_header, $m ) ? $m[1] : trim( $from_header );
			$from        = strtolower( sanitize_email( $from ) );
			$from_name   = trim( preg_replace( '/<[^>]*>/', '', $from_header ), " \"'" );

			$own_from = class_exists( '\\WPMailSMTP\\Options' ) ? strtolower( (string) \WPMailSMTP\Options::init()->get( 'mail', 'from_email' ) ) : '';
			$auto     = ( isset( $headers['auto-submitted'] ) && strtolower( $headers['auto-submitted'] ) !== 'no' )
				|| preg_match( '/\b(bulk|junk|auto_reply|list)\b/i', (string) ( $headers['precedence'] ?? '' ) )
				|| isset( $headers['x-autoreply'] ) || isset( $headers['x-autorespond'] );
			$order_id = self::order_from_header( ( $headers['to'] ?? '' ) . ' ' . ( $headers['cc'] ?? '' ) . ' ' . ( $headers['delivered-to'] ?? '' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : false;

			$skip = ! $order || $auto || $from === '' || $from === strtolower( $mailbox ) || ( $own_from !== '' && $from === $own_from );
			if ( ! $skip ) {
				$text = MMI_Order_Conversation::strip_quoted( self::body_text( $message->getPayload() ) );
				$id   = $text === '' ? 0 : MMI_Order_Conversation::add( $order, MMI_Order_Conversation::SENDER_CUSTOMER, $text, array(
					'external_id' => 'gmail:' . $message->getId(),
					'author'      => $from_name !== '' ? $from_name : $from,
					'user_id'     => 0,
					'time'        => (int) floor( (int) $message->getInternalDate() / 1000 ) ?: time(),
				) );
				if ( $id && strcasecmp( $from, (string) $order->get_billing_email() ) !== 0 ) {
					MMI_Order_Conversation::add( $order, MMI_Order_Conversation::SENDER_SYSTEM, sprintf(
						/* translators: %s: sender's email address */
						__( 'The reply above came from %s, not the order\'s email address.', 'mmi-shared' ),
						$from
					) );
				}
			}

			self::file_away( $gmail, $message->getId() );
		}

		/** Plain-text part if there is one, else the HTML part as text. */
		private static function body_text( $part ): string {
			$plain = '';
			$html  = '';
			$walk  = static function ( $p ) use ( &$walk, &$plain, &$html ) {
				$type = strtolower( (string) $p->getMimeType() );
				$data = $p->getBody() ? (string) $p->getBody()->getData() : '';
				if ( $data !== '' ) {
					$decoded = (string) base64_decode( strtr( $data, '-_', '+/' ) );
					if ( $type === 'text/plain' && $plain === '' ) {
						$plain = $decoded;
					} elseif ( $type === 'text/html' && $html === '' ) {
						$html = $decoded;
					}
				}
				foreach ( (array) $p->getParts() as $child ) {
					$walk( $child );
				}
			};
			$walk( $part );
			if ( $plain !== '' ) {
				return $plain;
			}
			$html = preg_replace( '#<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#i', "\n", $html );
			return html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
		}

		/** Labelled LABEL_NAME, read, out of the inbox. */
		private static function file_away( $gmail, string $message_id ): void {
			$label = self::label_id( $gmail );
			$class = self::GMAIL_CLASS . '\\ModifyMessageRequest';
			$req   = new $class();
			if ( $label !== '' ) {
				$req->setAddLabelIds( array( $label ) );
			}
			$req->setRemoveLabelIds( array( 'INBOX', 'UNREAD' ) );
			MMI_API_Throttler::throttle( 'gmail' );
			$gmail->users_messages->modify( 'me', $message_id, $req );
		}

		private static function label_id( $gmail ): string {
			$id = (string) get_option( self::OPTION_LABEL, '' );
			if ( $id !== '' ) {
				return $id;
			}
			MMI_API_Throttler::throttle( 'gmail' );
			foreach ( (array) $gmail->users_labels->listUsersLabels( 'me' )->getLabels() as $label ) {
				if ( $label->getName() === self::LABEL_NAME ) {
					$id = (string) $label->getId();
				}
			}
			if ( $id === '' ) {
				$class = self::GMAIL_CLASS . '\\Label';
				$new   = new $class();
				$new->setName( self::LABEL_NAME );
				$new->setLabelListVisibility( 'labelShow' );
				$new->setMessageListVisibility( 'show' );
				MMI_API_Throttler::throttle( 'gmail' );
				$id = (string) $gmail->users_labels->create( 'me', $new )->getId();
			}
			update_option( self::OPTION_LABEL, $id, false );
			return $id;
		}

		/** Logged; refusals and rate limits pause polling for an hour. */
		private static function failed( \Throwable $e ): void {
			$code = (int) $e->getCode();
			if ( $code === 400 || $code === 404 ) {
				delete_option( self::OPTION_LABEL ); // The label may have been deleted in Gmail; found or made again next time.
			}
			if ( in_array( $code, array( 401, 403, 429 ), true ) ) {
				set_transient( self::BACKOFF, 1, HOUR_IN_SECONDS );
				if ( $code === 429 ) {
					MMI_API_Throttler::penalize( 'gmail' );
				}
			}
			if ( class_exists( 'MMI_Logger' ) ) {
				MMI_Logger::warn( 'Order conversation Gmail poll failed (' . $code . '): ' . substr( $e->getMessage(), 0, 300 ), array(), 'order-conversation', __CLASS__ );
			}
		}
	}
}
