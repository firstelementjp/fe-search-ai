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
		if ( ! get_option( 'feas_ai_disable_css' ) ) {
			wp_enqueue_style(
				'feas-ai-chat-style',
				plugin_dir_url( FEAS_AI_PLUGIN_FILE ) . 'assets/css/frontend-styles.css',
				array(),
				FEAS_AI_VERSION
			);
		}

		wp_enqueue_script(
			'feas-ai-chat-main',
			plugin_dir_url( FEAS_AI_PLUGIN_FILE ) . 'assets/js/frontend-scripts.js',
			array(),
			FEAS_AI_VERSION,
			true
		);

		wp_localize_script(
			'feas-ai-chat-main',
			'feas_ai_ajax_obj',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ), // For log storage
				'rest_url'   => rest_url( 'feas-ai/v1/stream' ), // For streaming
				'rest_nonce' => wp_create_nonce( 'wp_rest' ), // Nonce for REST API
				'nonce'      => wp_create_nonce( 'feas_ai_ajax_nonce' ),
			)
		);
	}
}
