<?php
/**
 * Unit tests for FE Search AI Logger
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Logger functionality tests
 *
 * @since 1.0.0
 */
class LoggerTest extends TestCase {

	/**
	 * Set up test environment before each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		// Enable debug mode for testing
		update_option(
			'fe_search_ai_settings',
			[
				'advanced' => [
					'debug_mode' => true,
				],
			]
		);
	}

	/**
	 * Clean up after each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Clear logs after each test
		\FESearchAI\Core\FE_Search_AI_Logger::clear_logs();

		// Also clear conversation logs
		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			$wpdb->query( "TRUNCATE TABLE `{$table_name}`" );
		}

		// Reset sequence counters
		$reflection = new \ReflectionClass( 'FESearchAI\Core\FE_Search_AI_Logger' );
		$property   = $reflection->getProperty( 'sequence_counters' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Test that logger class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_logger_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Core\FE_Search_AI_Logger' ), 'Logger class should exist' );
	}

	/**
	 * Test basic log functionality
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_basic_log() {
		\FESearchAI\Core\FE_Search_AI_Logger::log( 'INFO', 'Test message' );

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';
		$count      = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		$this->assertEquals( 1, $count, 'One log entry should be created' );
	}

	/**
	 * Test log with data
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_log_with_data() {
		\FESearchAI\Core\FE_Search_AI_Logger::log(
			'INFO',
			'Test message with data',
			[ 'key' => 'value' ]
		);

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';
		$log        = $wpdb->get_row( "SELECT * FROM {$table_name} LIMIT 1" );

		$this->assertNotNull( $log, 'Log entry should exist' );
		$this->assertEquals( 'info', $log->level, 'Log level should be sanitized to lowercase' );
		$this->assertEquals( 'Test message with data', $log->message, 'Log message should match' );
	}

	/**
	 * Test that log is not written when debug mode is disabled
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_log_disabled_when_debug_mode_off() {
		// Disable debug mode
		update_option(
			'fe_search_ai_settings',
			[
				'advanced' => [
					'debug_mode' => false,
				],
			]
		);

		\FESearchAI\Core\FE_Search_AI_Logger::log( 'INFO', 'Test message' );

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';
		$count      = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		$this->assertEquals( 0, $count, 'No log entry should be created when debug mode is disabled' );
	}

	/**
	 * Test forbidden keys are filtered from log data
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_forbidden_keys_are_filtered() {
		\FESearchAI\Core\FE_Search_AI_Logger::log(
			'INFO',
			'Test message',
			[
				'question' => 'sensitive data',
				'answer'   => 'sensitive data',
				'tokens'   => 'sensitive data',
				'safe_key' => 'safe data',
			]
		);

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';
		$log        = $wpdb->get_row( "SELECT * FROM {$table_name} LIMIT 1" );

		$this->assertNotNull( $log, 'Log entry should exist' );
		$data = json_decode( $log->extra_data, true );

		$this->assertArrayNotHasKey( 'question', $data, 'Forbidden key "question" should be filtered' );
		$this->assertArrayNotHasKey( 'answer', $data, 'Forbidden key "answer" should be filtered' );
		$this->assertArrayNotHasKey( 'tokens', $data, 'Forbidden key "tokens" should be filtered' );
		$this->assertArrayHasKey( 'safe_key', $data, 'Safe key should be present' );
	}

	/**
	 * Test clear_logs functionality
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_clear_logs() {
		// Create multiple log entries
		\FESearchAI\Core\FE_Search_AI_Logger::log( 'INFO', 'Test message 1' );
		\FESearchAI\Core\FE_Search_AI_Logger::log( 'INFO', 'Test message 2' );
		\FESearchAI\Core\FE_Search_AI_Logger::log( 'INFO', 'Test message 3' );

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';
		$count      = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		$this->assertEquals( 3, $count, 'Three log entries should be created' );

		// Clear logs
		\FESearchAI\Core\FE_Search_AI_Logger::clear_logs();

		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		$this->assertEquals( 0, $count, 'All log entries should be cleared' );
	}

	/**
	 * Test sequence ID generation
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_sequence_id() {
		$sequence_id = \FESearchAI\Core\FE_Search_AI_Logger::generate_sequence_id();

		$this->assertIsString( $sequence_id, 'Sequence ID should be a string' );
		$this->assertStringStartsWith( 'process_', $sequence_id, 'Sequence ID should start with prefix' );
	}

	/**
	 * Test sequence ID generation with custom prefix
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_sequence_id_with_custom_prefix() {
		$sequence_id = \FESearchAI\Core\FE_Search_AI_Logger::generate_sequence_id( 'chat' );

		$this->assertIsString( $sequence_id, 'Sequence ID should be a string' );
		$this->assertStringStartsWith( 'chat_', $sequence_id, 'Sequence ID should start with custom prefix' );
	}

	/**
	 * Test log with sequence tracking
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_log_with_sequence() {
		$sequence_id = 'test_sequence_123';

		\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence( 'INFO', 'Step 1', [], $sequence_id );
		\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence( 'INFO', 'Step 2', [], $sequence_id );
		\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence( 'INFO', 'Step 3', [], $sequence_id );

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';
		$logs       = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY id ASC" );

		$this->assertCount( 3, $logs, 'Three log entries should be created' );

		// Check sequence order
		$data1 = json_decode( $logs[0]->extra_data, true );
		$data2 = json_decode( $logs[1]->extra_data, true );
		$data3 = json_decode( $logs[2]->extra_data, true );

		$this->assertEquals( $sequence_id, $data1['sequence_id'], 'Sequence ID should match' );
		$this->assertEquals( 1, $data1['sequence_order'], 'First entry should have order 1' );
		$this->assertEquals( 2, $data2['sequence_order'], 'Second entry should have order 2' );
		$this->assertEquals( 3, $data3['sequence_order'], 'Third entry should have order 3' );
	}

	/**
	 * Test log without sequence ID falls back to standard log
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_log_with_sequence_empty_id() {
		\FESearchAI\Core\FE_Search_AI_Logger::log_with_sequence( 'INFO', 'Test message', [], '' );

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';
		$log        = $wpdb->get_row( "SELECT * FROM {$table_name} LIMIT 1" );

		$this->assertNotNull( $log, 'Log entry should exist' );
		$data = json_decode( $log->extra_data, true );

		$this->assertArrayNotHasKey( 'sequence_id', $data, 'Sequence ID should not be present when empty' );
		$this->assertArrayNotHasKey( 'sequence_order', $data, 'Sequence order should not be present when empty' );
	}

	/**
	 * Test log rotation removes old entries
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_rotate_logs_removes_old_entries() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';

		// Clear any existing logs first
		\FESearchAI\Core\FE_Search_AI_Logger::clear_logs();

		// Create old log entry (40 days ago)
		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) );
		$wpdb->insert(
			$table_name,
			[
				'level'      => 'INFO',
				'message'    => 'Old log entry',
				'extra_data' => '{}',
				'created_at' => $old_date,
			]
		);

		// Create recent log entry (10 days ago)
		$recent_date = gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) );
		$wpdb->insert(
			$table_name,
			[
				'level'      => 'INFO',
				'message'    => 'Recent log entry',
				'extra_data' => '{}',
				'created_at' => $recent_date,
			]
		);

		$count_before = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		$this->assertEquals( 2, $count_before, 'Two log entries should exist before rotation' );

		// Rotate logs (default 30 days retention)
		\FESearchAI\Core\FE_Search_AI_Logger::rotate_logs();

		$count_after = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		$this->assertEquals( 1, $count_after, 'Only recent log entry should remain after rotation' );

		$remaining_log = $wpdb->get_row( "SELECT * FROM {$table_name} LIMIT 1" );
		$this->assertEquals( 'Recent log entry', $remaining_log->message, 'Recent log should remain' );
	}

	/**
	 * Test log rotation with custom retention period
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_rotate_logs_with_custom_retention() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_system_logs';

		// Clear any existing logs first
		\FESearchAI\Core\FE_Search_AI_Logger::clear_logs();

		// Create old log entry (20 days ago)
		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) );
		$wpdb->insert(
			$table_name,
			[
				'level'      => 'INFO',
				'message'    => 'Old log entry',
				'extra_data' => '{}',
				'created_at' => $old_date,
			]
		);

		// Set custom retention to 10 days
		add_filter( 'fe_search_ai_log_retention_days', function() {
			return 10;
		} );

		\FESearchAI\Core\FE_Search_AI_Logger::rotate_logs();

		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		$this->assertEquals( 0, $count, 'All entries should be removed with 10-day retention' );

		remove_all_filters( 'fe_search_ai_log_retention_days' );
	}

	/**
	 * Test conversation log rotation
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_rotate_conversation_logs() {
		// Conversation logs table is only created by Pro version.
		if ( ! class_exists( 'FESearchAI\\Pro\\Admin\\FE_Search_AI_Pro_Settings' ) ) {
			$this->markTestSkipped( 'Conversation logs table is only available in Pro version.' );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'fe_search_ai_logs';

		// Create old conversation log (10 days ago)
		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) );
		$wpdb->insert(
			$table_name,
			[
				'session_id'   => 'test_session',
				'question'     => 'Test question',
				'answer'       => 'Test answer',
				'context_found' => 1,
				'rating'       => 5,
				'created_at'   => $old_date,
			]
		);

		// Create recent conversation log (3 days ago)
		$recent_date = gmdate( 'Y-m-d H:i:s', time() - ( 3 * DAY_IN_SECONDS ) );
		$wpdb->insert(
			$table_name,
			[
				'session_id'   => 'test_session_2',
				'question'     => 'Test question 2',
				'answer'       => 'Test answer 2',
				'context_found' => 1,
				'rating'       => 5,
				'created_at'   => $recent_date,
			]
		);

		$count_before = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		$this->assertEquals( 2, $count_before, 'Two conversation logs should exist before rotation' );

		// Rotate conversation logs (default 7 days retention)
		\FESearchAI\Core\FE_Search_AI_Logger::rotate_conversation_logs();

		$count_after = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		$this->assertEquals( 1, $count_after, 'Only recent conversation log should remain after rotation' );
	}
}
