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
	 * Test encryption with custom encryption key
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_encryption_with_custom_key() {
		// Define custom encryption key
		if ( ! defined( 'FE_AI_SEARCH_ENCRYPTION_KEY' ) ) {
			define( 'FE_AI_SEARCH_ENCRYPTION_KEY', 'custom_encryption_key_32_chars_long!' );
		}

		$plaintext = 'Test with custom key';
		$encrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( $plaintext );
		$decrypted = \FESearchAI\Core\FE_Search_AI_Encryption_Helper::decrypt( $encrypted );

		$this->assertEquals( $plaintext, $decrypted, 'Custom key should work for encryption/decryption' );
	}
}
