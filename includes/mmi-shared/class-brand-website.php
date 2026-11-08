<?php
/**
 * MMI_Brand_Website — one "Brand website" value per brand, shared by every MMI plugin.
 *
 * Stored as product_brand term meta `_mmi_brand_website`. Several distributed plugins
 * need it (mmi-seo writes brand meta descriptions from the maker's home page;
 * mmi-data-pipeline reads maker pages), and a store may run any one of them, any
 * two, or all of them. So the value and its field belong to none of them: whichever
 * plugin calls init() first puts the field on the brand screen, and every later
 * caller gets the same field and the same value. A plugin installed later finds
 * the websites already there; removing the plugin that came first leaves them in
 * place for the others. init() is idempotent.
 *
 * Reading never needs init(): get(), for_product() and host() work anywhere.
 *
 * Seams (neutral names, ADR-0012 addendum):
 *  - 'mmi_brand_website_legacy_keys'  term meta keys an older field used. get() falls
 *    back to them and clear() removes them too, so a cleared website can't come
 *    back from the old key. Default none.
 *  - 'mmi_brand_website_field_help'   the help line under the field.
 *  - 'mmi_brand_website_changed'      action( $term_id, $new, $old ) after a change
 *    made through set()/clear() or the field.
 *
 * Do not hand-edit this file in a single plugin; edit the canonical source in
 * mmi-admin/lib/mmi-shared/ and run lib/sync-to-plugins.sh.
 *
 * @package MMI_Shared
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Brand_Website' ) ) :

	class MMI_Brand_Website {

		const TAXONOMY = 'product_brand';
		const KEY      = '_mmi_brand_website';
		const NONCE    = 'mmi_brand_website_nonce';
		const ACTION   = 'mmi_brand_website';
		const INPUT    = 'mmi_brand_website';

		private static bool $initialized = false;

		/** Put the field on the brand screens. Safe to call from every plugin. */
		public static function init(): void {
			if ( self::$initialized ) {
				return;
			}
			self::$initialized = true;

			add_action( 'init', array( __CLASS__, 'register_meta' ) );
			add_action( self::TAXONOMY . '_add_form_fields', array( __CLASS__, 'add_field' ), 9 );
			add_action( self::TAXONOMY . '_edit_form_fields', array( __CLASS__, 'edit_field' ), 9 );
			add_action( 'created_' . self::TAXONOMY, array( __CLASS__, 'save' ) );
			add_action( 'edited_' . self::TAXONOMY, array( __CLASS__, 'save' ) );
		}

		public static function initialized(): bool {
			return self::$initialized;
		}

		/** Every write of the key is cleaned the same way, whoever writes it. */
		public static function register_meta(): void {
			if ( ! taxonomy_exists( self::TAXONOMY ) ) {
				return;
			}
			register_term_meta(
				self::TAXONOMY,
				self::KEY,
				array(
					'type'              => 'string',
					'single'            => true,
					'sanitize_callback' => array( __CLASS__, 'clean' ),
					'auth_callback'     => static fn( $allowed, $key, $term_id ) => current_user_can( 'edit_term', (int) $term_id ),
					'show_in_rest'      => false,
				)
			);
		}

		/** A website as stored: an http(s) URL, or '' when it isn't one. */
		public static function clean( $value ): string {
			$value = trim( (string) $value );
			if ( $value === '' ) {
				return '';
			}
			if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
				$value = 'https://' . $value; // "acclaim-audio.com" typed without a scheme
			}
			$url  = esc_url_raw( $value, array( 'http', 'https' ) );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			// A real domain name ("example.com", "xn--…"), not words that became a host once https:// went in front.
			return preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$/', $host ) ? $url : '';
		}

		/** @return string[] older keys this value used to live under. */
		public static function legacy_keys(): array {
			$keys = apply_filters( 'mmi_brand_website_legacy_keys', array() );
			return array_values( array_filter( array_map( 'strval', is_array( $keys ) ? $keys : array() ), static fn( $k ) => $k !== '' && $k !== self::KEY ) );
		}

		/** The brand's website, from the shared key, else an older key. '' when none. */
		public static function get( int $term_id ): string {
			if ( $term_id <= 0 ) {
				return '';
			}
			$value = trim( (string) get_term_meta( $term_id, self::KEY, true ) );
			if ( $value !== '' ) {
				return $value;
			}
			foreach ( self::legacy_keys() as $key ) {
				$value = trim( (string) get_term_meta( $term_id, $key, true ) );
				if ( $value !== '' ) {
					return $value;
				}
			}
			return '';
		}

		/** The website's host without "www." ('' when none): "acclaim-audio.com". */
		public static function host( int $term_id ): string {
			$host = strtolower( (string) wp_parse_url( self::get( $term_id ), PHP_URL_HOST ) );
			return (string) preg_replace( '/^www\./', '', $host );
		}

		/** The first of a product's brands that has a website: [ term_id, url ], or null. */
		public static function for_product( int $product_id ): ?array {
			$terms = get_the_terms( $product_id, self::TAXONOMY );
			if ( ! is_array( $terms ) ) {
				return null;
			}
			foreach ( $terms as $term ) {
				$url = self::get( (int) $term->term_id );
				if ( $url !== '' ) {
					return array( (int) $term->term_id, $url );
				}
			}
			return null;
		}

		/** Save a website ('' or an unusable value clears it). Returns what is stored now. */
		public static function set( int $term_id, string $url ): string {
			$url = self::clean( $url );
			if ( $url === '' ) {
				self::clear( $term_id );
				return '';
			}
			$old = self::get( $term_id );
			update_term_meta( $term_id, self::KEY, $url );
			if ( $old !== $url ) {
				do_action( 'mmi_brand_website_changed', $term_id, $url, $old );
			}
			return $url;
		}

		/** Remove a brand's website, older keys included, so it can't come back from one. */
		public static function clear( int $term_id ): void {
			$old = self::get( $term_id );
			delete_term_meta( $term_id, self::KEY );
			foreach ( self::legacy_keys() as $key ) {
				delete_term_meta( $term_id, $key );
			}
			if ( $old !== '' ) {
				do_action( 'mmi_brand_website_changed', $term_id, '', $old );
			}
		}

		private static function help(): string {
			return (string) apply_filters( 'mmi_brand_website_field_help', __( "The brand's own website, for example the maker's home page.", 'mmi-shared' ) );
		}

		public static function add_field(): void {
			?>
			<div class="form-field">
				<label for="mmi-brand-website"><?php esc_html_e( 'Brand website', 'mmi-shared' ); ?></label>
				<input type="url" name="<?php echo esc_attr( self::INPUT ); ?>" id="mmi-brand-website" value="" placeholder="https://">
				<p><?php echo esc_html( self::help() ); ?></p>
				<?php wp_nonce_field( self::ACTION, self::NONCE ); ?>
			</div>
			<?php
		}

		public static function edit_field( $term ): void {
			$term_id = $term instanceof WP_Term ? (int) $term->term_id : 0;
			?>
			<tr class="form-field">
				<th scope="row"><label for="mmi-brand-website"><?php esc_html_e( 'Brand website', 'mmi-shared' ); ?></label></th>
				<td>
					<input type="url" name="<?php echo esc_attr( self::INPUT ); ?>" id="mmi-brand-website" value="<?php echo esc_attr( self::get( $term_id ) ); ?>" placeholder="https://">
					<p class="description"><?php echo esc_html( self::help() ); ?></p>
					<?php wp_nonce_field( self::ACTION, self::NONCE ); ?>
				</td>
			</tr>
			<?php
		}

		public static function save( int $term_id ): void {
			if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::ACTION ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_term', $term_id ) || ! isset( $_POST[ self::INPUT ] ) ) {
				return;
			}
			$typed = trim( (string) wp_unslash( $_POST[ self::INPUT ] ) );
			if ( $typed === '' ) {
				self::clear( $term_id );
				return;
			}
			if ( self::clean( $typed ) === '' ) {
				return; // not a web address: keep what was there rather than lose it
			}
			self::set( $term_id, $typed );
		}
	}

endif;
