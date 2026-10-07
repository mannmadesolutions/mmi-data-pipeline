<?php
/**
 * MMI_Credentials — encryption at rest for stored API secrets (bundled/vendored copy).
 *
 * MMI_Settings calls seal() on every value whose key names a secret (is_secret_key(),
 * recursing into arrays) before writing it, and reveal() after reading it, so plugins
 * keep calling MMI_Settings::get()/set() and never see ciphertext. Code that has a
 * reason to read or write wp_mmi with raw SQL calls reveal()/seal() itself.
 *
 * Cipher: libsodium secretbox (XSalsa20-Poly1305), random 24-byte nonce per value.
 * Key: derived with BLAKE2b from MMI_CREDENTIALS_KEY when wp-config defines it, else
 * from this site's SECURE_AUTH salt. A database dump alone therefore reveals nothing
 * as long as the salts (or the constant) live in wp-config, as WordPress installs them.
 *
 * Consequences worth knowing:
 *   - Rotating the salts (or changing MMI_CREDENTIALS_KEY) makes stored secrets
 *     unreadable; they read as '' and must be re-entered. Adding MMI_CREDENTIALS_KEY
 *     later is safe: values sealed with the salt-derived key still open, and are
 *     re-sealed under the new key on their next save.
 *   - A database copied to another site (staging) can't open them there.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_Credentials {

	const PREFIX = 'mmienc1:';

	/**
	 * Setting names (and array keys inside a setting) that hold secrets. Anchored to the
	 * end of the name, optionally followed by an environment suffix, so lookalikes such as
	 * mmi_cog_meta_key, *_client_id or *-token_url stay plain.
	 */
	const SECRET_KEY_PATTERN = '/(^|[-_])(token|api[-_]?key|api[-_]?secret|api[-_]?token|token[-_]?key|access[-_]?token|refresh[-_]?token|auth[-_]?token|secret([-_]?key)?|client[-_]?secret|webhook[-_]?secret|signing[-_]?(key|secret)|password|passwd|pwd|private[-_]?key|service[-_]?account[-_]?key)([-_](production|sandbox|live|test|prod|dev))?$/i';

	private static bool $warned = false;

	public static function is_secret_key( string $key ): bool {
		return (bool) preg_match( self::SECRET_KEY_PATTERN, $key );
	}

	public static function is_sealed( $value ): bool {
		return is_string( $value ) && strpos( $value, self::PREFIX ) === 0;
	}

	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && self::key_material() !== '';
	}

	/** Encrypts one string. '' and already-sealed values pass through unchanged. */
	public static function seal( string $plain ): string {
		if ( $plain === '' || self::is_sealed( $plain ) || ! self::available() ) {
			return $plain;
		}
		$keys  = self::keys();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $keys[0] ) );
	}

	/**
	 * Decrypts one value. Plaintext (written before encryption existed) is returned as is;
	 * a sealed value that no key opens reads as ''.
	 */
	public static function reveal( $stored ) {
		if ( ! self::is_sealed( $stored ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( ! self::available() ) {
			return '';
		}
		if ( $raw !== false && strlen( $raw ) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			foreach ( self::keys() as $key ) {
				$plain = sodium_crypto_secretbox_open( $box, $nonce, $key );
				if ( $plain !== false ) {
					return $plain;
				}
			}
		}
		if ( ! self::$warned && class_exists( 'MMI_Logger' ) ) {
			self::$warned = true;
			MMI_Logger::warn( 'A stored credential could not be decrypted (salts or MMI_CREDENTIALS_KEY changed?); it reads as empty until re-entered', array(), 'security', 'MMI_Credentials' );
		}
		return '';
	}

	/** seal()/reveal() applied to a setting: the value itself when $key names a secret, else secret-named array keys. */
	public static function seal_setting( string $key, $value ) {
		return self::walk( $key, $value, true );
	}

	public static function reveal_setting( string $key, $value ) {
		return self::walk( $key, $value, false );
	}

	/** True when a setting holds a secret still stored as plaintext. */
	public static function needs_sealing( string $key, $value ): bool {
		return self::seal_setting( $key, $value ) !== $value;
	}

	private static function walk( string $key, $value, bool $seal ) {
		if ( is_string( $value ) ) {
			if ( ! self::is_secret_key( $key ) ) {
				return $value;
			}
			return $seal ? self::seal( $value ) : self::reveal( $value );
		}
		if ( is_array( $value ) ) {
			// A list inherits its parent's name: a secret-named list of strings is all secret.
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::walk( is_string( $k ) ? $k : $key, $v, $seal );
			}
		}
		return $value;
	}

	/**
	 * SECURE_AUTH key + salt, read from the wp-config constants directly (what wp_salt()
	 * returns for them) so this works before pluggable.php loads; wp_salt() covers installs
	 * that keep their salts in the database instead.
	 */
	private static function key_material(): string {
		static $material = null;
		if ( $material !== null ) {
			return $material;
		}
		$placeholder = 'put your unique phrase here';
		if ( defined( 'SECURE_AUTH_KEY' ) && defined( 'SECURE_AUTH_SALT' ) && SECURE_AUTH_KEY !== '' && SECURE_AUTH_KEY !== $placeholder && SECURE_AUTH_SALT !== $placeholder ) {
			return $material = SECURE_AUTH_KEY . SECURE_AUTH_SALT;
		}
		if ( function_exists( 'wp_salt' ) ) {
			return $material = wp_salt( 'secure_auth' );
		}
		return ''; // Too early to know; not cached, so a later call can still succeed.
	}

	/** Current key first, then the salt-derived key so values sealed before MMI_CREDENTIALS_KEY was set still open. */
	private static function keys(): array {
		static $keys = null;
		if ( $keys === null ) {
			$salt = sodium_crypto_generichash( self::key_material(), 'mmi-credentials-v1', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			$keys = defined( 'MMI_CREDENTIALS_KEY' ) && (string) MMI_CREDENTIALS_KEY !== ''
				? array( sodium_crypto_generichash( (string) MMI_CREDENTIALS_KEY, 'mmi-credentials-v1', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ), $salt )
				: array( $salt );
		}
		return $keys;
	}
}
