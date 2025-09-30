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
 */

namespace FEAISearch\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use FEAISearch\Admin\FEAS_AI_Settings;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @since      1.0.0
 * @package    fe-ai-search
 * @author     FirstElement, Inc. <info@firstelement.co.jp>
 */
class FEAS_AI_Admin {

	public function __construct() {

		new FEAS_AI_Settings();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
	}

	/**
	 * Load scripts and styles for the admin panel.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$allowed_hooks = array(
			'toplevel_page_fe-ai-search',
		);
		$allowed_hooks = apply_filters( 'feas_ai_admin_allowed_hooks', $allowed_hooks );

		if ( ! in_array( $hook_suffix, $allowed_hooks ) ) {
			return;
		}

		wp_enqueue_style(
			'codemirror-css',
			'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.css'
		);
		wp_enqueue_style(
			'feas-ai-admin-style',
			plugin_dir_url( FEAS_AI_PLUGIN_FILE ) . 'assets/css/admin-styles.css',
			array(),
			FEAS_AI_VERSION
		);

		wp_enqueue_script(
			'codemirror-js',
			'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.js',
			array(),
			false,
			true
		);
		wp_enqueue_script(
			'codemirror-markdown',
			'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/markdown/markdown.min.js',
			array('codemirror-js'),
			false,
			true
		);
		wp_enqueue_script(
			'feas-ai-admin-sync',
			plugin_dir_url( FEAS_AI_PLUGIN_FILE ) . 'assets/js/admin-scripts.js',
			array('jquery'),
			FEAS_AI_VERSION,
			true
		);

		wp_localize_script(
			'feas-ai-admin-sync',
			'feas_ai_sync_obj',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'feas_ai_ajax_nonce' ),
			)
		);
	}

	/**
	 * Add a dedicated menu to the admin panel.
	 */
	public function add_admin_menu() {
		$parent_slug = 'fe-ai-search';
		add_menu_page(
			'FE AI Search',
			'FE AI Search',
			'manage_options',
			$parent_slug,
			array( FEAS_AI_Settings::class, 'render_page' ),
			'dashicons-search',
			80
		);
		add_submenu_page(
			$parent_slug,
			'Settings',
			'Settings',
			'manage_options',
			$parent_slug,
			array( FEAS_AI_Settings::class, 'render_page' )
		);
	}
}
