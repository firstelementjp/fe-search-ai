<?php
/**
 * Contextual retrieval helpers.
 *
 * Improves retrieval quality for conversational queries in two ways:
 *
 * 1. Contextual query expansion: follow-up questions like "Is there a cheaper
 *    plan?" lose their subject. This class deterministically extracts entity
 *    candidates (Latin phrases, quoted terms, katakana runs, long kanji
 *    compounds) from recent conversation history and appends the missing ones
 *    to the retrieval query. The original question is still used for the LLM
 *    prompt; only the retrieval query is expanded.
 *
 * 2. Per-post chunk cap: limits how many chunks from a single post may occupy
 *    the candidate set fed to the reranker / prompt, so one irrelevant post
 *    cannot dominate the context window.
 *
 * @package    fe-search-ai
 * @subpackage Core
 */

namespace FESearchAI\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contextual retrieval helpers.
 *
 * @package fe-search-ai
 * @since 1.5.0
 */
class FE_Search_AI_Contextual_Retrieval {

	/**
	 * Maximum number of entity terms appended to the retrieval query.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const DEFAULT_MAX_ENTITIES = 3;

	/**
	 * Number of recent history messages scanned for entity candidates.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const DEFAULT_HISTORY_MESSAGES = 6;

	/**
	 * Maximum chunks kept per post in the retrieved candidate set.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const DEFAULT_MAX_CHUNKS_PER_POST = 2;

	/**
	 * Decision: run the normal retrieval pipeline.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const DECISION_RETRIEVE = 'retrieve';

	/**
	 * Decision: skip retrieval and answer directly (small talk).
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const DECISION_ANSWER = 'answer';

	/**
	 * Decision: skip retrieval and ask the visitor to clarify the topic.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const DECISION_CLARIFY = 'clarify';

	/**
	 * Returns the small talk phrase patterns merged from all i18n files.
	 *
	 * Each `includes/i18n/{locale}.php` file may declare a `small_talk` list
	 * of regular-expression alternatives. Patterns from every language are
	 * merged so a visitor's greeting is detected regardless of the site
	 * locale — mirroring how `injection_phrases` are combined.
	 *
	 * @since 1.5.0
	 * @return string[] Regex alternatives for small talk detection.
	 */
	private static function get_small_talk_patterns() {
		$patterns = [];
		$i18n_dir = FE_SEARCH_AI_PLUGIN_DIR . 'includes/i18n/';
		$files    = glob( $i18n_dir . '*.php' );

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$lang_data = include $file;
				if ( ! empty( $lang_data['small_talk'] ) && is_array( $lang_data['small_talk'] ) ) {
					$patterns = array_merge( $patterns, $lang_data['small_talk'] );
				}
			}
		}

		return array_values( array_unique( array_map( 'strval', $patterns ) ) );
	}

	/**
	 * Default maximum document-frequency ratio for entity candidates.
	 *
	 * A candidate whose rarest lookup token appears in more than this
	 * fraction of indexed chunks is treated as ubiquitous site vocabulary
	 * rather than a topic anchor.
	 *
	 * @since 1.5.0
	 * @var float
	 */
	const DEFAULT_ENTITY_MAX_DOC_RATIO = 0.5;

	/**
	 * Returns the entity stop terms merged from all i18n files.
	 *
	 * Each `includes/i18n/{locale}.php` file may declare an
	 * `entity_stop_terms` list of generic terms that carry little entity
	 * value on their own ("plan", "service", "plugin" ...). Lists from every
	 * language are merged so visitor text is filtered regardless of the site
	 * locale, mirroring `small_talk` / `injection_phrases`.
	 *
	 * @since 1.5.0
	 * @return string[] Generic terms excluded from entity candidates.
	 */
	private static function get_entity_stop_terms() {
		$terms    = [];
		$i18n_dir = FE_SEARCH_AI_PLUGIN_DIR . 'includes/i18n/';
		$files    = glob( $i18n_dir . '*.php' );

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$lang_data = include $file;
				if ( ! empty( $lang_data['entity_stop_terms'] ) && is_array( $lang_data['entity_stop_terms'] ) ) {
					$terms = array_merge( $terms, $lang_data['entity_stop_terms'] );
				}
			}
		}

		return array_values( array_unique( array_map( 'strval', $terms ) ) );
	}

	/**
	 * Register filter hooks.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function register() {
		add_filter( 'fe_search_ai_retrieved_chunks', [ __CLASS__, 'limit_chunks_per_post' ], 10, 2 );
	}

	/**
	 * Whether contextual query expansion is enabled.
	 *
	 * Defaults to enabled; the `advanced.contextual_query` setting can disable
	 * it, and the `fe_search_ai_contextual_query_enabled` filter overrides both.
	 *
	 * @since 1.5.0
	 * @param array $settings Plugin settings array (fe_search_ai_settings).
	 * @return bool
	 */
	public static function is_contextual_query_enabled( array $settings ) {
		$advanced      = isset( $settings['advanced'] ) && is_array( $settings['advanced'] ) ? $settings['advanced'] : [];
		$default_value = ! isset( $advanced['contextual_query'] ) || ! empty( $advanced['contextual_query'] );

		/**
		 * Filters whether contextual query expansion is enabled.
		 *
		 * @since 1.5.0
		 *
		 * @param bool  $default_value Effective value after the setting is read.
		 * @param array $settings      Plugin settings array.
		 */
		return (bool) apply_filters( 'fe_search_ai_contextual_query_enabled', $default_value, $settings );
	}

	/**
	 * Builds the retrieval query for a conversational question.
	 *
	 * Extracts entity candidates from recent history and appends those missing
	 * from the question so context-dependent follow-ups still anchor retrieval
	 * to the topic under discussion. Returns the original question unchanged
	 * when disabled, when history is empty, or when no new entities are found.
	 *
	 * @since 1.5.0
	 * @param string $question Sanitized user question.
	 * @param array  $history  Sanitized conversation history (role/content pairs).
	 * @param array  $settings Plugin settings array.
	 * @return string The query to use for context retrieval.
	 */
	public static function build_retrieval_query( $question, array $history, array $settings ) {
		$question  = (string) $question;
		$entities  = [];
		$retrieval = $question;

		if ( '' !== $question && ! empty( $history ) && self::is_contextual_query_enabled( $settings ) ) {

			/**
			 * Filters how many recent history messages are scanned for entities.
			 *
			 * @since 1.5.0
			 *
			 * @param int    $messages Default message count (6).
			 * @param string $question User question.
			 */
			$message_limit = (int) apply_filters( 'fe_search_ai_contextual_query_history_messages', self::DEFAULT_HISTORY_MESSAGES, $question );
			if ( $message_limit <= 0 ) {
				$message_limit = self::DEFAULT_HISTORY_MESSAGES;
			}

			$question_has_entities = ! empty( self::extract_entities( $question ) );
			$entity_pool           = self::collect_history_entities( array_slice( $history, -$message_limit ) );
			$scored                = [];
			foreach ( $entity_pool as $key => $entry ) {
				// Entities that only appear in assistant questions are "offered"
				// topics (clarify suggestions). They anchor the query only when
				// the user's question brings no entity of its own — a vague
				// reply like "教えて" means accepting the offer, while a
				// question carrying its own entity is a new direction the
				// suggestions must not contaminate.
				$offered_only = 0 === $entry['user'] && 0 === $entry['assistant'] && $entry['offered'] > 0;
				if ( $question_has_entities && $offered_only ) {
					continue;
				}
				$scored[ $key ] = [
					'term'   => $entry['term'],
					'score'  => ( 2 * $entry['user'] ) + $entry['assistant'] + $entry['offered'],
					'length' => $entry['length'],
				];
			}

			// An entity-free question ("教えて", "もっと安いのは？") carries
			// little retrieval signal on its own, so the most recent substantive
			// user message is appended as the query base. Pure filler replies
			// ("特に希望なし") are dropped entirely: their tokens ("特に",
			// "希望", "なし") index-match generic content and would pull the
			// expanded query away from the topic under discussion.
			$base = $question;
			if ( ! $question_has_entities ) {
				$last_user = self::last_user_message_text( array_slice( $history, -$message_limit ) );
				if ( '' !== $last_user ) {
					$base = self::question_is_filler( $question )
						? $last_user
						: trim( $question . ' ' . $last_user );
				}
			}

			// Drop entities already present in the question.
			foreach ( array_keys( $scored ) as $key ) {
				if ( false !== mb_stripos( $question, $scored[ $key ]['term'] ) ) {
					unset( $scored[ $key ] );
				}
			}

			if ( ! empty( $scored ) ) {
				usort(
					$scored,
					function ( $a, $b ) {
						if ( $a['score'] !== $b['score'] ) {
							return $b['score'] - $a['score'];
						}
						return $b['length'] - $a['length'];
					}
				);

				/**
				 * Filters the maximum number of entity terms appended to the query.
				 *
				 * @since 1.5.0
				 *
				 * @param int    $max_entities Default maximum (3).
				 * @param string $question     User question.
				 */
				$max_entities = (int) apply_filters( 'fe_search_ai_contextual_query_max_entities', self::DEFAULT_MAX_ENTITIES, $question );
				if ( $max_entities < 0 ) {
					$max_entities = self::DEFAULT_MAX_ENTITIES;
				}

				if ( $max_entities > 0 ) {
					$entities  = array_map(
						function ( $item ) {
							return $item['term'];
						},
						array_slice( $scored, 0, $max_entities )
					);
					$retrieval = trim( $base . ' ' . implode( ' ', $entities ) );
				} else {
					$retrieval = $base;
				}
			} else {
				$retrieval = $base;
			}
		}

		/**
		 * Filters the expanded retrieval query before it is used for search.
		 *
		 * Fires on every retrieve decision — including when no entities were
		 * appended or contextual expansion is disabled — so model-based
		 * rewriters (e.g. the Pro LLM query expansion) can replace the
		 * deterministic result unconditionally.
		 *
		 * @since 1.5.0
		 *
		 * @param string   $retrieval Expanded query (or the original question).
		 * @param string   $question  Original user question.
		 * @param string[] $entities  Entity terms that were appended.
		 * @param array    $history   Sanitized conversation history.
		 */
		return (string) apply_filters( 'fe_search_ai_contextual_retrieval_query', $retrieval, $question, $entities, $history );
	}

	/**
	 * Decides how a question should be handled before retrieval runs.
	 *
	 * Heuristic order:
	 * 1. Small talk (greetings, thanks, acknowledgements) -> answer directly.
	 * 2. No entity candidates in the question or recent history -> ask the
	 *    visitor to clarify what they want, unless the previous assistant turn
	 *    was already a clarifying question.
	 * 3. Everything else -> run retrieval.
	 *
	 * The `fe_search_ai_retrieval_decision` filter can override the result,
	 * e.g. to plug in a model-based decision such as TypeSafe's Jev.
	 *
	 * @since 1.5.0
	 * @param string $question Sanitized user question.
	 * @param array  $history  Sanitized conversation history.
	 * @param array  $settings Plugin settings array.
	 * @return string One of the DECISION_* constants, or a custom intent
	 *                slug supplied by the decision filter.
	 */
	public static function decide( $question, array $history, array $settings ) {
		$details = self::decide_with_details( $question, $history, $settings );
		return $details['decision'];
	}

	/**
	 * Decides how a question should be handled, with diagnostic counts.
	 *
	 * Same decision logic as decide() but also returns the entity counts
	 * that drove it, so callers can log safe numeric metadata explaining
	 * why a turn retrieved, answered, or asked for clarification.
	 *
	 * @since 1.5.0
	 * @param string $question Sanitized user question.
	 * @param array  $history  Sanitized conversation history.
	 * @param array  $settings Plugin settings array.
	 * @return array {
	 *     decision, reason (small_talk|entities|clarify_guard|entity_free|
	 *     empty_question|filter_override), heuristic_decision,
	 *     heuristic_reason, question_entity_count, history_entity_count,
	 *     offered_entity_count
	 * }.
	 */
	public static function decide_with_details( $question, array $history, array $settings ) {
		$question          = (string) $question;
		$decision          = self::DECISION_RETRIEVE;
		$reason            = 'empty_question';
		$question_entities = [];
		$history_entities  = [];
		$offered_count     = 0;

		if ( '' !== $question ) {
			if ( self::is_small_talk( $question ) ) {
				$decision = self::DECISION_ANSWER;
				$reason   = 'small_talk';
			} else {
				$question_entities = self::extract_entities( $question );
				$entity_pool       = self::collect_history_entities( array_slice( $history, -self::DEFAULT_HISTORY_MESSAGES ) );
				$history_entities  = array_values(
					array_map(
						function ( $entry ) {
							return $entry['term'];
						},
						$entity_pool
					)
				);
				$offered_count     = count(
					array_filter(
						$entity_pool,
						function ( $entry ) {
							return 0 === $entry['user'] && 0 === $entry['assistant'] && $entry['offered'] > 0;
						}
					)
				);
				if ( ! empty( $question_entities ) || ! empty( $history_entities ) ) {
					$reason = 'entities';
				} elseif ( self::last_assistant_was_clarifying( $history ) ) {
					// Entity-free reply to an assistant question is treated as
					// an answer to that question, not a new vague topic.
					$reason = 'clarify_guard';
				} else {
					$decision = self::DECISION_CLARIFY;
					$reason   = 'entity_free';
				}
			}
		}

		/**
		 * Filters the retrieval decision for the current question.
		 *
		 * External decision models (e.g. TypeSafe Jev) can hook here to replace
		 * the heuristic result.
		 *
		 * @since 1.5.0
		 *
		 * @param string $decision One of the DECISION_* constants, or a custom
		 *                         intent slug (e.g. `stats`) when a behavior
		 *                         provider is registered via
		 *                         `fe_search_ai_retrieval_intent_behavior`.
		 * @param string $question Sanitized user question.
		 * @param array  $history  Sanitized conversation history.
		 * @param array  $settings Plugin settings array.
		 * @param array  $details  Heuristic details (reason, entity counts)
		 *                         a decision model can use as input features.
		 */
		$heuristic      = $decision;
		$heuristic_name = $reason;
		$details        = [
			'reason'                => $reason,
			'question_entity_count' => count( $question_entities ),
			'history_entity_count'  => count( $history_entities ),
			'offered_entity_count'  => $offered_count,
		];

		$decision = (string) apply_filters( 'fe_search_ai_retrieval_decision', $decision, $question, $history, $settings, $details );

		// Custom intent slugs pass through so future agentic routes (stats,
		// site_info, ...) can be handled by external behavior providers;
		// anything unparseable still falls back to retrieval.
		if ( ! in_array( $decision, [ self::DECISION_RETRIEVE, self::DECISION_ANSWER, self::DECISION_CLARIFY ], true ) ) {
			$decision = preg_match( '/^[a-z][a-z0-9_]*$/', $decision ) ? $decision : self::DECISION_RETRIEVE;
		}

		if ( $decision !== $heuristic ) {
			$reason = 'filter_override';
		}

		return [
			'decision'              => $decision,
			'reason'                => $reason,
			'heuristic_decision'    => $heuristic,
			'heuristic_reason'      => $heuristic_name,
			'question_entity_count' => count( $question_entities ),
			'history_entity_count'  => count( $history_entities ),
			'offered_entity_count'  => $offered_count,
		];
	}

	/**
	 * Resolves what a decision intent should do in the chat pipeline.
	 *
	 * Built-in intents map to retrieve / answer / clarify behavior. Future
	 * agentic intents (e.g. a `stats` intent returning aggregate counts, or
	 * `site_info` answering from site profile data) can register their own
	 * behavior without touching core code.
	 *
	 * @since 1.5.0
	 * @param string $decision Decision intent slug.
	 * @param string $question Sanitized user question.
	 * @param array  $history  Sanitized conversation history.
	 * @param array  $settings Plugin settings array.
	 * @return array { retrieve: bool, instruction: string }.
	 */
	public static function resolve_intent_behavior( $decision, $question, array $history, array $settings ) {
		// Unknown/custom intents default to retrieval so an unhandled slug
		// degrades to the pre-gating behavior rather than a silent no-context
		// answer.
		$behavior = [
			'retrieve'    => ! in_array( $decision, [ self::DECISION_ANSWER, self::DECISION_CLARIFY ], true ),
			'instruction' => self::DECISION_CLARIFY === $decision ? self::get_clarify_instruction() : '',
		];

		/**
		 * Filters the pipeline behavior for a retrieval decision intent.
		 *
		 * Custom intents returned via `fe_search_ai_retrieval_decision`
		 * (e.g. from an external decision model) can define their behavior
		 * here: whether the retrieval pipeline runs and which per-turn
		 * instruction is appended to the system prompt.
		 *
		 * @since 1.5.0
		 *
		 * @param array  $behavior { retrieve: bool, instruction: string }.
		 * @param string $decision Decision intent slug.
		 * @param string $question Sanitized user question.
		 * @param array  $history  Sanitized conversation history.
		 * @param array  $settings Plugin settings array.
		 */
		$behavior = (array) apply_filters( 'fe_search_ai_retrieval_intent_behavior', $behavior, $decision, $question, $history, $settings );

		return [
			'retrieve'    => ! empty( $behavior['retrieve'] ),
			'instruction' => isset( $behavior['instruction'] ) ? (string) $behavior['instruction'] : '',
		];
	}

	/**
	 * Whether the question is small talk that needs no retrieval context.
	 *
	 * Matches the normalized question (lowercased, punctuation trimmed)
	 * against a list of greeting/thanks/acknowledgement patterns.
	 *
	 * @since 1.5.0
	 * @param string $question Sanitized user question.
	 * @return bool
	 */
	public static function is_small_talk( $question ) {
		$normalized = mb_strtolower( trim( (string) $question ) );
		// Strip trailing punctuation and emoji-ish marks so "こんにちは！" matches.
		$normalized = preg_replace( '/[\s!！?？.。．〜～…、,]+$/u', '', $normalized );
		if ( '' === $normalized ) {
			return false;
		}

		/**
		 * Filters the small talk phrase patterns.
		 *
		 * Each entry is used as a regular-expression alternative and must
		 * match the whole normalized question.
		 *
		 * @since 1.5.0
		 *
		 * @param string[] $patterns Default patterns.
		 * @param string   $question Normalized question text.
		 */
		$patterns = (array) apply_filters( 'fe_search_ai_small_talk_patterns', self::get_small_talk_patterns(), $normalized );
		if ( empty( $patterns ) ) {
			return false;
		}

		return (bool) preg_match( '/^(?:' . implode( '|', $patterns ) . ')$/u', $normalized );
	}

	/**
	 * Returns the instruction appended to the system prompt in clarify mode.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public static function get_clarify_instruction() {
		$instruction = "\n\n## Current Turn Instruction\n" .
			'The user\'s question does not specify what product, page, or topic it refers to. ' .
			'Do NOT attempt to answer it yet. Reply with a short question asking what they would like to know about, ' .
			'and suggest one or two likely topics if helpful.';

		/**
		 * Filters the instruction injected into the system prompt when the
		 * retrieval decision is `clarify`.
		 *
		 * @since 1.5.0
		 *
		 * @param string $instruction Default instruction text.
		 */
		return (string) apply_filters( 'fe_search_ai_clarify_instruction', $instruction );
	}

	/**
	 * Collects history entities with per-source occurrence counts.
	 *
	 * Each entity records how often it appeared in user turns, in
	 * declarative assistant turns, and in question-form assistant turns
	 * ("offered" topics such as clarify suggestions). The distinction lets
	 * callers keep offered entities as anchors for vague replies while
	 * dropping them when the user steers to a new direction.
	 *
	 * @since 1.5.0
	 * @param array $messages History messages to scan (already sliced).
	 * @return array Map of lowercase term => { term, user, assistant, offered, length }.
	 */
	private static function collect_history_entities( array $messages ) {
		$entities = [];
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || empty( $message['content'] ) || ! is_string( $message['content'] ) ) {
				continue;
			}
			$role       = isset( $message['role'] ) ? (string) $message['role'] : '';
			$from_user  = 'user' === $role;
			$from_offer = 'assistant' === $role && self::text_is_question( $message['content'] );
			foreach ( self::extract_entities( $message['content'] ) as $entity ) {
				$key = mb_strtolower( $entity );
				if ( ! isset( $entities[ $key ] ) ) {
					$entities[ $key ] = [
						'term'      => $entity,
						'user'      => 0,
						'assistant' => 0,
						'offered'   => 0,
						'length'    => mb_strlen( $entity ),
					];
				}
				if ( $from_user ) {
					++$entities[ $key ]['user'];
				} elseif ( $from_offer ) {
					++$entities[ $key ]['offered'];
				} else {
					++$entities[ $key ]['assistant'];
				}
			}
		}
		return $entities;
	}

	/**
	 * Whether a text reads as a question.
	 *
	 * Matches interrogative markers anywhere in the message, not only at
	 * the end, so mixed statements like "知りたいですか？たとえば…" still
	 * count as questions.
	 *
	 * @since 1.5.0
	 * @param string $content Message text.
	 * @return bool
	 */
	private static function text_is_question( $content ) {
		return (bool) preg_match( '/[?？]|ですか|ますか|でしょうか|お聞かせ|教えて(?:ください)?/u', (string) $content );
	}

	/**
	 * Whether a question consists only of filler/vague terms.
	 *
	 * Used to drop pure-filler replies ("特に希望なし", "教えて", "うん")
	 * from the expanded retrieval query so their tokens do not pollute
	 * keyword and vector search. A question that retains any content
	 * after filler removal ("もっと安いのは？") keeps contributing to the
	 * query base.
	 *
	 * @since 1.5.0
	 * @param string $question Sanitized user question.
	 * @return bool
	 */
	private static function question_is_filler( $question ) {
		$rest = (string) $question;
		$rest = preg_replace( '/[\s　、。,.!！?？〜~…・「」『』\'"]/u', '', $rest );
		if ( '' === $rest ) {
			return true;
		}

		/**
		 * Filters the filler terms stripped when detecting vague replies.
		 *
		 * @since 1.5.0
		 *
		 * @param string[] $terms    Filler terms.
		 * @param string   $question Original user question.
		 */
		$terms = (array) apply_filters( 'fe_search_ai_contextual_query_weak_reply_terms', self::get_weak_reply_terms(), $question );
		usort(
			$terms,
			function ( $a, $b ) {
				return mb_strlen( (string) $b ) - mb_strlen( (string) $a );
			}
		);
		foreach ( $terms as $term ) {
			$term = (string) $term;
			if ( '' !== $term ) {
				$rest = str_replace( $term, '', $rest );
			}
		}

		// Single leftover characters cannot anchor a search meaningfully.
		return mb_strlen( $rest ) < 2;
	}

	/**
	 * Returns the default filler terms treated as carrying no search intent.
	 *
	 * @since 1.5.0
	 * @return string[]
	 */
	private static function get_weak_reply_terms() {
		return [
			'特に希望なし', '特になし', '特にない', '特に', 'とくに',
			'希望なし', '希望', 'なし', 'ない', '無し', 'ありません',
			'ください', 'お願い', 'おねがい', '頼む', 'たのむ',
			'教えて', 'おしえて', 'お聞かせ',
			'ですか', 'ますか', 'でしょうか', 'でしょう', 'です', 'ます',
			'うん', 'はい', 'いいえ', 'いや', 'ええ', 'そう',
			'何でも', 'なんでも', 'どれでも', 'どちらでも', '全部', 'ぜんぶ', 'すべて',
			'大丈夫', 'だいじょうぶ', '問題ない', '問題なし', 'いい',
			'何', 'なん', 'なに', 'どれ',
		];
	}

	/**
	 * Returns the most recent user message content for query-base expansion.
	 *
	 * @since 1.5.0
	 * @param array $history Sanitized conversation history.
	 * @return string Last user message text (max 200 chars), or ''.
	 */
	private static function last_user_message_text( array $history ) {
		for ( $i = count( $history ) - 1; $i >= 0; --$i ) {
			$message = $history[ $i ];
			if ( ! is_array( $message ) || 'user' !== ( $message['role'] ?? '' ) || empty( $message['content'] ) || ! is_string( $message['content'] ) ) {
				continue;
			}
			return mb_substr( trim( $message['content'] ), 0, 200 );
		}
		return '';
	}

	/**
	 * Whether the last assistant message looks like a clarifying question.
	 *
	 * Used to avoid asking for clarification twice in a row when the visitor
	 * answers with entity-free text such as "うん".
	 *
	 * @since 1.5.0
	 * @param array $history Sanitized conversation history.
	 * @return bool
	 */
	private static function last_assistant_was_clarifying( array $history ) {
		for ( $i = count( $history ) - 1; $i >= 0; --$i ) {
			$message = $history[ $i ];
			if ( ! is_array( $message ) || 'assistant' !== ( $message['role'] ?? '' ) ) {
				continue;
			}
			$content = isset( $message['content'] ) && is_string( $message['content'] ) ? trim( $message['content'] ) : '';
			if ( '' === $content ) {
				return false;
			}
			// Any interrogative marker in the latest assistant turn counts as
			// a clarifying question, including "question + suggestion" shapes
			// that end with a statement.
			return self::text_is_question( $content );
		}
		return false;
	}

	/**
	 * Extracts entity candidates from free text deterministically.
	 *
	 * Candidates come from four patterns: quoted spans, Latin phrases that
	 * contain an uppercase letter or digit, katakana runs of three or more
	 * characters, and kanji compounds of four or more characters. Generic
	 * terms in the stop list are removed.
	 *
	 * @since 1.5.0
	 * @param string $text Source text.
	 * @return string[] Unique candidate terms in order of appearance.
	 */
	public static function extract_entities( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return [];
		}

		$candidates = [];

		// Quoted spans: 「...」, 『...』, “...”, "...".
		if ( preg_match_all( '/[「『“"]([^」』”"]{2,40})[」』”"]/u', $text, $matches ) ) {
			foreach ( $matches[1] as $quoted ) {
				$quoted = trim( $quoted );
				if ( '' !== $quoted ) {
					$candidates[] = $quoted;
				}
			}
		}

		// Latin phrases: keep runs containing an uppercase letter or digit so
		// brand names like "FE Search AI Pro" survive while plain English
		// sentence text is dropped.
		if ( preg_match_all( '/[A-Za-z0-9][A-Za-z0-9+&._-]*(?:\s+[A-Za-z0-9+&._-]+)*/u', $text, $matches ) ) {
			foreach ( $matches[0] as $phrase ) {
				$phrase = self::trim_latin_phrase( $phrase );
				if ( '' !== $phrase && mb_strlen( $phrase ) >= 3 && preg_match( '/[A-Z0-9]/', $phrase ) ) {
					$candidates[] = $phrase;
				}
			}
		}

		// Katakana runs of three or more characters.
		if ( preg_match_all( '/[ァ-ヶー]{3,}/u', $text, $matches ) ) {
			foreach ( $matches[0] as $katakana ) {
				$candidates[] = $katakana;
			}
		}

		// Kanji compounds of four or more characters.
		if ( preg_match_all( '/[一-龯々]{4,}/u', $text, $matches ) ) {
			foreach ( $matches[0] as $kanji ) {
				$candidates[] = $kanji;
			}
		}

		/**
		 * Filters the generic stop terms removed from entity candidates.
		 *
		 * @since 1.5.0
		 *
		 * @param string[] $stop_terms Default stop terms.
		 * @param string   $text       Source text.
		 */
		$stop_terms = (array) apply_filters( 'fe_search_ai_contextual_query_stop_terms', self::get_entity_stop_terms(), $text );
		$stop_map   = [];
		foreach ( $stop_terms as $term ) {
			$stop_map[ mb_strtolower( (string) $term ) ] = true;
		}

		$unique = [];
		foreach ( $candidates as $candidate ) {
			$key = mb_strtolower( $candidate );
			if ( isset( $stop_map[ $key ] ) || isset( $unique[ $key ] ) ) {
				continue;
			}
			$unique[ $key ] = $candidate;
		}

		return self::filter_common_terms( array_values( $unique ) );
	}

	/**
	 * Drops candidates that are ubiquitous across the indexed content.
	 *
	 * A static stop list cannot know which words are generic on a given site
	 * ("job" on a job board, "recipe" on a cooking site). This filter
	 * measures each candidate against the BM25 keyword index instead: the
	 * candidate is dropped when even its rarest lookup token appears in more
	 * than `fe_search_ai_entity_max_doc_ratio` of indexed chunks. Terms the
	 * index does not know are kept, so a missing or empty index disables the
	 * check entirely.
	 *
	 * @since 1.5.0
	 * @param string[] $candidates Entity candidates after stop-term removal.
	 * @return string[] Candidates that are discriminating enough to keep.
	 */
	private static function filter_common_terms( array $candidates ) {
		if ( empty( $candidates ) ) {
			return $candidates;
		}

		/**
		 * Filters the maximum document-frequency ratio for entity candidates.
		 *
		 * A candidate is dropped when its rarest lookup token appears in more
		 * than this fraction of indexed chunks. Values outside (0, 1] disable
		 * the check.
		 *
		 * @since 1.5.0
		 *
		 * @param float $ratio Default maximum ratio (0.5).
		 */
		$ratio = (float) apply_filters( 'fe_search_ai_entity_max_doc_ratio', self::DEFAULT_ENTITY_MAX_DOC_RATIO );
		if ( $ratio <= 0 || $ratio > 1 ) {
			return $candidates;
		}

		$lookup_terms = [];
		foreach ( $candidates as $candidate ) {
			foreach ( self::entity_lookup_tokens( $candidate ) as $token ) {
				$lookup_terms[ $token ] = true;
			}
		}
		$lookup_terms = array_keys( $lookup_terms );

		$stats = [
			'total_chunks' => self::get_indexed_chunk_count(),
			'frequencies'  => self::get_term_document_frequencies( $lookup_terms ),
		];

		/**
		 * Filters the document-frequency statistics used to detect ubiquitous
		 * entity candidates.
		 *
		 * Mainly intended for tests and for hosts that maintain the keyword
		 * index outside the default table.
		 *
		 * @since 1.5.0
		 *
		 * @param array    $stats        Array with `total_chunks` (int) and
		 *                               `frequencies` (token => document count).
		 * @param string[] $lookup_terms Normalized tokens being looked up.
		 */
		$stats = (array) apply_filters( 'fe_search_ai_entity_document_stats', $stats, $lookup_terms );

		$total       = isset( $stats['total_chunks'] ) ? (int) $stats['total_chunks'] : 0;
		$frequencies = isset( $stats['frequencies'] ) && is_array( $stats['frequencies'] ) ? $stats['frequencies'] : [];
		if ( $total <= 0 || empty( $frequencies ) ) {
			return $candidates;
		}

		$kept = [];
		foreach ( $candidates as $candidate ) {
			$min_df = null;
			foreach ( self::entity_lookup_tokens( $candidate ) as $token ) {
				if ( isset( $frequencies[ $token ] ) ) {
					$min_df = null === $min_df ? (int) $frequencies[ $token ] : min( $min_df, (int) $frequencies[ $token ] );
				}
			}
			// Candidates unknown to the index are rare by definition; a known
			// but ubiquitous rarest token marks the whole candidate as noise.
			if ( null !== $min_df && ( $min_df / $total ) > $ratio ) {
				continue;
			}
			$kept[] = $candidate;
		}

		return $kept;
	}

	/**
	 * Returns normalized lookup tokens for a document-frequency check.
	 *
	 * Applies the same normalization as the keyword index (lowercase, kana
	 * width folding, non-alphanumeric stripping) and splits on whitespace so
	 * a Latin phrase like "FE Search AI Pro" resolves to its rarest word.
	 *
	 * @since 1.5.0
	 * @param string $candidate Entity candidate text.
	 * @return string[] Normalized tokens (all longer than one character).
	 */
	private static function entity_lookup_tokens( $candidate ) {
		$normalized = mb_strtolower( mb_convert_kana( (string) $candidate, 'as', 'UTF-8' ), 'UTF-8' );
		$normalized = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $normalized );
		$tokens     = preg_split( '/\s+/', trim( (string) $normalized ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) ) {
			return [];
		}
		return array_values(
			array_filter(
				$tokens,
				function ( $token ) {
					return mb_strlen( $token ) > 1;
				}
			)
		);
	}

	/**
	 * Returns the number of indexed chunks (rows in the vectors table).
	 *
	 * Cached per request; entity filtering runs at most once per turn.
	 *
	 * @since 1.5.0
	 * @return int Indexed chunk count, 0 when the table is missing/empty.
	 */
	private static function get_indexed_chunk_count() {
		static $count = null;
		if ( null !== $count ) {
			return $count;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'fe_search_ai_vectors';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		return $count;
	}

	/**
	 * Returns document frequencies for the given index keywords.
	 *
	 * Each keyword_index row pairs a keyword with one chunk, so COUNT(*) per
	 * keyword is the number of chunks containing it. Results are cached per
	 * request so repeated entity extractions cost one query.
	 *
	 * @since 1.5.0
	 * @param string[] $terms Normalized index keywords.
	 * @return array Map of keyword => containing-chunk count.
	 */
	private static function get_term_document_frequencies( array $terms ) {
		static $cache = [];
		$missing      = array_values( array_diff( $terms, array_keys( $cache ) ) );
		if ( ! empty( $missing ) ) {
			global $wpdb;
			$table        = $wpdb->prefix . 'fe_search_ai_keyword_index';
			$placeholders = implode( ',', array_fill( 0, count( $missing ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT `keyword`, COUNT(*) AS `df` FROM `{$table}` WHERE `keyword` IN ({$placeholders}) GROUP BY `keyword`", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$missing
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$cache[ $row['keyword'] ] = (int) $row['df'];
			}
			foreach ( $missing as $term ) {
				if ( ! isset( $cache[ $term ] ) ) {
					$cache[ $term ] = 0;
				}
			}
		}

		$frequencies = [];
		foreach ( $terms as $term ) {
			if ( isset( $cache[ $term ] ) ) {
				$frequencies[ $term ] = $cache[ $term ];
			}
		}
		return $frequencies;
	}

	/**
	 * Limits how many chunks from one post may occupy the candidate set.
	 *
	 * Runs before the reranker on `fe_search_ai_retrieved_chunks` so a single
	 * post cannot dominate the final context. The `fe_search_ai_max_chunks_per_post`
	 * filter adjusts the cap (default 2; 0 or negative disables the cap).
	 *
	 * @since 1.5.0
	 * @param array  $chunks   Retrieved chunk items (each may carry post_id).
	 * @param string $question Retrieval query (unused, kept for filter parity).
	 * @return array Filtered chunk list preserving original order.
	 */
	public static function limit_chunks_per_post( $chunks, $question ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_array( $chunks ) || empty( $chunks ) ) {
			return $chunks;
		}

		/**
		 * Filters the maximum number of chunks kept per post.
		 *
		 * @since 1.5.0
		 *
		 * @param int    $max      Default maximum (2). 0 or negative disables.
		 * @param string $question Retrieval query.
		 */
		$max = (int) apply_filters( 'fe_search_ai_max_chunks_per_post', self::DEFAULT_MAX_CHUNKS_PER_POST, $question );
		if ( $max <= 0 ) {
			return $chunks;
		}

		$counts  = [];
		$limited = [];
		foreach ( $chunks as $chunk ) {
			$post_id = is_array( $chunk ) && isset( $chunk['post_id'] ) ? (int) $chunk['post_id'] : 0;
			if ( $post_id > 0 ) {
				$counts[ $post_id ] = ( $counts[ $post_id ] ?? 0 ) + 1;
				if ( $counts[ $post_id ] > $max ) {
					continue;
				}
			}
			$limited[] = $chunk;
		}

		return $limited;
	}

	/**
	 * Trims leading/trailing lowercase-only words from a Latin phrase.
	 *
	 * "the FE Search AI Pro is" becomes "FE Search AI Pro" so English
	 * sentence fragments do not become bogus entity terms.
	 *
	 * @since 1.5.0
	 * @param string $phrase Raw matched phrase.
	 * @return string Trimmed phrase.
	 */
	private static function trim_latin_phrase( $phrase ) {
		$words = preg_split( '/\s+/', trim( $phrase ) );
		if ( ! is_array( $words ) ) {
			return '';
		}
		while ( ! empty( $words ) && ! preg_match( '/[A-Z0-9]/', $words[0] ) ) {
			array_shift( $words );
		}
		while ( ! empty( $words ) && ! preg_match( '/[A-Z0-9]/', end( $words ) ) ) {
			array_pop( $words );
		}
		return implode( ' ', $words );
	}
}
