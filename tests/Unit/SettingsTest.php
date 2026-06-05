<?php
/**
 * Unit tests for FE Search AI Settings
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Settings functionality tests
 *
 * @since 1.0.0
 */
class SettingsTest extends TestCase {

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
	 * Test that settings class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_settings_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Admin\FE_Search_AI_Settings' ), 'Settings class should exist' );
	}

	/**
	 * Test sanitize_numeric_string with half-width numbers
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sanitize_numeric_string_half_width() {
		$settings = new \FESearchAI\Admin\FE_Search_AI_Settings();
		$result   = $settings->sanitize_numeric_string( '123,456,789' );

		$this->assertEquals( '123,456,789', $result, 'Half-width numbers should remain unchanged' );
	}

	/**
	 * Test sanitize_numeric_string with full-width numbers
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sanitize_numeric_string_full_width() {
		$settings = new \FESearchAI\Admin\FE_Search_AI_Settings();
		$result   = $settings->sanitize_numeric_string( '１２３，４５６，７８９' );

		$this->assertEquals( '123,456,789', $result, 'Full-width numbers should be converted to half-width' );
	}

	/**
	 * Test sanitize_numeric_string with mixed characters
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sanitize_numeric_string_mixed() {
		$settings = new \FESearchAI\Admin\FE_Search_AI_Settings();
		$result   = $settings->sanitize_numeric_string( 'abc123def' );

		$this->assertEquals( '123', $result, 'Non-numeric characters should be removed' );
	}

	/**
	 * Test sanitize_numeric_string with empty string
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sanitize_numeric_string_empty() {
		$settings = new \FESearchAI\Admin\FE_Search_AI_Settings();
		$result   = $settings->sanitize_numeric_string( '' );

		$this->assertEquals( '', $result, 'Empty string should remain empty' );
	}

	/**
	 * Test sanitize_numeric_string with spaces
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sanitize_numeric_string_spaces() {
		$settings = new \FESearchAI\Admin\FE_Search_AI_Settings();
		$result   = $settings->sanitize_numeric_string( '1 2 3' );

		$this->assertEquals( '123', $result, 'Spaces should be removed' );
	}

	/**
	 * Test sanitize_sync_options structure
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sanitize_sync_options_structure() {
		$settings = new \FESearchAI\Admin\FE_Search_AI_Settings();

		$input = [
			'post_types' => [
				'post' => [
					'enabled'          => '1',
					'include_title'    => '1',
					'include_content'  => '1',
					'include_date'     => '0',
					'include_author'   => '0',
					'taxonomies'       => [ 'category', 'post_tag' ],
					'custom_fields'    => 'field1,field2',
				],
			],
		];

		$result = $settings->sanitize_sync_options( $input );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'post_types', $result, 'Result should have post_types key' );
		$this->assertArrayHasKey( 'post', $result['post_types'], 'Result should have post type' );
	}

	/**
	 * Test sanitize_sync_options checkbox conversion
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sanitize_sync_options_checkbox_conversion() {
		$settings = new \FESearchAI\Admin\FE_Search_AI_Settings();

		$input = [
			'post_types' => [
				'post' => [
					'enabled'          => '1',
					'include_title'    => '1',
					'include_content'  => '0',
					'include_date'     => '',
					'include_author'   => '1',
					'taxonomies'       => [],
				],
			],
		];

		$result = $settings->sanitize_sync_options( $input );

		$this->assertEquals( 1, $result['post_types']['post']['enabled'], 'Checked checkbox should convert to 1' );
		$this->assertEquals( 1, $result['post_types']['post']['include_title'], 'Checked checkbox should convert to 1' );
		$this->assertEquals( 0, $result['post_types']['post']['include_content'], 'Unchecked checkbox should convert to 0' );
		$this->assertEquals( 0, $result['post_types']['post']['include_date'], 'Empty checkbox should convert to 0' );
	}

	/**
	 * Test sanitize_sync_options custom fields sanitization
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sanitize_sync_options_custom_fields() {
		$settings = new \FESearchAI\Admin\FE_Search_AI_Settings();

		$input = [
			'post_types' => [
				'post' => [
					'enabled'       => '1',
					'custom_fields' => 'field1,field2<script>alert(1)</script>',
				],
			],
		];

		$result = $settings->sanitize_sync_options( $input );

		$this->assertArrayHasKey( 'custom_fields', $result['post_types']['post'], 'Custom fields should be preserved' );
		$this->assertStringNotContainsString( '<script>', $result['post_types']['post']['custom_fields'], 'Script tags should be sanitized' );
	}

	/**
	 * Test sanitize_display_options structure
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sanitize_display_options_structure() {
		$settings = new \FESearchAI\Admin\FE_Search_AI_Settings();

		$input = [
			'display_rules' => [
				'include_ids' => '123,456',
				'exclude_ids' => '789',
			],
		];

		$result = $settings->sanitize_display_options( $input );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'display_rules', $result, 'Result should have display_rules key' );
	}

	/**
	 * Test sanitize_display_options full-width number conversion
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sanitize_display_options_full_width_conversion() {
		$settings = new \FESearchAI\Admin\FE_Search_AI_Settings();

		$input = [
			'display_rules' => [
				'include_ids' => '１２３，４５６',
			],
		];

		$result = $settings->sanitize_display_options( $input );

		// Note: sanitize_display_options only converts full-width numbers using 'n' option
		// Full-width commas are not converted by this method
		$this->assertEquals( '123，456', $result['display_rules']['include_ids'], 'Full-width numbers should be converted' );
	}

	/**
	 * Test that key sanitization methods exist
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sanitization_methods_exist() {
		$settings = new \FESearchAI\Admin\FE_Search_AI_Settings();

		$this->assertTrue( method_exists( $settings, 'sanitize_sync_options' ), 'sanitize_sync_options should exist' );
		$this->assertTrue( method_exists( $settings, 'sanitize_numeric_string' ), 'sanitize_numeric_string should exist' );
		$this->assertTrue( method_exists( $settings, 'sanitize_display_options' ), 'sanitize_display_options should exist' );
	}
}
