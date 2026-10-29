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
		remove_all_filters( 'fe_search_ai_retrieval_decision' );
		remove_all_filters( 'fe_search_ai_small_talk_patterns' );
		remove_all_filters( 'fe_search_ai_clarify_instruction' );
		remove_all_filters( 'fe_search_ai_entity_max_doc_ratio' );
		remove_all_filters( 'fe_search_ai_entity_document_stats' );
	}

	/**
	 * Disable the document-frequency check by default so extraction tests do
	 * not depend on the test database's keyword index contents.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter(
			'fe_search_ai_entity_document_stats',
			function () {
				return [ 'total_chunks' => 0, 'frequencies' => [] ];
			}
		);
	}

	/**
	 * Re-enables the document-frequency check with injected statistics.
	 *
	 * @since 1.5.0
	 * @param array $stats Stats map: total_chunks and frequencies.
	 * @return void
	 */
	private function inject_document_stats( array $stats ) {
		remove_all_filters( 'fe_search_ai_entity_document_stats' );
		add_filter(
			'fe_search_ai_entity_document_stats',
			function () use ( $stats ) {
				return $stats;
			}
		);
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
	 * Test entity stop terms are loaded from the i18n files for every
	 * language, not only the site locale.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_entity_stop_terms_loaded_from_i18n() {
		// Japanese default term.
		$this->assertSame( [], FE_Search_AI_Contextual_Retrieval::extract_entities( 'プラグイン' ) );
		// English default term merges regardless of site locale.
		$this->assertSame( [], FE_Search_AI_Contextual_Retrieval::extract_entities( 'Version' ) );
	}

	/**
	 * Test custom entity stop terms can be added via the filter, matching
	 * how Pro merges the site's custom stop words.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_custom_entity_stop_terms_via_filter() {
		add_filter(
			'fe_search_ai_contextual_query_stop_terms',
			function ( $terms ) {
				$terms[] = '中途採用';
				return $terms;
			}
		);
		$entities = FE_Search_AI_Contextual_Retrieval::extract_entities( '中途採用と店頭販売について' );
		$this->assertNotContains( '中途採用', $entities );
		$this->assertContains( '店頭販売', $entities );
	}

	/**
	 * Test ubiquitous terms are dropped by the document-frequency check.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_common_terms_dropped_by_doc_ratio() {
		$this->inject_document_stats(
			[
				'total_chunks' => 10,
				'frequencies'  => [
					'ベクトル' => 9,
					'店頭販売' => 2,
				],
			]
		);
		$entities = FE_Search_AI_Contextual_Retrieval::extract_entities( 'ベクトル検索と店頭販売' );
		$this->assertNotContains( 'ベクトル', $entities );
		$this->assertContains( '店頭販売', $entities );
	}

	/**
	 * Test a multi-word candidate survives when its rarest token is
	 * discriminating even though other tokens are common.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_phrase_survives_when_rarest_token_is_rare() {
		$this->inject_document_stats(
			[
				'total_chunks' => 10,
				'frequencies'  => [
					'fe'     => 1,
					'search' => 6,
					'ai'     => 8,
					'pro'    => 5,
				],
			]
		);
		$entities = FE_Search_AI_Contextual_Retrieval::extract_entities( 'FE Search AI Pro' );
		$this->assertContains( 'FE Search AI Pro', $entities );
	}

	/**
	 * Test the document-frequency check is disabled by a non-positive ratio.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_doc_ratio_check_disabled_with_zero() {
		add_filter( 'fe_search_ai_entity_max_doc_ratio', '__return_zero' );
		$this->inject_document_stats(
			[
				'total_chunks' => 10,
				'frequencies'  => [ 'ベクトル' => 10 ],
			]
		);
		$entities = FE_Search_AI_Contextual_Retrieval::extract_entities( 'ベクトル検索' );
		$this->assertContains( 'ベクトル', $entities );
	}

	/**
	 * Test terms unknown to the index are kept as entities.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_unknown_terms_survive_doc_ratio() {
		$this->inject_document_stats(
			[
				'total_chunks' => 10,
				'frequencies'  => [ 'ベクトル' => 9 ],
			]
		);
		$entities = FE_Search_AI_Contextual_Retrieval::extract_entities( '店頭販売' );
		$this->assertContains( '店頭販売', $entities );
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

	/**
	 * Test that a Japanese greeting is decided as answer-direct.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_decide_greeting_returns_answer() {
		$this->assertSame(
			FE_Search_AI_Contextual_Retrieval::DECISION_ANSWER,
			FE_Search_AI_Contextual_Retrieval::decide( 'こんにちは！', [], [] )
		);
		$this->assertSame(
			FE_Search_AI_Contextual_Retrieval::DECISION_ANSWER,
			FE_Search_AI_Contextual_Retrieval::decide( 'hello', [], [] )
		);
		$this->assertSame(
			FE_Search_AI_Contextual_Retrieval::DECISION_ANSWER,
			FE_Search_AI_Contextual_Retrieval::decide( 'ありがとうございます', [], [] )
		);
	}

	/**
	 * Test that an underspecified first question is decided as clarify.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_decide_vague_question_returns_clarify() {
		$this->assertSame(
			FE_Search_AI_Contextual_Retrieval::DECISION_CLARIFY,
			FE_Search_AI_Contextual_Retrieval::decide( '使い方を教えて', [], [] )
		);
	}

	/**
	 * Test that a question containing an entity is decided as retrieve.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_decide_entity_question_returns_retrieve() {
		$this->assertSame(
			FE_Search_AI_Contextual_Retrieval::DECISION_RETRIEVE,
			FE_Search_AI_Contextual_Retrieval::decide( 'FE Search AI Proの価格は？', [], [] )
		);
	}

	/**
	 * Test that a vague follow-up retrieves when history has entities.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_decide_vague_followup_with_history_retrieves() {
		$history = [
			[ 'role' => 'user', 'content' => 'FE Search AI Proは年額いくらですか？' ],
			[ 'role' => 'assistant', 'content' => 'FE Search AI Proは年額17,800円です。' ],
		];
		$this->assertSame(
			FE_Search_AI_Contextual_Retrieval::DECISION_RETRIEVE,
			FE_Search_AI_Contextual_Retrieval::decide( 'もっと安いプランはないの？', $history, [] )
		);
	}

	/**
	 * Test that a vague reply after a clarifying question is not re-clarified.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_decide_vague_answer_after_clarify_retrieves() {
		$history = [
			[ 'role' => 'user', 'content' => '使い方を教えて' ],
			[ 'role' => 'assistant', 'content' => 'どの製品についてお知りになりたいですか？' ],
		];
		$this->assertSame(
			FE_Search_AI_Contextual_Retrieval::DECISION_RETRIEVE,
			FE_Search_AI_Contextual_Retrieval::decide( 'どれでもいいよ', $history, [] )
		);
	}

	/**
	 * Test that the decision filter can override the heuristic result.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_decision_filter_overrides_heuristic() {
		add_filter(
			'fe_search_ai_retrieval_decision',
			function () {
				return 'answer';
			}
		);
		$this->assertSame(
			FE_Search_AI_Contextual_Retrieval::DECISION_ANSWER,
			FE_Search_AI_Contextual_Retrieval::decide( 'FE Search AI Proの価格は？', [], [] )
		);
	}

	/**
	 * Test that an invalid filtered decision falls back to retrieve.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_decision_filter_invalid_value_falls_back() {
		add_filter(
			'fe_search_ai_retrieval_decision',
			function () {
				return 'bogus';
			}
		);
		$this->assertSame(
			FE_Search_AI_Contextual_Retrieval::DECISION_RETRIEVE,
			FE_Search_AI_Contextual_Retrieval::decide( 'こんにちは', [], [] )
		);
	}

	/**
	 * Test small talk detection handles punctuation and composite phrases.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_is_small_talk() {
		$this->assertTrue( FE_Search_AI_Contextual_Retrieval::is_small_talk( 'こんにちは！' ) );
		$this->assertTrue( FE_Search_AI_Contextual_Retrieval::is_small_talk( 'thank you.' ) );
		$this->assertFalse( FE_Search_AI_Contextual_Retrieval::is_small_talk( 'こんにちは、料金を教えて' ) );
		$this->assertFalse( FE_Search_AI_Contextual_Retrieval::is_small_talk( '使い方を教えて' ) );
	}

	/**
	 * Test the clarify instruction is a non-empty filterable string.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function test_clarify_instruction_is_filterable() {
		$this->assertNotEmpty( FE_Search_AI_Contextual_Retrieval::get_clarify_instruction() );
		add_filter(
			'fe_search_ai_clarify_instruction',
			function () {
				return 'CUSTOM';
			}
		);
		$this->assertSame( 'CUSTOM', FE_Search_AI_Contextual_Retrieval::get_clarify_instruction() );
	}
}
