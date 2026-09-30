<?php
/**
 * Unit tests for FE Search AI Retrieval Trace Recorder
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Retrieval trace recorder persistence, rotation, and clear tests
 *
 * @since 1.2.0
 */
class RetrievalTraceRecorderTest extends TestCase {

	/**
	 * Set up test environment before each test
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		\FESearchAI\Core\FE_Search_AI_Activator::create_tables();
		\FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::clear();
		delete_option( 'fe_search_ai_settings' );
	}

	/**
	 * Clean up after each test
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		\FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::clear();
		delete_option( 'fe_search_ai_settings' );
		remove_all_filters( 'fe_search_ai_retrieval_trace_retention_days' );
	}

	/**
	 * Build a minimal valid trace payload
	 *
	 * @since 1.2.0
	 * @param string $trace_id Trace identifier.
	 * @return array Trace payload.
	 */
	private function make_trace( $trace_id = 'trace_1' ) {
		return [
			'trace_id'     => $trace_id,
			'sequence_id'  => 'seq_1',
			'query_hash'   => str_repeat( 'a', 64 ),
			'query_length' => 5,
			'items'        => [
				[
					'final_rank' => 1,
					'post_id'    => 10,
					'chunk_hash' => str_repeat( 'b', 64 ),
					'source'     => 'vector',
				],
			],
		];
	}

	/**
	 * Insert a trace header row with an explicit created_at timestamp
	 *
	 * @since 1.2.0
	 * @param string $trace_id   Trace identifier.
	 * @param string $created_at UTC datetime string.
	 * @return void
	 */
	private function insert_trace_row( $trace_id, $created_at ) {
		global $wpdb;
		$wpdb->insert(
			\FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::traces_table(),
			[
				'trace_id'        => $trace_id,
				'sequence_id'     => 'seq_1',
				'query_hash'      => str_repeat( 'a', 64 ),
				'query_length'    => 5,
				'pipeline'        => '[]',
				'candidate_count' => 1,
				'payload'         => '{}',
				'created_at'      => $created_at,
			]
		);
	}

	/**
	 * Insert a trace item row with an explicit created_at timestamp
	 *
	 * @since 1.2.0
	 * @param string $trace_id   Trace identifier.
	 * @param string $created_at UTC datetime string.
	 * @return void
	 */
	private function insert_trace_item_row( $trace_id, $created_at ) {
		global $wpdb;
		$wpdb->insert(
			\FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::trace_items_table(),
			[
				'trace_id'   => $trace_id,
				'final_rank' => 1,
				'post_id'    => 10,
				'chunk_hash' => str_repeat( 'b', 64 ),
				'source'     => 'vector',
				'scores'     => '{}',
				'ranks'      => '{}',
				'payload'    => '{}',
				'created_at' => $created_at,
			]
		);
	}

	/**
	 * Count rows in a trace table
	 *
	 * @since 1.2.0
	 * @param string $table Table name.
	 * @return int Row count.
	 */
	private function row_count( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Test that record() inserts when the persistence setting is enabled
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_record_inserts_when_setting_enabled() {
		update_option( 'fe_search_ai_settings', [ 'advanced' => [ 'retrieval_trace_persistence' => true ] ] );

		$result = \FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::record( $this->make_trace() );

		$this->assertTrue( $result, 'record() should return true when persistence is enabled' );
		$this->assertSame( 1, $this->row_count( \FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::traces_table() ), 'Trace row should be inserted' );
		$this->assertSame( 1, $this->row_count( \FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::trace_items_table() ), 'Trace item row should be inserted' );
	}

	/**
	 * Test that record() does not insert when the setting is disabled or absent
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_record_skips_when_setting_disabled() {
		update_option( 'fe_search_ai_settings', [ 'advanced' => [ 'retrieval_trace_persistence' => false ] ] );

		$result = \FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::record( $this->make_trace() );

		$this->assertFalse( $result, 'record() should return false when persistence is disabled' );
		$this->assertSame( 0, $this->row_count( \FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::traces_table() ), 'No trace row should be inserted' );
	}

	/**
	 * Test that rotate() removes rows older than the configured retention in both tables
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_rotate_removes_old_rows_in_both_tables() {
		update_option( 'fe_search_ai_settings', [ 'advanced' => [ 'retrieval_trace_retention_days' => 3 ] ] );

		$traces_table = \FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::traces_table();
		$items_table  = \FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::trace_items_table();

		$old_date    = gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) );
		$recent_date = gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) );

		$this->insert_trace_row( 'old_trace', $old_date );
		$this->insert_trace_row( 'new_trace', $recent_date );
		$this->insert_trace_item_row( 'old_trace', $old_date );
		$this->insert_trace_item_row( 'new_trace', $recent_date );

		\FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::rotate();

		$this->assertSame( 1, $this->row_count( $traces_table ), 'Only the recent trace should remain' );
		$this->assertSame( 1, $this->row_count( $items_table ), 'Only the recent trace item should remain' );

		global $wpdb;
		$remaining = $wpdb->get_var( "SELECT trace_id FROM {$traces_table} LIMIT 1" );
		$this->assertEquals( 'new_trace', $remaining, 'The 5-day-old trace should be deleted by the 3-day retention' );
	}

	/**
	 * Test that clear() empties both trace tables
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_clear_empties_both_tables() {
		$this->insert_trace_row( 'trace_a', gmdate( 'Y-m-d H:i:s' ) );
		$this->insert_trace_item_row( 'trace_a', gmdate( 'Y-m-d H:i:s' ) );

		\FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::clear();

		$this->assertSame( 0, $this->row_count( \FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::traces_table() ), 'Traces table should be empty' );
		$this->assertSame( 0, $this->row_count( \FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::trace_items_table() ), 'Trace items table should be empty' );
	}
}
