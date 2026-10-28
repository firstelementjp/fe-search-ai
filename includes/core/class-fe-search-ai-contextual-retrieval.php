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
	 * Generic terms that carry little entity value on their own.
	 *
	 * @since 1.5.0
	 * @var string[]
	 */
	const DEFAULT_STOP_TERMS = [
		'プラン',
		'サービス',
		'サイト',
		'ページ',
		'バージョン',
		'プラグイン',
		'アップデート',
		'インストール',
		'セットアップ',
		'オプション',
		'デフォルト',
		'カスタム',
		'エクスポート',
		'インポート',
		'ダウンロード',
		'アップグレード',
		'エンタープライズ',
	];

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
		$question = (string) $question;
		if ( '' === $question || empty( $history ) || ! self::is_contextual_query_enabled( $settings ) ) {
			return $question;
		}

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

		$history_slice = array_slice( $history, -$message_limit );
		$scored        = [];
		foreach ( $history_slice as $message ) {
			if ( ! is_array( $message ) || empty( $message['content'] ) || ! is_string( $message['content'] ) ) {
				continue;
			}
			// Entities mentioned in user turns are stronger topic signals than
			// those that only appear in assistant answers.
			$weight = ( isset( $message['role'] ) && 'user' === $message['role'] ) ? 2 : 1;
			foreach ( self::extract_entities( $message['content'] ) as $entity ) {
				$key = mb_strtolower( $entity );
				if ( ! isset( $scored[ $key ] ) ) {
					$scored[ $key ] = [
						'term'   => $entity,
						'score'  => 0,
						'length' => mb_strlen( $entity ),
					];
				}
				$scored[ $key ]['score'] += $weight;
			}
		}

		// Drop entities already present in the question.
		foreach ( array_keys( $scored ) as $key ) {
			if ( false !== mb_stripos( $question, $scored[ $key ]['term'] ) ) {
				unset( $scored[ $key ] );
			}
		}
		if ( empty( $scored ) ) {
			return $question;
		}

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
		if ( 0 === $max_entities ) {
			return $question;
		}

		$entities = array_map(
			function ( $item ) {
				return $item['term'];
			},
			array_slice( $scored, 0, $max_entities )
		);

		$retrieval_query = trim( $question . ' ' . implode( ' ', $entities ) );

		/**
		 * Filters the expanded retrieval query before it is used for search.
		 *
		 * @since 1.5.0
		 *
		 * @param string   $retrieval_query Expanded query.
		 * @param string   $question        Original user question.
		 * @param string[] $entities        Entity terms that were appended.
		 * @param array    $history         Sanitized conversation history.
		 */
		return (string) apply_filters( 'fe_search_ai_contextual_retrieval_query', $retrieval_query, $question, $entities, $history );
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
	 * @return string One of the DECISION_* constants.
	 */
	public static function decide( $question, array $history, array $settings ) {
		$question = (string) $question;
		$decision = self::DECISION_RETRIEVE;

		if ( '' !== $question ) {
			if ( self::is_small_talk( $question ) ) {
				$decision = self::DECISION_ANSWER;
			} else {
				$question_entities = self::extract_entities( $question );
				$history_entities  = self::extract_history_entities( $history );
				if ( empty( $question_entities ) && empty( $history_entities ) ) {
					$decision = self::last_assistant_was_clarifying( $history )
						? self::DECISION_RETRIEVE
						: self::DECISION_CLARIFY;
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
		 * @param string $decision One of the DECISION_* constants.
		 * @param string $question Sanitized user question.
		 * @param array  $history  Sanitized conversation history.
		 * @param array  $settings Plugin settings array.
		 */
		$decision = (string) apply_filters( 'fe_search_ai_retrieval_decision', $decision, $question, $history, $settings );

		if ( ! in_array( $decision, [ self::DECISION_RETRIEVE, self::DECISION_ANSWER, self::DECISION_CLARIFY ], true ) ) {
			$decision = self::DECISION_RETRIEVE;
		}

		return $decision;
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
	 * Extracts entity candidates from recent history messages.
	 *
	 * @since 1.5.0
	 * @param array $history Sanitized conversation history.
	 * @return string[] Candidate terms found in recent history.
	 */
	private static function extract_history_entities( array $history ) {
		if ( empty( $history ) ) {
			return [];
		}
		$entities = [];
		foreach ( array_slice( $history, -self::DEFAULT_HISTORY_MESSAGES ) as $message ) {
			if ( ! is_array( $message ) || empty( $message['content'] ) || ! is_string( $message['content'] ) ) {
				continue;
			}
			foreach ( self::extract_entities( $message['content'] ) as $entity ) {
				$entities[ mb_strtolower( $entity ) ] = $entity;
			}
		}
		return array_values( $entities );
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
			// A turn ending with a question mark or containing typical
			// interrogative endings counts as a clarifying question.
			return (bool) preg_match( '/[?？]\s*$|(?:ですか|ますか|でしょうか|お聞かせ|教えて(?:ください)?[.。]?\s*$)/u', $content );
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
		$stop_terms = (array) apply_filters( 'fe_search_ai_contextual_query_stop_terms', self::DEFAULT_STOP_TERMS, $text );
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

		return array_values( $unique );
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
