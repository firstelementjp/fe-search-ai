<?php
/**
 * Plugin Name: FE AI Search
 * Plugin URI:  https://fe-advanced-search.com/
 * Description: AI-powered search for WordPress.
 * Version:     0.3
 * Author:      FirstElement, Inc.
 * Author URI:  https://www.firstelement.co.jp/
 * Text Domain: fe-ai-search
 * Domain Path: /languages/
 */

ini_set('display_errors', 1); // ▼▼▼ この2行を追加 ▼▼▼
 error_reporting(E_ALL);

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FEAS_AI_VERSION', '1.0.0' );
define( 'FEAS_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FEAS_AI_PLUGIN_FILE', __FILE__ );
define( 'FEAS_AI_PRO_URL', 'https://fe-ai-search.com/pricing' );

if ( file_exists( FEAS_AI_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once FEAS_AI_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 *  Custom Autoloader
 */
spl_autoload_register(function ($class) {
    $prefix = 'FEAISearch\\';
    $base_dir = FEAS_AI_PLUGIN_DIR . 'includes/';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);

    // First, search for files in PSR-4 format (e.g., FEAS_AI_Admin.php).
    $psr4_file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($psr4_file)) {
        require $psr4_file;
        return;
    }

    // If not found with PSR-4, look for a WordPress-style file (e.g., class-feas-ai-admin.php).
    $parts = explode('\\', $relative_class);
    $class_name = array_pop($parts);
    $wp_file_name = 'class-' . str_replace('_', '-', strtolower($class_name)) . '.php';

    $wp_file_path = $base_dir . strtolower(implode('/', $parts));
    if (!empty($parts)) {
        $wp_file_path .= '/';
    }
    $wp_file_path .= $wp_file_name;

    if (file_exists($wp_file_path)) {
        require $wp_file_path;
        return;
    }
});

function feas_ai_run_plugin() {
    $assets_handler = new FEAISearch\Core\FEAS_AI_Assets();
    $sync_handler   = new FEAISearch\Ajax\FEAS_AI_Sync_Handler();

    add_filter( 'feas_ai_get_sync_handler_instance', function() use ( $sync_handler ) {
        return $sync_handler;
    });

    new FEAISearch\Admin\FEAS_AI_Admin();
    new FEAISearch\Frontend\FEAS_AI_Chat_UI( $assets_handler );
    new FEAISearch\Ajax\FEAS_AI_Chat_Handler( $sync_handler );
    new FEAISearch\Core\FEAS_AI_Sync_Hooks( $sync_handler );
}
add_action( 'plugins_loaded', 'feas_ai_run_plugin' );

register_activation_hook( FEAS_AI_PLUGIN_FILE, ['FEAISearch\Core\FEAS_AI_Activator', 'activate'] );
register_deactivation_hook( FEAS_AI_PLUGIN_FILE, ['FEAISearch\Core\FEAS_AI_Activator', 'deactivate'] );

/**
 * Template Tag
 */
function fe_ai_search() {
    echo do_shortcode('[fe_ai_search]');
}
