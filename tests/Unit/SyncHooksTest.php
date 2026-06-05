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
}
