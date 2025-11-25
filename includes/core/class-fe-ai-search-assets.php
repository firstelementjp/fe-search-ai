<?php
/**
 * Registers all plugin assets (CSS and JavaScript).
 *
 * This file defines the fe_ai_search_Assets class, which is responsible for
 * enqueuing all styles and scripts for both the public-facing chat UI
 * and the admin settings pages.
 *
 * @package    fe-ai-search
 * @subpackage Core
 * @since      1.0.0
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FEAISearch\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The assets handler class.
 *
 * This class is responsible for registering and enqueueing all the
 * assets, styles, and scripts that the plugin uses.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @subpackage Core
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_AI_Search_Assets {

	private $options = [];

	public function __construct() {
		$this->options = get_option( 'fe_ai_search_settings', [] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Load scripts and styles for the front end.
	 */
	public function enqueue_assets() {
		$ui_options      = $this->options['display']['ui'] ?? [];
		$enable_css      = $ui_options['enable_css'] ?? true;
		$enable_js       = $ui_options['enable_js'] ?? true;
		$animation_speed = $ui_options['animation_speed'] ?? 3;
		$send_mode       = $ui_options['send_mode'] ?? 'enter';

		$license_data      = get_option( 'fe_ai_search_license', [] );
		$status            = $license_data['status'] ?? 'inactive';
		$products          = $license_data['data']['products'] ?? [];
		$is_license_active = ( 'active' === $status && in_array( 'pro', $products, true ) );

		$ip_limit_count = 100; // Default
		if ( $is_license_active && class_exists( '\FEAISearch\Pro\Admin\FE_AI_Search_Pro_Settings' ) ) {
			$pro_options        = get_option( 'fe_ai_search_pro_settings', [] );
			$rate_limit_options = $pro_options['security']['rate_limit'] ?? [];
			$ip_limit_count     = $rate_limit_options['ip_limit_count'] ?? 100;
		}

		if ( $enable_css ) {
			wp_enqueue_style(
				'fe-ai-search-frontend-styles',
				plugin_dir_url( FE_AI_SEARCH_PLUGIN_FILE ) . 'assets/css/frontend-styles.css',
				[],
				FE_AI_SEARCH_VERSION
			);
		}

		if ( ! $enable_js ) {
			return;
		}

		wp_enqueue_script(
			'fe-ai-search-frontend-scripts',
			plugin_dir_url( FE_AI_SEARCH_PLUGIN_FILE ) . 'assets/js/frontend-scripts.js',
			[ 'wp-i18n' ],
			FE_AI_SEARCH_VERSION,
			true
		);

		wp_set_script_translations(
			'fe-ai-search-frontend-scripts',
			'fe-ai-search',
			plugin_dir_path( FE_AI_SEARCH_PLUGIN_FILE ) . 'languages'
		);

		// Pass data to JavaScript.
		wp_localize_script(
			'fe-ai-search-frontend-scripts',
			'fe_ai_search_ajax_obj',
			[
				'ajax_url'          => admin_url( 'admin-ajax.php' ),
				'rest_url'          => rest_url( 'fe-ai-search/v1/stream' ),
				'rest_nonce'        => wp_create_nonce( 'wp_rest' ),
				'nonce'             => wp_create_nonce( 'fe_ai_search_ajax_nonce' ),
				'animation_speed'   => (int) $animation_speed,
				'is_pro_active'     => class_exists( '\FEAISearch\Pro\Admin\FE_AI_Search_Pro_Settings' ),
				'is_license_active' => $is_license_active,
				'ip_limit_count'    => (int) $ip_limit_count,
				'send_mode'         => $send_mode,
			]
		);
	}
}
