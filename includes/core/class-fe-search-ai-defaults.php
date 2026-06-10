<?php
/**
 * Shared default values used across the FE AI Search plugin.
 *
 * Centralizes default text for the chat UI so that the admin settings
 * placeholders and the frontend chat UI always stay in sync.
 *
 * @package    fe-search-ai
 * @subpackage Core
 */

namespace FESearchAI\Core;

/**
 * Shared default values used across the FE AI Search plugin.
 *
 * Centralizes default text for the chat UI so that the admin settings
 * placeholders and the frontend chat UI always stay in sync.
 */
class FE_Search_AI_Defaults {
	/**
	 * Returns the default display text values for the chat UI.
	 *
	 * @return array{
	 *     window_title: string,
	 *     greeting_message: string,
	 *     placeholder_text: string,
	 *     submit_button_text: string
	 * }
	 */
	public static function get_display_text_defaults() {
		return [
			'window_title'       => __( 'FE Search AI', 'fe-search-ai' ),
			'greeting_message'   => __( 'Hello! I am FE Search AI. How can I help you today?', 'fe-search-ai' ),
			'placeholder_text'   => __( 'Ask a question about this site…', 'fe-search-ai' ),
			'submit_button_text' => __( 'Send', 'fe-search-ai' ),
		];
	}
}
