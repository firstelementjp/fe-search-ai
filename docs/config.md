# Configuration

The settings screen (**FE Search AI** in the admin menu) is organized into tabs. This page is a field-by-field reference for each tab in the free plugin. Pro adds **Models** and **Security** tabs plus extra fields.

- **Providers** — API keys and model provider selection
- **Sync** — Content to index, storage backends, sync controls
- **Prompts** — Site information and the base system prompt
- **Display** — Floating chat, embed mode, and appearance
- **Privacy** — Data-handling summary and legal document links
- **Advanced settings** — Reranker, Qdrant, tokenizer, logging, and data management

## Providers tab

### API Keys

One password field per provider. Keys are encrypted before being stored in the database. Each row has a **Test** button that verifies the key with a live API call, and shows the model currently in use.

| Provider           | Purpose                         | Default model               |
| ------------------ | ------------------------------- | --------------------------- |
| OpenAI (GPT)       | Chat completions and embeddings | `gpt-5.4-mini`              |
| Anthropic (Claude) | Chat completions                | `claude-haiku-4-5-20251001` |
| Google (Gemini)    | Chat completions and embeddings | `gemini-2.5-flash`          |
| Cohere (Rerank)    | Result reranking                | `rerank-v3.5`               |

Model selection is a Pro feature. Without Pro, the default model for each provider is used.

**Pro additions to this table:**

- **DeepSeek** — extra chat provider with its own API key and Test button. Hidden by default; enable it with the `fe_search_ai_show_deepseek_provider` filter.
- **Custom Endpoint (Chat)** — OpenAI-compatible chat completions endpoint (e.g. a local LLM server). Endpoint URL plus an optional API key.
- **Custom Endpoint (Embedding)** — OpenAI-compatible embeddings endpoint (e.g. `http://localhost:11434/v1/embeddings` for a self-hosted model). Adds a "Custom (OpenAI-Compatible)" choice to the Vectorization AI dropdown.

Built-in rate limits protect your API quota: **50 requests per hour per IP address** and **1,000 requests per day per site**. Adjust them with the `fe_search_ai_rate_limit_settings` filter (see [Developer Hooks](hooks.md)).

### Chat AI

The provider that generates answers: OpenAI, Anthropic, or Google.

### Vectorization AI

The provider that converts content and questions into embedding vectors: OpenAI (`text-embedding-3`) or Google (`text-embedding-004`). The embedding provider determines the vector dimensions required by your Qdrant collection — changing it requires a compatible collection and a full reindex.

### Rerank AI

The provider used for reranking. Only Cohere is supported in the free version. Requires a Cohere API key; see Reranker Settings in the Advanced tab.

## Models tab (Pro)

Appears when a Pro license is active. Each provider gets a dropdown of preset models plus a custom model name field that overrides the preset.

| Field                           | Presets                                                                    |
| ------------------------------- | -------------------------------------------------------------------------- |
| **OpenAI (GPT) Model**          | `gpt-5.4-mini` (default), `gpt-5.4`                                        |
| **Anthropic (Claude) Model**    | `claude-haiku-4-5-20251001` (default), `claude-sonnet-4-6`                 |
| **Google (Gemini) Model**       | `gemini-2.5-flash` (default), `gemini-2.5-pro`                             |
| **Cohere Rerank Model**         | `rerank-v3.5` (default), `rerank-v4.0-fast`, `rerank-v4.0-pro`             |
| **Custom chat model name**      | Model ID sent to the Custom Endpoint (Chat).                               |
| **Custom embedding model name** | Model ID sent to the Custom Endpoint (Embedding), e.g. `nomic-embed-text`. |

A **DeepSeek Model** row (`deepseek-chat`, `deepseek-coder`) appears only when the `fe_search_ai_show_deepseek_provider` filter is enabled.

Changing the embedding model changes vector dimensions — see the note under Vectorization AI and rebuild the index after changing it.

## Sync tab

See [Sync System](sync.md) for how indexing works end to end.

### Content to Sync

#### Sync Targets

An accordion with one entry per public post type (attachments excluded). For each post type:

- **Enabled checkbox** in the accordion title — include the post type in sync (posts and pages are enabled by default).
- **Include in Chunk Data** — which fields are indexed: Post Title, Post Content, Post Date, Post Author, and each public taxonomy.
- **Custom Fields (Post Meta)** (Pro) — checkbox plus a textarea for meta keys (comma- or newline-separated) to include in chunk data. Useful when essential content lives in ACF or other custom fields.
- **Taxonomy options** — per taxonomy, choose _Include only specified term IDs_ or _Exclude specified term IDs_, with a comma-separated term ID list. Leave the list empty to include all terms.

A post type is skipped entirely if none of its "Include in Chunk Data" items are checked.

#### Only Sync Specific Posts

Comma-separated post IDs. When set, only these posts are synced — post-type rules are ignored, although each post type's metadata settings still apply.

#### Exclude Specific Posts

Comma-separated post IDs to skip even when they match the post-type rules.

#### Data Storage

Checkboxes for the storage backends used for chunks and vectors:

- **WordPress database** — keyword index (BM25) in `{prefix}fe_search_ai_vectors` and `{prefix}fe_search_ai_keyword_index`. No external service required.
- **Qdrant (external vector database)** — semantic vector search. Requires the Qdrant connection settings in the Advanced tab.

#### Hybrid Search

When enabled (default), retrieval combines the WordPress keyword index and Qdrant vector search, fused with Reciprocal Rank Fusion. Requires **both** storage backends.

#### Embeddings from Summaries

When enabled (default), each chunk is first summarized into structured topics, facts, entities, and keywords by the chat provider, and the summary — not the raw text — is embedded. Usually improves matching for broad queries, at the cost of extra API calls during sync.

#### Sync Limit

Maximum number of posts synced, counted from the most recent. Default `100`; `-1` syncs all eligible posts. Useful for trial syncs and cost control.

#### Batch Size

Posts processed per AJAX batch. Default `10`, range 1–100. Lower values reduce timeout risk on shared hosting; higher values finish faster.

### Tuning (Pro)

A **Tuning** section is appended to the Sync tab when a Pro license is active.

#### Custom Stop Words (Keyword Search)

Comma-separated words excluded from the keyword index and from search-query tokenization. Stop words affect **BM25 keyword search only** — embeddings and vector search are unaffected.

- The built-in per-language list is always applied; view it under **View Default Stop Words** — no need to re-add those.
- Entries are normalized before merging: Japanese words get half-width/lowercase normalization, other languages lowercase only. Per-word normalization is filterable via `fe_search_ai_normalize_custom_stop_word`.
- Useful for site-specific terms that appear everywhere and therefore hurt keyword ranking (e.g. your own company or product name).
- After changing the list, run **Rebuild Index** so the keyword index is rebuilt without the new stop words.

Developers can also modify the effective list programmatically with the `fe_search_ai_stop_words` filter (see [Developer Hooks](hooks.md)).

### Synchronization

Index status and sync buttons — see [Sync System](sync.md).

## Prompts tab

| Field                  | Description                                                                                                                                                            |
| ---------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Site Name (AI)**     | Name the AI should use for this site; fills the `{site_name}` placeholder. Defaults to the site title.                                                                 |
| **Site Purpose (AI)**  | What the site provides and what users look for; fills the `{site_purpose}` placeholder. Defaults to the site tagline.                                                  |
| **Base System Prompt** | Full instruction text sent to the chat model. Leave empty to use the built-in prompt. Placeholders such as `{site_name}` and `{site_purpose}` are expanded at runtime. |
| **Structured Output**  | Request JSON-structured responses where the provider supports it; unsupported providers fall back to plain text. Off by default.                                       |

Example of a **Site Purpose (AI)** description (for a job board site):

```text
This site is a job listing site.
The main content type is job postings.

Each piece of content may include the following metadata:
- Region (prefecture, overseas)
- Industry
- Employment type (part-time, full-time, contract, temporary staffing, etc.)
- Salary / compensation
- Keywords / tags

Users mainly ask questions to find jobs matching their preferred conditions.
Where relevant, use the metadata to narrow down, compare, and summarize search results.
```

Describing the content types, available metadata, and users' typical goals improves how the AI interprets search results.

### Prompt placeholders

Placeholders are expanded in the system prompt at request time:

| Placeholder         | Expanded value                                                                                                                                                                    |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `{site_name}`       | Site Name (AI), or the site title.                                                                                                                                                |
| `{site_purpose}`    | Site Purpose (AI), or the site tagline.                                                                                                                                           |
| `{site_url}`        | The site URL.                                                                                                                                                                     |
| `{user_question}`   | The visitor's question (after PII/forbidden-word preprocessing).                                                                                                                  |
| `{context_content}` | Retrieved content chunks (`Title` / `URL` / `Metadata` / `Content` per item), plus linked legal documents. `[No relevant information found for this query]` when nothing matched. |

The built-in prompt instructs the model to answer only from the search results, cite results as Markdown links, say when information was not found, and respond in the question's language. The fully expanded prompt is filterable via `fe_search_ai_final_system_prompt` (see [Developer Hooks](hooks.md)).

### Model-specific Custom Prompts (Pro)

An accordion with one textarea per chat provider (OpenAI, Anthropic, Google, Custom OpenAI-compatible, and DeepSeek when enabled). Overrides the Base System Prompt for that provider only; blank entries fall back to the standard prompt. Use it to tune instruction style for each model's quirks.

## Display tab

### Floating Mode Settings

| Field                                  | Description                                                                                |
| -------------------------------------- | ------------------------------------------------------------------------------------------ |
| **Enable floating chat**               | Show the chat bubble across the site (on by default).                                      |
| **Login status**                       | Show to logged-in users / non-logged-in users (both on by default).                        |
| **Display device**                     | Show on PC / Mobile (both on by default).                                                  |
| **Conditions for Displaying the Chat** | Page types: Home, Archive, Search result page, 404 page, Single pages (all on by default). |
| **Display only with these post IDs**   | Comma-separated IDs. When set, all other display rules are ignored.                        |
| **Do not display with these post IDs** | Comma-separated IDs to exclude.                                                            |

### Embed Mode

Displays the `[fe-search-ai]` shortcode for manual placement. See [Search Integration](search.md).

### Chat UI Appearance

**Text & Colors:**

| Field                        | Default                                               |
| ---------------------------- | ----------------------------------------------------- |
| Chat window title            | `FE Search AI`                                        |
| First greeting               | `Hello! I am FE Search AI. How can I help you today?` |
| Input field placeholder      | `Ask a question about this site…`                     |
| Submit button text           | `Send`                                                |
| Bubble / Send Button Color   | `#E9E9E9`                                             |
| Gradient (optional)          | start `#00AFFE`, end `#973CFF`, angle `135`°          |
| Animate the bubble           | On; slowly animates the gradient                      |
| Chat window background color | `#FFFFFF`                                             |
| Base text color              | `#333333`                                             |

**Interaction:**

- **Typing Animation Speed** — slider 1 (smooth) to 10 (fast); default `7`.
- **Send Key Settings (Default)** — `Enter`, `Shift+Enter`, or `Cmd/Ctrl+Enter` to send. Visitors can override this in the chat's own settings menu.

**Footer Notice:** text shown at the bottom of the chat window, next to the settings icon. Blank uses the default AI-disclaimer text.

### Fullscreen Page (Pro)

A page dropdown appended to the Display tab. When a static page is selected, visiting that page renders the chat UI full-screen — a dedicated "talk to the AI" page without a floating bubble.

## Privacy tab

- **Current Data Handling** — a live summary of active recipients (which services receive visitor input), server-side retention periods for each record class, and whether diagnostic logging is enabled.
- **Legal Documents** — Terms of Service and Privacy Policy pages shown in the chat's privacy notice. If no privacy page is selected, the page configured in WordPress's own privacy settings is used.

See [Privacy and Data Handling](privacy.md) for the full data processing map.

### User Consent (Pro)

An opt-in consent layer shown to visitors before they use the chat.

| Field                                                              | Default       | Description                                                                                                                                   |
| ------------------------------------------------------------------ | ------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Require user consent on first use                                  | Off           | Visitors must agree to the Terms of Service and Privacy Policy before chatting. Requires both legal pages to be configured above.             |
| Save diagnostic conversation summaries while Debug Mode is enabled | Off           | Stores lengths, context status, and operational metadata — never question/answer text.                                                        |
| Offer optional conversation analytics consent                      | Off           | Visitors may opt in to storing PII- and forbidden-word-masked question/answer text for service improvement. Refusing does not block chat use. |
| Conversation log retention                                         | 7 days        | Days before diagnostic summaries and opt-in analytics logs are deleted (1–365).                                                               |
| Expired consent record retention                                   | 180 days      | Days before revoked/obsolete consent records are deleted (1–3650).                                                                            |
| Required terms / optional analytics checkbox labels                | Built-in text | Labels shown next to each consent checkbox.                                                                                                   |
| Consent screen message                                             | Built-in text | Notice text; `%s` placeholders expand to the Terms/Privacy URLs. HTML allowed.                                                                |

The tab also shows the **current consent version** hash — it changes whenever disclosed terms change, and visitors are asked to consent again. See [Privacy and Data Handling](privacy.md) for what is stored.

## Advanced settings tab

### Reranker Settings

Requires a Cohere API key in the Providers tab.

| Field                              | Default | Range | Description                                                            |
| ---------------------------------- | ------- | ----- | ---------------------------------------------------------------------- |
| Enable reranker                    | On      | —     | Reorder retrieved chunks with Cohere before sending them to the LLM.   |
| Top N chunks for LLM               | `5`     | 1–20  | Only the top N reranked chunks are sent to the model.                  |
| Initial candidates (vector search) | `50`    | 5–200 | Candidates fetched from Qdrant before reranking.                       |
| Hybrid candidate limit             | `50`    | 5–200 | Per-source candidates when hybrid search is on.                        |
| Rerank timeout (sec)               | `15`    | 1–60  | Timeout for the Cohere request; on timeout the original order is kept. |

### Qdrant Settings

| Field               | Description                                                                                    |
| ------------------- | ---------------------------------------------------------------------------------------------- |
| **Qdrant Endpoint** | Base URL of the Qdrant HTTP API, including port (e.g. `https://your-instance.qdrant.io:6333`). |
| **Qdrant API Key**  | Stored encrypted; leaving the field empty keeps the saved key.                                 |
| **Collection Name** | Collection used for this site. The collection's vector size must match the embedding model.    |

Note: Qdrant Cloud **free** clusters may delete collections after a period of inactivity.

For creating a cluster and collection (including the required vector size), see [External Service Setup](services.md).

### Japanese Tokenizer

Shown only when the site locale is `ja` / `ja_JP`. Affects keyword (BM25) indexing and search — not used when only a vector database is in use.

- **Engine**: `Built-in (TinySegmenter)` (default, requires PHP 8.0+) or `Yahoo! Japanese MA API`. On PHP < 8.0, Yahoo! MA is forced and the selector is disabled.
- **Yahoo! App ID**: stored encrypted; can be overridden by defining `FE_SEARCH_AI_YAHOO_APP_ID` in `wp-config.php`, which takes priority. For how to obtain the ID, see [External Service Setup](services.md).

### Advanced Settings

| Field                              | Description                                                                                                                                                                                               |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Load default plugin CSS**        | On by default. Uncheck to style the chat UI entirely with your theme.                                                                                                                                     |
| **Load default plugin JavaScript** | On by default. Uncheck only if you replace the chat UI HTML and handle API communication yourself.                                                                                                        |
| **Debug Mode**                     | Writes operational logs to `{prefix}fe_search_ai_system_logs`. Enable only while troubleshooting; it can impact performance.                                                                              |
| **Log Retention (days)**           | Days to keep system logs before the daily rotation deletes them. Default `30`, range 1–365.                                                                                                               |
| **Retrieval Trace Persistence**    | Stores retrieval traces (hashed queries, post IDs, ranking scores — never question or answer text) for search-quality analysis. Off by default, with a configurable retention period (default `30` days). |

### External API (Pro)

The Advanced tab gains two Pro sections:

- **API Token Management** — generate and revoke named Bearer tokens (`fesai_tk_...`) for the `/query` and MCP endpoints.
- **MCP Integration** — ready-to-paste MCP client configuration and the exposed tool list.

See [MCP Integration](mcp.md) for setup and endpoint details.

### Data Management

Destructive maintenance actions, each with an explicit button:

- **Delete Synced Data** — removes all vectors and keyword indexes; AI search stops working until you sync again.
- **Delete System Logs** — truncates `{prefix}fe_search_ai_system_logs`.
- **Delete Retrieval Traces** — truncates `{prefix}fe_search_ai_retrieval_traces` and `{prefix}fe_search_ai_retrieval_trace_items`.
- **Delete Conversation Logs** (Pro) — truncates `{prefix}fe_search_ai_logs`.
- **Delete Consent Records** (Pro) — delete only revoked/obsolete consent records, or all records in `{prefix}fe_search_ai_consents`.

### Delete Data on Uninstall

When enabled, all plugin tables and settings are removed on uninstall. Leave it off to preserve settings and sync data across reinstalls.

## Security (Pro)

The **Security** tab appears when a Pro license is active.

### Forbidden Words List

A comma-separated list of words and phrases to redact. Matches are replaced with `[REDACTED]` (case-insensitive) in **both** visitor input and AI responses — the filter hooks `fe_search_ai_preprocess_user_question` and `fe_search_ai_preprocess_model_response`, so it also applies to the Pro REST `/query` and MCP endpoints. The list is stored encrypted (AES-256-CBC).

- Basic injection phrases (e.g. "ignore previous instructions") are always filtered from a built-in language file, shown under **View Default Security Filters** — you do not need to add them.
- If the custom list is left empty, a built-in English fallback list is used.
- Use it for project names, internal terms, or injection phrases specific to your site.

### API Rate Limiting

| Field                       | Default           | Description                                              |
| --------------------------- | ----------------- | -------------------------------------------------------- |
| Per-user limit (IP-based)   | 50 requests/hour  | `-1` disables the limit.                                 |
| Site-wide limit (global)    | 1000 requests/day | Safety valve against runaway API cost. `-1` disables.    |
| Administrator notifications | 80%               | Email sent when the global limit reaches this threshold. |
| Notification email          | Site admin email  | Recipient for the threshold notification.                |

## System Logs page (Pro)

Pro adds a **System Logs** submenu under the FE Search AI admin menu: a filterable list of `{prefix}fe_search_ai_system_logs` (and conversation logs) with a **CSV export** button for offline analysis.

## Encryption

All API keys and the Yahoo! App ID are encrypted before storage (`FE_Search_AI_Encryption_Helper`) and only decrypted at request time.
