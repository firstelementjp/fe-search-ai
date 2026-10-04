<?php
/**
 * Multilingual integration for the FE Search AI chat UI texts.
 *
 * Registers the configured chat UI texts with Polylang, WPML String
 * Translation, or Bogo, and translates them on the frontend through the
 * fe_search_ai_display_texts filter.
 *
 * @package    fe-search-ai
 * @subpackage Core
 * @since 1.3.0
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multilingual integration for the FE Search AI chat UI texts.
 *
 * Registers the configured chat UI texts with Polylang, WPML String
 * Translation, or Bogo, and translates them on the frontend through the
 * fe_search_ai_display_texts filter.
 *
 * @since 1.3.0
 * @package    fe-search-ai
 * @subpackage Core
 * @author     FirstElement K.K. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_Search_AI_Multilingual {

	/**
	 * String translation group name shared by Polylang and WPML.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const STRING_GROUP = 'FE Search AI';

	/**
	 * Bogo translation context used for the chat UI texts.
	 *
	 * Bogo derives the lookup context from the part of the item name before
	 * the first colon, so item names are stored as `fe_search_ai:<key>`.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const BOGO_CONTEXT = 'fe_search_ai';

	/**
	 * Translatable chat UI text fields.
	 *
	 * Maps each option key under `display.text` to the label used when
	 * registering the string and whether the field accepts multiple lines.
	 *
	 * @since 1.3.0
	 * @var array
	 */
	const TEXT_FIELDS = [
		'window_title'       => [ 'name' => 'Chat window title', 'multiline' => false ],
		'greeting_message'   => [ 'name' => 'Greeting message', 'multiline' => true ],
		'placeholder_text'   => [ 'name' => 'Input placeholder', 'multiline' => false ],
		'submit_button_text' => [ 'name' => 'Submit button text', 'multiline' => false ],
		'footer_notice'      => [ 'name' => 'Footer notice', 'multiline' => true ],
	];

	/**
	 * Registers the multilingual integration hooks.
	 *
	 * String registration runs on `init` so Polylang and WPML are loaded,
	 * the `bogo_terms_translation` filter adds the texts to the Bogo Texts
	 * screen, and the display-text filter translates the values before
	 * rendering.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public static function register(): void {
		/**
		 * Filters whether the multilingual integration is enabled.
		 *
		 * Return false to skip string registration and translation entirely,
		 * e.g. when a different multilingual plugin handles the texts.
		 *
		 * @since 1.3.0
		 * @param bool $enabled Whether the integration is enabled. Default: true.
		 */
		// Hook name is properly prefixed with fe_search_ai_.
		if ( ! apply_filters( 'fe_search_ai_enable_multilingual_integration', true ) ) {
			return;
		}

		add_action( 'init', [ __CLASS__, 'register_strings' ] );
		add_filter( 'bogo_terms_translation', [ __CLASS__, 'add_bogo_translation_items' ], 10, 2 );
		// Priority 5 so site code hooked at the default priority 10 can still override.
		add_filter( 'fe_search_ai_display_texts', [ __CLASS__, 'translate_display_texts' ], 5 );
	}

	/**
	 * Checks whether Polylang is active.
	 *
	 * @since 1.3.0
	 * @return bool True when the Polylang functions are available.
	 */
	public static function is_polylang_active(): bool {
		return function_exists( 'pll__' ) && function_exists( 'pll_register_string' );
	}

	/**
	 * Checks whether WPML String Translation is active.
	 *
	 * @since 1.3.0
	 * @return bool True when WPML is available.
	 */
	public static function is_wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_translate_single_string' );
	}

	/**
	 * Checks whether Bogo is active.
	 *
	 * @since 1.3.0
	 * @return bool True when the Bogo functions are available.
	 */
	public static function is_bogo_active(): bool {
		return function_exists( 'bogo_translate' );
	}

	/**
	 * Returns the multilingual provider used for the chat UI texts.
	 *
	 * Detects Polylang, WPML, then Bogo, in that order. Sites running several
	 * multilingual plugins can pick a different one through the
	 * fe_search_ai_multilingual_provider filter.
	 *
	 * @since 1.3.0
	 * @return string One of 'polylang', 'wpml', 'bogo', or '' when none detected.
	 */
	public static function get_active_provider(): string {
		$provider = '';
		if ( self::is_polylang_active() ) {
			$provider = 'polylang';
		} elseif ( self::is_wpml_active() ) {
			$provider = 'wpml';
		} elseif ( self::is_bogo_active() ) {
			$provider = 'bogo';
		}

		/**
		 * Filters the multilingual provider used for the chat UI texts.
		 *
		 * Lets sites with several multilingual plugins installed pick which
		 * one handles the chat UI texts.
		 *
		 * @since 1.3.0
		 * @param string $provider One of 'polylang', 'wpml', 'bogo', or '' when none detected.
		 */
		// Hook name is properly prefixed with fe_search_ai_.
		return (string) apply_filters( 'fe_search_ai_multilingual_provider', $provider );
	}

	/**
	 * Returns the translated labels for the chat UI text fields.
	 *
	 * Reuses the same msgids as the settings screen so no new translation
	 * strings are needed.
	 *
	 * @since 1.3.0
	 * @return array Map of text field keys to translated labels.
	 */
	public static function get_text_field_labels(): array {
		return [
			'window_title'       => __( 'Chat window title', 'fe-search-ai' ),
			'greeting_message'   => __( 'First greeting', 'fe-search-ai' ),
			'placeholder_text'   => __( 'Input field placeholders', 'fe-search-ai' ),
			'submit_button_text' => __( 'Submit button text', 'fe-search-ai' ),
			'footer_notice'      => __( 'Footer Notice', 'fe-search-ai' ),
		];
	}

	/**
	 * Returns the configured custom chat UI texts.
	 *
	 * Only non-empty values stored in the `fe_search_ai_settings` option are
	 * returned. Default texts are intentionally excluded because they are
	 * already translatable through the plugin text domain.
	 *
	 * @since 1.3.0
	 * @param array|null $options Optional settings array. Defaults to the stored option.
	 * @return array Map of text field keys to trimmed, non-empty strings.
	 */
	public static function get_custom_texts( $options = null ): array {
		if ( null === $options ) {
			$options = get_option( 'fe_search_ai_settings', [] );
		}

		$texts       = [];
		$stored      = is_array( $options ) ? ( $options['display']['text'] ?? [] ) : [];
		$text_fields = self::TEXT_FIELDS;

		foreach ( $text_fields as $key => $field ) {
			if ( isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) && '' !== trim( $stored[ $key ] ) ) {
				$texts[ $key ] = trim( $stored[ $key ] );
			}
		}

		return $texts;
	}

	/**
	 * Registers the configured texts with Polylang or WPML String Translation.
	 *
	 * Does nothing for Bogo (its items are exposed through the
	 * bogo_terms_translation filter) or when no provider is active.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public static function register_strings(): void {
		$provider = self::get_active_provider();

		if ( 'polylang' !== $provider && 'wpml' !== $provider ) {
			return;
		}

		foreach ( self::get_custom_texts() as $key => $value ) {
			$field = self::TEXT_FIELDS[ $key ];
			if ( 'polylang' === $provider ) {
				pll_register_string( $field['name'], $value, self::STRING_GROUP, $field['multiline'] );
			} else {
				do_action( 'wpml_register_single_string', self::STRING_GROUP, $field['name'], $value );
			}
		}
	}

	/**
	 * Adds the configured texts to the Bogo Texts translation screen.
	 *
	 * Bogo stores each item name as the msgid and uses the part before the
	 * first colon as the lookup context, so names use the
	 * `fe_search_ai:<key>` format.
	 *
	 * @since 1.3.0
	 * @param array  $items  Translation items built by Bogo.
	 * @param string $locale Locale being edited.
	 * @return array The filtered translation items.
	 */
	public static function add_bogo_translation_items( $items, $locale ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The locale is required by the bogo_terms_translation filter signature but not needed for the item list.
		if ( ! is_array( $items ) ) {
			return [];
		}

		if ( 'bogo' !== self::get_active_provider() ) {
			return $items;
		}

		$labels = self::get_text_field_labels();

		foreach ( self::get_custom_texts() as $key => $value ) {
			$name    = self::BOGO_CONTEXT . ':' . $key;
			$items[] = [
				'name'       => $name,
				'original'   => $value,
				'translated' => bogo_translate( $name, self::BOGO_CONTEXT, $value ),
				'context'    => sprintf( '%s: %s', self::STRING_GROUP, $labels[ $key ] ),
				'cap'        => 'manage_options',
			];
		}

		return $items;
	}

	/**
	 * Translates the display texts through the active multilingual provider.
	 *
	 * Hooked on `fe_search_ai_display_texts` at priority 5. Strings that are
	 * not registered (e.g. built-in defaults) are returned unchanged by the
	 * translation APIs.
	 *
	 * @since 1.3.0
	 * @param array $args Associative array of display texts and metadata.
	 * @return array The filtered texts, or the original value when it is not an array.
	 */
	public static function translate_display_texts( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$provider = self::get_active_provider();

		foreach ( self::TEXT_FIELDS as $key => $field ) {
			if ( ! isset( $args[ $key ] ) || ! is_string( $args[ $key ] ) || '' === $args[ $key ] ) {
				continue;
			}
			switch ( $provider ) {
				case 'polylang':
					$args[ $key ] = pll__( $args[ $key ] );
					break;
				case 'wpml':
					$args[ $key ] = apply_filters( 'wpml_translate_single_string', $args[ $key ], self::STRING_GROUP, $field['name'] );
					break;
				case 'bogo':
					$translated = bogo_translate( self::BOGO_CONTEXT . ':' . $key, self::BOGO_CONTEXT, $args[ $key ] );
					// An empty saved translation must not blank the text; the
					// default fallback already ran before this filter.
					if ( is_string( $translated ) && '' !== $translated ) {
						$args[ $key ] = $translated;
					}
					break;
			}
		}

		return $args;
	}
}
