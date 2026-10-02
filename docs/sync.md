# Sync System

The sync system builds and maintains the searchable index used by FE Search AI.

## What sync does

During sync, the plugin extracts content from selected WordPress posts, pages, and custom post types. For each post it:

1. Splits content into chunks (approx. 1,000 characters each by default, adjustable via `fe_search_ai_chunk_size`).
2. Optionally generates a structured summary per chunk (topics, facts, entities, keywords) via the chat provider.
3. Creates embedding vectors with the selected embedding provider and stores them in Qdrant.
4. Builds keyword index data used by BM25 ranking in the `{prefix}fe_search_ai_keyword_index` table.

## Sync modes

### Sync Changes (smart sync)

Processes only content that changed since the last completed sync. Specifically, it:

- removes index rows for posts that are no longer published;
- indexes posts whose `post_modified_gmt` is after the last sync timestamp;
- refuses to run if the sync settings changed since the last sync — in that case the UI asks you to run **Rebuild Index** instead.

Smart sync requires a baseline. If no sync has ever completed, the UI asks you to run **Rebuild Index** once first.

### Rebuild Index (full sync)

Truncates `{prefix}fe_search_ai_vectors` and `{prefix}fe_search_ai_keyword_index`, then reindexes all eligible posts from scratch. Use it:

- on first setup;
- after changing sync targets, tokenizer, embedding provider, or other index-affecting settings;
- when index health metrics look wrong (see below).

### Real-time sync

When a post in an enabled post type is published or updated, it is automatically reindexed via `save_post`. Trashing or deleting a post (`wp_trash_post`, `delete_post`) removes it from the index. Only `publish` status is indexed; autosaves and revisions are skipped. The post language is detected via Polylang, WPML, or Bogo when available, falling back to the site locale (`fe_search_ai_post_language_code` filter).

Full sync and real-time sync timestamps are tracked separately and shown as **Last Bulk Sync** and **Last Realtime Sync**.

## Index health metrics

The Sync screen shows the current state of the index:

| Metric                          | Meaning                                                                                                                     |
| ------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| **Indexed Posts**               | Number of distinct posts present in the index.                                                                              |
| **Vectors**                     | Total chunk rows in `{prefix}fe_search_ai_vectors`. One post usually produces several chunks.                               |
| **Keyword Token Count Missing** | Chunk rows where `keyword_token_count` is `0`. Non-zero values here mean BM25 keyword matching is missing for those chunks. |
| **Average Keyword Tokens**      | Average token count per chunk (excluding zero rows). Near-zero values suggest tokenization is not working.                  |
| **Keyword Index Rows**          | Total rows in `{prefix}fe_search_ai_keyword_index` (term frequencies).                                                      |

If **Keyword Token Count Missing** is non-zero while **Keyword Index Rows** is populated — or keyword search results lack BM25 scores — run **Rebuild Index** to rebuild the metadata.

## Batch processing and interruption

Sync runs in sequential AJAX batches (Batch Size posts per request) with a progress bar. The last-sync timestamp and the sync-settings hash are only updated **after all batches complete**.

If a sync is interrupted (browser closed, timeout, server error):

- **Rebuild Index** already truncated the index, so it must be run again — re-running **Sync Changes** only covers posts modified since the last completed sync and will leave the index incomplete.
- An interrupted **Sync Changes** run can simply be retried; processed posts are already indexed and will be re-processed harmlessly (sync is idempotent per post — the post's previous rows are deleted before reindexing).

If syncs repeatedly stall, lower Batch Size and check PHP `memory_limit` / `max_execution_time`.

## Retrieval trace

Every chat request builds a _retrieval trace_ — a compact record of which chunks were retrieved and how they scored. Traces are used for search-quality analysis and never contain question or answer text.

A trace contains:

- `trace_id`, `sequence_id` — identifiers;
- `query_hash`, `query_length` — SHA-256 and length of the question (never the text itself);
- `pipeline` — which stages contributed: `bm25`, `qdrant`, `hybrid`, `cohere`;
- `metadata.source_counts` — how many final items came from `qdrant`, `keyword`, `both`, or `unknown` sources;
- `items` — per-chunk `final_rank`, `post_id`, title, chunk/permalink hashes, scores (`bm25_score`, `qdrant_score`, `hybrid_score`, `cohere_relevance_score`) and ranks (`bm25_rank`, `qdrant_rank`, `hybrid_rank`, `cohere_rank`).

### Inspecting traces

- With **Debug Mode** on, traces appear in `{prefix}fe_search_ai_system_logs` as `Retrieval trace scores captured.` entries.
- With **Retrieval Trace Persistence** on (Advanced settings tab), traces are stored in `{prefix}fe_search_ai_retrieval_traces` and `{prefix}fe_search_ai_retrieval_trace_items`, rotated daily after the configured retention (default 30 days), deletable via **Delete Retrieval Traces**, and removed on uninstall when data deletion is enabled.

A quick health check: if final trace items lack `bm25_score` but the keyword index has rows, the per-chunk token counts are probably zero — run **Rebuild Index**.

Related filters: `fe_search_ai_retrieval_trace_payload`, `fe_search_ai_enable_retrieval_trace_persistence`, `fe_search_ai_retrieval_trace_retention_days`.

## Japanese content

For Japanese sites, tokenization affects BM25 keyword matching. TinySegmenter (PHP 8.0+) works without external API setup. Yahoo! JAPAN Japanese MA API can be configured when higher-accuracy tokenization is required. Changing the tokenizer requires a **Rebuild Index**.

## Troubleshooting sync

- Confirm API keys are valid (**Test** button in the Providers tab).
- Confirm Qdrant endpoint, API key, and collection — and that the collection's vector size matches the embedding model.
- Reduce Batch Size on limited hosting environments.
- Check PHP memory limit and max execution time.
- Enable Debug Mode and review `{prefix}fe_search_ai_system_logs`, or check `wp-content/debug.log`.
