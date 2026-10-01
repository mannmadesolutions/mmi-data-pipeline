<?php
/**
 * MMI_Software_Fulfillment
 *
 * Vendor-neutral core for delivering software licenses to a customer —
 * shared so each software-vendor integration (mmi-xchange-integration today,
 * SkuPort/Plugivery later) and each marketplace (mmi-reverb-integration) can
 * plug into one model without depending on each other.
 *
 * Three concerns, one owner each:
 *
 *  1. Providers — a vendor integration registers how to recognize its own
 *     line items (register_provider()). Nothing here knows how to buy from a
 *     vendor; the provider's own plugin does that and reports back through
 *     record_placement()/record_fulfillment().
 *  2. Per-line-item records — ITEM_META on each WC_Order_Item_Product:
 *     'placed' once a vendor PO was bought for it, 'fulfilled' once the
 *     customer was emailed. 'placed' is what stops a second purchase when an
 *     admin placed a PO and then closed the email step without sending.
 *  3. Customer grouping + one email — customer_key() is how separate orders
 *     (a Reverb buyer's two checkouts, or two mannmade.us orders a day apart)
 *     are recognized as one customer; render_bundle_email()/send_bundle_email()
 *     deliver every pending title in a single message.
 *
 * customer_key() precedence: linked WC customer account, then a real
 * (non-marketplace-relay) billing email, then the
 * 'mmi_software_fulfillment_customer_key' filter (mmi-reverb-integration
 * keys still-relayed orders by Reverb buyer ID), else the order alone.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Software_Fulfillment', false ) ) {

	class MMI_Software_Fulfillment {

		const ITEM_META        = '_mmi_sw_fulfillment';
		const EMAIL_LOG_META   = '_mmi_sw_bundle_email_log';
		const MAX_EMAIL_LOG    = 10;
		const STATUS_PLACED    = 'placed';
		const STATUS_FULFILLED = 'fulfilled';

		/**
		 * @var array<string, array{label:string, resolve_sku:callable}>
		 */
		private static array $providers = array();

		/**
		 * @param string $key    Stable identifier, e.g. 'xchange'.
		 * @param array  $config {
		 *     @type string   $label       e.g. 'XChange'.
		 *     @type callable $resolve_sku function( int $product_id ): string — this
		 *                                 provider's own SKU for the product, or '' if
		 *                                 the provider doesn't sell it.
		 * }
		 */
		public static function register_provider( string $key, array $config ): void {
			self::$providers[ $key ] = array(
				'label'       => (string) ( $config['label'] ?? $key ),
				'resolve_sku' => $config['resolve_sku'] ?? null,
			);
		}

		public static function has_provider( string $key ): bool {
			return isset( self::$providers[ $key ] );
		}

		/**
		 * First registered provider that sells this line item's product.
		 *
		 * @return array{provider:string, sku:string}|null
		 */
		public static function resolve_item( \WC_Order_Item_Product $item ): ?array {
			// Variation first — a variation can carry its own vendor SKU,
			// falling back to the parent's.
			$candidates = array_filter( array( (int) $item->get_variation_id(), (int) $item->get_product_id() ) );
			foreach ( self::$providers as $key => $provider ) {
				if ( ! is_callable( $provider['resolve_sku'] ) ) {
					continue;
				}
				foreach ( $candidates as $product_id ) {
					$sku = (string) $provider['resolve_sku']( $product_id );
					if ( $sku !== '' ) {
						return array( 'provider' => $key, 'sku' => $sku );
					}
				}
			}
			return null;
		}

		/* ── Customer grouping ─────────────────────────────────────────────── */

		public static function customer_key( \WC_Order $order ): string {
			if ( $order->get_customer_id() > 0 ) {
				return 'c:' . $order->get_customer_id();
			}

			$email      = strtolower( trim( $order->get_billing_email() ) );
			$is_relayed = class_exists( 'MMI_Guest_Customer_Converter' )
				&& MMI_Guest_Customer_Converter::looks_like_relay_email( $email );
			if ( $email !== '' && ! $is_relayed ) {
				return 'e:' . $email;
			}

			return (string) apply_filters( 'mmi_software_fulfillment_customer_key', 'o:' . $order->get_id(), $order );
		}

		/* ── Per-line-item records ─────────────────────────────────────────── */

		/**
		 * @return array{status:string, provider:string, sku:string, po_number:string, license_key:string, download_url:string, support_url:string, placed_at:string, fulfilled_at:string}|null
		 */
		public static function get_record( \WC_Order_Item_Product $item ): ?array {
			$record = $item->get_meta( self::ITEM_META, true );
			return is_array( $record ) && ! empty( $record['status'] ) ? $record : null;
		}

		/**
		 * The line item on $order this provider SKU belongs to, if any.
		 */
		public static function find_item( \WC_Order $order, string $sku ): ?\WC_Order_Item_Product {
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$resolved = self::resolve_item( $item );
				if ( $resolved !== null && $resolved['sku'] === $sku ) {
					return $item;
				}
			}
			return null;
		}

		/**
		 * A vendor PO was bought for this item — written the moment the
		 * purchase succeeds, before any email, so a later "Fulfill" click
		 * reuses this PO instead of buying again.
		 */
		public static function record_placement( int $order_id, string $sku, string $po_number, array $extra = array() ): bool {
			return self::write_record( $order_id, $sku, self::STATUS_PLACED, array_merge( $extra, array(
				'po_number' => $po_number,
				'placed_at' => current_time( 'mysql' ),
			) ) );
		}

		/**
		 * The customer has been sent this item's license.
		 */
		public static function record_fulfillment( int $order_id, string $sku, array $data ): bool {
			return self::write_record( $order_id, $sku, self::STATUS_FULFILLED, array_merge( $data, array(
				'fulfilled_at' => current_time( 'mysql' ),
			) ) );
		}

		private static function write_record( int $order_id, string $sku, string $status, array $data ): bool {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return false;
			}
			$item = self::find_item( $order, $sku );
			if ( ! $item ) {
				return false;
			}

			$resolved = self::resolve_item( $item );
			$existing = self::get_record( $item ) ?? array();
			$allowed  = array( 'po_number', 'license_key', 'download_url', 'support_url', 'placed_at', 'fulfilled_at' );

			$record = array_merge(
				$existing,
				array_intersect_key( array_map( 'strval', $data ), array_flip( $allowed ) ),
				array(
					'status'   => $status,
					'provider' => $resolved['provider'] ?? '',
					'sku'      => $sku,
				)
			);

			$item->update_meta_data( self::ITEM_META, $record );
			$item->save();
			return true;
		}

		/* ── One email for every pending title ─────────────────────────────── */

		/**
		 * @param array $args {
		 *     @type string $customer_name
		 *     @type array  $items list of {
		 *         name, logo_url?, product_url?, vendor_name?, brand_url?,
		 *         license_key? (newlines kept), download_url?, support_url?,
		 *         order_number?, po_number?,
		 *         downloads? list of {label, url} — one button per platform
		 *                    (e.g. SkuPort's separate OSX/Windows links);
		 *                    takes precedence over the single download_url
		 *     }
		 * }
		 * @return string HTML body, or '' if the shared email template class is unavailable.
		 */
		public static function render_bundle_email( array $args ): string {
			if ( ! class_exists( 'MMI_Email_Templates' ) ) {
				return '';
			}

			$items = array_values( array_filter( (array) ( $args['items'] ?? array() ), 'is_array' ) );
			$first = trim( (string) ( $args['customer_name'] ?? '' ) ) ?: __( 'there', 'mmi-shared' );

			// Same WooCommerce brand tokens MMI_Xchange_Fulfillment_Email uses,
			// so this matches the customer's order-confirmation email. Email
			// clients need inline styles; there is no stylesheet to put these in.
			$c = array(
				'dark'    => get_option( 'woocommerce_email_text_color', '#3c3c3c' ),
				'muted'   => get_option( 'woocommerce_email_footer_text_color', '#767676' ),
				'accent'  => get_option( 'woocommerce_email_base_color', '#720eec' ),
				'panel'   => '#f8f8f8',
				'border'  => '#e5e5e5',
				'warn_bg' => '#fff3cd',
				'warn'    => '#b45309',
			);

			$cards = '';
			foreach ( $items as $item ) {
				$cards .= self::render_item_card( $item, $c );
			}

			// One support notice for the whole email rather than per card —
			// the per-card support button carries the vendor-specific link.
			$notice = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 20px;background:' . esc_attr( $c['warn_bg'] ) . ';border-left:4px solid ' . esc_attr( $c['warn'] ) . ';border-radius:6px;">'
				. '<tr><td style="padding:12px 16px;font-size:13px;line-height:1.5;color:' . esc_attr( $c['dark'] ) . ';">'
				. esc_html__( 'Each product below is made and supported by its manufacturer, not by us. For installation or activation help, use the "Manufacturer Support" link on that product — replies to this email can\'t answer those questions.', 'mmi-shared' )
				. '</td></tr></table>';

			$count = count( $items );

			return MMI_Email_Templates::render_customer_notice( array(
				'box_heading' => $count === 1
					? sprintf(
						/* translators: %s: product name */
						esc_html__( 'Your %s license is ready', 'mmi-shared' ),
						esc_html( (string) ( $items[0]['name'] ?? '' ) )
					)
					: sprintf(
						/* translators: %d: number of products */
						esc_html__( 'Your %d software licenses are ready', 'mmi-shared' ),
						$count
					),
				'box_subtext' => sprintf(
					/* translators: %s: customer's first name */
					esc_html__( 'Hi %s — here\'s everything you need for each product you purchased.', 'mmi-shared' ),
					esc_html( $first )
				),
				'body_html'   => $notice . $cards,
				'cta_url'     => home_url(),
				'cta_label'   => esc_html__( 'Visit Our Store', 'mmi-shared' ),
			) );
		}

		private static function render_item_card( array $item, array $c ): string {
			$name     = trim( (string) ( $item['name'] ?? '' ) );
			$logo     = trim( (string) ( $item['logo_url'] ?? '' ) );
			$url      = trim( (string) ( $item['product_url'] ?? '' ) );
			$vendor   = trim( (string) ( $item['vendor_name'] ?? '' ) );
			$brand    = trim( (string) ( $item['brand_url'] ?? '' ) );
			$license  = trim( (string) ( $item['license_key'] ?? '' ) );
			$download = trim( (string) ( $item['download_url'] ?? '' ) );
			$downloads = array_values( array_filter(
				(array) ( $item['downloads'] ?? array() ),
				static fn( $d ) => is_array( $d ) && trim( (string) ( $d['url'] ?? '' ) ) !== ''
			) );
			if ( ! $downloads && $download !== '' ) {
				$downloads = array( array( 'label' => '', 'url' => $download ) );
			}
			$support  = trim( (string) ( $item['support_url'] ?? '' ) );
			$order_no = trim( (string) ( $item['order_number'] ?? '' ) );
			$po       = trim( (string) ( $item['po_number'] ?? '' ) );

			$link = static fn( string $href, string $text ): string => $href !== ''
				? '<a href="' . esc_url( $href ) . '" style="color:' . esc_attr( $c['accent'] ) . ';text-decoration:none;">' . esc_html( $text ) . '</a>'
				: esc_html( $text );

			$logo_cell = $logo !== ''
				? '<td style="width:96px;padding:0 16px 0 0;vertical-align:top;"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '" width="96" style="width:96px;max-width:96px;height:auto;border-radius:6px;display:block;"></td>'
				: '';

			$meta = array();
			if ( $vendor !== '' ) {
				$meta[] = $link( $brand, $vendor );
			}
			if ( $order_no !== '' ) {
				/* translators: %s: order number */
				$meta[] = esc_html( sprintf( __( 'Order #%s', 'mmi-shared' ), $order_no ) );
			}
			if ( $po !== '' ) {
				/* translators: %s: vendor purchase-order number */
				$meta[] = esc_html( sprintf( __( 'Ref %s', 'mmi-shared' ), $po ) );
			}

			$header = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr>' . $logo_cell
				. '<td style="vertical-align:top;">'
				. '<p style="margin:0 0 4px;font-size:17px;font-weight:700;color:' . esc_attr( $c['dark'] ) . ';">' . $link( $url, $name ) . '</p>'
				. ( $meta ? '<p style="margin:0;font-size:12px;color:' . esc_attr( $c['muted'] ) . ';">' . implode( ' &middot; ', $meta ) . '</p>' : '' )
				. '</td></tr></table>';

			$license_html = $license !== ''
				? '<p style="margin:16px 0 4px;font-size:12px;color:' . esc_attr( $c['muted'] ) . ';text-transform:uppercase;letter-spacing:.04em;">' . esc_html__( 'License Key', 'mmi-shared' ) . '</p>'
					. '<p style="margin:0;font-family:Consolas,Menlo,monospace;font-size:15px;font-weight:600;background:#ffffff;border:1px solid ' . esc_attr( $c['border'] ) . ';border-radius:6px;padding:12px 14px;color:' . esc_attr( $c['dark'] ) . ';word-break:break-all;">' . nl2br( esc_html( $license ) ) . '</p>'
				: '';

			$buttons = '';
			foreach ( $downloads as $d ) {
				$label = trim( (string) ( $d['label'] ?? '' ) );
				$text  = $label !== ''
					/* translators: %s: platform, e.g. "OSX" */
					? sprintf( __( 'Download (%s)', 'mmi-shared' ), $label )
					: __( 'Download', 'mmi-shared' );
				$buttons .= '<a href="' . esc_url( (string) $d['url'] ) . '" style="display:inline-block;margin:16px 8px 0 0;padding:10px 18px;background:' . esc_attr( $c['accent'] ) . ';color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;">' . esc_html( $text ) . ' &rarr;</a>';
			}
			if ( $support !== '' ) {
				$buttons .= '<a href="' . esc_url( $support ) . '" style="display:inline-block;margin:16px 0 0;padding:9px 16px;border:2px solid ' . esc_attr( $c['warn'] ) . ';color:' . esc_attr( $c['warn'] ) . ';text-decoration:none;border-radius:6px;font-size:13px;font-weight:600;">' . esc_html__( 'Manufacturer Support', 'mmi-shared' ) . '</a>';
			}

			return '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 16px;background:' . esc_attr( $c['panel'] ) . ';border:1px solid ' . esc_attr( $c['border'] ) . ';border-radius:8px;">'
				. '<tr><td style="padding:18px 20px;">' . $header . $license_html . $buttons . '</td></tr></table>';
		}

		/**
		 * Renders and sends one email covering every item, and appends the
		 * attempt to each involved order's EMAIL_LOG_META.
		 *
		 * @param int[] $order_ids Orders the items belong to (history is written to each).
		 * @return array{success:bool, message:string}
		 */
		public static function send_bundle_email( string $to, string $customer_name, array $items, array $order_ids, bool $is_test = false ): array {
			if ( ! is_email( $to ) ) {
				return array( 'success' => false, 'message' => __( 'Not a valid email address.', 'mmi-shared' ) );
			}

			$body = self::render_bundle_email( array( 'customer_name' => $customer_name, 'items' => $items ) );
			if ( $body === '' ) {
				return array( 'success' => false, 'message' => __( 'Email templates are unavailable.', 'mmi-shared' ) );
			}

			$count   = count( $items );
			$subject = $count === 1
				? sprintf(
					/* translators: 1: product name, 2: site name */
					__( 'Your %1$s license is ready — %2$s', 'mmi-shared' ),
					(string) ( $items[0]['name'] ?? '' ),
					get_bloginfo( 'name' )
				)
				: sprintf(
					/* translators: 1: number of products, 2: site name */
					__( 'Your %1$d software licenses are ready — %2$s', 'mmi-shared' ),
					$count,
					get_bloginfo( 'name' )
				);
			if ( $is_test ) {
				$subject = '[TEST] ' . $subject;
			}

			$headers     = array( 'Content-Type: text/html; charset=UTF-8' );
			$admin_email = get_option( 'admin_email' );
			if ( ! $is_test && is_email( $admin_email ) && strcasecmp( $admin_email, $to ) !== 0 ) {
				$headers[] = 'Cc: ' . $admin_email;
			}

			$sent   = wp_mail( $to, $subject, $body, $headers );
			$result = $sent
				? array( 'success' => true, 'message' => __( 'Email sent.', 'mmi-shared' ) )
				: array( 'success' => false, 'message' => __( "wp_mail() reported failure — check the site's mail configuration.", 'mmi-shared' ) );

			if ( ! $is_test ) {
				foreach ( array_unique( array_map( 'intval', $order_ids ) ) as $order_id ) {
					self::append_email_log( $order_id, $to, $count, $result );
				}
			}

			// Log files are plain text on disk — the recipient is masked there;
			// the full address stays in the order's own EMAIL_LOG_META.
			$masked_to = self::mask_email( $to );
			if ( class_exists( 'MMI_Logger' ) ) {
				$sent
					? MMI_Logger::info( "Software bundle email sent to {$masked_to}", array( 'items' => $count, 'orders' => $order_ids, 'test' => $is_test ), 'general', 'MMI_Software_Fulfillment' )
					: MMI_Logger::warn( "Software bundle email failed to send to {$masked_to}", array( 'orders' => $order_ids ), 'general', 'MMI_Software_Fulfillment' );
			}

			if ( class_exists( 'MMI_Audit_Log' ) ) {
				MMI_Audit_Log::record( 'mmi-shared', 'fulfillment.email_send', array(
					'object_type' => 'order',
					'object_id'   => implode( ',', array_unique( array_map( 'intval', $order_ids ) ) ),
					'outcome'     => $sent ? 'success' : 'failure',
					'details'     => array(
						'recipient' => $masked_to,
						'items'     => $count,
						'test'      => $is_test,
					),
				) );
			}

			return $result;
		}

		/** "jane.doe@example.com" → "j***@example.com" for logs. */
		private static function mask_email( string $email ): string {
			$at = strrpos( $email, '@' );
			if ( false === $at || 0 === $at ) {
				return '***';
			}
			return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
		}

		/**
		 * @return array<int, array{sent_at:string, to:string, items:int, success:bool, message:string}>
		 */
		public static function get_email_log( int $order_id ): array {
			$order = wc_get_order( $order_id );
			$log   = $order ? $order->get_meta( self::EMAIL_LOG_META, true ) : array();
			return is_array( $log ) ? $log : array();
		}

		private static function append_email_log( int $order_id, string $to, int $items, array $result ): void {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
			$log   = self::get_email_log( $order_id );
			$log[] = array(
				'sent_at' => current_time( 'mysql' ),
				'to'      => $to,
				'items'   => $items,
				'success' => (bool) $result['success'],
				'message' => (string) $result['message'],
			);
			$order->update_meta_data( self::EMAIL_LOG_META, array_slice( $log, -self::MAX_EMAIL_LOG ) );
			$order->save();
		}
	}
}
