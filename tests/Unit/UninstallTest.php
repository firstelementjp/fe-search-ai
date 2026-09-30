<?php
/**
 * Unit tests for FE Search AI uninstall.php
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Uninstall script tests
 *
 * @since 1.2.0
 */
class UninstallTest extends TestCase {

	/**
	 * Plugin table names without the WP prefix.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	private $table_suffixes = [
		'fe_search_ai_vectors',
		'fe_search_ai_keyword_index',
		'fe_search_ai_system_logs',
		'fe_search_ai_logs',
		'fe_search_ai_retrieval_traces',
		'fe_search_ai_retrieval_trace_items',
	];

	/**
	 * Option names removed by uninstall.php.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	private $option_names = [
		'fe_search_ai_settings',
		'fe_search_ai_custom_prompts',
		'fe_search_ai_site_info',
		'fe_search_ai_sync_state',
		'fe_search_ai_license',
		'fe_search_ai_delete_on_uninstall',
	];

	/**
	 * Transient names removed by uninstall.php.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	private $transient_names = [
		'fe_search_ai_license_error',
		'fe_search_ai_rl_notify_sent',
		'fe_search_ai_rl_global_day',
		'fe_search_ai_i18n_notice_dismissed',
		'fe_search_ai_consent_links_missing',
	];

	/**
	 * Set up test environment before each test
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->create_plugin_tables();
		$this->clean_plugin_data();
	}

	/**
	 * Clean up after each test
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Restore tables and clear leftover data so other test classes are unaffected.
		$this->clean_plugin_data();
		$this->create_plugin_tables();
	}

	/**
	 * Recreate plugin tables for a clean state
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function create_plugin_tables() {
		global $wpdb;

		\FESearchAI\Core\FE_Search_AI_Activator::create_tables();

		// The conversation logs table is created by Pro only; create a stub for tests.
		$logs_table = $wpdb->prefix . 'fe_search_ai_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$logs_table}` (id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))" );
	}

	/**
	 * Remove plugin options and transients
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function clean_plugin_data() {
		foreach ( $this->option_names as $option_name ) {
			delete_option( $option_name );
		}
		foreach ( $this->transient_names as $transient_name ) {
			delete_transient( $transient_name );
		}
		delete_site_transient( 'fe_search_ai_github_latest_release' );
		delete_transient( 'fe_search_ai_rl_ip_abc' );
	}

	/**
	 * Seed plugin options and transients to verify deletion
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function seed_plugin_data() {
		update_option( 'fe_search_ai_custom_prompts', [ 'system_prompt' => 'test' ] );
		update_option( 'fe_search_ai_site_info', [ 'site_name' => 'test' ] );
		update_option( 'fe_search_ai_sync_state', [ 'last_sync' => 123 ] );
		update_option( 'fe_search_ai_license', [ 'key' => 'test' ] );

		set_transient( 'fe_search_ai_license_error', 'error', 60 );
		set_transient( 'fe_search_ai_rl_notify_sent', true, 60 );
		set_transient( 'fe_search_ai_rl_global_day', 5, 60 );
		set_transient( 'fe_search_ai_i18n_notice_dismissed', true, 60 );
		set_transient( 'fe_search_ai_consent_links_missing', true, 60 );
		set_transient( 'fe_search_ai_rl_ip_abc', 3, 60 );
		set_site_transient( 'fe_search_ai_github_latest_release', [ 'tag_name' => '1.0.0' ], 60 );
	}

	/**
	 * Execute the uninstall script
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * Assert a plugin table exists
	 *
	 * @since 1.2.0
	 * @param string $suffix Table name without prefix.
	 * @return void
	 */
	private function assertTableExists( $suffix ) {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		$this->assertEquals( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "Table {$table} should exist" );
	}

	/**
	 * Assert a plugin table is dropped
	 *
	 * @since 1.2.0
	 * @param string $suffix Table name without prefix.
	 * @return void
	 */
	private function assertTableMissing( $suffix ) {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "Table {$table} should be dropped" );
	}

	/**
	 * Test that uninstall removes all data when the setting is enabled
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_uninstall_removes_data_when_setting_enabled() {
		update_option( 'fe_search_ai_settings', [ 'advanced' => [ 'delete_on_uninstall' => true ] ] );
		$this->seed_plugin_data();

		$this->run_uninstall();

		foreach ( $this->table_suffixes as $suffix ) {
			$this->assertTableMissing( $suffix );
		}
		foreach ( $this->option_names as $option_name ) {
			$this->assertFalse( get_option( $option_name ), "Option {$option_name} should be deleted" );
		}
		foreach ( $this->transient_names as $transient_name ) {
			$this->assertFalse( get_transient( $transient_name ), "Transient {$transient_name} should be deleted" );
		}
		// Assert via DB because the direct DELETE in uninstall.php bypasses the option cache.
		global $wpdb;
		$rl_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_fe_search_ai_rl_ip_' ) . '%'
			)
		);
		$this->assertEquals( 0, $rl_count, 'Rate-limit transients should be deleted' );
		$this->assertFalse( get_site_transient( 'fe_search_ai_github_latest_release' ), 'Site transient should be deleted' );
	}

	/**
	 * Test that uninstall preserves data when the setting is disabled
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_uninstall_preserves_data_when_setting_disabled() {
		update_option( 'fe_search_ai_settings', [ 'advanced' => [ 'delete_on_uninstall' => false ] ] );
		$this->seed_plugin_data();

		$this->run_uninstall();

		foreach ( $this->table_suffixes as $suffix ) {
			$this->assertTableExists( $suffix );
		}
		$this->assertNotFalse( get_option( 'fe_search_ai_settings' ), 'Settings option should be preserved' );
		$this->assertNotFalse( get_option( 'fe_search_ai_custom_prompts' ), 'Custom prompts option should be preserved' );
		$this->assertNotFalse( get_transient( 'fe_search_ai_rl_ip_abc' ), 'Rate-limit transient should be preserved' );
	}

	/**
	 * Test that the legacy standalone option still triggers deletion
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_uninstall_honors_legacy_option() {
		update_option( 'fe_search_ai_delete_on_uninstall', 1 );
		$this->seed_plugin_data();

		$this->run_uninstall();

		foreach ( $this->table_suffixes as $suffix ) {
			$this->assertTableMissing( $suffix );
		}
		$this->assertFalse( get_option( 'fe_search_ai_settings' ), 'Settings option should be deleted' );
		$this->assertFalse( get_option( 'fe_search_ai_delete_on_uninstall' ), 'Legacy option should be deleted' );
	}
}
