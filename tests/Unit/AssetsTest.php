<?php
/**
 * Unit tests for FE Search AI Assets
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Assets functionality tests
 *
 * @since 1.0.0
 */
class AssetsTest extends TestCase {

	/**
	 * Set up test environment before each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		// Clear any existing settings
		delete_option( 'fe_search_ai_settings' );
	}

	/**
	 * Clean up after each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Clear settings after each test
		delete_option( 'fe_search_ai_settings' );
	}

	/**
	 * Test that assets class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_assets_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Core\FE_Search_AI_Assets' ), 'Assets class should exist' );
	}

	/**
	 * Test that key methods exist
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_key_methods_exist() {
		$assets = new \FESearchAI\Core\FE_Search_AI_Assets();

		$this->assertTrue( method_exists( $assets, 'enqueue_assets' ), 'enqueue_assets method should exist' );
	}

	/**
	 * Test enqueue_assets method is callable
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_enqueue_assets_callable() {
		$assets = new \FESearchAI\Core\FE_Search_AI_Assets();

		$this->assertTrue( is_callable( [ $assets, 'enqueue_assets' ] ), 'enqueue_assets should be callable' );
	}
}
