<?php
/**
 * Unit tests for FE Search AI Activator
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Activator functionality tests
 *
 * @since 1.0.0
 */
class ActivatorTest extends TestCase {

	/**
	 * Set up test environment before each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		// Clear any existing scheduled hooks
		wp_clear_scheduled_hook( 'fe_search_ai_daily_log_rotation_event' );
	}

	/**
	 * Clean up after each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Clear scheduled hooks after each test
		wp_clear_scheduled_hook( 'fe_search_ai_daily_log_rotation_event' );
	}

	/**
	 * Test that activator class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_activator_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Core\FE_Search_AI_Activator' ), 'Activator class should exist' );
	}

	/**
	 * Test DB_VERSION constant is defined
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_db_version_constant() {
		$reflection = new \ReflectionClass( 'FESearchAI\Core\FE_Search_AI_Activator' );
		$this->assertTrue( $reflection->hasConstant( 'DB_VERSION' ), 'DB_VERSION constant should be defined' );
		$this->assertIsString( \FESearchAI\Core\FE_Search_AI_Activator::DB_VERSION, 'DB_VERSION should be a string' );
	}

	/**
	 * Test create_tables creates all required tables
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_create_tables_creates_required_tables() {
		global $wpdb;

		// Drop existing tables to ensure clean test
		$tables = [
			$wpdb->prefix . 'fe_search_ai_vectors',
			$wpdb->prefix . 'fe_search_ai_keyword_index',
			$wpdb->prefix . 'fe_search_ai_system_logs',
			$wpdb->prefix . 'fe_search_ai_logs',
		];

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		// Run create_tables
		\FESearchAI\Core\FE_Search_AI_Activator::create_tables();

		// Check that all tables exist
		foreach ( $tables as $table ) {
			$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertEquals( $table, $result, "Table {$table} should be created" );
		}
	}

	/**
	 * Test create_tables can be called multiple times safely
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_create_tables_idempotent() {
		global $wpdb;

		// First call
		\FESearchAI\Core\FE_Search_AI_Activator::create_tables();

		// Get table count after first call
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';
		$count_before = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		// Insert a test row
		$wpdb->insert(
			$table_name,
			[
				'level'      => 'INFO',
				'message'    => 'Test message',
				'extra_data' => '{}',
				'created_at' => current_time( 'mysql' ),
			]
		);

		// Second call
		\FESearchAI\Core\FE_Search_AI_Activator::create_tables();

		// Check that data is preserved
		$count_after = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		$this->assertEquals( $count_before + 1, $count_after, 'Data should be preserved when create_tables is called again' );
	}

	/**
	 * Test schedule_cron_jobs schedules the daily event
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_schedule_cron_jobs() {
		// Ensure no existing schedule
		wp_clear_scheduled_hook( 'fe_search_ai_daily_log_rotation_event' );

		// Schedule cron jobs
		\FESearchAI\Core\FE_Search_AI_Activator::schedule_cron_jobs();

		// Check that the event is scheduled
		$next_scheduled = wp_next_scheduled( 'fe_search_ai_daily_log_rotation_event' );
		$this->assertNotFalse( $next_scheduled, 'Cron event should be scheduled' );
	}

	/**
	 * Test schedule_cron_jobs does not duplicate schedules
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_schedule_cron_jobs_no_duplicate() {
		// First schedule
		\FESearchAI\Core\FE_Search_AI_Activator::schedule_cron_jobs();
		$first_schedule = wp_next_scheduled( 'fe_search_ai_daily_log_rotation_event' );

		// Second schedule
		\FESearchAI\Core\FE_Search_AI_Activator::schedule_cron_jobs();
		$second_schedule = wp_next_scheduled( 'fe_search_ai_daily_log_rotation_event' );

		// Should be the same timestamp (no duplicate)
		$this->assertEquals( $first_schedule, $second_schedule, 'Cron event should not be duplicated' );
	}

	/**
	 * Test deactivate clears scheduled cron jobs
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_deactivate_clears_cron_jobs() {
		// Schedule the cron job first
		\FESearchAI\Core\FE_Search_AI_Activator::schedule_cron_jobs();
		$this->assertNotFalse( wp_next_scheduled( 'fe_search_ai_daily_log_rotation_event' ), 'Cron should be scheduled before deactivation' );

		// Deactivate
		\FESearchAI\Core\FE_Search_AI_Activator::deactivate();

		// Check that the event is cleared
		$next_scheduled = wp_next_scheduled( 'fe_search_ai_daily_log_rotation_event' );
		$this->assertFalse( $next_scheduled, 'Cron event should be cleared after deactivation' );
	}

	/**
	 * Test activate sets DB version
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_activate_sets_db_version() {
		// Clear existing settings
		delete_option( 'fe_search_ai_settings' );

		// Activate
		\FESearchAI\Core\FE_Search_AI_Activator::activate();

		// Check DB version is set
		$options = get_option( 'fe_search_ai_settings', [] );
		$this->assertArrayHasKey( 'advanced', $options, 'Options should have advanced key' );
		$this->assertArrayHasKey( 'db_version', $options['advanced'], 'Advanced options should have db_version key' );
		$this->assertEquals( \FESearchAI\Core\FE_Search_AI_Activator::DB_VERSION, $options['advanced']['db_version'], 'DB version should match constant' );
	}

	/**
	 * Test check_db_version updates when version is old
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_check_db_version_updates_old_version() {
		// Set old version
		$options = get_option( 'fe_search_ai_settings', [] );
		$options['advanced']['db_version'] = '1.0';
		update_option( 'fe_search_ai_settings', $options );

		// Check version
		\FESearchAI\Core\FE_Search_AI_Activator::check_db_version();

		// Verify version is updated
		$options = get_option( 'fe_search_ai_settings', [] );
		$this->assertEquals( \FESearchAI\Core\FE_Search_AI_Activator::DB_VERSION, $options['advanced']['db_version'], 'DB version should be updated' );
	}

	/**
	 * Test check_db_version does not update when version is current
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_check_db_version_skips_current_version() {
		// Set current version
		$options = get_option( 'fe_search_ai_settings', [] );
		$options['advanced']['db_version'] = \FESearchAI\Core\FE_Search_AI_Activator::DB_VERSION;
		update_option( 'fe_search_ai_settings', $options );

		// Check version
		\FESearchAI\Core\FE_Search_AI_Activator::check_db_version();

		// Verify version is unchanged
		$options = get_option( 'fe_search_ai_settings', [] );
		$this->assertEquals( \FESearchAI\Core\FE_Search_AI_Activator::DB_VERSION, $options['advanced']['db_version'], 'DB version should remain unchanged' );
	}
}
