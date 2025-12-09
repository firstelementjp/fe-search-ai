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
define( 'FE_AI_SEARCH_PRO_URL', 'https://fe-search.com/ai/pro' );

if ( file_exists( FE_AI_SEARCH_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once FE_AI_SEARCH_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Custom Autoloader for WordPress class naming convention
 * Handles both PSR-4 and WordPress-style class names (class-*.php)
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

		// Try PSR-4 style first (handled by Composer, but kept as fallback)
		$psr4_file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
		if ( file_exists( $psr4_file ) ) {
			require $psr4_file;
			return;
		}

		// Try WordPress-style class names (class-*.php)
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

// Register plugin hooks after autoloaders are ready
register_activation_hook( FE_AI_SEARCH_PLUGIN_FILE, [ 'FEAISearch\Core\FE_AI_Search_Activator', 'activate' ] );
register_deactivation_hook( FE_AI_SEARCH_PLUGIN_FILE, [ 'FEAISearch\Core\FE_AI_Search_Activator', 'deactivate' ] );

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'fe-ai-search',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'
		);
	},
	5
);

add_action(
	'init',
	static function () {
		\FEAISearch\Core\FE_AI_Search_Activator::check_db_version();
		$assets_handler = new FEAISearch\Core\FE_AI_Search_Assets();
		$sync_handler   = new FEAISearch\Ajax\FE_AI_Search_Sync_Handler();

		add_filter(
			'fe_ai_search_get_sync_handler_instance',
			static function () use ( $sync_handler ) {
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
);

add_action(
	'fe_ai_search_daily_log_rotation_event',
	static function () {
		\FEAISearch\Core\FE_AI_Search_Logger::rotate_logs();
	}
);

/**
 * Template Tag: Display FE AI Search chat interface
 *
 * Usage: <?php fe_ai_search(); ?>
 * Equivalent to: <?php echo do_shortcode( '[fe_ai_search]' ); ?>
 *
 * @since 1.0.0
 * @return void
 */
function fe_ai_search() {
	echo do_shortcode( '[fe_ai_search]' );
}
