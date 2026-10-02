# Troubleshooting

## The chat does not answer

Check these items first:

1. Chat provider API key is configured and passes the **Test** button in the Providers tab.
2. Embedding provider API key is configured.
3. Qdrant endpoint, API key, and collection are valid (when Qdrant storage is enabled).
4. Content has been synced (Indexed Posts > 0 on the Sync tab).
5. The frontend page contains `[fe-search-ai]` or floating chat is enabled for that page type and device.
6. You are not hitting the built-in rate limits (50 requests/hour per IP, 1,000/day per site). Wait or raise them via the `fe_search_ai_rate_limit_settings` filter.

Also open the browser console and Network tab: a failing request to the WordPress REST endpoint usually reveals the actual error (authentication, timeout, or provider error).

## Answers are not relevant

- Rebuild the index after changing sync settings.
- Confirm the relevant posts are included in sync targets and published.
- Improve source content titles and headings.
- Enable Cohere reranking if available.
- Adjust the system prompt and Site Purpose.
- Check **Keyword Token Count Missing** on the Sync tab — a non-zero value means BM25 is not matching those chunks; run **Rebuild Index**.
- With Retrieval Trace Persistence or Debug Mode enabled, inspect `source_counts` and per-item scores to see whether vector or keyword search is contributing nothing.

## Sync fails

- Reduce Batch Size (Sync tab).
- Check server memory and timeout limits (`memory_limit`, `max_execution_time`).
- Confirm provider API quota.
- Confirm the Qdrant collection exists and its vector size matches the embedding model (see below).

### "Sync settings have changed"

Smart sync refuses to run when the saved sync-settings hash differs from the current settings. This is expected after changing sync targets or related options — run **Rebuild Index** once to apply them.

### Sync was interrupted partway

The last-sync timestamp is only written after all batches finish. If a **Rebuild Index** run was interrupted, run it again — the index was truncated at the start and re-running **Sync Changes** will not fill in the gaps. An interrupted **Sync Changes** run can simply be retried.

### Smart sync reports nothing to do

- Smart sync only indexes posts modified after the last completed sync. If the index was cleared (e.g. **Delete Synced Data**), run **Rebuild Index** instead.
- If no sync has ever completed, smart sync has no baseline — run **Rebuild Index** first.

## Qdrant errors

- **Authentication failures (401/403)**: check the API key and that it belongs to the cluster in the endpoint URL.
- **Collection not found (404)**: create the collection in Qdrant first; the plugin upserts into an existing collection.
- **Vector size mismatch**: the collection's configured dimensions must equal the embedding model's output size. If you switched the embedding provider (for example OpenAI ↔ Google), create a new collection with the correct dimensions, update the Collection Name setting, and run **Rebuild Index**.
- Qdrant Cloud **free** clusters may delete collections after inactivity — recheck the cluster if sync or search suddenly fails after a quiet period.

## API key test fails

- Confirm there are no extra spaces in the key.
- Check provider account billing and quota status.
- Confirm the selected model is available for your account.
- Review provider dashboard logs if available.

## Japanese search quality is poor

- Confirm the site locale and tokenizer settings (Advanced settings tab).
- TinySegmenter requires PHP 8.0+; on older PHP the plugin uses Yahoo! MA API and the engine selector is disabled.
- Try Yahoo! JAPAN Japanese MA API if TinySegmenter is insufficient.
- Add important terms explicitly to page titles or headings.
- After changing the tokenizer, run **Rebuild Index** — the keyword index must be rebuilt with the new engine.

## Where to look for logs

| Tool                   | Where                                                                   | Contains                                                                     |
| ---------------------- | ----------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| Debug Mode system logs | `{prefix}fe_search_ai_system_logs` table (Advanced settings tab)        | Operational events, retrieval trace scores, errors — never conversation text |
| Retrieval traces       | `{prefix}fe_search_ai_retrieval_traces` / `_items` (opt-in persistence) | Hashed queries, post IDs, per-source scores and ranks                        |
| WordPress debug log    | `wp-content/debug.log`                                                  | PHP errors and plugin `error_log` output                                     |
| Browser console        | DevTools                                                                | Frontend JS errors and failed REST requests                                  |

## Where to get help

- [GitHub Issues](https://github.com/firstelementjp/fe-search-ai/issues)
- [Support](https://www.firstelement.co.jp/en/contact)
