<?php
/**
 * Initializes functions related to the plugin management screen.
 *
 * Defines the menu structure of the management screen
 * and serves the role of loading management classes such as settings pages.
 *
 * @package    fe-ai-search
 * @subpackage Admin
 * @since      1.0.0
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */

namespace FEAISearch\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FEAISearch\Admin\FE_AI_Search_Settings;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @subpackage Admin
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 * @license    GPL-2.0-or-later
 */
class FE_AI_Search_Admin {

	public function __construct() {

		new FE_AI_Search_Settings();

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_head', [ $this, 'maybe_reposition_settings_errors' ] );
	}

	/**
	 * Load scripts and styles for the admin panel.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$allowed_hooks = [
			'toplevel_page_fe-ai-search',
		];
		$allowed_hooks = apply_filters( 'fe_ai_search_admin_allowed_hooks', $allowed_hooks );

		if ( ! in_array( $hook_suffix, $allowed_hooks ) ) {
			return;
		}

		$use_unminified = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$admin_css      = $use_unminified ? 'assets/css/admin-styles.css' : 'assets/css/admin-styles.min.css';
		$admin_js       = $use_unminified ? 'assets/js/admin-scripts.js' : 'assets/js/admin-scripts.min.js';

		// Color picker (Pickr) styles.
		wp_enqueue_style(
			'fe-ai-search-pickr',
			plugin_dir_url( FE_AI_SEARCH_PLUGIN_FILE ) . 'assets/vendor/pickr.min.css',
			[],
			FE_AI_SEARCH_VERSION
		);
		wp_enqueue_script(
			'fe-ai-search-pickr',
			plugin_dir_url( FE_AI_SEARCH_PLUGIN_FILE ) . 'assets/vendor/pickr.min.js',
			[],
			FE_AI_SEARCH_VERSION,
			true
		);
		wp_enqueue_style(
			'codemirror-css',
			'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.css'
		);
		wp_enqueue_style(
			'fe-ai-search-admin-style',
			plugin_dir_url( FE_AI_SEARCH_PLUGIN_FILE ) . $admin_css,
			[],
			FE_AI_SEARCH_VERSION
		);

		wp_enqueue_script(
			'codemirror-js',
			'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.js',
			[],
			false,
			true
		);
		wp_enqueue_script(
			'codemirror-markdown',
			'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/markdown/markdown.min.js',
			[ 'codemirror-js' ],
			false,
			true
		);
		wp_enqueue_script(
			'fe-ai-search-admin-sync',
			plugin_dir_url( FE_AI_SEARCH_PLUGIN_FILE ) . $admin_js,
			[ 'wp-i18n', 'codemirror-js', 'fe-ai-search-pickr' ],
			FE_AI_SEARCH_VERSION,
			true
		);

		wp_set_script_translations(
			'fe-ai-search-admin-sync',
			'fe-ai-search',
			plugin_dir_path( FE_AI_SEARCH_PLUGIN_FILE ) . 'languages'
		);

		wp_localize_script(
			'fe-ai-search-admin-sync',
			'fe_ai_search_sync_obj',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'fe_ai_search_ajax_nonce' ),
			]
		);
	}

	/**
	 * Add a dedicated menu to the admin panel.
	 */
	public function add_admin_menu() {
		$parent_slug = 'fe-ai-search';
		add_menu_page(
			'FE Search AI',
			'FE Search AI',
			'manage_options',
			$parent_slug,
			[ FE_AI_Search_Settings::class, 'render_page' ],
			'dashicons-search',
			80
		);
		add_submenu_page(
			$parent_slug,
			__( 'Settings', 'fe-ai-search' ),
			__( 'Settings', 'fe-ai-search' ),
			'manage_options',
			$parent_slug,
			[ FE_AI_Search_Settings::class, 'render_page' ]
		);
	}

	/**
	 * Ensures Settings API notices are rendered only in our custom container
	 * on the FE Search AI settings page.
	 *
	 * WordPress hooks settings_errors() into admin_notices globally, which
	 * causes the "Settings saved" notice to appear before our custom header
	 * layout. On the FE Search AI screen, we remove that global callback so
	 * that notices are only output where FE_AI_Search_Settings::render_page()
	 * explicitly calls settings_errors() inside the fe-ai-search-notices
	 * wrapper.
	 */
	public function maybe_reposition_settings_errors() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		if ( 'toplevel_page_fe-ai-search' === $screen->id ) {
			remove_action( 'admin_notices', 'settings_errors' );
		}
	}
}
