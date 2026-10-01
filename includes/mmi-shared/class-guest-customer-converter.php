<?php
/**
 * MMI_Guest_Customer_Converter
 *
 * Converts a guest order's marketplace-relay billing address into a real,
 * linked customer account — the operation both mmi-reverb-integration's
 * automatic Reverb-reply flow (class-email-request-manager.php) and
 * mmi-xchange-integration's manual "Link to Customer" widget (Orders tab,
 * admin-xchange.js's .mmi-x-guest-convert-btn) perform, so the actual
 * create-or-link logic lives in exactly one place rather than being
 * duplicated per plugin.
 *
 * Originally lived in mmi-hub; deleted with it 2026-09-17
 * (mmi-hub-elimination migration) without being migrated. Every call site in
 * both consuming plugins was already class_exists()-guarded, so nothing
 * fataled — but the feature went completely dark: Reverb's auto-conversion
 * cron silently no-ops, mmi-xchange-integration's manual-fulfillment
 * "verified after external import" check (MMI_Xchange_Checkout::
 * is_verified_after_external_import()) fails closed with no way to ever
 * open it, and the Orders tab's "Link to Customer" button posts to an AJAX
 * action (mmi_guest_customer_convert) nothing has registered a handler for.
 * Reconstructed from calling-convention evidence across both plugins:
 * looks_like_relay_email()'s regex is the exact pattern already duplicated
 * inline (twice) in mmi-reverb-integration's class-software-handler.php;
 * EMAIL_LOCKED_META_KEY's literal value ('_mmi_billing_email_locked') comes
 * from class-order-importer.php, which had already hardcoded it as a
 * workaround once the constant reference stopped resolving; convert_order()'s
 * signature comes from class-email-request-manager.php's one call site; and
 * ajax_convert()'s request shape (order_id/email/send_welcome) comes from
 * admin-xchange.js's widget, which was never touched by the migration and
 * still posts the exact same fields the original mmi-hub handler expected.
 *
 * Added as the 20th bundled class (2026-09-19) — see bootstrap.php.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Guest_Customer_Converter', false ) ) {

	class MMI_Guest_Customer_Converter {

		/** Nonce action for the ajax_convert() AJAX endpoint. */
		const NONCE_ACTION = 'mmi_guest_customer_convert';

		/**
		 * Order meta key set to 'yes' once a human or the automatic Reverb-reply
		 * flow has deliberately corrected this order's billing email away from
		 * a marketplace relay address. mmi-reverb-integration's Order_Importer
		 * checks this on every re-sync to avoid reverting the correction back
		 * to the marketplace's own (relay) address; mmi-xchange-integration's
		 * is_verified_after_external_import() checks it before allowing manual
		 * fulfillment on an externally-sourced order.
		 */
		const EMAIL_LOCKED_META_KEY = '_mmi_billing_email_locked';

		/**
		 * Marketplace relay/buyer-privacy address pattern. Reverb's own relay
		 * domain plus the two generic patterns already in use — see this
		 * file's own docblock for where they were duplicated before this
		 * class existed to own them.
		 */
		const RELAY_EMAIL_PATTERN = '/@(private-relay\.reverb\.com|relay\.|proxy\.)/i';

		/**
		 * True if $email looks like a marketplace relay/proxy address rather
		 * than the buyer's real, reachable address.
		 */
		public static function looks_like_relay_email( string $email ): bool {
			return '' !== $email && (bool) preg_match( self::RELAY_EMAIL_PATTERN, $email );
		}

		/**
		 * Finds or creates a WooCommerce customer account for $email, links
		 * $order to it, corrects the order's billing email, and locks it
		 * against being reverted by the marketplace's next re-sync.
		 *
		 * @param \WC_Order $order        Order to convert.
		 * @param string    $email        Buyer's real (non-relay) email address.
		 * @param bool      $send_welcome Whether to send the standard WooCommerce
		 *                                "new account" email. Only meaningful
		 *                                when a new account is actually created —
		 *                                an email address that already matches an
		 *                                existing customer is linked silently
		 *                                either way, since there is nothing new to
		 *                                welcome them to. Automatic conversions (a
		 *                                buyer's Reverb reply) pass false; the
		 *                                manual "Link to Customer" widget exposes
		 *                                this as an admin-facing checkbox.
		 * @param string    $reason       Free-text context recorded in the order
		 *                                note, e.g. "manual admin conversion" or
		 *                                "automatic Reverb reply conversion".
		 * @return array{success:bool, message:string, customer_id?:int}
		 */
		public static function convert_order( \WC_Order $order, string $email, bool $send_welcome, string $reason ): array {
			$email = sanitize_email( $email );

			if ( ! is_email( $email ) ) {
				self::audit( $order, 'failure', $reason, [ 'error' => 'invalid_email' ] );
				return [
					'success' => false,
					'message' => "That doesn't look like a valid email address.",
				];
			}

			$user    = get_user_by( 'email', $email );
			$created = false;

			if ( ! $user ) {
				// wc_create_new_customer() fires woocommerce_created_customer_notification
				// synchronously and unconditionally, which WC_Emails hooks to send its
				// "new account" email — suppress that hook around the call, not after
				// the fact, when the caller didn't ask for a welcome email.
				if ( ! $send_welcome ) {
					remove_action( 'woocommerce_created_customer_notification', [ WC()->mailer(), 'customer_new_account' ], 10 );
				}

				$customer_id = wc_create_new_customer(
					$email,
					'',
					'',
					[
						'first_name' => $order->get_billing_first_name(),
						'last_name'  => $order->get_billing_last_name(),
					]
				);

				if ( ! $send_welcome ) {
					add_action( 'woocommerce_created_customer_notification', [ WC()->mailer(), 'customer_new_account' ], 10, 3 );
				}

				if ( is_wp_error( $customer_id ) ) {
					self::audit( $order, 'failure', $reason, [ 'error' => $customer_id->get_error_code() ] );
					return [
						'success' => false,
						'message' => $customer_id->get_error_message(),
					];
				}

				$user    = get_user_by( 'id', $customer_id );
				$created = true;
			}

			$customer_id = $user->ID;

			$order->set_customer_id( $customer_id );
			$order->set_billing_email( $email );
			$order->update_meta_data( self::EMAIL_LOCKED_META_KEY, 'yes' );
			$order->add_order_note(
				sprintf( 'Linked to customer account #%d (%s) — %s.', $customer_id, $email, $reason )
			);
			$order->save();

			self::audit( $order, 'success', $reason, [
				'customer_id'     => $customer_id,
				'account_created' => $created,
				'welcome_sent'    => $created && $send_welcome,
			] );

			return [
				'success'     => true,
				'message'     => 'Order linked to customer account.',
				'customer_id' => $customer_id,
			];
		}

		/**
		 * AJAX: wp_ajax_mmi_guest_customer_convert — the "Link to Customer"
		 * widget's endpoint (mmi-xchange-integration's Orders tab). Registered
		 * eagerly in bootstrap.php, since a shared-library class has no
		 * hook-registration lifecycle of its own (same shape as the WC
		 * product-filter and taxonomy-filter AJAX endpoints there).
		 */
		public static function ajax_convert(): void {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				if ( class_exists( 'MMI_Audit_Log' ) ) {
					MMI_Audit_Log::record( 'mmi-shared', 'customer.link_order', array(
						'object_type' => 'order',
						'object_id'   => absint( $_POST['order_id'] ?? 0 ),
						'outcome'     => 'denied',
					) );
				}
				wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
			}

			$order_id     = absint( $_POST['order_id'] ?? 0 );
			$email        = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$send_welcome = ! empty( $_POST['send_welcome'] );

			$order = $order_id ? wc_get_order( $order_id ) : false;
			if ( ! $order ) {
				wp_send_json_error( [ 'message' => 'Order not found.' ] );
			}

			$result = self::convert_order( $order, $email, $send_welcome, 'manual admin conversion' );

			if ( ! $result['success'] ) {
				wp_send_json_error( [ 'message' => $result['message'] ] );
			}

			wp_send_json_success( $result );
		}

		/**
		 * Audit trail for an order being linked to a customer account (manual
		 * or automatic). Never records the email address itself.
		 */
		private static function audit( \WC_Order $order, string $outcome, string $reason, array $details = [] ): void {
			if ( ! class_exists( 'MMI_Audit_Log' ) ) {
				return;
			}
			MMI_Audit_Log::record( 'mmi-shared', 'customer.link_order', array(
				'object_type' => 'order',
				'object_id'   => $order->get_id(),
				'outcome'     => $outcome,
				'details'     => array_merge( [ 'reason' => $reason ], $details ),
			) );
		}
	}
}
