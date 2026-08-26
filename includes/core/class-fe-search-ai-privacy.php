<?php
/**
 * Privacy configuration and external recipient registry.
 *
 * @package    fe-search-ai
 * @subpackage Core
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds privacy notices and versioned consent configuration.
 *
 * @since 1.2.0
 */
class FE_Search_AI_Privacy {

	/**
	 * Consent schema version.
	 *
	 * @var string
	 */
	const CONSENT_SCHEMA_VERSION = '1';

	/**
	 * Returns the provider privacy registry.
	 *
	 * @param array $settings Free plugin settings.
	 * @param array $pro_settings Pro plugin settings.
	 * @return array Provider registry.
	 */
	public static function get_provider_registry( array $settings = [], array $pro_settings = [] ) {
		$registry = [
			'openai'    => [
				'label'       => 'OpenAI',
				'data'        => [ 'question', 'conversation_history', 'retrieved_content' ],
				'purpose'     => 'chat_and_embedding',
				'is_external' => true,
			],
			'google'    => [
				'label'       => 'Google Gemini',
				'data'        => [ 'question', 'conversation_history', 'retrieved_content' ],
				'purpose'     => 'chat_and_embedding',
				'is_external' => true,
			],
			'anthropic' => [
				'label'       => 'Anthropic Claude',
				'data'        => [ 'question', 'conversation_history', 'retrieved_content' ],
				'purpose'     => 'chat',
				'is_external' => true,
			],
			'cohere'    => [
				'label'       => 'Cohere Rerank',
				'data'        => [ 'question', 'retrieved_content' ],
				'purpose'     => 'reranking',
				'is_external' => true,
			],
			'yahoo_ma'  => [
				'label'       => 'Yahoo! JAPAN Japanese MA API',
				'data'        => [ 'question_or_indexed_content' ],
				'purpose'     => 'tokenization',
				'is_external' => true,
			],
			'qdrant'    => [
				'label'       => 'Qdrant',
				'data'        => [ 'vector_data', 'indexed_content_metadata' ],
				'purpose'     => 'vector_storage_and_search',
				'is_external' => true,
			],
		];

		/**
		 * Filters provider privacy metadata.
		 *
		 * @param array $registry Provider registry.
		 * @param array $settings Free plugin settings.
		 * @param array $pro_settings Pro plugin settings.
		 */
		return apply_filters( 'fe_search_ai_privacy_provider_registry', $registry, $settings, $pro_settings );
	}

	/**
	 * Resolves recipients enabled by the current settings.
	 *
	 * @param array $settings Free plugin settings.
	 * @param array $pro_settings Pro plugin settings.
	 * @return array Active provider metadata.
	 */
	public static function get_active_recipients( array $settings = [], array $pro_settings = [] ) {
		$registry = self::get_provider_registry( $settings, $pro_settings );
		$active   = [];
		$provider = isset( $settings['provider'] ) && is_array( $settings['provider'] ) ? $settings['provider'] : [];
		$chat     = sanitize_key( $provider['chat'] ?? 'openai' );
		$embed    = sanitize_key( $provider['embedding'] ?? 'openai' );

		if ( isset( $registry[ $chat ] ) ) {
			$active[ $chat ] = $registry[ $chat ];
		}
		if ( isset( $registry[ $embed ] ) ) {
			$active[ $embed ] = $registry[ $embed ];
		}

		$rerank = isset( $settings['rerank'] ) && is_array( $settings['rerank'] ) ? $settings['rerank'] : [];
		if ( ! empty( $rerank['enabled'] ) && isset( $registry['cohere'] ) ) {
			$active['cohere'] = $registry['cohere'];
		}

		$tokenizer = $settings['tokenizer']['ja'] ?? [];
		if ( is_array( $tokenizer ) && 'yahoo_ma' === ( $tokenizer['engine'] ?? '' ) && isset( $registry['yahoo_ma'] ) ) {
			$active['yahoo_ma'] = $registry['yahoo_ma'];
		}

		$vector = isset( $settings['vector'] ) && is_array( $settings['vector'] ) ? $settings['vector'] : [];
		$stores = isset( $vector['stores'] ) && is_array( $vector['stores'] ) ? $vector['stores'] : [];
		if ( ! empty( $stores['qdrant'] ) && isset( $registry['qdrant'] ) ) {
			$active['qdrant'] = $registry['qdrant'];
		}

		/**
		 * Filters recipients active for the current configuration.
		 *
		 * @param array $active Active recipients.
		 * @param array $settings Free plugin settings.
		 * @param array $pro_settings Pro plugin settings.
		 */
		return apply_filters( 'fe_search_ai_active_privacy_recipients', $active, $settings, $pro_settings );
	}

	/**
	 * Builds legal document metadata.
	 *
	 * @param array $settings Free plugin settings.
	 * @return array Legal document metadata.
	 */
	public static function get_legal_documents( array $settings = [] ) {
		$links      = $settings['display']['links'] ?? [];
		$terms_id   = absint( $links['terms_page_id'] ?? 0 );
		$privacy_id = absint( $links['privacy_page_id'] ?? 0 );
		$terms      = $terms_id ? get_post( $terms_id ) : null;
		$privacy    = $privacy_id ? get_post( $privacy_id ) : null;

		return [
			'terms'   => [
				'id'       => $terms_id,
				'url'      => $terms_id ? (string) get_permalink( $terms_id ) : '',
				'modified' => $terms instanceof \WP_Post ? (string) $terms->post_modified_gmt : '',
			],
			'privacy' => [
				'id'       => $privacy_id,
				'url'      => $privacy_id ? (string) get_permalink( $privacy_id ) : (string) get_privacy_policy_url(),
				'modified' => $privacy instanceof \WP_Post ? (string) $privacy->post_modified_gmt : '',
			],
		];
	}

	/**
	 * Builds frontend privacy configuration.
	 *
	 * @param array $settings Free plugin settings.
	 * @param array $pro_settings Pro plugin settings.
	 * @return array Privacy configuration.
	 */
	public static function get_frontend_config( array $settings = [], array $pro_settings = [] ) {
		$recipients = self::get_active_recipients( $settings, $pro_settings );
		$legal      = self::get_legal_documents( $settings );
		$config     = [
			'enable_consent'      => false,
			'require_terms'       => false,
			'analytics_available' => false,
			'diagnostic_enabled'  => false,
			'consent_message'     => '',
			'terms_label'         => __( 'I agree to the Terms of Service.', 'fe-search-ai' ),
			'analytics_label'     => __( 'Allow masked conversation content to be stored for service improvement.', 'fe-search-ai' ),
			'recipients'          => $recipients,
			'legal'               => $legal,
			'browser_storage'     => [ 'session_id', 'conversation_history', 'feedback_log_ids' ],
			'log_mode'            => 'none',
			'consent_schema'      => self::CONSENT_SCHEMA_VERSION,
		];

		/**
		 * Filters frontend privacy configuration.
		 *
		 * @param array $config Frontend privacy configuration.
		 * @param array $settings Free plugin settings.
		 * @param array $pro_settings Pro plugin settings.
		 */
		$config            = apply_filters( 'fe_search_ai_privacy_config', $config, $settings, $pro_settings );
		$config['version'] = self::generate_consent_version( $config );

		return $config;
	}

	/**
	 * Generates a stable consent version from displayed processing details.
	 *
	 * @param array $config Privacy configuration without a version.
	 * @return string Consent version hash.
	 */
	public static function generate_consent_version( array $config ) {
		unset( $config['version'] );
		$config = self::sort_recursive( $config );
		return hash( 'sha256', (string) wp_json_encode( $config ) );
	}

	/**
	 * Sorts associative arrays recursively for stable hashing.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed Normalized value.
	 */
	private static function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_recursive( $item );
		}
		$is_list = [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value );
		}
		return $value;
	}
}
