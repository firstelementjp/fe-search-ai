<?php
/**
 * Unit tests for FE Search AI Retrieval Trace
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Retrieval trace functionality tests
 *
 * @since 1.0.0
 */
class RetrievalTraceTest extends TestCase {

	/**
	 * Test payload includes scores and excludes raw text fields.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_build_payload_includes_scores_without_raw_text() {
		$chunks = [
			[
				'content_chunk'            => 'Sensitive chunk text',
				'summary_text'             => 'Sensitive summary text',
				'permalink'                => 'https://example.com/test-post',
				'post_id'                  => 123,
				'title'                    => 'Test Post',
				'source'                   => 'qdrant',
				'bm25_score'               => 1.25,
				'bm25_rank'                => 2,
				'qdrant_score'             => 0.91,
				'qdrant_rank'              => 1,
				'hybrid_score'             => 0.03,
				'hybrid_rank'              => 1,
				'cohere_relevance_score'   => 0.98,
				'cohere_rank'              => 1,
				'hybrid_sources'           => [ 'qdrant' => true, 'keyword' => true ],
				'hybrid_rankings'          => [ 'qdrant' => 1, 'keyword' => 2 ],
			],
		];

		$payload = \FESearchAI\Core\FE_Search_AI_Retrieval_Trace::build_payload( $chunks, 'Sensitive question', 'chat_test', [ 'provider' => 'openai' ] );

		$this->assertArrayHasKey( 'trace_id', $payload, 'Trace ID should be included' );
		$this->assertSame( 'chat_test', $payload['sequence_id'], 'Sequence ID should be preserved' );
		$this->assertSame( 1, $payload['candidate_count'], 'Candidate count should match chunks' );
		$this->assertTrue( $payload['pipeline']['bm25'], 'BM25 pipeline flag should be true' );
		$this->assertTrue( $payload['pipeline']['qdrant'], 'Qdrant pipeline flag should be true' );
		$this->assertTrue( $payload['pipeline']['hybrid'], 'Hybrid pipeline flag should be true' );
		$this->assertTrue( $payload['pipeline']['cohere'], 'Cohere pipeline flag should be true' );
		$this->assertArrayNotHasKey( 'question', $payload, 'Raw question should not be included' );
		$this->assertArrayNotHasKey( 'content_chunk', $payload['items'][0], 'Raw chunk content should not be included' );
		$this->assertArrayNotHasKey( 'summary_text', $payload['items'][0], 'Raw summary text should not be included' );
		$this->assertSame( 0.98, $payload['items'][0]['scores']['cohere_relevance_score'], 'Cohere score should be included' );
		$this->assertSame( 1, $payload['items'][0]['ranks']['cohere_rank'], 'Cohere rank should be included' );
		$this->assertSame( 2, $payload['items'][0]['hybrid_rankings']['keyword'], 'Hybrid source ranking should be included' );
		$this->assertSame( 'openai', $payload['metadata']['provider'], 'Caller metadata should be preserved' );
		$this->assertSame( 1, $payload['metadata']['source_counts']['both'], 'Both-source count should be included' );
		$this->assertSame( 0, $payload['metadata']['source_counts']['qdrant'], 'Qdrant-only count should be included' );
		$this->assertSame( 0, $payload['metadata']['source_counts']['keyword'], 'Keyword-only count should be included' );
	}

	/**
	 * Test recorder does not persist by default.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_recorder_does_not_persist_by_default() {
		\FESearchAI\Core\FE_Search_AI_Activator::create_tables();

		$trace = \FESearchAI\Core\FE_Search_AI_Retrieval_Trace::build_payload(
			[
				[
					'content_chunk' => 'Chunk text',
					'post_id'       => 123,
				],
			],
			'Test question',
			'chat_test'
		);

		$result = \FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::record( $trace );

		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . \FESearchAI\Core\FE_Search_AI_Retrieval_Trace_Recorder::traces_table() . '`' );

		$this->assertFalse( $result, 'Recorder should not persist unless explicitly enabled' );
		$this->assertSame( 0, $count, 'Trace table should stay empty by default' );
	}
}
