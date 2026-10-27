<?php
/**
 * Unit tests for FE_Search_AI_Contextual_Retrieval
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;
use FESearchAI\Core\FE_Search_AI_Contextual_Retrieval;

/**
 * Contextual retrieval functionality tests
 *
 * @since 1.5.0
 */
class ContextualRetrievalTest extends TestCase {

	/**
	 * Clean up filters after each test.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'fe_search_ai_contextual_query_enabled' );
		remove_all_filters( 'fe_search_ai_contextual_query_max_entities' );
		remove_all_filters( 'fe_search_ai_contextual_query_history_messages' );
		remove_all_filters( 'fe_search_ai_contextual_query_stop_terms' );
		remove_all_filters( 'fe_search_ai_contextual_retrieval_query' );
		remove_all_filters( 'fe_search_ai_max_chunks_per_post' );
	}

	/**
	 * Test that the original question is returned when history is empty.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_empty_history_returns_original_question() {
		$question = 'もっと安いプランはないの？';
		$this->assertSame(
			$question,
			FE_Search_AI_Contextual_Retrieval::build_retrieval_query( $question, [], [] )
		);
	}

	/**
	 * Test that expansion can be disabled via the settings array.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_disabled_setting_returns_original_question() {
		$question = 'もっと安いプランはないの？';
		$history  = [
			[ 'role' => 'user', 'content' => 'FE Search AI Proは年額いくらですか？' ],
		];
		$settings = [ 'advanced' => [ 'contextual_query' => false ] ];
		$this->assertSame(
			$question,
			FE_Search_AI_Contextual_Retrieval::build_retrieval_query( $question, $history, $settings )
		);
	}

	/**
	 * Test that the enabled filter can force-disable expansion.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_enabled_filter_overrides_default() {
		add_filter( 'fe_search_ai_contextual_query_enabled', '__return_false' );
		$question = 'もっと安いプランはないの？';
		$history  = [
			[ 'role' => 'user', 'content' => 'FE Search AI Proは年額いくらですか？' ],
		];
		$this->assertSame(
			$question,
			FE_Search_AI_Contextual_Retrieval::build_retrieval_query( $question, $history, [] )
		);
	}

	/**
	 * Test that missing history entities are appended to the query.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_entities_from_history_are_appended() {
		$question = 'もっと安いプランはないの？';
		$history  = [
			[ 'role' => 'user', 'content' => 'FE Search AI Proは年額いくらですか？' ],
			[ 'role' => 'assistant', 'content' => 'FE Search AI Proは年額17,800円です。' ],
		];
		$result = FE_Search_AI_Contextual_Retrieval::build_retrieval_query( $question, $history, [] );
		$this->assertStringStartsWith( $question, $result );
		$this->assertStringContainsString( 'FE Search AI Pro', $result );
	}

	/**
	 * Test that entities already present in the question are not duplicated.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_entity_already_in_question_is_not_duplicated() {
		$question = 'FE Search AI Proに安いプランはないの？';
		$history  = [
			[ 'role' => 'user', 'content' => 'FE Search AI Proは年額いくらですか？' ],
		];
		$result = FE_Search_AI_Contextual_Retrieval::build_retrieval_query( $question, $history, [] );
		$this->assertSame( 1, substr_count( $result, 'FE Search AI Pro' ) );
	}

	/**
	 * Test that the max entities filter limits appended terms.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_max_entities_filter_limits_appended_terms() {
		add_filter(
			'fe_search_ai_contextual_query_max_entities',
			function () {
				return 1;
			}
		);
		$question = '他に選択肢はある？';
		$history  = [
			[ 'role' => 'user', 'content' => 'Qdrant と Cohere Rerank の違いを教えて' ],
		];
		$result = FE_Search_AI_Contextual_Retrieval::build_retrieval_query( $question, $history, [] );
		// Only the single highest-scoring entity (longest on tie) is appended.
		$this->assertStringContainsString( 'Cohere Rerank', $result );
		$this->assertStringNotContainsString( 'Qdrant', $result );
	}

	/**
	 * Test extract_entities picks up Latin phrases with capitals.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_extract_entities_finds_latin_phrase() {
		$entities = FE_Search_AI_Contextual_Retrieval::extract_entities( 'the FE Search AI Pro is a plugin' );
		$this->assertContains( 'FE Search AI Pro', $entities );
	}

	/**
	 * Test extract_entities skips lowercase English fragments.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_extract_entities_skips_lowercase_english() {
		$entities = FE_Search_AI_Contextual_Retrieval::extract_entities( 'the answer is documented below' );
		$this->assertSame( [], $entities );
	}

	/**
	 * Test extract_entities picks up katakana runs and kanji compounds.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_extract_entities_finds_japanese_terms() {
		$entities = FE_Search_AI_Contextual_Retrieval::extract_entities( 'ベクトル検索と店頭販売の設定について' );
		$this->assertContains( 'ベクトル', $entities );
		$this->assertContains( '店頭販売', $entities );
	}

	/**
	 * Test extract_entities applies the stop term list.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_extract_entities_applies_stop_terms() {
		$entities = FE_Search_AI_Contextual_Retrieval::extract_entities( 'プランとプラグインについて' );
		$this->assertNotContains( 'プラン', $entities );
		$this->assertNotContains( 'プラグイン', $entities );
	}

	/**
	 * Test extract_entities picks up quoted spans.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_extract_entities_finds_quoted_terms() {
		$entities = FE_Search_AI_Contextual_Retrieval::extract_entities( '「インデックスを再構築」をクリックしてください' );
		$this->assertContains( 'インデックスを再構築', $entities );
	}

	/**
	 * Test per-post cap keeps at most the default number of chunks.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_limit_chunks_per_post_caps_at_default() {
		$chunks = [
			[ 'post_id' => 1, 'id' => 'a' ],
			[ 'post_id' => 1, 'id' => 'b' ],
			[ 'post_id' => 1, 'id' => 'c' ],
			[ 'post_id' => 2, 'id' => 'd' ],
			[ 'post_id' => 2, 'id' => 'e' ],
		];
		$result = FE_Search_AI_Contextual_Retrieval::limit_chunks_per_post( $chunks, 'q' );
		$this->assertCount( 4, $result );
		$this->assertSame( [ 'a', 'b', 'd', 'e' ], array_column( $result, 'id' ) );
	}

	/**
	 * Test per-post cap can be adjusted via filter.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_limit_chunks_per_post_filter_adjusts_cap() {
		add_filter(
			'fe_search_ai_max_chunks_per_post',
			function () {
				return 1;
			}
		);
		$chunks = [
			[ 'post_id' => 1, 'id' => 'a' ],
			[ 'post_id' => 1, 'id' => 'b' ],
			[ 'post_id' => 2, 'id' => 'c' ],
		];
		$result = FE_Search_AI_Contextual_Retrieval::limit_chunks_per_post( $chunks, 'q' );
		$this->assertSame( [ 'a', 'c' ], array_column( $result, 'id' ) );
	}

	/**
	 * Test per-post cap is disabled with a non-positive value.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_limit_chunks_per_post_disabled_with_zero() {
		add_filter( 'fe_search_ai_max_chunks_per_post', '__return_zero' );
		$chunks = [
			[ 'post_id' => 1 ],
			[ 'post_id' => 1 ],
			[ 'post_id' => 1 ],
		];
		$result = FE_Search_AI_Contextual_Retrieval::limit_chunks_per_post( $chunks, 'q' );
		$this->assertCount( 3, $result );
	}

	/**
	 * Test per-post cap keeps chunks without post_id untouched.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_limit_chunks_per_post_keeps_unidentified_chunks() {
		$chunks = [
			[ 'post_id' => 1, 'id' => 'a' ],
			[ 'post_id' => 1, 'id' => 'b' ],
			[ 'post_id' => 1, 'id' => 'c' ],
			[ 'id' => 'external' ],
		];
		$result = FE_Search_AI_Contextual_Retrieval::limit_chunks_per_post( $chunks, 'q' );
		$this->assertSame( [ 'a', 'b', 'external' ], array_column( $result, 'id' ) );
	}
}
