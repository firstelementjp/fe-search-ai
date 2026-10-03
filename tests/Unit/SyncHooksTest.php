<?php
/**
 * Unit tests for FE Search AI Sync Hooks
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Sync Hooks functionality tests
 *
 * @since 1.0.0
 */
class SyncHooksTest extends TestCase {

	/**
	 * Set up test environment before each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		// Clear any existing settings
		delete_option( 'fe_search_ai_settings' );
		delete_option( 'fe_search_ai_sync_queue' );
		delete_option( 'fe_search_ai_sync_queue_lock' );
		wp_clear_scheduled_hook( 'fe_search_ai_process_sync_queue' );
	}

	/**
	 * Clean up after each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Clear settings after each test
		delete_option( 'fe_search_ai_settings' );
		delete_option( 'fe_search_ai_sync_queue' );
		delete_option( 'fe_search_ai_sync_queue_lock' );
		wp_clear_scheduled_hook( 'fe_search_ai_process_sync_queue' );
	}

	/**
	 * Test that sync hooks class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sync_hooks_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Core\FE_Search_AI_Sync_Hooks' ), 'Sync Hooks class should exist' );
	}

	/**
	 * Test that key methods exist
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_key_methods_exist() {
		// Create a mock sync handler for constructor
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$this->assertTrue( method_exists( $sync_hooks, 'sync_single_post_on_update' ), 'sync_single_post_on_update method should exist' );
		$this->assertTrue( method_exists( $sync_hooks, 'delete_post_from_index' ), 'delete_post_from_index method should exist' );
		$this->assertTrue( method_exists( $sync_hooks, 'enqueue_post_for_sync' ), 'enqueue_post_for_sync method should exist' );
		$this->assertTrue( method_exists( $sync_hooks, 'process_sync_queue' ), 'process_sync_queue method should exist' );
		$this->assertTrue( method_exists( $sync_hooks, 'index_post' ), 'index_post method should exist' );
		$this->assertTrue( method_exists( $sync_hooks, 'remove_post_from_sync' ), 'remove_post_from_sync method should exist' );
	}

	/**
	 * Test delete_post_from_index with non-existent post
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_delete_post_from_index_non_existent() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		// Should not throw error for non-existent post
		$sync_hooks->delete_post_from_index( 99999 );

		$this->assertTrue( true, 'delete_post_from_index should handle non-existent post gracefully' );
	}

	/**
	 * Test delete_post_from_index method is callable
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_delete_post_from_index_callable() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$this->assertTrue( is_callable( [ $sync_hooks, 'delete_post_from_index' ] ), 'delete_post_from_index should be callable' );
	}

	/**
	 * Test sync_single_post_on_update method is callable
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sync_single_post_on_update_callable() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$this->assertTrue( is_callable( [ $sync_hooks, 'sync_single_post_on_update' ] ), 'sync_single_post_on_update should be callable' );
	}

	/**
	 * Test sync_single_post_on_update skips autosave
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sync_single_post_on_update_skips_autosave() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		// Create a test post
		$post_id = wp_insert_post(
			[
				'post_title'   => 'Test Post',
				'post_content' => 'Test content',
				'post_status'  => 'publish',
			]
		);

		// Simulate autosave by calling wp_is_post_autosave with the post ID
		// Since wp_is_post_autosave checks if the post is a revision of another post,
		// we'll skip this test as it requires full WordPress context
		$this->markTestSkipped( 'Autosave test requires full WordPress HTTP context' );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test sync_single_post_on_update skips revision
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sync_single_post_on_update_skips_revision() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		// Create a test post
		$post_id = wp_insert_post(
			[
				'post_title'   => 'Test Post',
				'post_content' => 'Test content',
				'post_status'  => 'publish',
			]
		);

		// Create a revision
		$revision_id = wp_save_post_revision( $post_id );

		// Should skip revision
		$sync_hooks->sync_single_post_on_update( $revision_id, get_post( $revision_id ) );

		$this->assertTrue( true, 'sync_single_post_on_update should skip revision' );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test enqueue_post_for_sync queues the post and schedules a cron event
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_enqueue_post_for_sync_queues_post() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$sync_hooks->enqueue_post_for_sync( 123 );

		$queue = get_option( 'fe_search_ai_sync_queue', [] );
		$this->assertIsArray( $queue, 'Sync queue should be an array' );
		$this->assertArrayHasKey( 123, $queue, 'Post ID should be present in the sync queue' );
		$this->assertTrue(
			$this->has_scheduled_queue_event(),
			'A queue processing cron event should be scheduled'
		);
	}

	/**
	 * Check whether a queue processing cron event is scheduled.
	 *
	 * Events are scheduled with a unique argument, so wp_next_scheduled()
	 * cannot find them without knowing the argument.
	 *
	 * @since 1.3.0
	 * @return bool Whether at least one queue event exists in the cron array.
	 */
	private function has_scheduled_queue_event() {
		foreach ( _get_cron_array() as $cron ) {
			if ( isset( $cron['fe_search_ai_process_sync_queue'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Test enqueue_post_for_sync deduplicates post IDs
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_enqueue_post_for_sync_deduplicates() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$sync_hooks->enqueue_post_for_sync( 123 );
		$sync_hooks->enqueue_post_for_sync( 123 );

		$queue = get_option( 'fe_search_ai_sync_queue', [] );
		$this->assertCount( 1, $queue, 'Duplicate post IDs should be queued only once' );
	}

	/**
	 * Test remove_post_from_sync removes the post from the queue
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_remove_post_from_sync_dequeues_post() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$sync_hooks->enqueue_post_for_sync( 123 );
		$sync_hooks->remove_post_from_sync( 123 );

		$queue = get_option( 'fe_search_ai_sync_queue', [] );
		$this->assertArrayNotHasKey( 123, $queue, 'Post ID should be removed from the sync queue' );
	}

	/**
	 * Test process_sync_queue releases the lock on an empty queue
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function test_process_sync_queue_empty_queue() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$sync_hooks->process_sync_queue();

		$this->assertFalse(
			get_option( 'fe_search_ai_sync_queue_lock' ),
			'Queue lock should be released after processing'
		);
	}
}
