<?php

namespace FEAISearch\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A helper class for encrypting and decrypting data.
 *
 * Provides static methods for symmetric encryption using a key derived from
 * either a custom constant or WordPress's salts.
 */
class FE_AI_Search_Encryption_Helper {

	private const CIPHER = 'aes-256-cbc';

	/**
	 * Gets the encryption key in a hierarchical manner.
	 *
	 * 1. Uses the FE_AI_SEARCH_ENCRYPTION_KEY constant if defined in wp-config.php.
	 * 2. Falls back to using WordPress's default AUTH_KEY as a secure, site-unique key.
	 *
	 * @return string|false The encryption key, or false if no key is available.
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
	 * Encrypts a plaintext string.
	 *
	 * @param string $plaintext The string to encrypt.
	 * @return string The encrypted and base64-encoded string, or the original string on failure.
	 */
	public static function encrypt( $plaintext ) {
		$key = self::get_key();
		if ( ! $key || empty( $plaintext ) ) {
			return $plaintext; // Do not encrypt if key is missing.
		}

		$ivlen = openssl_cipher_iv_length( self::CIPHER );
		$iv    = openssl_random_pseudo_bytes( $ivlen );
		$ciphertext_raw = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return base64_encode( $iv . $ciphertext_raw );
	}

	/**
	 * Decrypts a ciphertext string.
	 *
	 * @param string $ciphertext The base64-encoded string to decrypt.
	 * @return string The decrypted string, or the original string on failure.
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

		$ivlen = openssl_cipher_iv_length( self::CIPHER );
		$iv    = substr( $c, 0, $ivlen );
		$ciphertext_raw = substr( $c, $ivlen );

		$decrypted = openssl_decrypt( $ciphertext_raw, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return false === $decrypted ? $ciphertext : $decrypted;
	}
}
