<?php
/**
 * Unit tests for FE Search AI Multilingual
 *
 * @package FE_Search_AI\Tests\Unit
 */

if ( ! function_exists( 'pll__' ) ) {
	/**
	 * Polylang translation stub for tests.
	 *
	 * @param string $string The string to translate.
	 * @return string The prefixed string.
	 */
	function pll__( $string ) {
		return 'PLL:' . $string;
	}
}

if ( ! function_exists( 'pll_register_string' ) ) {
	/**
	 * Polylang string registration stub for tests.
	 *
	 * @param string $name      The string name/label.
	 * @param string $string    The string value.
	 * @param string $group     The string group.
	 * @param bool   $multiline Whether the string is multiline.
	 * @return void
	 */
	function pll_register_string( $name, $string, $group = '', $multiline = false ) {
		$GLOBALS['fe_search_ai_test_pll_registered'][] = compact( 'name', 'string', 'group', 'multiline' );
	}
}

if ( ! function_exists( 'bogo_translate' ) ) {
	/**
	 * Bogo translation stub for tests.
	 *
	 * @param string $singular The singular string (msgid).
	 * @param string $context  The translation context.
	 * @param string $default  Fallback when no translation exists.
	 * @return string The translated string or the default.
	 */
	function bogo_translate( $singular, $context = '', $default = '' ) {
		return $GLOBALS['fe_search_ai_test_bogo_mo'][ $context . '|' . $singular ] ?? $default;
	}
}

use PHPUnit\Framework\TestCase;

/**
 * Multilingual integration tests
 *
 * @since 1.3.0
 */
class MultilingualTest extends TestCase {

	/**
	 * Provider override callback registered on fe_search_ai_multilingual_provider.
	 *
	 * @since 1.3.0
	 * @var callable|null
	 */
	private $provider_override = null;

	/**
	 * Set up test environment before each test
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$GLOBALS['fe_search_ai_test_pll_registered'] = [];
		$GLOBALS['fe_search_ai_test_bogo_mo']        = [];
		$this->provider_override                    = null;
		delete_option( 'fe_search_ai_settings' );
	}

	/**
	 * Clean up after each test
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function tearDown(): void {
		if ( null !== $this->provider_override ) {
			remove_filter( 'fe_search_ai_multilingual_provider', $this->provider_override );
			$this->provider_override = null;
		}
		delete_option( 'fe_search_ai_settings' );
		$GLOBALS['fe_search_ai_test_pll_registered'] = [];
		$GLOBALS['fe_search_ai_test_bogo_mo']        = [];
		parent::tearDown();
	}

	/**
	 * Forces the multilingual provider for the duration of a test.
	 *
	 * @since 1.3.0
	 * @param string $provider The provider to force.
	 * @return void
	 */
	private function force_provider( $provider ) {
		$this->provider_override = static function () use ( $provider ) {
			return $provider;
		};
		add_filter( 'fe_search_ai_multilingual_provider', $this->provider_override );
	}

	/**
	 * Test that multilingual class exists
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Core\FE_Search_AI_Multilingual' ), 'Multilingual class should exist' );
	}

	/**
	 * Test get_active_provider defaults to Polylang when stubs exist
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_get_active_provider_defaults_to_polylang_with_stubs() {
		$this->assertSame( 'polylang', \FESearchAI\Core\FE_Search_AI_Multilingual::get_active_provider() );
	}

	/**
	 * Test the provider filter overrides detection
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_get_active_provider_filter_overrides_detection() {
		$this->force_provider( 'bogo' );
		$this->assertSame( 'bogo', \FESearchAI\Core\FE_Search_AI_Multilingual::get_active_provider() );
	}

	/**
	 * Test get_custom_texts excludes empty and missing values
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_get_custom_texts_excludes_empty_values() {
		$options = [
			'display' => [
				'text' => [
					'greeting_message' => '  Hello  ',
					'window_title'     => '',
					'footer_notice'    => '   ',
				],
			],
		];

		$texts = \FESearchAI\Core\FE_Search_AI_Multilingual::get_custom_texts( $options );

		$this->assertSame( [ 'greeting_message' => 'Hello' ], $texts );
	}

	/**
	 * Test register_strings registers custom texts with Polylang
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_register_strings_registers_custom_texts_with_polylang() {
		update_option(
			'fe_search_ai_settings',
			[
				'display' => [
					'text' => [
						'greeting_message' => 'Hi',
						'window_title'     => 'Title',
					],
				],
			]
		);

		\FESearchAI\Core\FE_Search_AI_Multilingual::register_strings();

		$registered = $GLOBALS['fe_search_ai_test_pll_registered'];
		$this->assertCount( 2, $registered );

		$this->assertContains(
			[
				'name'      => 'Greeting message',
				'string'    => 'Hi',
				'group'     => 'FE Search AI',
				'multiline' => true,
			],
			$registered
		);
		$this->assertContains(
			[
				'name'      => 'Chat window title',
				'string'    => 'Title',
				'group'     => 'FE Search AI',
				'multiline' => false,
			],
			$registered
		);
	}

	/**
	 * Test add_bogo_translation_items appends custom texts
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_add_bogo_translation_items_appends_custom_texts() {
		$this->force_provider( 'bogo' );
		update_option(
			'fe_search_ai_settings',
			[
				'display' => [
					'text' => [
						'greeting_message' => 'こんにちは',
						'window_title'     => 'タイトル',
					],
				],
			]
		);
		$GLOBALS['fe_search_ai_test_bogo_mo'] = [
			'fe_search_ai|fe_search_ai:greeting_message' => 'Hello',
		];

		$items = \FESearchAI\Core\FE_Search_AI_Multilingual::add_bogo_translation_items(
			[ [ 'name' => 'blogname' ] ],
			'en_US'
		);

		$this->assertCount( 3, $items );
		$this->assertSame( 'blogname', $items[0]['name'] );

		$window_title = $items[1];
		$this->assertSame( 'fe_search_ai:window_title', $window_title['name'] );
		$this->assertSame( 'タイトル', $window_title['translated'] );

		$greeting = $items[2];
		$this->assertSame( 'fe_search_ai:greeting_message', $greeting['name'] );
		$this->assertSame( 'こんにちは', $greeting['original'] );
		$this->assertSame( 'Hello', $greeting['translated'] );
		$this->assertSame( 'manage_options', $greeting['cap'] );
		$this->assertStringContainsString( 'FE Search AI', $greeting['context'] );
	}

	/**
	 * Test add_bogo_translation_items is a no-op for other providers
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_add_bogo_translation_items_is_noop_for_other_providers() {
		$input = [ [ 'name' => 'blogname' ] ];
		$this->assertSame( $input, \FESearchAI\Core\FE_Search_AI_Multilingual::add_bogo_translation_items( $input, 'en_US' ) );
	}

	/**
	 * Test translate_display_texts uses Polylang for non-empty texts
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_translate_display_texts_uses_polylang() {
		$args = \FESearchAI\Core\FE_Search_AI_Multilingual::translate_display_texts(
			[
				'mode'             => 'float',
				'greeting_message' => 'Hi',
				'footer_notice'    => '',
			]
		);

		$this->assertSame( 'PLL:Hi', $args['greeting_message'] );
		$this->assertSame( '', $args['footer_notice'] );
		$this->assertSame( 'float', $args['mode'] );
	}

	/**
	 * Test translate_display_texts uses Bogo and ignores empty translations
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_translate_display_texts_uses_bogo() {
		$this->force_provider( 'bogo' );
		$GLOBALS['fe_search_ai_test_bogo_mo'] = [
			'fe_search_ai|fe_search_ai:greeting_message' => 'Hello',
			'fe_search_ai|fe_search_ai:window_title'     => '',
		];

		$args = \FESearchAI\Core\FE_Search_AI_Multilingual::translate_display_texts(
			[
				'greeting_message' => 'こんにちは',
				'window_title'     => 'タイトル',
				'placeholder_text' => 'Q?',
			]
		);

		$this->assertSame( 'Hello', $args['greeting_message'] );
		$this->assertSame( 'タイトル', $args['window_title'] );
		$this->assertSame( 'Q?', $args['placeholder_text'] );
	}

	/**
	 * Test the chat UI applies the fe_search_ai_display_texts filter
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_chat_ui_applies_display_texts_filter() {
		update_option(
			'fe_search_ai_settings',
			[
				'display' => [
					'text' => [
						'greeting_message' => 'Original',
					],
				],
			]
		);

		$callback = static function ( $args ) {
			return array_merge( $args, [ 'greeting_message' => 'Filtered greeting' ] );
		};
		add_filter( 'fe_search_ai_display_texts', $callback );

		$chat_ui = new \FESearchAI\Frontend\FE_Search_AI_Chat_UI( new \FESearchAI\Core\FE_Search_AI_Assets() );
		$html    = $chat_ui->get_chat_ui_html( 'float' );

		remove_filter( 'fe_search_ai_display_texts', $callback );

		$this->assertStringContainsString( 'Filtered greeting', $html );
		$this->assertStringNotContainsString( 'Original', $html );
	}
}
