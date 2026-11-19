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

	/**
	 * Test sync_single_post_on_update does not queue a password-protected post
	 *
	 * @since 1.4.1
	 * @return void
	 */
	public function test_sync_single_post_on_update_skips_password_protected_post() {
		update_option(
			'fe_search_ai_settings',
			[
				'sync' => [
					'targets' => [
						'post' => [ 'enabled' => true ],
					],
				],
			]
		);

		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$post_id = wp_insert_post(
			[
				'post_title'    => 'test_password_protected_post',
				'post_content'  => 'Secret content',
				'post_status'   => 'publish',
				'post_password' => 'secret-pass',
			]
		);

		$sync_hooks->sync_single_post_on_update( $post_id, get_post( $post_id ) );

		$queue = get_option( 'fe_search_ai_sync_queue', [] );
		$this->assertArrayNotHasKey( $post_id, $queue, 'Password-protected post should not be queued for indexing' );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test sync_single_post_on_update removes a queued post once it becomes protected
	 *
	 * @since 1.4.1
	 * @return void
	 */
	public function test_sync_single_post_on_update_removes_post_when_protected() {
		update_option(
			'fe_search_ai_settings',
			[
				'sync' => [
					'targets' => [
						'post' => [ 'enabled' => true ],
					],
				],
			]
		);

		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$post_id = wp_insert_post(
			[
				'post_title'   => 'test_post_becoming_protected',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			]
		);

		$sync_hooks->enqueue_post_for_sync( $post_id );
		$queue = get_option( 'fe_search_ai_sync_queue', [] );
		$this->assertArrayHasKey( $post_id, $queue, 'Post should be queued before protection' );

		wp_update_post(
			[
				'ID'            => $post_id,
				'post_password' => 'secret-pass',
			]
		);

		$sync_hooks->sync_single_post_on_update( $post_id, get_post( $post_id ) );

		$queue = get_option( 'fe_search_ai_sync_queue', [] );
		$this->assertArrayNotHasKey( $post_id, $queue, 'Protected post should be removed from the sync queue' );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test sync_single_post_on_update does not queue a published post when
	 * the realtime_sync option is disabled (the default for new installs).
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_sync_single_post_on_update_skips_when_realtime_sync_disabled() {
		update_option(
			'fe_search_ai_settings',
			[
				'sync' => [
					'realtime_sync' => false,
					'targets'       => [
						'post' => [ 'enabled' => true ],
					],
				],
			]
		);

		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$post_id = wp_insert_post(
			[
				'post_title'   => 'test_realtime_sync_disabled',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			]
		);

		$sync_hooks->sync_single_post_on_update( $post_id, get_post( $post_id ) );

		$queue = get_option( 'fe_search_ai_sync_queue', [] );
		$this->assertArrayNotHasKey( $post_id, $queue, 'Published post should not be queued when realtime sync is disabled' );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test sync_single_post_on_update queues a published post when
	 * the realtime_sync option is enabled.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_sync_single_post_on_update_queues_when_realtime_sync_enabled() {
		update_option(
			'fe_search_ai_settings',
			[
				'sync' => [
					'realtime_sync' => true,
					'targets'       => [
						'post' => [ 'enabled' => true ],
					],
				],
			]
		);

		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$post_id = wp_insert_post(
			[
				'post_title'   => 'test_realtime_sync_enabled',
				'post_content' => 'Content',
				'post_status'  => 'publish',
			]
		);

		$sync_hooks->sync_single_post_on_update( $post_id, get_post( $post_id ) );

		$queue = get_option( 'fe_search_ai_sync_queue', [] );
		$this->assertArrayHasKey( $post_id, $queue, 'Published post should be queued when realtime sync is enabled' );

		// The constructor registers 'save_post'; remove it so this instance's
		// cached options do not affect later tests in the randomized run order.
		remove_action( 'save_post', [ $sync_hooks, 'sync_single_post_on_update' ] );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test enqueue_posts_for_sync queues multiple posts in one call and
	 * ignores duplicates and invalid IDs.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_enqueue_posts_for_sync_queues_multiple_posts() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$sync_hooks->enqueue_posts_for_sync( [ 10, 20, 20, 0, -5 ] );

		$queue = get_option( 'fe_search_ai_sync_queue', [] );
		$this->assertCount( 2, $queue, 'Only valid unique post IDs should be queued' );
		$this->assertArrayHasKey( 10, $queue );
		$this->assertArrayHasKey( 20, $queue );
		$this->assertTrue(
			$this->has_scheduled_queue_event(),
			'A queue processing cron event should be scheduled'
		);
	}

	/**
	 * Test sync_imported_posts_batch queues the IDs provided by the importer.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_sync_imported_posts_batch_enqueues_posts() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$sync_hooks->sync_imported_posts_batch( [ 30, 40 ] );

		$queue = get_option( 'fe_search_ai_sync_queue', [] );
		$this->assertArrayHasKey( 30, $queue );
		$this->assertArrayHasKey( 40, $queue );
	}

	/**
	 * Test the import batch hook is only subscribed when import_sync is on.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_import_batch_hook_registered_only_when_enabled() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );

		$disabled_hooks = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );
		$this->assertFalse(
			has_action( 'fe_csv_import_export_import_batch_completed', [ $disabled_hooks, 'sync_imported_posts_batch' ] ),
			'Import batch hook should not be registered when import_sync is disabled'
		);

		update_option(
			'fe_search_ai_settings',
			[
				'sync' => [
					'import_sync' => true,
				],
			]
		);

		$enabled_hooks = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );
		$this->assertNotFalse(
			has_action( 'fe_csv_import_export_import_batch_completed', [ $enabled_hooks, 'sync_imported_posts_batch' ] ),
			'Import batch hook should be registered when import_sync is enabled'
		);

		remove_action( 'fe_csv_import_export_import_batch_completed', [ $enabled_hooks, 'sync_imported_posts_batch' ] );
	}

	/**
	 * Test index_post never chunks a password-protected post
	 *
	 * @since 1.4.1
	 * @return void
	 */
	public function test_index_post_skips_password_protected_post() {
		update_option(
			'fe_search_ai_settings',
			[
				'sync' => [
					'targets' => [
						'post' => [ 'enabled' => true ],
					],
				],
			]
		);

		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$sync_handler->expects( $this->never() )->method( 'create_chunks_from_post' );
		$sync_hooks = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$post_id = wp_insert_post(
			[
				'post_title'    => 'test_index_skip_protected',
				'post_content'  => 'Secret content',
				'post_status'   => 'publish',
				'post_password' => 'secret-pass',
			]
		);

		$sync_hooks->index_post( $post_id );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test index_post mirrors vectors to Qdrant when the Qdrant store is enabled.
	 *
	 * Stale points for the post must be deleted before the upsert so chunks
	 * left by a longer previous version do not linger.
	 *
	 * @since 1.4.1
	 * @return void
	 */
	public function test_index_post_mirrors_to_qdrant_when_enabled() {
		update_option(
			'fe_search_ai_settings',
			[
				'provider' => [ 'embedding' => 'openai' ],
				'sync'     => [
					'targets' => [
						'post' => [ 'enabled' => true ],
					],
				],
				'vector'   => [
					'store'  => 'qdrant',
					'qdrant' => [
						'endpoint'   => 'https://qdrant.example.test',
						'collection' => 'feai_test',
						'api_key'    => \FESearchAI\Core\FE_Search_AI_Encryption_Helper::encrypt( 'test-qdrant-key' ),
					],
				],
			]
		);

		$sync_handler = new \FESearchAI\Ajax\FE_Search_AI_Sync_Handler();
		$sync_hooks   = new \FESearchAI\Core\FE_Search_AI_Sync_Hooks( $sync_handler );

		$embedding_filter = function () {
			return [
				'data'            => [ [ 'embedding' => [ 0.1, 0.2, 0.3 ] ] ],
				'embedding_model' => 'test-embedding',
				'embedding_dim'   => 3,
			];
		};
		add_filter( 'fe_search_ai_embedding_result_for_openai', $embedding_filter );

		$requests    = [];
		$http_filter = function ( $preempt, $args, $url ) use ( &$requests ) {
			$requests[] = [
				'method' => $args['method'] ?? 'POST',
				'url'    => $url,
				'body'   => $args['body'] ?? '',
			];
			return [
				'headers'  => [],
				'body'     => '{"result":true}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $http_filter, 10, 3 );

		$post_id = wp_insert_post(
			[
				'post_title'   => 'test_qdrant_mirror',
				'post_content' => 'Qdrant mirror test content.',
				'post_status'  => 'publish',
			]
		);

		try {
			$sync_hooks->index_post( $post_id );

			$delete_calls = array_filter(
				$requests,
				function ( $request ) {
					return false !== strpos( $request['url'], '/points/delete' );
				}
			);
			$upsert_calls = array_filter(
				$requests,
				function ( $request ) {
					return 'PUT' === $request['method'] && false !== strpos( $request['url'], '/points' );
				}
			);

			$this->assertNotEmpty( $delete_calls, 'Existing points should be deleted before upsert' );
			$this->assertNotEmpty( $upsert_calls, 'Vectors should be upserted to Qdrant' );

			$delete_body = json_decode( array_values( $delete_calls )[0]['body'], true );
			$this->assertContains(
				[ 'key' => 'post_id', 'match' => [ 'value' => $post_id ] ],
				$delete_body['filter']['must'] ?? [],
				'Delete request should target the post_id payload field'
			);

			$upsert_body = json_decode( array_values( $upsert_calls )[0]['body'], true );
			$this->assertSame( $post_id * 10000, $upsert_body['points'][0]['id'] ?? 0, 'Point ID should be derived from post ID' );
			$this->assertSame( $post_id, $upsert_body['points'][0]['payload']['post_id'] ?? 0, 'Payload should carry the post ID' );
		} finally {
			remove_filter( 'pre_http_request', $http_filter, 10 );
			remove_filter( 'fe_search_ai_embedding_result_for_openai', $embedding_filter );
			wp_delete_post( $post_id, true );
			delete_option( 'fe_search_ai_settings' );
		}
	}
}
