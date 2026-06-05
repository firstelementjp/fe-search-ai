<?php
/**
 * PHPUnit bootstrap for FE Search AI
 *
 * This file prepares a WordPress-aware environment for plugin tests.
 *
 * @package FE_Search_AI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	$wp_dir = false;

	$possible_paths = [
		'../../../wp',
		'../../../../wp',
		dirname( dirname( dirname( __DIR__ ) ) ) . '/wp',
		dirname( dirname( dirname( dirname( __DIR__ ) ) ) ),
	];

	foreach ( $possible_paths as $candidate_path ) {
		if ( file_exists( $candidate_path . '/wp-config.php' ) ) {
			$wp_dir = $candidate_path;
			break;
		}
	}

	if ( $wp_dir ) {
		define( 'ABSPATH', $wp_dir . '/' );
	} else {
		die( 'WordPress installation not found. Please set up WP_TESTS_DIR environment variable.' );
	}
}

if ( false === getenv( 'WP_TESTS_DIR' ) ) {
	$wp_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
} else {
	$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
}

if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	die( 'WordPress test files not found. Please run: composer install' );
}

if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', dirname( __DIR__ ) . '/wp-tests-config.php' );
}

require_once $wp_tests_dir . '/includes/functions.php';


/**
 * Load the FE Search AI plugin for the WordPress test bootstrap
 *
 * @return void
 */
function fe_search_ai_tests_load_plugin() {
	require_once dirname( __DIR__ ) . '/fe-search-ai.php';
	if ( function_exists( 'fe_search_ai_init' ) ) {
		fe_search_ai_init();
	}
}

tests_add_filter( 'muplugins_loaded', 'fe_search_ai_tests_load_plugin' );

require_once $wp_tests_dir . '/includes/bootstrap.php';

$fe_search_ai_results_dir = dirname( __DIR__ ) . '/tests/results';

if ( ! is_dir( $fe_search_ai_results_dir ) ) {
	wp_mkdir_p( $fe_search_ai_results_dir );
}

if ( ! function_exists( 'fe_search_ai' ) ) {
	/**
	 * Get a singleton-like FE Search AI instance for tests
	 *
	 * @return \FE_Search_AI|null
	 */
	function fe_search_ai() {
		static $instance = null;
		if ( null === $instance && class_exists( 'FE_Search_AI' ) ) {
			$instance = new \FE_Search_AI();
		}

		return $instance;
	}
}

if ( ! function_exists( 'fe_search_ai_cleanup' ) ) {
	/**
	 * Remove transient test data created during runs
	 *
	 * @return void
	 */
	function fe_search_ai_cleanup() {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_title LIKE 'test_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'test_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fe_search_ai_test_%'" );
	}
}
