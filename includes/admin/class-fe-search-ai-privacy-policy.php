<?php
/**
 * Suggested WordPress Privacy Policy content for FE Search AI.
 *
 * @package    fe-search-ai
 * @subpackage Admin
 */

namespace FESearchAI\Admin;

use FESearchAI\Core\FE_Search_AI_Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers suggested privacy policy text describing the plugin's data flow.
 *
 * @since 1.2.0
 */
class FE_Search_AI_Privacy_Policy {

	/**
	 * Register the admin_init hook that adds the policy content.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', [ __CLASS__, 'add_policy_content' ] );
	}

	/**
	 * Builds and registers the suggested privacy policy content.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$settings     = get_option( 'fe_search_ai_settings', [] );
		$pro_settings = get_option( 'fe_search_ai_pro_settings', [] );
		$settings     = is_array( $settings ) ? $settings : [];
		$pro_settings = is_array( $pro_settings ) ? $pro_settings : [];

		$recipients         = FE_Search_AI_Privacy::get_active_recipients( $settings, $pro_settings );
		$config             = FE_Search_AI_Privacy::get_frontend_config( $settings, $pro_settings );
		$receiving_labels   = [];
		$nonreceiving_labels = [];
		foreach ( $recipients as $recipient ) {
			$label = isset( $recipient['label'] ) ? (string) $recipient['label'] : '';
			if ( '' === $label ) {
				continue;
			}
			if ( ! empty( $recipient['user_content'] ) ) {
				$receiving_labels[] = $label;
			} else {
				$nonreceiving_labels[] = $label;
			}
		}

		$content  = '<h2>' . __( 'AI Search and Chat', 'fe-search-ai' ) . '</h2>';
		$content .= '<p>' . sprintf(
			/* translators: %s: comma-separated list of external service names */
			__( 'This site uses FE Search AI to answer visitor questions. When you use the chat, your question and recent conversation history in the current browser session are sent to the following external services to generate a response: %s.', 'fe-search-ai' ),
			esc_html( implode( ', ', $receiving_labels ) )
		) . '</p>';
		$content .= '<p>' . sprintf(
			/* translators: %s: comma-separated list of external service names */
			__( 'Other external services used by this feature that do not receive your input: %s.', 'fe-search-ai' ),
			esc_html( ! empty( $nonreceiving_labels ) ? implode( ', ', $nonreceiving_labels ) : __( 'none', 'fe-search-ai' ) )
		) . '</p>';

		$content .= '<h3>' . __( 'What we store', 'fe-search-ai' ) . '</h3>';
		$content .= '<p>' . __( 'By default this site does not store your questions or the AI\'s answers on its server. Only operational metadata (such as message lengths, timestamps, and hashed identifiers) may be retained for diagnostics for a limited period. Your conversation history is kept in your browser\'s session storage and is cleared when you close the tab or use the clear-history option.', 'fe-search-ai' ) . '</p>';

		if ( ! empty( $config['analytics_available'] ) ) {
			$retention_days = isset( $config['conversation_log_retention_days'] ) ? (int) $config['conversation_log_retention_days'] : 7;
			$content       .= '<p>' . sprintf(
				/* translators: %d: number of days */
				__( 'If you opt in to conversation analytics, question and answer text is stored after personal-data masking for service improvement, and deleted after %d days. You can withdraw this consent at any time from the chat privacy menu.', 'fe-search-ai' ),
				$retention_days
			) . '</p>';
		}

		$content .= '<h3>' . __( 'Rate limiting', 'fe-search-ai' ) . '</h3>';
		$content .= '<p>' . __( 'To prevent abuse we keep a short-lived (1 hour) counter keyed by a keyed hash of your IP address. The IP address itself is not stored.', 'fe-search-ai' ) . '</p>';
		$content .= '<p><em>' . __( 'Review each service provider\'s privacy policy and data retention terms. Retention on the providers\' side is governed by your contract with them (for example, zero-data-retention arrangements).', 'fe-search-ai' ) . '</em></p>';

		wp_add_privacy_policy_content( 'FE Search AI', wp_kses_post( $content ) );
	}
}
