<?php
/**
 * Encryption functionality for FE AI Search.
 *
 * @package    fe-search-ai
 * @subpackage Core
 * @since      1.0.0
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption helper class for FE AI Search.
 *
 * This class provides static methods for symmetric encryption using AES-256-CBC.
 * It can use either a custom encryption key defined in wp-config.php or fall back
 * to WordPress's AUTH_KEY for encryption/decryption operations.
 *
 * @since      1.0.0
 * @package    fe-search-ai
 * @subpackage Core
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_Encryption_Helper {

	/**
	 * The encryption cipher to use for OpenSSL operations.
	 *
	 * @since   1.0.0
	 * @var     string
	 */
	private const CIPHER = 'aes-256-cbc';

	/**
	 * Retrieves the encryption key in a hierarchical manner.
	 *
	 * The key retrieval follows this priority order:
	 * 1. Uses the FE_AI_SEARCH_ENCRYPTION_KEY constant if defined in wp-config.php.
	 * 2. Falls back to using WordPress's default AUTH_KEY as a secure, site-unique key.
	 *
	 * @since   1.0.0
	 * @static
	 *
	 * @return string|false The encryption key, or false if no valid key is available.
	 *                     Returns false only if neither FE_AI_SEARCH_ENCRYPTION_KEY nor AUTH_KEY is defined.
	 */
	private static function get_key() {
		// Priority 1: User-defined constant in wp-config.php for advanced users.
		if ( defined( 'FE_AI_SEARCH_ENCRYPTION_KEY' ) && ! empty( FE_AI_SEARCH_ENCRYPTION_KEY ) ) {
			return FE_AI_SEARCH_ENCRYPTION_KEY;
		}

		// Priority 2: Fallback to WordPress's secure, site-unique AUTH_KEY.
		if ( defined( 'AUTH_KEY' ) && ! empty( AUTH_KEY ) ) {
			return AUTH_KEY;
		}

		// This should never happen on a properly configured WordPress site.
		return false;
	}

	/**
	 * Encrypts a plaintext string using AES-256-CBC encryption.
	 *
	 * @since   1.0.0
	 * @static
	 *
	 * @param string $plaintext The plaintext string to encrypt.
	 * @return string The encrypted and base64-encoded string, or the original string if encryption fails.
	 *               Returns original string if:
	 *               - No encryption key is available
	 *               - Plaintext is empty
	 *               - Encryption fails
	 */
	public static function encrypt( $plaintext ) {
		$key = self::get_key();
		if ( ! $key || empty( $plaintext ) ) {
			return $plaintext; // Do not encrypt if key is missing.
		}

		$ivlen          = openssl_cipher_iv_length( self::CIPHER );
		$iv             = openssl_random_pseudo_bytes( $ivlen );
		$ciphertext_raw = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return base64_encode( $iv . $ciphertext_raw );
	}

	/**
	 * Decrypts a base64-encoded ciphertext string.
	 *
	 * @since   1.0.0
	 * @static
	 *
	 * @param string $ciphertext The base64-encoded string to decrypt.
	 * @return string The decrypted string, or the original ciphertext if decryption fails.
	 *               Returns original ciphertext if:
	 *               - No encryption key is available
	 *               - Ciphertext is empty
	 *               - Ciphertext is not valid base64
	 *               - Decryption fails
	 */
	public static function decrypt( $ciphertext ) {
		$key = self::get_key();
		if ( ! $key || empty( $ciphertext ) ) {
			return $ciphertext; // Do not decrypt if key is missing.
		}

		$c = base64_decode( $ciphertext, true );
		if ( false === $c ) {
			return $ciphertext; // Return original if not valid base64.
		}

		$ivlen          = openssl_cipher_iv_length( self::CIPHER );
		$iv             = substr( $c, 0, $ivlen );
		$ciphertext_raw = substr( $c, $ivlen );

		$decrypted = openssl_decrypt( $ciphertext_raw, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return false === $decrypted ? $ciphertext : $decrypted;
	}
}
