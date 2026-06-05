<?php
/**
 * Unit tests for FE Search AI Defaults
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Defaults functionality tests
 *
 * @since 1.0.0
 */
class DefaultsTest extends TestCase {

	/**
	 * Test that defaults class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_defaults_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Core\FE_Search_AI_Defaults' ), 'Defaults class should exist' );
	}

	/**
	 * Test get_display_text_defaults returns array
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_get_display_text_defaults_returns_array() {
		$defaults = \FESearchAI\Core\FE_Search_AI_Defaults::get_display_text_defaults();

		$this->assertIsArray( $defaults, 'get_display_text_defaults should return an array' );
	}

	/**
	 * Test get_display_text_defaults has required keys
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_get_display_text_defaults_has_required_keys() {
		$defaults = \FESearchAI\Core\FE_Search_AI_Defaults::get_display_text_defaults();

		$this->assertArrayHasKey( 'window_title', $defaults, 'Array should have window_title key' );
		$this->assertArrayHasKey( 'greeting_message', $defaults, 'Array should have greeting_message key' );
		$this->assertArrayHasKey( 'placeholder_text', $defaults, 'Array should have placeholder_text key' );
		$this->assertArrayHasKey( 'submit_button_text', $defaults, 'Array should have submit_button_text key' );
	}

	/**
	 * Test get_display_text_defaults values are strings
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_get_display_text_defaults_values_are_strings() {
		$defaults = \FESearchAI\Core\FE_Search_AI_Defaults::get_display_text_defaults();

		$this->assertIsString( $defaults['window_title'], 'window_title should be a string' );
		$this->assertIsString( $defaults['greeting_message'], 'greeting_message should be a string' );
		$this->assertIsString( $defaults['placeholder_text'], 'placeholder_text should be a string' );
		$this->assertIsString( $defaults['submit_button_text'], 'submit_button_text should be a string' );
	}

	/**
	 * Test get_display_text_defaults values are not empty
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_get_display_text_defaults_values_are_not_empty() {
		$defaults = \FESearchAI\Core\FE_Search_AI_Defaults::get_display_text_defaults();

		$this->assertNotEmpty( $defaults['window_title'], 'window_title should not be empty' );
		$this->assertNotEmpty( $defaults['greeting_message'], 'greeting_message should not be empty' );
		$this->assertNotEmpty( $defaults['placeholder_text'], 'placeholder_text should not be empty' );
		$this->assertNotEmpty( $defaults['submit_button_text'], 'submit_button_text should not be empty' );
	}
}
