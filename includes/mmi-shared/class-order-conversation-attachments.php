<?php
/**
 * MMI_Order_Conversation_Attachments
 *
 * What staff can put into an order message besides text, like Reverb's own
 * message composer: a product (its page, or on a Reverb order its Reverb
 * listing) and a coupon (an existing one, or one made right there).
 *
 * Every attachment is stored as a snapshot taken when the message is sent —
 * title, link, price, a coupon's terms — so the thread keeps showing what the
 * customer was actually sent even if the product or coupon changes later.
 * Snapshots are always built on the server from ids (build()); nothing the
 * browser sends is stored as-is.
 *
 * Reverb orders: Reverb doesn't allow sending its buyers off Reverb, so a
 * product links to its Reverb listing (no listing → refused) and coupons are
 * refused (a Reverb offer is the way to give a Reverb buyer a discount).
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Order_Conversation_Attachments', false ) ) {

	class MMI_Order_Conversation_Attachments {

		const AJAX_FIND   = 'mmi_order_conversation_find';
		const AJAX_COUPON = 'mmi_order_conversation_coupon';
		/** Nonce for the composer's tools (search, new coupon). */
		const NONCE       = 'mmi_order_conversation_tools';
		const MAX         = 6;
		const RESULTS     = 8;

		private static bool $initialized = false;

		public static function init(): void {
			if ( self::$initialized ) {
				return;
			}
			self::$initialized = true;
			add_action( 'wp_ajax_' . self::AJAX_FIND, array( __CLASS__, 'ajax_find' ) );
			add_action( 'wp_ajax_' . self::AJAX_COUPON, array( __CLASS__, 'ajax_coupon' ) );
		}

		/** Coupons can go to this order's customer (not on Reverb). */
		public static function coupons_allowed( \WC_Order $order ): bool {
			return MMI_Order_Conversation::channel( $order ) === MMI_Order_Conversation::CHANNEL_EMAIL;
		}

		/* ── Snapshots ────── */

		/**
		 * Snapshots for the attachments staff picked.
		 *
		 * @param array $refs [ ['type' => 'product'|'coupon', 'id' => int], … ]
		 * @return array|\WP_Error Snapshots, or why one can't be sent.
		 */
		public static function build( array $refs, \WC_Order $order ) {
			$out  = array();
			$seen = array();
			foreach ( array_slice( $refs, 0, self::MAX ) as $ref ) {
				$type = sanitize_key( (string) ( $ref['type'] ?? '' ) );
				$id   = absint( $ref['id'] ?? 0 );
				if ( ! $id || isset( $seen[ $type . $id ] ) ) {
					continue;
				}
				$seen[ $type . $id ] = true;
				$snap = $type === 'product' ? self::product( $id, $order ) : ( $type === 'coupon' ? self::coupon( $id, $order ) : null );
				if ( is_wp_error( $snap ) ) {
					return $snap;
				}
				if ( $snap ) {
					$out[] = $snap;
				}
			}
			return $out;
		}

		/** @return array|\WP_Error|null */
		private static function product( int $id, \WC_Order $order ) {
			$product = wc_get_product( $id );
			if ( ! $product || $product->get_status() !== 'publish' ) {
				return null;
			}
			$reverb = ! self::coupons_allowed( $order );
			$url    = $reverb ? self::reverb_url( $product ) : (string) get_permalink( $product->get_parent_id() ?: $id );
			if ( $url === '' ) {
				return new \WP_Error( 'no_listing', sprintf( __( '%s has no Reverb listing to link to (Reverb buyers can only be sent Reverb links).', 'mmi-shared' ), $product->get_name() ) );
			}
			$image = (int) $product->get_image_id();
			return array(
				'type'  => 'product',
				'id'    => $id,
				'title' => $product->get_name(),
				'url'   => esc_url_raw( $url ),
				'price' => html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ), ENT_QUOTES, 'UTF-8' ),
				'image' => $image ? (string) wp_get_attachment_image_url( $image, 'thumbnail' ) : '',
				'where' => $reverb ? 'reverb' : 'store',
			);
		}

		/** The product's live Reverb listing, '' when it has none. */
		public static function reverb_url( \WC_Product $product ): string {
			$id  = $product->get_parent_id() ?: $product->get_id();
			$url = (string) get_post_meta( $id, 'reverb_listing_url', true );
			if ( $url === '' && ( $listing = (string) get_post_meta( $id, 'reverb_listing_id', true ) ) !== '' ) {
				$url = 'https://reverb.com/item/' . rawurlencode( $listing );
			}
			return $url;
		}

		/** @return array|\WP_Error|null */
		private static function coupon( int $id, \WC_Order $order ) {
			if ( ! self::coupons_allowed( $order ) ) {
				return new \WP_Error( 'reverb_coupon', __( 'Reverb doesn\'t allow sending its buyers outside coupons. Use a Reverb offer instead.', 'mmi-shared' ) );
			}
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'shop_coupon' || $post->post_status !== 'publish' ) {
				return null;
			}
			$coupon = new \WC_Coupon( $id );
			return array(
				'type'    => 'coupon',
				'id'      => $id,
				'code'    => strtoupper( $coupon->get_code() ),
				'summary' => self::coupon_summary( $coupon ),
				'url'     => (string) ( wc_get_page_permalink( 'shop' ) ?: home_url( '/' ) ),
			);
		}

		/** "15% off · until Nov 30, 2026 · one use · for your email address" */
		public static function coupon_summary( \WC_Coupon $coupon ): string {
			$amount = (float) $coupon->get_amount();
			$parts  = array();
			switch ( $coupon->get_discount_type() ) {
				case 'percent':
					$parts[] = sprintf( __( '%s%% off', 'mmi-shared' ), wc_format_localized_decimal( $amount ) );
					break;
				case 'fixed_product':
					$parts[] = sprintf( __( '%s off each item', 'mmi-shared' ), html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) );
					break;
				default:
					$parts[] = sprintf( __( '%s off your order', 'mmi-shared' ), html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) );
			}
			if ( $coupon->get_free_shipping() ) {
				$parts[] = __( 'free shipping', 'mmi-shared' );
			}
			$expires = $coupon->get_date_expires();
			if ( $expires ) {
				$parts[] = sprintf( __( 'until %s', 'mmi-shared' ), wc_format_datetime( $expires ) );
			}
			if ( (int) $coupon->get_usage_limit() === 1 || (int) $coupon->get_usage_limit_per_user() === 1 ) {
				$parts[] = __( 'one use', 'mmi-shared' );
			}
			if ( $coupon->get_email_restrictions() ) {
				$parts[] = __( 'for your email address', 'mmi-shared' );
			}
			if ( (float) $coupon->get_minimum_amount() > 0 ) {
				$parts[] = sprintf( __( 'on orders over %s', 'mmi-shared' ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $coupon->get_minimum_amount() ) ), ENT_QUOTES, 'UTF-8' ) );
			}
			return implode( ' · ', $parts );
		}

		/* ── Rendering ────── */

		/** Plain lines, for Reverb messages, previews and the plain-text body. */
		public static function text( array $atts ): string {
			$lines = array();
			foreach ( $atts as $a ) {
				if ( ( $a['type'] ?? '' ) === 'product' ) {
					$lines[] = $a['title'] . ( $a['price'] !== '' ? ' (' . $a['price'] . ')' : '' ) . ': ' . $a['url'];
				} elseif ( ( $a['type'] ?? '' ) === 'coupon' ) {
					$lines[] = sprintf( __( 'Coupon code %1$s: %2$s', 'mmi-shared' ), $a['code'], $a['summary'] );
				}
			}
			return implode( "\n", $lines );
		}

		/**
		 * Cards. 'thread' uses the conversation's classes (styles() below);
		 * 'email' is table markup with inline styles for mail clients.
		 */
		public static function html( array $atts, string $context = 'thread' ): string {
			$html = '';
			foreach ( $atts as $a ) {
				$type = (string) ( $a['type'] ?? '' );
				if ( $type === 'product' ) {
					$label = ( $a['where'] ?? '' ) === 'reverb' ? __( 'View on Reverb', 'mmi-shared' ) : __( 'View product', 'mmi-shared' );
					$html .= $context === 'email'
						? '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:10px 0 0;border:1px solid #e5e5e5;border-radius:8px;background:#fff;"><tr>'
							. ( $a['image'] !== '' ? '<td style="width:72px;padding:10px;"><img src="' . esc_url( $a['image'] ) . '" alt="" width="64" height="64" style="display:block;border-radius:6px;"></td>' : '' )
							. '<td style="padding:10px;"><div style="font-weight:700;color:#1d2327;">' . esc_html( $a['title'] ) . '</div>'
							. ( $a['price'] !== '' ? '<div style="color:#646970;font-size:13px;margin:2px 0 6px;">' . esc_html( $a['price'] ) . '</div>' : '' )
							. '<a href="' . esc_url( $a['url'] ) . '" style="font-weight:600;">' . esc_html( $label ) . ' &rarr;</a></td></tr></table>'
						: '<a class="mmi-conv-card mmi-conv-card--product" href="' . esc_url( $a['url'] ) . '" target="_blank" rel="noopener">'
							. ( $a['image'] !== '' ? '<img src="' . esc_url( $a['image'] ) . '" alt="" loading="lazy">' : '<span class="mmi-conv-card-icon dashicons dashicons-products" aria-hidden="true"></span>' )
							. '<span class="mmi-conv-card-text"><strong>' . esc_html( $a['title'] ) . '</strong>'
							. ( $a['price'] !== '' ? '<span>' . esc_html( $a['price'] ) . '</span>' : '' )
							. '<em>' . esc_html( $label ) . ' &rarr;</em></span></a>';
				} elseif ( $type === 'coupon' ) {
					$html .= $context === 'email'
						? '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:10px 0 0;border:2px dashed #45852c;border-radius:8px;background:#f4faf1;"><tr><td style="padding:12px 14px;">'
							. '<div style="font-size:12px;color:#646970;">' . esc_html__( 'Coupon code', 'mmi-shared' ) . '</div>'
							. '<div style="font-size:20px;font-weight:700;letter-spacing:1px;color:#1d2327;">' . esc_html( $a['code'] ) . '</div>'
							. '<div style="font-size:13px;color:#3c434a;margin-top:2px;">' . esc_html( $a['summary'] ) . '</div>'
							. '<a href="' . esc_url( $a['url'] ) . '" style="display:inline-block;margin-top:8px;font-weight:600;">' . esc_html__( 'Shop now', 'mmi-shared' ) . ' &rarr;</a></td></tr></table>'
						: '<div class="mmi-conv-card mmi-conv-card--coupon"><span class="mmi-conv-card-icon dashicons dashicons-tickets-alt" aria-hidden="true"></span>'
							. '<span class="mmi-conv-card-text"><span>' . esc_html__( 'Coupon code', 'mmi-shared' ) . '</span><strong class="mmi-conv-code">' . esc_html( $a['code'] ) . '</strong>'
							. '<span>' . esc_html( $a['summary'] ) . '</span></span></div>';
				}
			}
			return $html === '' ? '' : ( $context === 'email' ? $html : '<div class="mmi-conv-cards">' . $html . '</div>' );
		}

		public static function styles(): string {
			return '.mmi-conv-cards{display:flex;flex-direction:column;gap:8px;margin-top:8px}'
				. '.mmi-conv-card{display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid #dcdcde;border-radius:8px;background:#fff;color:inherit;text-decoration:none;max-width:420px}'
				. 'a.mmi-conv-card:hover{border-color:#8c8f94}'
				. '.mmi-conv-card img{width:48px;height:48px;object-fit:cover;border-radius:6px;flex:none}'
				. '.mmi-conv-card-icon{width:40px;height:40px;font-size:28px;line-height:40px;text-align:center;color:#45852c;flex:none}'
				. '.mmi-conv-card-text{display:flex;flex-direction:column;gap:1px;font-size:13px;line-height:1.35}'
				. '.mmi-conv-card-text span,.mmi-conv-card-text em{color:#646970}'
				. '.mmi-conv-card-text em{font-style:normal;font-weight:600;color:inherit}'
				. '.mmi-conv-card--coupon{border:2px dashed #45852c;background:#f4faf1}'
				. '.mmi-conv-code{font-size:17px;letter-spacing:1px}';
		}

		/* ── Composer AJAX ────── */

		private static function guard(): \WC_Order {
			$order_id = absint( $_POST['order_id'] ?? 0 );
			if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! current_user_can( 'edit_shop_orders' ) ) {
				wp_send_json_error( __( 'Not allowed. Reload the page and try again.', 'mmi-shared' ), 403 );
			}
			$order = $order_id ? wc_get_order( $order_id ) : false;
			if ( ! $order ) {
				wp_send_json_error( __( 'Order not found.', 'mmi-shared' ) );
			}
			return $order;
		}

		/** Products or coupons matching what staff typed. */
		public static function ajax_find(): void {
			$order = self::guard();
			$kind  = sanitize_key( (string) ( $_POST['kind'] ?? 'product' ) );
			$q     = sanitize_text_field( wp_unslash( (string) ( $_POST['q'] ?? '' ) ) );
			$rows  = array();
			if ( $kind === 'coupon' ) {
				if ( ! self::coupons_allowed( $order ) ) {
					wp_send_json_error( __( 'Reverb doesn\'t allow sending its buyers outside coupons. Use a Reverb offer instead.', 'mmi-shared' ) );
				}
				foreach ( get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 's' => $q, 'numberposts' => self::RESULTS, 'orderby' => 'date', 'order' => 'DESC' ) ) as $post ) {
					$c      = new \WC_Coupon( $post->ID );
					$rows[] = array( 'type' => 'coupon', 'id' => $post->ID, 'label' => strtoupper( $c->get_code() ), 'detail' => self::coupon_summary( $c ) );
				}
			} else {
				$reverb = ! self::coupons_allowed( $order );
				if ( $q === '' ) {
					// Nothing typed yet: this order's own products first.
					$ids = array();
					foreach ( $order->get_items() as $item ) {
						if ( $item instanceof \WC_Order_Item_Product ) {
							$ids[] = $item->get_variation_id() ?: $item->get_product_id();
						}
					}
				} else {
					// WooCommerce's own admin search: title, SKU, ID.
					$ids = (array) \WC_Data_Store::load( 'product' )->search_products( wc_clean( $q ), '', true, false, self::RESULTS );
				}
				$found = array_filter( array_map( 'wc_get_product', array_slice( array_unique( array_map( 'intval', $ids ) ), 0, self::RESULTS ) ), static fn( $p ) => $p && $p->get_status() === 'publish' );
				foreach ( $found as $p ) {
					$listing = $reverb ? self::reverb_url( $p ) : '';
					$rows[]  = array(
						'type'     => 'product',
						'id'       => $p->get_id(),
						'label'    => $p->get_name(),
						'detail'   => html_entity_decode( wp_strip_all_tags( wc_price( (float) $p->get_price() ) ), ENT_QUOTES, 'UTF-8' ) . ( $p->get_sku() ? ' · ' . $p->get_sku() : '' ) . ( $reverb && $listing === '' ? ' · ' . __( 'not on Reverb', 'mmi-shared' ) : '' ),
						'disabled' => $reverb && $listing === '',
					);
				}
			}
			wp_send_json_success( array( 'rows' => $rows ) );
		}

		/** A new coupon made from the composer (one click, stated terms). */
		public static function ajax_coupon(): void {
			$order = self::guard();
			if ( ! current_user_can( 'edit_shop_coupons' ) && ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( __( 'You are not allowed to create coupons.', 'mmi-shared' ), 403 );
			}
			if ( ! self::coupons_allowed( $order ) ) {
				wp_send_json_error( __( 'Reverb doesn\'t allow sending its buyers outside coupons. Use a Reverb offer instead.', 'mmi-shared' ) );
			}
			$type   = sanitize_key( (string) ( $_POST['discount_type'] ?? 'percent' ) );
			$type   = in_array( $type, array( 'percent', 'fixed_cart' ), true ) ? $type : 'percent';
			$amount = round( (float) ( $_POST['amount'] ?? 0 ), 2 );
			$days   = max( 1, min( 365, absint( $_POST['days'] ?? 30 ) ) );
			if ( $amount <= 0 || ( $type === 'percent' && $amount > 100 ) ) {
				wp_send_json_error( __( 'Enter a discount above 0 (a percentage can be at most 100).', 'mmi-shared' ) );
			}
			$code = strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_POST['code'] ?? '' ) ) );
			if ( $code === '' ) {
				$code = 'THANKS-' . strtoupper( wp_generate_password( 6, false, false ) );
			}
			if ( wc_get_coupon_id_by_code( $code ) ) {
				wp_send_json_error( sprintf( __( 'A coupon called %s already exists. Pick another code, or attach the existing one.', 'mmi-shared' ), $code ) );
			}
			$email  = sanitize_email( $order->get_billing_email() );
			$coupon = new \WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( $type );
			$coupon->set_amount( $amount );
			$coupon->set_date_expires( time() + $days * DAY_IN_SECONDS );
			if ( ! empty( $_POST['only_customer'] ) && is_email( $email ) ) {
				$coupon->set_email_restrictions( array( $email ) );
			}
			if ( ! empty( $_POST['one_use'] ) ) {
				$coupon->set_usage_limit( 1 );
				$coupon->set_usage_limit_per_user( 1 );
			}
			$user = wp_get_current_user();
			$coupon->set_description( sprintf( 'Made in the messages for order #%1$s by %2$s on %3$s.', $order->get_order_number(), $user->display_name, wp_date( 'Y-m-d H:i' ) ) );
			$id = $coupon->save();
			if ( ! $id ) {
				wp_send_json_error( __( 'The coupon could not be saved.', 'mmi-shared' ) );
			}
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				MMI_Audit_Log::record( 'mmi-shared', 'coupon.create', array( 'outcome' => 'success', 'details' => array( 'coupon_id' => $id, 'order_id' => $order->get_id(), 'type' => $type, 'amount' => $amount, 'days' => $days ) ) );
			}
			$c = new \WC_Coupon( $id );
			wp_send_json_success( array( 'row' => array( 'type' => 'coupon', 'id' => $id, 'label' => strtoupper( $c->get_code() ), 'detail' => self::coupon_summary( $c ) ) ) );
		}
	}
}
