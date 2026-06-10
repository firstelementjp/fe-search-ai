<?php
/**
 * Plugin Name: FE Search AI
 * Plugin URI:  https://github.com/firstelementjp/fe-search-ai
 * Description: AI-powered search for WordPress.
 * Version:     0.9.0
 * Author:      FirstElement K.K., Daijiro Miyazawa
 * Author URI:  https://www.firstelement.co.jp/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fe-search-ai
 * Domain Path: /languages/
 *
 * @package fe-search-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FE_SEARCH_AI_VERSION', '0.9.0' );
define( 'FE_SEARCH_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FE_SEARCH_AI_PLUGIN_FILE', __FILE__ );
define( 'FE_SEARCH_AI_PRO_URL', 'https://www.firstelement.co.jp/en/products/fe-search-ai-plugin/' );

/**
 *  Custom Autoloader
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefixes = [
			'FESearchAI\\',
			'FESearchAI\\',
		];
		$base_dir = FE_SEARCH_AI_PLUGIN_DIR . 'includes/';

		$matched_prefix = null;
		foreach ( $prefixes as $prefix ) {
			$len = strlen( $prefix );
			if ( strncmp( $prefix, $class_name, $len ) === 0 ) {
				$matched_prefix = $prefix;
				break;
			}
		}
		if ( null === $matched_prefix ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $matched_prefix ) );

		$psr4_file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
		if ( file_exists( $psr4_file ) ) {
			require_once $psr4_file;
			return;
		}

		$parts            = explode( '\\', $relative_class );
		$short_class_name = array_pop( $parts );
		$wp_file_name     = 'class-' . str_replace( '_', '-', strtolower( $short_class_name ) ) . '.php';

		$wp_file_path = $base_dir . strtolower( implode( '/', $parts ) );
		if ( ! empty( $parts ) ) {
			$wp_file_path .= '/';
		}
		$wp_file_path .= $wp_file_name;

		if ( file_exists( $wp_file_path ) ) {
			require_once $wp_file_path;
			return;
		}
	}
);

if ( file_exists( FE_SEARCH_AI_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once FE_SEARCH_AI_PLUGIN_DIR . 'vendor/autoload.php';
}

register_activation_hook( FE_SEARCH_AI_PLUGIN_FILE, [ 'FESearchAI\Core\FE_Search_AI_Activator', 'activate' ] );
register_deactivation_hook( FE_SEARCH_AI_PLUGIN_FILE, [ 'FESearchAI\Core\FE_Search_AI_Activator', 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function () {
		\FESearchAI\Core\FE_Search_AI_Activator::check_db_version();
		$assets_handler = new FESearchAI\Core\FE_Search_AI_Assets();
		$sync_handler   = new FESearchAI\Ajax\FE_Search_AI_Sync_Handler();

		\FESearchAI\Core\FE_Search_AI_Cohere_Reranker::register();

		add_filter(
			'fe_search_ai_get_sync_handler_instance',
			function () use ( $sync_handler ) {
				return $sync_handler;
			}
		);

		new FESearchAI\Admin\FE_Search_AI_Admin();
		new FESearchAI\Admin\FE_Search_AI_License_Settings();
		new FESearchAI\Frontend\FE_Search_AI_Chat_UI( $assets_handler );
		new FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		// To integrate real-time synchronization with batch synchronization
		$GLOBALS['fe_search_ai_sync_hooks'] = new FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );
	}
);

add_action(
	'fe_search_ai_daily_log_rotation_event',
	static function () {
		\FESearchAI\Core\FE_Search_AI_Logger::rotate_logs();
	}
);

/**
 * Template Tag for displaying the AI Search interface.
 *
 * This function provides a simple template tag that can be used in themes
 * to display the AI search interface. It outputs the fe_search_ai shortcode.
 *
 * @since 0.9.0
 * @return void Outputs the AI search interface HTML
 */
function fe_search_ai() {
	echo do_shortcode( '[fe_search_ai]' );
}
