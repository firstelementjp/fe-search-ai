<?php
/**
 * Registers all plugin assets (CSS and JavaScript).
 *
 * This file defines the FEAS_AI_Assets class, which is responsible for
 * enqueuing all styles and scripts for both the public-facing chat UI
 * and the admin settings pages.
 *
 * @package    fe-ai-search
 * @subpackage Core
 * @since      1.0.0
 */

namespace FEAISearch\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The assets handler class.
 *
 * This class is responsible for registering and enqueueing all the
 * assets, styles, and scripts that the plugin uses.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 */
class FEAS_AI_Assets {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Load scripts and styles for the front end.
	 */
	public static function enqueue_assets() {
		$options = get_option( 'feas_ai_display_options', [] );

		$enable_css = $options['enable_css'] ?? true;
		$animation_speed = $display_options['animation_speed'] ?? 3;

		$locale = get_locale();
		$is_cjk = in_array( substr( $locale, 0, 2 ), [ 'ja', 'zh', 'ko' ], true );
		$send_on_shift_enter = $options['send_on_shift_enter'] ?? $is_cjk;

		$license_data      = get_option( 'feas_ai_license_data', [] );
		$is_license_active = ( 'active' === ( $license_data['status'] ?? 'inactive' ) );
		$is_license_active = 'active';

		if ( $enable_css ) {
			wp_enqueue_style(
				'feas-ai-frontend-styles',
				plugin_dir_url( FEAS_AI_PLUGIN_FILE ) . 'assets/css/frontend-styles.css',
				[],
				FEAS_AI_VERSION
			);
		}

		wp_enqueue_script(
			'feas-ai-chat-main',
			plugin_dir_url( FEAS_AI_PLUGIN_FILE ) . 'assets/js/frontend-scripts.js',
			array( 'wp-i18n' ),
			FEAS_AI_VERSION,
			true
		);

		wp_set_script_translations(
			'feas-ai-chat-main',
			'fe-ai-search',
			plugin_dir_path( FEAS_AI_PLUGIN_FILE ) . 'languages'
		);

		wp_localize_script(
			'feas-ai-chat-main',
			'feas_ai_ajax_obj',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ), // For log storage
				'rest_url'   => rest_url( 'feas-ai/v1/stream' ), // For streaming
				'rest_nonce' => wp_create_nonce( 'wp_rest' ), // Nonce for REST API
				'nonce'      => wp_create_nonce( 'feas_ai_ajax_nonce' ),
				'animation_speed' => (int) $animation_speed,
				'send_on_shift_enter' => (bool) $send_on_shift_enter,
				'is_pro_active'       => class_exists( 'FEAISearch\Pro\Admin\FEAS_AI_Pro_Settings' ),
				'is_license_active'   => $is_license_active,
			)
		);
	}
}
