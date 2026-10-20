<?php
/**
 * Encryption functionality for FE AI Search.
 *
 * @package    fe-search-ai
 * @subpackage Core
 * @since 0.9.0
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption helper class for FE AI Search.
 *
 * New values are encrypted with libsodium's `sodium_crypto_secretbox()`
 * (XSalsa20-Poly1305), which provides authenticated encryption. Values written
 * by older versions used AES-256-CBC via OpenSSL and remain decryptable through
 * the legacy code path.
 *
 * Key material follows this priority order:
 * 1. The FE_SEARCH_AI_ENCRYPTION_KEY constant, if defined in wp-config.php.
 * 2. The legacy FE_AI_SEARCH_ENCRYPTION_KEY constant, kept for backward
 *    compatibility with the plugin's former "FE AI Search" name.
 * 3. WordPress's AUTH_KEY as a site-unique fallback.
 *
 * Decryption tries every available candidate key, so values encrypted under a
 * different key source (e.g. before the constant was defined) still decrypt.
 *
 * @since 0.9.0
 * @package    fe-search-ai
 * @subpackage Core
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_Encryption_Helper {

	/**
	 * The cipher used for legacy OpenSSL-encrypted values.
	 *
	 * @since 0.9.0
	 * @var     string
	 */
	private const CIPHER = 'aes-256-cbc';

	/**
	 * Versioned prefix marking values encrypted with libsodium.
	 *
	 * Stored format: feai:v2: . base64( nonce . ciphertext )
	 *
	 * @since 1.3.0
	 * @var     string
	 */
	private const V2_PREFIX = 'feai:v2:';

	/**
	 * Retrieves the primary encryption key material.
	 *
	 * The key retrieval follows this priority order:
	 * 1. Uses the FE_SEARCH_AI_ENCRYPTION_KEY constant if defined in wp-config.php.
	 * 2. Falls back to the legacy FE_AI_SEARCH_ENCRYPTION_KEY constant.
	 * 3. Falls back to using WordPress's default AUTH_KEY as a secure, site-unique key.
	 *
	 * @since 0.9.0
	 * @static
	 *
	 * @return string|false The encryption key, or false if no valid key is available.
	 *                     Returns false only if no key constant and no AUTH_KEY is defined.
	 */
	private static function get_key() {
		$keys = self::get_candidate_keys();
		return empty( $keys ) ? false : $keys[0];
	}

	/**
	 * Returns every available key material source, highest priority first.
	 *
	 * Used by decrypt() so that values encrypted before a key source change
	 * (for example before a key constant was defined) still decrypt
	 * transparently.
	 *
	 * @since 1.3.0
	 * @static
	 *
	 * @return string[] List of unique key material strings. May be empty.
	 */
	private static function get_candidate_keys() {
		$keys = [];

		if ( defined( 'FE_SEARCH_AI_ENCRYPTION_KEY' ) && ! empty( FE_SEARCH_AI_ENCRYPTION_KEY ) ) {
			$keys[] = FE_SEARCH_AI_ENCRYPTION_KEY;
		}

		// Legacy constant name from the plugin's former "FE AI Search" naming.
		if ( defined( 'FE_AI_SEARCH_ENCRYPTION_KEY' ) && ! empty( FE_AI_SEARCH_ENCRYPTION_KEY ) ) {
			$keys[] = FE_AI_SEARCH_ENCRYPTION_KEY;
		}

		if ( defined( 'AUTH_KEY' ) && ! empty( AUTH_KEY ) ) {
			$keys[] = AUTH_KEY;
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Derives a fixed-length binary secretbox key from arbitrary key material.
	 *
	 * @since 1.3.0
	 * @static
	 *
	 * @param string $key_material Raw key material of any length.
	 * @return string A SODIUM_CRYPTO_SECRETBOX_KEYBYTES-length binary key.
	 */
	private static function derive_key( $key_material ) {
		return sodium_crypto_generichash( $key_material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Checks whether a stored value uses the current libsodium format.
	 *
	 * @since 1.3.0
	 * @static
	 *
	 * @param mixed $value The stored value to inspect.
	 * @return bool True if the value carries the v2 format prefix.
	 */
	public static function is_v2_format( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::V2_PREFIX );
	}

	/**
	 * Encrypts a plaintext string using libsodium secretbox (XSalsa20-Poly1305).
	 *
	 * Falls back to the legacy AES-256-CBC format when libsodium is not
	 * available. Encrypted output is prefixed with self::V2_PREFIX so stored
	 * values can be told apart from legacy ciphertext.
	 *
	 * @since 0.9.0
	 * @static
	 *
	 * @param string $plaintext The plaintext string to encrypt.
	 * @return string The encrypted and base64-encoded string, or the original string if encryption fails.
	 *               Returns original string if:
	 *               - No encryption key is available
	 *               - Plaintext is empty
	 */
	public static function encrypt( $plaintext ) {
		if ( empty( $plaintext ) || ! is_string( $plaintext ) ) {
			return $plaintext;
		}

		$key = self::get_key();
		if ( ! $key ) {
			return $plaintext; // Do not encrypt if key is missing.
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return self::encrypt_legacy( $plaintext, $key );
		}

		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, self::derive_key( $key ) );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return self::V2_PREFIX . base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypts a stored ciphertext string.
	 *
	 * Handles both the current libsodium format (V2_PREFIX) and the legacy
	 * AES-256-CBC format. Every available candidate key is tried, so values
	 * keep decrypting after the key source changes.
	 *
	 * @since 0.9.0
	 * @static
	 *
	 * @param string $ciphertext The stored string to decrypt.
	 * @return string The decrypted string, or the original value if decryption fails.
	 *               Returns original value if:
	 *               - No encryption key is available
	 *               - Ciphertext is empty
	 *               - Ciphertext is not valid base64
	 *               - Decryption fails with every candidate key
	 */
	public static function decrypt( $ciphertext ) {
		if ( empty( $ciphertext ) || ! is_string( $ciphertext ) ) {
			return $ciphertext;
		}

		$keys = self::get_candidate_keys();
		if ( empty( $keys ) ) {
			return $ciphertext; // Do not decrypt if key is missing.
		}

		if ( self::is_v2_format( $ciphertext ) ) {
			return self::decrypt_v2( $ciphertext, $keys );
		}

		return self::decrypt_legacy( $ciphertext, $keys );
	}

	/**
	 * Decrypts a v2 (libsodium) formatted value.
	 *
	 * @since 1.3.0
	 * @static
	 *
	 * @param string   $ciphertext The stored string including the V2_PREFIX.
	 * @param string[] $keys       Candidate key materials to try.
	 * @return string The decrypted plaintext, or the original ciphertext on failure.
	 */
	private static function decrypt_v2( $ciphertext, $keys ) {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return $ciphertext;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$payload = base64_decode( substr( $ciphertext, strlen( self::V2_PREFIX ) ), true );
		if ( false === $payload || strlen( $payload ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return $ciphertext;
		}

		$nonce     = substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$encrypted = substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		foreach ( $keys as $key ) {
			$decrypted = sodium_crypto_secretbox_open( $encrypted, $nonce, self::derive_key( $key ) );
			if ( false !== $decrypted ) {
				return $decrypted;
			}
		}

		return $ciphertext;
	}

	/**
	 * Decrypts a legacy AES-256-CBC value.
	 *
	 * Supports values that were encrypted multiple times by unwrapping up to
	 * three encryption layers, trying every candidate key at each layer.
	 *
	 * @since 1.3.0
	 * @static
	 *
	 * @param string   $ciphertext The base64-encoded legacy ciphertext.
	 * @param string[] $keys       Candidate key materials to try.
	 * @return string The decrypted plaintext, or the original ciphertext on failure.
	 */
	private static function decrypt_legacy( $ciphertext, $keys ) {
		$value = $ciphertext;
		for ( $i = 0; $i < 3; $i++ ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$c = base64_decode( $value, true );
			if ( false === $c ) {
				return $value; // Return current value if not valid base64.
			}

			$ivlen = openssl_cipher_iv_length( self::CIPHER );
			if ( strlen( $c ) <= $ivlen ) {
				return $value; // Return current value if the payload is too short.
			}

			$iv             = substr( $c, 0, $ivlen );
			$ciphertext_raw = substr( $c, $ivlen );

			$decrypted = false;
			foreach ( $keys as $key ) {
				$decrypted = openssl_decrypt( $ciphertext_raw, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
				if ( false !== $decrypted ) {
					break;
				}
			}

			if ( false === $decrypted || $decrypted === $value ) {
				return $value;
			}

			$value = $decrypted;
		}

		return $value;
	}

	/**
	 * Encrypts a plaintext string using the legacy AES-256-CBC format.
	 *
	 * Only used as a fallback when libsodium is unavailable. Output has no
	 * version prefix and stays compatible with decrypt_legacy().
	 *
	 * @since 1.3.0
	 * @static
	 *
	 * @param string $plaintext The plaintext string to encrypt.
	 * @param string $key       The raw key material.
	 * @return string The encrypted and base64-encoded string.
	 */
	private static function encrypt_legacy( $plaintext, $key ) {
		$ivlen          = openssl_cipher_iv_length( self::CIPHER );
		$iv             = random_bytes( $ivlen );
		$ciphertext_raw = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $iv . $ciphertext_raw );
	}

	/**
	 * Re-encrypts a stored secret when it is not in the current format.
	 *
	 * Only rewrites values that successfully decrypt under the legacy path.
	 * Undecryptable values are left untouched so they can still be recovered
	 * later (for example by restoring a rotated AUTH_KEY), and plaintext
	 * values are picked up by the regular sanitize path on the next save.
	 *
	 * @since 1.3.0
	 * @static
	 *
	 * @param mixed $value The stored value.
	 * @return mixed The migrated value, or the original value.
	 */
	private static function migrate_value( $value ) {
		if ( ! is_string( $value ) || '' === $value || self::is_v2_format( $value ) ) {
			return $value;
		}

		$decrypted = self::decrypt( $value );
		if ( '' === $decrypted || ! is_string( $decrypted ) || $decrypted === $value ) {
			return $value;
		}

		return self::encrypt( $decrypted );
	}

	/**
	 * Migrates all stored secrets to the current encryption format.
	 *
	 * Re-encrypts API keys, the Yahoo! App ID, and license keys in place when
	 * they are stored as legacy ciphertext that still decrypts. Idempotent
	 * and safe to run on every plugin upgrade. Add-on plugins can declare
	 * their own secret locations via the fe_search_ai_migrate_secret_targets
	 * filter.
	 *
	 * @since 1.3.0
	 * @static
	 *
	 * @return int The number of values that were re-encrypted.
	 */
	public static function migrate_stored_secrets() {
		$targets = [
			'fe_search_ai_settings' => [
				[ 'provider', 'openai_key' ],
				[ 'provider', 'google_key' ],
				[ 'provider', 'anthropic_key' ],
				[ 'provider', 'cohere_key' ],
				[ 'provider', 'openai_compatible_key' ],
				[ 'rerank', 'cohere_api_key' ],
				[ 'vector', 'qdrant', 'api_key' ],
				[ 'tokenizer', 'ja', 'yahoo_id' ],
			],
			'fe_search_ai_license'  => [
				[ 'products', '*', 'key' ],
			],
		];

		/**
		 * Filters the option locations whose secrets are re-encrypted during
		 * migration.
		 *
		 * Each entry maps an option name to a list of key paths. A path of []
		 * means the option value itself is the secret, and a '*' segment
		 * iterates every element at that array level.
		 *
		 * @since 1.3.0
		 * @param array $targets Option name => list of key paths.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$targets = apply_filters( 'fe_search_ai_migrate_secret_targets', $targets );
		}

		$migrated = 0;
		foreach ( (array) $targets as $option_name => $paths ) {
			$migrated += self::migrate_option_secrets( $option_name, (array) $paths );
		}

		return $migrated;
	}

	/**
	 * Re-encrypts secrets stored at the given paths inside a single option.
	 *
	 * Paths are lists of array keys; a '*' segment iterates every element at
	 * that level, and an empty path targets the option value itself.
	 *
	 * @since 1.3.0
	 * @static
	 *
	 * @param string $option_name The option name.
	 * @param array  $paths       List of key paths to secret values.
	 * @return int The number of values that were re-encrypted.
	 */
	public static function migrate_option_secrets( $option_name, array $paths ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) || ! self::get_key() ) {
			return 0;
		}

		$option_value = get_option( $option_name, [] );
		$migrated     = 0;

		foreach ( $paths as $path ) {
			$migrated += self::migrate_at_path( $option_value, (array) $path );
		}

		if ( $migrated > 0 ) {
			update_option( $option_name, $option_value );
		}

		return $migrated;
	}

	/**
	 * Migrates the value located at a key path within a nested structure.
	 *
	 * A '*' segment iterates every element at that array level; an empty
	 * path targets $node itself.
	 *
	 * @since 1.3.0
	 * @static
	 *
	 * @param mixed $node The structure to walk, passed by reference.
	 * @param array $path The remaining key path.
	 * @return int The number of values that were re-encrypted.
	 */
	private static function migrate_at_path( &$node, array $path ) {
		if ( [] === $path ) {
			$new_value = self::migrate_value( $node );
			if ( $new_value !== $node ) {
				$node = $new_value;
				return 1;
			}
			return 0;
		}

		$segment = array_shift( $path );
		if ( '*' === $segment ) {
			if ( ! is_array( $node ) ) {
				return 0;
			}
			$migrated = 0;
			foreach ( $node as &$child ) {
				$migrated += self::migrate_at_path( $child, $path );
			}
			unset( $child );
			return $migrated;
		}

		if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
			return 0;
		}

		return self::migrate_at_path( $node[ $segment ], $path );
	}
}
