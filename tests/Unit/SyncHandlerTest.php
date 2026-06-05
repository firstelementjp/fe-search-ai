<?php
/**
 * Unit tests for FE Search AI Sync Handler
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Sync Handler functionality tests
 *
 * @since 1.0.0
 */
class SyncHandlerTest extends TestCase {

	/**
	 * Test that sync handler class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_sync_handler_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' ), 'Sync Handler class should exist' );
	}

	/**
	 * Test that key methods exist
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_key_methods_exist() {
		$handler = new \FESearchAI\Ajax\FE_Search_AI_Sync_Handler();

		$this->assertTrue( method_exists( $handler, 'ajax_start_sync' ), 'ajax_start_sync method should exist' );
		$this->assertTrue( method_exists( $handler, 'ajax_process_batch' ), 'ajax_process_batch method should exist' );
		$this->assertTrue( method_exists( $handler, 'find_similar_chunks' ), 'find_similar_chunks method should exist' );
		$this->assertTrue( method_exists( $handler, 'create_chunks_from_post' ), 'create_chunks_from_post method should exist' );
		$this->assertTrue( method_exists( $handler, 'tokenize_text' ), 'tokenize_text method should exist' );
	}

	/**
	 * Test tokenize_text with empty string
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_tokenize_text_empty_string() {
		$handler = new \FESearchAI\Ajax\FE_Search_AI_Sync_Handler();
		$result  = $handler->tokenize_text( '' );

		$this->assertIsArray( $result, 'Tokenize should return array' );
		$this->assertEmpty( $result, 'Empty string should produce empty array' );
	}

	/**
	 * Test tokenize_text with simple English text
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_tokenize_text_english() {
		// Set locale to English for this test
		add_filter( 'locale', function() {
			return 'en_US';
		} );

		$handler = new \FESearchAI\Ajax\FE_Search_AI_Sync_Handler();
		$result  = $handler->tokenize_text( 'Hello world test' );

		$this->assertIsArray( $result, 'Tokenize should return array' );
		$this->assertNotEmpty( $result, 'Text should produce tokens' );

		remove_all_filters( 'locale' );
	}

	/**
	 * Test tokenize_text removes symbols
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_tokenize_text_removes_symbols() {
		add_filter( 'locale', function() {
			return 'en_US';
		} );

		$handler = new \FESearchAI\Ajax\FE_Search_AI_Sync_Handler();
		$result  = $handler->tokenize_text( 'Hello, world! Test @#$%' );

		$this->assertIsArray( $result, 'Tokenize should return array' );
		// Symbols should be removed
		foreach ( $result as $token ) {
			$this->assertDoesNotMatchRegularExpression( '/[^\p{L}\p{N}]/u', $token, 'Token should not contain symbols' );
		}

		remove_all_filters( 'locale' );
	}

	/**
	 * Test tokenize_text normalizes text
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_tokenize_text_normalizes_case() {
		add_filter( 'locale', function() {
			return 'en_US';
		} );

		$handler = new \FESearchAI\Ajax\FE_Search_AI_Sync_Handler();
		$result  = $handler->tokenize_text( 'HELLO WORLD' );

		$this->assertIsArray( $result, 'Tokenize should return array' );
		// All tokens should be lowercase
		foreach ( $result as $token ) {
			$this->assertEquals( mb_strtolower( $token ), $token, 'Token should be lowercase' );
		}

		remove_all_filters( 'locale' );
	}

	/**
	 * Test prepare_embedding_texts_from_chunks method exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_prepare_embedding_texts_method_exists() {
		$handler = new \FESearchAI\Ajax\FE_Search_AI_Sync_Handler();
		$this->assertTrue( method_exists( $handler, 'prepare_embedding_texts_from_chunks' ), 'prepare_embedding_texts_from_chunks method should exist' );
	}

	/**
	 * Test get_embeddings_via_selected_provider method exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_get_embeddings_method_exists() {
		$handler = new \FESearchAI\Ajax\FE_Search_AI_Sync_Handler();
		$this->assertTrue( method_exists( $handler, 'get_embeddings_via_selected_provider' ), 'get_embeddings_via_selected_provider method should exist' );
	}
}
