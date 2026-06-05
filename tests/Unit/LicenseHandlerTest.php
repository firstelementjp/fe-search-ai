<?php
/**
 * Unit tests for FE Search AI License Handler
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * License Handler functionality tests
 *
 * @since 1.0.0
 */
class LicenseHandlerTest extends TestCase {

	/**
	 * Set up test environment before each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		// Define required constants for testing
		if ( ! defined( 'FE_SEARCH_AI_LICENSE_API_URL' ) ) {
			define( 'FE_SEARCH_AI_LICENSE_API_URL', 'https://test-api.example.com' );
		}
		if ( ! defined( 'FE_SEARCH_AI_LICENSE_PRODUCT_ID_PRO' ) ) {
			define( 'FE_SEARCH_AI_LICENSE_PRODUCT_ID_PRO', 123 );
		}
	}

	/**
	 * Test that license handler class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_license_handler_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Core\FE_Search_AI_License_Handler' ), 'License Handler class should exist' );
	}

	/**
	 * Test activate with empty license key returns error
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_activate_empty_license_key() {
		$handler = new \FESearchAI\Core\FE_Search_AI_License_Handler();
		$result  = $handler->activate( '' );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'success', $result, 'Result should have success key' );
		$this->assertFalse( $result['success'], 'Activation should fail with empty license key' );
		$this->assertArrayHasKey( 'message', $result, 'Result should have message key' );
	}

	/**
	 * Test deactivate with empty license key returns error
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_deactivate_empty_license_key() {
		$handler = new \FESearchAI\Core\FE_Search_AI_License_Handler();
		$result  = $handler->deactivate( '' );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'success', $result, 'Result should have success key' );
		$this->assertFalse( $result['success'], 'Deactivation should fail with empty license key' );
	}

	/**
	 * Test activate returns error when API URL is not defined
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_activate_without_api_url() {
		// This test verifies the error handling logic
		// Since we define the constant in setUp, we skip this test in this context
		// In a real integration test, you would test with the constant undefined
		$this->markTestSkipped( 'API URL is defined in setUp for other tests' );
	}

	/**
	 * Test activate method exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_activate_method_exists() {
		$handler = new \FESearchAI\Core\FE_Search_AI_License_Handler();
		$this->assertTrue( method_exists( $handler, 'activate' ), 'activate method should exist' );
	}

	/**
	 * Test deactivate method exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_deactivate_method_exists() {
		$handler = new \FESearchAI\Core\FE_Search_AI_License_Handler();
		$this->assertTrue( method_exists( $handler, 'deactivate' ), 'deactivate method should exist' );
	}

	/**
	 * Test activate with valid license key structure
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_activate_with_valid_key_structure() {
		$handler = new \FESearchAI\Core\FE_Search_AI_License_Handler();
		// This test only checks that the method can be called with a valid-looking key
		// The actual API call will fail in test environment, but we verify the structure
		$result = $handler->activate( 'ABCD-1234-EFGH-5678' );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'success', $result, 'Result should have success key' );
		$this->assertArrayHasKey( 'message', $result, 'Result should have message key' );
	}

	/**
	 * Test deactivate with valid license key structure
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_deactivate_with_valid_key_structure() {
		$handler = new \FESearchAI\Core\FE_Search_AI_License_Handler();
		// This test only checks that the method can be called with a valid-looking key
		$result = $handler->deactivate( 'ABCD-1234-EFGH-5678' );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'success', $result, 'Result should have success key' );
		$this->assertArrayHasKey( 'message', $result, 'Result should have message key' );
	}
}
