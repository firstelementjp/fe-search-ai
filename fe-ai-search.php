<?php
/**
 * Plugin Name: FE AI Search
 * Plugin URI:  https://fe-advanced-search.com/
 * Description: AI-powered search for WordPress.
 * Version:     1.0.0
 * Author:      FirstElement, Inc.
 * Author URI:  https://www.firstelement.co.jp/
 * Text Domain: fe-ai-search
 * Domain Path: /languages/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FE_AI_SEARCH_VERSION', '1.0.0' );
define( 'FE_AI_SEARCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FE_AI_SEARCH_PLUGIN_FILE', __FILE__ );
define( 'FE_AI_SEARCH_PRO_URL', 'https://fe-ai-search.com/pricing' );

if ( file_exists( FE_AI_SEARCH_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once FE_AI_SEARCH_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 *  Custom Autoloader
 */
spl_autoload_register(
	function ( $class ) {
		$prefix   = 'FEAISearch\\';
		$base_dir = FE_AI_SEARCH_PLUGIN_DIR . 'includes/';
		$len      = strlen( $prefix );

		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );

		$psr4_file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
		if ( file_exists( $psr4_file ) ) {
			require $psr4_file;
			return;
		}

		$parts        = explode( '\\', $relative_class );
		$class_name   = array_pop( $parts );
		$wp_file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

		$wp_file_path = $base_dir . strtolower( implode( '/', $parts ) );
		if ( ! empty( $parts ) ) {
			$wp_file_path .= '/';
		}
		$wp_file_path .= $wp_file_name;

		if ( file_exists( $wp_file_path ) ) {
			require $wp_file_path;
			return;
		}
	}
);

register_activation_hook( FE_AI_SEARCH_PLUGIN_FILE, [ 'FEAISearch\Core\FE_AI_Search_Activator', 'activate' ] );
register_deactivation_hook( FE_AI_SEARCH_PLUGIN_FILE, [ 'FEAISearch\Core\FE_AI_Search_Activator', 'deactivate' ] );

/**
 * Boots the FE AI Search plugin and wires up core services.
 *
 * Initializes the database schema if needed, registers the main admin,
 * frontend, and AJAX handlers, and stores the shared sync hooks instance.
 *
 * @since 1.0.0
 */
function fe_ai_search_run_plugin() {
	\FEAISearch\Core\FE_AI_Search_Activator::check_db_version();
	$assets_handler = new FEAISearch\Core\FE_AI_Search_Assets();
	$sync_handler   = new FEAISearch\Ajax\FE_AI_Search_Sync_Handler();

	add_filter(
		'fe_ai_get_sync_handler_instance',
		function () use ( $sync_handler ) {
			return $sync_handler;
		}
	);

	new FEAISearch\Admin\FE_AI_Search_Admin();
	new FEAISearch\Admin\FE_AI_Search_License_Settings();
	new FEAISearch\Frontend\FE_AI_Search_Chat_UI( $assets_handler );
	new FEAISearch\Ajax\FE_AI_Search_Chat_Handler( $sync_handler );

	// To integrate real-time synchronization with batch synchronization
	$GLOBALS['fe_ai_search_sync_hooks'] = new FEAISearch\Core\FE_AI_Search_Sync_Hooks( $sync_handler );
}
add_action( 'plugins_loaded', 'fe_ai_search_run_plugin' );

/**
 * Template Tag
 */
function fe_ai_search() {
	echo do_shortcode( '[fe_ai_search]' );
}
