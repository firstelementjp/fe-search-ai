<?php
/**
 * Unit tests for FE Search AI Cohere Reranker
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Cohere Reranker functionality tests
 *
 * @since 1.0.0
 */
class CohereRerankerTest extends TestCase {

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
	 * Test that cohere reranker class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_cohere_reranker_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Core\FE_Search_AI_Cohere_Reranker' ), 'Cohere Reranker class should exist' );
	}

	/**
	 * Test that key public methods exist
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_key_public_methods_exist() {
		$this->assertTrue( method_exists( 'FESearchAI\Core\FE_Search_AI_Cohere_Reranker', 'register' ), 'register method should exist' );
		$this->assertTrue( method_exists( 'FESearchAI\Core\FE_Search_AI_Cohere_Reranker', 'maybe_rerank_chunks' ), 'maybe_rerank_chunks method should exist' );
	}

	/**
	 * Test maybe_rerank_chunks with empty chunks
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_maybe_rerank_chunks_empty_chunks() {
		$result = \FESearchAI\Core\FE_Search_AI_Cohere_Reranker::maybe_rerank_chunks( [], 'test question' );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEmpty( $result, 'Empty chunks should return empty array' );
	}

	/**
	 * Test maybe_rerank_chunks with non-array input
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_maybe_rerank_chunks_non_array() {
		$result = \FESearchAI\Core\FE_Search_AI_Cohere_Reranker::maybe_rerank_chunks( 'not an array', 'test question' );

		$this->assertEquals( 'not an array', $result, 'Non-array input should be returned as-is' );
	}

	/**
	 * Test maybe_rerank_chunks when rerank is disabled
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_maybe_rerank_chunks_disabled() {
		// Set settings with rerank disabled
		update_option(
			'fe_search_ai_settings',
			[
				'rerank' => [
					'enabled' => false,
					'top_n'   => 5,
				],
			]
		);

		$chunks = [
			[ 'content_chunk' => 'First chunk', 'post_id' => 1 ],
			[ 'content_chunk' => 'Second chunk', 'post_id' => 2 ],
			[ 'content_chunk' => 'Third chunk', 'post_id' => 3 ],
		];

		$result = \FESearchAI\Core\FE_Search_AI_Cohere_Reranker::maybe_rerank_chunks( $chunks, 'test question' );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertCount( 3, $result, 'Should return all chunks when disabled' );
	}

	/**
	 * Test maybe_rerank_chunks with default top_n
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_maybe_rerank_chunks_default_top_n() {
		// Set settings with rerank disabled but no top_n specified
		update_option(
			'fe_search_ai_settings',
			[
				'rerank' => [
					'enabled' => false,
				],
			]
		);

		$chunks = array_fill( 0, 10, [ 'content_chunk' => 'Test chunk', 'post_id' => 1 ] );

		$result = \FESearchAI\Core\FE_Search_AI_Cohere_Reranker::maybe_rerank_chunks( $chunks, 'test question' );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertCount( 5, $result, 'Should return default top_n (5) chunks' );
	}

	/**
	 * Test maybe_rerank_chunks with custom top_n
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_maybe_rerank_chunks_custom_top_n() {
		update_option(
			'fe_search_ai_settings',
			[
				'rerank' => [
					'enabled' => false,
					'top_n'   => 3,
				],
			]
		);

		$chunks = array_fill( 0, 10, [ 'content_chunk' => 'Test chunk', 'post_id' => 1 ] );

		$result = \FESearchAI\Core\FE_Search_AI_Cohere_Reranker::maybe_rerank_chunks( $chunks, 'test question' );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertCount( 3, $result, 'Should return custom top_n (3) chunks' );
	}

	/**
	 * Test maybe_rerank_chunks when enabled but no API key
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_maybe_rerank_chunks_enabled_no_api_key() {
		update_option(
			'fe_search_ai_settings',
			[
				'rerank' => [
					'enabled' => true,
					'top_n'   => 5,
				],
				'provider' => [
					'cohere_key' => '',
				],
			]
		);

		$chunks = [
			[ 'content_chunk' => 'First chunk', 'post_id' => 1 ],
			[ 'content_chunk' => 'Second chunk', 'post_id' => 2 ],
		];

		$result = \FESearchAI\Core\FE_Search_AI_Cohere_Reranker::maybe_rerank_chunks( $chunks, 'test question' );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertCount( 2, $result, 'Should return trimmed chunks when API key is missing' );
	}

	/**
	 * Test register method is callable
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_register_callable() {
		$this->assertTrue( is_callable( [ 'FESearchAI\Core\FE_Search_AI_Cohere_Reranker', 'register' ] ), 'register should be callable' );
	}

	/**
	 * Test maybe_rerank_chunks method is callable
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_maybe_rerank_chunks_callable() {
		$this->assertTrue( is_callable( [ 'FESearchAI\Core\FE_Search_AI_Cohere_Reranker', 'maybe_rerank_chunks' ] ), 'maybe_rerank_chunks should be callable' );
	}
}
