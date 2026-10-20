<?php
/**
 * Unit tests for FE Search AI Encryption Helper
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Encryption Helper functionality tests
 *
 * @since 1.0.0
 */
class EncryptionHelperTest extends TestCase {

	/**
	 * Set up test environment before each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		// Ensure AUTH_KEY is defined for testing
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'test_auth_key_for_encryption_testing_12345678' );
		}
	}

	/**
	 * Test that encryption helper class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_encryption_helper_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Core\FE_Search_AI_Encryption_Helper' ), 'Encryption Helper class should exist' );
	}

	/**
	 * Test basic encryption functionality
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_basic_encryption() {
		$plaintext = 'Hello, World!';
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );

		$this->assertNotEquals( $plaintext, $encrypted, 'Encrypted string should differ from plaintext' );
		$this->assertIsString( $encrypted, 'Encrypted value should be a string' );
		$this->assertNotEmpty( $encrypted, 'Encrypted value should not be empty' );
	}

	/**
	 * Test basic decryption functionality
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_basic_decryption() {
		$plaintext = 'Hello, World!';
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );
		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted );

		$this->assertEquals( $plaintext, $decrypted, 'Decrypted string should match original plaintext' );
	}

	/**
	 * Test encryption with special characters
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_encryption_with_special_characters() {
		$plaintext = 'Test with special chars: !@#$%^&*()_+-=[]{}|;:\'",.<>?/~`';
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );
		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted );

		$this->assertEquals( $plaintext, $decrypted, 'Special characters should be preserved' );
	}

	/**
	 * Test encryption with Unicode characters
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_encryption_with_unicode() {
		$plaintext = '日本語テスト 🎉 Test with emoji and unicode';
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );
		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted );

		$this->assertEquals( $plaintext, $decrypted, 'Unicode characters should be preserved' );
	}

	/**
	 * Test encryption with long string
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_encryption_with_long_string() {
		$plaintext = str_repeat( 'A', 10000 );
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );
		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted );

		$this->assertEquals( $plaintext, $decrypted, 'Long strings should be preserved' );
	}

	/**
	 * Test encryption returns original string when plaintext is empty
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_encryption_empty_string() {
		$plaintext = '';
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );

		$this->assertEquals( $plaintext, $encrypted, 'Empty string should be returned as-is' );
	}

	/**
	 * Test decryption returns original string when ciphertext is empty
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_decryption_empty_string() {
		$ciphertext = '';
		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $ciphertext );

		$this->assertEquals( $ciphertext, $decrypted, 'Empty string should be returned as-is' );
	}

	/**
	 * Test decryption with invalid base64 string
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_decryption_invalid_base64() {
		$invalid_ciphertext = 'not-valid-base64!!!';
		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $invalid_ciphertext );

		$this->assertEquals( $invalid_ciphertext, $decrypted, 'Invalid base64 should be returned as-is' );
	}

	/**
	 * Test encryption produces different output each time (due to random IV)
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_encryption_randomness() {
		$plaintext = 'Test string';
		$encrypted1 = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );
		$encrypted2 = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );

		$this->assertNotEquals( $encrypted1, $encrypted2, 'Each encryption should produce different output due to random IV' );
	}

	/**
	 * Test that both encrypted strings decrypt to the same plaintext
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_different_encryptions_decrypt_same() {
		$plaintext = 'Test string';
		$encrypted1 = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );
		$encrypted2 = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );

		$decrypted1 = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted1 );
		$decrypted2 = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted2 );

		$this->assertEquals( $plaintext, $decrypted1, 'First decryption should match plaintext' );
		$this->assertEquals( $plaintext, $decrypted2, 'Second decryption should match plaintext' );
	}

	/**
	 * Test that new encryptions use the libsodium v2 format
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_encryption_uses_v2_format() {
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( 'format check' );

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->assertStringStartsWith( 'feai:v2:', $encrypted, 'Encrypted value should carry the v2 format prefix' );
		} else {
			$this->assertStringStartsNotWith( 'feai:v2:', $encrypted, 'Legacy fallback should not carry the v2 prefix' );
		}
	}

	/**
	 * Test is_v2_format detection
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_is_v2_format() {
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( 'format check' );

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->assertTrue( \FESearchAI\Core\FE_Search_AI_Encryption_Helper::is_v2_format( $encrypted ) );
		}

		$this->assertFalse( \FESearchAI\Core\FE_Search_AI_Encryption_Helper::is_v2_format( 'plain-api-key' ) );
		$this->assertFalse( \FESearchAI\Core\FE_Search_AI_Encryption_Helper::is_v2_format( '' ) );
		$this->assertFalse( \FESearchAI\Core\FE_Search_AI_Encryption_Helper::is_v2_format( 123 ) );
	}

	/**
	 * Test that a tampered v2 ciphertext fails authentication
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_tampered_v2_ciphertext_fails() {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium is not available' );
		}

		$plaintext = 'sk-secret-key';
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );

		// Flip one character inside the base64 payload.
		$pos          = strlen( $encrypted ) - 2;
		$replacement  = 'A' === $encrypted[ $pos ] ? 'B' : 'A';
		$encrypted[ $pos ] = $replacement;

		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted );

		$this->assertNotEquals( $plaintext, $decrypted, 'Tampered ciphertext must not decrypt to the original plaintext' );
		$this->assertEquals( $encrypted, $decrypted, 'Tampered ciphertext should be returned unchanged' );
	}

	/**
	 * Test that legacy AES-256-CBC ciphertext still decrypts
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_legacy_cbc_ciphertext_decrypts() {
		$plaintext = 'legacy-api-key';
		$iv        = random_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
		$legacy    = base64_encode( $iv . openssl_encrypt( $plaintext, 'aes-256-cbc', AUTH_KEY, OPENSSL_RAW_DATA, $iv ) );

		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $legacy );

		$this->assertEquals( $plaintext, $decrypted, 'Legacy CBC ciphertext should still decrypt' );
	}

	/**
	 * Test that multiply-wrapped legacy ciphertext decrypts through all layers
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_legacy_cbc_double_wrapped_decrypts() {
		$plaintext = 'double-wrapped-key';

		$iv1       = random_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
		$layer1    = base64_encode( $iv1 . openssl_encrypt( $plaintext, 'aes-256-cbc', AUTH_KEY, OPENSSL_RAW_DATA, $iv1 ) );
		$iv2       = random_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
		$wrapped   = base64_encode( $iv2 . openssl_encrypt( $layer1, 'aes-256-cbc', AUTH_KEY, OPENSSL_RAW_DATA, $iv2 ) );

		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $wrapped );

		$this->assertEquals( $plaintext, $decrypted, 'Double-wrapped legacy ciphertext should decrypt through all layers' );
	}

	/**
	 * Creates a legacy AES-256-CBC ciphertext for migration tests.
	 *
	 * @since 1.3.0
	 * @param string $plaintext The plaintext to encrypt.
	 * @return string The legacy-format ciphertext.
	 */
	private function make_legacy_cbc( $plaintext ) {
		$iv = random_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
		return base64_encode( $iv . openssl_encrypt( $plaintext, 'aes-256-cbc', AUTH_KEY, OPENSSL_RAW_DATA, $iv ) );
	}

	/**
	 * Test migrate_option_secrets re-encrypts a root-level secret option
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_migrate_option_secrets_root_value() {
		$plaintext = 'root-level-secret';
		update_option( 'fe_search_ai_test_root_secret', $this->make_legacy_cbc( $plaintext ) );

		$migrated = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::migrate_option_secrets( 'fe_search_ai_test_root_secret', [ [] ] );

		$stored = get_option( 'fe_search_ai_test_root_secret' );
		$this->assertSame( 1, $migrated, 'One value should be migrated' );
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->assertTrue( \FESearchAI\Core\FE_Search_AI_Encryption_Helper::is_v2_format( $stored ), 'Migrated value should use v2 format' );
		}
		$this->assertSame( $plaintext, \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $stored ), 'Migrated value should decrypt to the original plaintext' );

		delete_option( 'fe_search_ai_test_root_secret' );
	}

	/**
	 * Test migrate_option_secrets handles nested paths and wildcards
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_migrate_option_secrets_nested_and_wildcard() {
		update_option(
			'fe_search_ai_test_nested',
			[
				'provider' => [
					'api_key' => $this->make_legacy_cbc( 'key-a' ),
					'other'   => 'plain-value',
				],
				'products' => [
					1 => [ 'key' => $this->make_legacy_cbc( 'key-b' ) ],
					2 => [ 'key' => $this->make_legacy_cbc( 'key-c' ) ],
				],
			]
		);

		$migrated = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::migrate_option_secrets(
			'fe_search_ai_test_nested',
			[
				[ 'provider', 'api_key' ],
				[ 'products', '*', 'key' ],
				[ 'missing', 'path' ],
			]
		);

		$this->assertSame( 3, $migrated, 'Three nested secrets should be migrated' );

		$stored = get_option( 'fe_search_ai_test_nested' );
		$this->assertSame( 'key-a', \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $stored['provider']['api_key'] ) );
		$this->assertSame( 'key-b', \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $stored['products'][1]['key'] ) );
		$this->assertSame( 'key-c', \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $stored['products'][2]['key'] ) );
		$this->assertSame( 'plain-value', $stored['provider']['other'], 'Non-secret values should be untouched' );

		delete_option( 'fe_search_ai_test_nested' );
	}

	/**
	 * Test that migrate_option_secrets is idempotent
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_migrate_option_secrets_idempotent() {
		$plaintext = 'idempotent-secret';
		update_option( 'fe_search_ai_test_idem', $this->make_legacy_cbc( $plaintext ) );

		\FESearchAI\Core\FE_Search_AI_Encryption_Helper::migrate_option_secrets( 'fe_search_ai_test_idem', [ [] ] );
		$second = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::migrate_option_secrets( 'fe_search_ai_test_idem', [ [] ] );

		$this->assertSame( 0, $second, 'Second run should migrate nothing' );
		$this->assertSame( $plaintext, \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( get_option( 'fe_search_ai_test_idem' ) ) );

		delete_option( 'fe_search_ai_test_idem' );
	}

	/**
	 * Test encryption with custom encryption key
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_encryption_with_custom_key() {
		// Define custom encryption key
		if ( ! defined( 'FE_SEARCH_AI_ENCRYPTION_KEY' ) ) {
			define( 'FE_SEARCH_AI_ENCRYPTION_KEY', 'custom_encryption_key_32_chars_long!' );
		}

		$plaintext = 'Test with custom key';
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );
		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted );

		$this->assertEquals( $plaintext, $decrypted, 'Custom key should work for encryption/decryption' );
	}

	/**
	 * Test that the legacy FE_AI_SEARCH_ENCRYPTION_KEY constant still works
	 *
	 * The old constant name predates the "FE Search AI" rename. Values
	 * encrypted under it must remain decryptable.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_encryption_with_legacy_key_constant() {
		if ( ! defined( 'FE_AI_SEARCH_ENCRYPTION_KEY' ) ) {
			define( 'FE_AI_SEARCH_ENCRYPTION_KEY', 'legacy_constant_key_32_chars_long!!' );
		}

		$plaintext = 'Test with legacy constant key';
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );
		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted );

		$this->assertEquals( $plaintext, $decrypted, 'Legacy constant key should work for encryption/decryption' );

		// A value encrypted under the legacy constant directly must also decrypt.
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$key        = sodium_crypto_generichash( FE_AI_SEARCH_ENCRYPTION_KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$legacy_v2  = 'feai:v2:' . base64_encode( $nonce . sodium_crypto_secretbox( 'old-value', $nonce, $key ) );
			$decrypted2 = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $legacy_v2 );

			$this->assertEquals( 'old-value', $decrypted2, 'V2 value encrypted under the legacy constant should decrypt' );
		}
	}
}
