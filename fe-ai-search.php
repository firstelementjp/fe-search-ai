<?php
/**
 * Plugin Name: FE AI Search
 * Plugin URI:  https://fe-advanced-search.com/
 * Description: AI-powered search for WordPress.
 * Version:     0.1
 * Author:      FirstElement, Inc.
 * Author URI:  https://www.firstelement.co.jp/
 * Text Domain: fe-ai-search
 * Domain Path: /languages/
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 定数を定義
define( 'FEAS_AI_VERSION', '0.1' );
define( 'FEAS_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FEAS_AI_PLUGIN_FILE', __FILE__ );

// 外部ライブラリを読み込み
require_once FEAS_AI_PLUGIN_DIR . 'vendor/Parsedown.php';

// プラグインのコアファイルを読み込み
require_once FEAS_AI_PLUGIN_DIR . 'includes/class-feas-ai-installer.php';
require_once FEAS_AI_PLUGIN_DIR . 'includes/class-feas-ai-assets.php';
require_once FEAS_AI_PLUGIN_DIR . 'includes/class-feas-ai-ajax.php';
require_once FEAS_AI_PLUGIN_DIR . 'includes/class-feas-ai-main.php';

register_activation_hook( FEAS_AI_PLUGIN_FILE, array( 'FEAS_AI_Installer', 'activate' ) );
register_deactivation_hook( FEAS_AI_PLUGIN_FILE, array( 'FEAS_AI_Installer', 'deactivate' ) );


// プラグインを起動
function feas_ai_run_plugin() {
	new FEAS_AI_Main();
}
add_action( 'plugins_loaded', 'feas_ai_run_plugin' );
