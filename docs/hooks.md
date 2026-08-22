# Developer Hooks

FE Search AI exposes WordPress filters and actions for extending providers, retrieval, indexing, chat output, logging, and the settings UI. This reference covers the hooks provided by the free plugin. Pro and third-party add-ons may provide additional hooks.

## Usage notes

- Register callbacks after the plugin loads, normally from a plugin or theme.
- Set the `$accepted_args` parameter of `add_filter()` or `add_action()` when a hook passes more than one argument.
- Escape HTML rendered by action callbacks and sanitize external input.
- Keep provider API keys server-side.
- Dynamic hook names contain the selected provider slug, such as `fe_search_ai_embedding_result_for_openai`.
- The source code remains authoritative if a release changes a hook signature.

## Chat and response filters

| Hook                                     | Filtered value and additional arguments | Purpose                                                                                                                                                                                  |
| ---------------------------------------- | --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `fe_search_ai_rate_limit_settings`       | `array $settings`                       | Changes rate limits used by both the REST handler and frontend configuration. Keys are `ip_limit_count`, `global_limit_count`, `notify_threshold`, and `notify_email`.                   |
| `fe_search_ai_preprocess_user_question`  | `string $question`                      | Changes the sanitized question before retrieval and model processing. The plugin's privacy and basic injection filters also run on this hook. Return an empty string to stop processing. |
| `fe_search_ai_retrieved_chunks`          | `array $chunks`, `string $question`     | Changes retrieved chunks before they are passed to the model. The built-in Cohere reranker runs here at priority 20 when enabled.                                                        |
| `fe_search_ai_preprocess_model_response` | `string $content`                       | Changes each model response or streamed text fragment before it is sent to the browser.                                                                                                  |
| `fe_search_ai_system_prompt`             | `string $prompt`, `string $provider`    | Changes the configured system prompt before context and placeholders are expanded.                                                                                                       |
| `fe_search_ai_final_system_prompt`       | `string $prompt`, `string $provider`    | Changes the final system prompt after all placeholders and context have been expanded.                                                                                                   |
| `fe_search_ai_personal_data_patterns`    | `array $patterns`                       | Changes the regular expressions used to redact email addresses and phone numbers.                                                                                                        |
| `fe_search_ai_rate_limit_message`        | `string $message`                       | Changes the localized frontend message shown when the request limit is reached.                                                                                                          |

## Retrieval and ranking filters

| Hook                                              | Filtered value and additional arguments                                      | Purpose                                                                                                                           |
| ------------------------------------------------- | ---------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `fe_search_ai_qdrant_search_limit`                | `int $limit`, `string $question`                                             | Changes the number of candidates requested from Qdrant. The default comes from the configured rerank initial candidate count.     |
| `fe_search_ai_max_chunks_for_llm`                 | `int $max_chunks`, `string $question`                                        | Changes the maximum number of BM25 chunks returned for model context. Default: `100`.                                             |
| `fe_search_ai_bm25_candidate_limit`               | `int $limit`, `string $question`                                             | Changes the number of keyword-index rows considered before BM25 scoring. Default: the greater of `500` and `$max_chunks * 20`.    |
| `fe_search_ai_bm25_k1`                            | `float $k1`, `string $question`                                              | Changes the BM25 term-frequency saturation parameter. Default: `1.2`; values less than or equal to zero fall back to the default. |
| `fe_search_ai_bm25_b`                             | `float $b`, `string $question`                                               | Changes the BM25 document-length normalization parameter. Default: `0.75`; valid values are from `0` to `1`.                      |
| `fe_search_ai_hybrid_candidate_limit`             | `int $limit`, `string $question`                                             | Changes the per-source candidate limit used by hybrid retrieval. The configured value defaults to `50`.                           |
| `fe_search_ai_hybrid_rrf_k`                       | `int $k`, `string $question`                                                 | Changes the Reciprocal Rank Fusion constant. Default: `60`.                                                                       |
| `fe_search_ai_hybrid_search_limit`                | `int $limit`, `string $question`                                             | Changes the maximum number of merged hybrid results. Default: `100`.                                                              |
| `fe_search_ai_retrieval_trace_payload`            | `array $payload`, `array $chunks`, `string $question`, `string $sequence_id` | Changes the safe retrieval trace payload before logging, dispatch, or optional persistence.                                       |
| `fe_search_ai_enable_retrieval_trace_persistence` | `bool $enabled`, `array $trace`                                              | Enables database persistence for retrieval traces. Default: `false`.                                                              |

## Sync, chunking, and summary filters

| Hook                                            | Filtered value and additional arguments                                                      | Purpose                                                                                                                                                               |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `fe_search_ai_sync_target_has_snippet`          | `bool $has_snippet`, `array $post_type_options`, `string $post_type_slug`                    | Changes whether an enabled post type has indexable snippet fields and should be included in a sync.                                                                   |
| `fe_search_ai_sync_query_args`                  | `array $args`                                                                                | Changes the arguments passed to `get_posts()` when collecting posts for bulk synchronization.                                                                         |
| `fe_search_ai_post_language_code`               | `string $language_code`, `int $post_id`, `WP_Post $post`                                     | Changes the language code detected for a post during real-time indexing.                                                                                              |
| `fe_search_ai_taxonomy_items`                   | `array $items`, `WP_Post $post`, `array $post_type_options`                                  | Changes the formatted taxonomy metadata items included in chunk data.                                                                                                 |
| `fe_search_ai_post_metadata_parts`              | `array $parts`, `WP_Post $post`, `array $post_type_options`                                  | Changes the complete metadata lines prepended to each post chunk.                                                                                                     |
| `fe_search_ai_chunk_size`                       | `int $size`, `WP_Post $post`                                                                 | Changes the approximate maximum chunk size in characters. Default: `1000`.                                                                                            |
| `fe_search_ai_qdrant_snippet_length`            | `int $length`, `WP_Post $post`, `array $chunk`                                               | Changes the maximum Qdrant payload snippet length. Default: `1000`.                                                                                                   |
| `fe_search_ai_chunk_summary`                    | `string\|null $override`, `array $chunk`, `WP_Post $post`, `string $language_code`           | Completely overrides summary generation for a chunk. Return `null` to use the built-in provider request, a string to use that summary, or an empty string to omit it. |
| `fe_search_ai_chunk_summary_prompt_context`     | `array $context`, `array $chunk`, `WP_Post $post`, `string $language_code`                   | Changes the structured context used to build a chunk-summary prompt.                                                                                                  |
| `fe_search_ai_chunk_summary_generation_prompt`  | `string $prompt`, `array $context`, `array $chunk`, `WP_Post $post`, `string $language_code` | Changes the final prompt sent to the chat provider for summary generation.                                                                                            |
| `fe_search_ai_chunk_summary_output_postprocess` | `string $summary`, `array $chunk`, `WP_Post $post`, `string $language_code`                  | Post-processes a generated chunk summary before it is stored.                                                                                                         |
| `fe_search_ai_summary_chat_result`              | `string\|null $override`, `string $prompt`, `string $provider`                               | Overrides the provider request used for summary generation. Return `null` to continue with the selected built-in provider.                                            |

## Tokenization filters

| Hook                                          | Filtered value and additional arguments                             | Purpose                                                                                            |
| --------------------------------------------- | ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `fe_search_ai_stop_words`                     | `array $stop_words`, `string $locale`                               | Changes the locale-specific stop-word list.                                                        |
| `fe_search_ai_tokens_for_lang`                | `array $tokens`, `string $normalized_text`, `string $language_code` | Changes raw tokens after language-specific segmentation and before stop-word removal and stemming. |
| `fe_search_ai_tokenize_text`                  | `array $tokens`, `string $normalized_text`, `string $locale`        | Changes the final tokens after stop-word removal, stemming, and optional deduplication.            |
| `fe_search_ai_tokenizer_status`               | `array $statuses`                                                   | Adds HTML status lines to the tokenizer status area on the Sync screen.                            |
| `fe_search_ai_japanese_tokenizer_status_text` | `string $status_html`, `string $tokenizer`                          | Changes the Japanese tokenizer status HTML generated by the built-in sync handler.                 |

## Provider and integration filters

| Hook                                            | Filtered value and additional arguments                                                           | Purpose                                                                                                                                                    |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `fe_search_ai_chat_providers`                   | `array $providers`                                                                                | Changes chat provider choices. The array maps provider slugs to display labels.                                                                            |
| `fe_search_ai_embedding_providers`              | `array $providers`                                                                                | Changes embedding provider choices. The array maps provider slugs to display labels.                                                                       |
| `fe_search_ai_rerank_providers`                 | `array $providers`                                                                                | Changes rerank provider choices. The array maps provider slugs to display labels.                                                                          |
| `fe_search_ai_embedding_result_for_{$provider}` | `array\|WP_Error\|null $result`, `array $texts`                                                   | Dynamic provider hook that overrides embedding generation. Return `null` to use the built-in handler. Example: `fe_search_ai_embedding_result_for_openai`. |
| `fe_search_ai_handle_custom_api_test`           | `array\|null $result`, `string $provider`, `string $api_key`                                      | Handles API-key tests for custom providers. Return `['is_valid' => bool, 'message' => string]`, or `null` to use a built-in handler.                       |
| `fe_search_ai_get_sync_handler_instance`        | mixed value, normally called as `apply_filters( 'fe_search_ai_get_sync_handler_instance', null )` | Returns the active `FE_Search_AI_Sync_Handler` instance to integrations. The free plugin supplies the callback for this integration filter.                |
| `fe_search_ai_enable_github_updates`            | `bool $enabled`                                                                                   | Enables the GitHub Releases update checker. Default: `true`.                                                                                               |
| `fe_search_ai_admin_allowed_hooks`              | `array $hook_suffixes`                                                                            | Adds admin page hook suffixes on which FE Search AI admin assets should load.                                                                              |

### Custom embedding provider example

```php
add_filter(
    'fe_search_ai_embedding_result_for_example',
    function ( $result, $texts ) {
        // Return an OpenAI-compatible embedding response or a WP_Error.
        return $result;
    },
    10,
    2
);
```

## Frontend display filters

| Hook                               | Filtered value and additional arguments   | Purpose                                                                                                                                            |
| ---------------------------------- | ----------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `fe_search_ai_should_display_chat` | `bool $should_display`                    | Changes whether the floating chat UI is rendered for the current request.                                                                          |
| `fe_search_ai_chat_ui_html`        | `string $html`, `array $args`             | Changes the complete chat UI HTML for floating and embedded displays.                                                                              |
| `fe_search_ai_dynamic_styles_css`  | `string $style_html`, `string $key_color` | Changes the dynamic `<style>` block printed by the chat UI.                                                                                        |
| `fe_search_ai_frontend_color_css`  | `string $css`, `array $colors`            | Changes inline frontend CSS variables. The color array includes `accent`, `background`, `text`, `border`, gradient, input, and user-bubble colors. |

## Privacy and logging filters

Conversation text and suspected personal data are not stored by default. Enabling these filters has privacy and compliance implications.

| Hook                                                | Filtered value and additional arguments                            | Purpose                                                                                                                                   |
| --------------------------------------------------- | ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `fe_search_ai_allow_conversation_log_question_text` | `bool $allowed`, `string $session_id`                              | Allows full question text to be stored in conversation logs. Default: `false`.                                                            |
| `fe_search_ai_allow_conversation_log_answer_text`   | `bool $allowed`, `string $session_id`                              | Allows full answer text to be stored in conversation logs. Default: `false`.                                                              |
| `fe_search_ai_allow_conversation_log_pii`           | `bool $allowed`, `string $session_id`                              | Allows question and answer text suspected of containing personal data to be stored. Default: `false`.                                     |
| `fe_search_ai_conversation_log_payload`             | `array $row`, `string $session_id`                                 | Changes a conversation log row before insertion. Only `session_id`, `question`, `answer`, `context_found`, and `created_at` are retained. |
| `fe_search_ai_allow_system_log_entry`               | `bool $allowed`, `string $level`, `string $message`, `array $data` | Allows or suppresses an individual system log entry after debug-mode checks. Default: `true`.                                             |
| `fe_search_ai_system_log_forbidden_keys`            | `array $keys`, `string $level`, `string $message`, `array $data`   | Changes data keys recursively removed from system log payloads before insertion.                                                          |
| `fe_search_ai_system_log_payload`                   | `array $data`, `string $level`, `string $message`                  | Changes sanitized system log context data before insertion. Forbidden keys are removed again after this filter runs.                      |
| `fe_search_ai_log_retention_days`                   | `int $days`                                                        | Changes system-log retention. Default: `30` days.                                                                                         |
| `fe_search_ai_conversation_log_retention_days`      | `int $days`                                                        | Changes conversation-log retention. Default: `7` days.                                                                                    |

## Settings sanitization filters

| Hook                                     | Filtered value and additional arguments                    | Purpose                                                                       |
| ---------------------------------------- | ---------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `fe_search_ai_sanitize_sync_target`      | `array $sanitized`, `array $raw`, `string $post_type_slug` | Changes one post type's sanitized sync-target settings before they are saved. |
| `fe_search_ai_sanitize_display_floating` | `array $sanitized`, `array $raw`                           | Changes sanitized floating-display settings before they are saved.            |

## Actions

### Runtime and lifecycle actions

| Hook                                    | Arguments                                                                         | Purpose                                                                                                                                                                                                      |
| --------------------------------------- | --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `fe_search_ai_retrieval_trace`          | `array $trace`                                                                    | Fires after a safe retrieval trace payload is built. The built-in recorder listens here but persists only when `fe_search_ai_enable_retrieval_trace_persistence` is enabled.                                 |
| `fe_search_ai_stream_for_{$provider}`   | `string $question`, `array $context_chunks`, `array $history`, `string $provider` | Dynamic action fired before the built-in streaming handler. A custom provider callback must stream its response and call `exit()` to prevent built-in execution. Example: `fe_search_ai_stream_for_example`. |
| `fe_search_ai_daily_log_rotation_event` | None                                                                              | WP-Cron event scheduled daily by the plugin. The built-in callback rotates system and conversation logs.                                                                                                     |

### Settings UI actions

Callbacks that output markup must escape their output appropriately.

| Hook                                                 | Arguments                                             | Location                                                                                 |
| ---------------------------------------------------- | ----------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `fe_search_ai_settings_tabs`                         | None                                                  | Inside the settings navigation tab wrapper.                                              |
| `fe_search_ai_settings_tabs_content`                 | None                                                  | At the end of the settings content container; pair it with `fe_search_ai_settings_tabs`. |
| `fe_search_ai_after_api_settings_fields`             | None                                                  | After all fields in the Providers tab.                                                   |
| `fe_search_ai_after_vector_store_settings_fields`    | `bool $is_pro`                                        | After vector-store fields in the Sync tab.                                               |
| `fe_search_ai_after_display_embed_settings_fields`   | None                                                  | After embedded-display fields.                                                           |
| `fe_search_ai_after_display_settings_fields`         | `bool $is_pro`                                        | After all fields in the Display tab.                                                     |
| `fe_search_ai_after_prompt_settings_fields`          | None                                                  | After all fields in the Prompts tab.                                                     |
| `fe_search_ai_after_data_management_settings_fields` | `bool $is_pro`                                        | Inside the data-management settings table.                                               |
| `fe_search_ai_after_advanced_settings_fields`        | `bool $is_pro`                                        | After all fields in the Advanced settings tab.                                           |
| `fe_search_ai_api_keys_table_rows`                   | `FE_Search_AI_Settings $settings`                     | At the end of the API-keys table body.                                                   |
| `fe_search_ai_after_api_key_fields`                  | `FE_Search_AI_Settings $settings`                     | After the API-keys table.                                                                |
| `fe_search_ai_sync_target_rows`                      | `WP_Post_Type $post_type`, `array $post_type_options` | At the end of each post type's sync-target table body.                                   |

## Examples

### Modify both prompt stages

```php
add_filter(
    'fe_search_ai_system_prompt',
    function ( $prompt, $provider ) {
        return $prompt . "\nAnswer concisely.";
    },
    10,
    2
);

add_filter(
    'fe_search_ai_final_system_prompt',
    function ( $prompt, $provider ) {
        return $prompt . "\nCite relevant site content when possible.";
    },
    10,
    2
);
```

### Adjust hybrid retrieval

```php
add_filter( 'fe_search_ai_hybrid_candidate_limit', function ( $limit, $question ) {
    return 75;
}, 10, 2 );

add_filter( 'fe_search_ai_hybrid_search_limit', function ( $limit, $question ) {
    return 50;
}, 10, 2 );
```

### Add settings tab content

```php
add_action( 'fe_search_ai_settings_tabs', function () {
    echo '<a href="#tab_example" class="nav-tab">' . esc_html__( 'Example', 'example-plugin' ) . '</a>';
} );

add_action( 'fe_search_ai_settings_tabs_content', function () {
    echo '<div id="tab_example" class="tab-content">';
    echo '<p>' . esc_html__( 'Example settings.', 'example-plugin' ) . '</p>';
    echo '</div>';
} );
```

## Source code

See the [GitHub repository](https://github.com/firstelementjp/fe-search-ai) for the authoritative implementation and current hook signatures.
