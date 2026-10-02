# Search Integration

FE Search AI provides a chat-style search UI that can be embedded into WordPress pages. There are two display modes, which can be used together.

## Embed mode (shortcode)

Add the shortcode to any post, page, or block editor content:

```text
[fe-search-ai]
```

The legacy `[fe_search_ai]` tag (with underscores) is also supported for backward compatibility.

### Template tag

For theme templates, use the `fe_search_ai()` template tag, which outputs the same chat UI:

```php
<?php
if ( function_exists( 'fe_search_ai' ) ) {
    fe_search_ai();
}
```

The chat UI renders only once per page — if both a shortcode and a floating chat would output, duplicates are suppressed.

## Floating chat

Enable **Enable floating chat** in the Display tab to show a chat bubble across the site. Display rules control:

- login status (logged-in / guest)
- device (PC / mobile)
- page types (home, archives, search results, 404, single pages)
- per-post include/exclude ID lists

See [Configuration](config.md) for the full field reference. The `fe_search_ai_should_display_chat` filter can override the result programmatically.

## How an answer is produced

When a visitor sends a question:

1. The question is sanitized and basic prompt-injection patterns are filtered (`fe_search_ai_preprocess_user_question`).
2. Relevant chunks are retrieved — BM25 keyword search from the WordPress index, vector search from Qdrant, or both merged with RRF when hybrid search is on.
3. Retrieved chunks can be filtered (`fe_search_ai_retrieved_chunks`); the built-in Cohere reranker runs here when enabled.
4. The top N chunks are passed to the chat provider, which streams the answer back.

## REST endpoint

The chat UI communicates with a public REST endpoint:

```text
POST /wp-json/fe-search-ai/v1/stream
```

Responses use Server-Sent Events (SSE) so answers stream in token by token. The endpoint is rate-limited per IP and per site (see [Configuration](config.md) and the `fe_search_ai_rate_limit_settings` filter), enforces the built-in request nonce, and applies the same privacy preprocessing as the rest of the pipeline. Pro adds a `/query` endpoint and an [MCP server](mcp.md) for external AI agents.

## Visitor-facing features

The chat window includes its own settings menu (gear icon) where visitors can:

- change the send key (Enter / Shift+Enter / Cmd/Ctrl+Enter) — the admin default comes from Display → Interaction;
- clear local chat history stored in the browser session;
- review which external services receive their input (privacy notice);
- withdraw privacy consent when Pro consent is enabled.

## Recommended placement

- Documentation top page
- Help center or FAQ page
- Product support pages
- Knowledge base pages
- Landing pages where visitors often ask questions

## Tuning answer quality

If answers are too broad or inaccurate:

1. Check whether the relevant content is indexed.
2. Review sync target settings.
3. Adjust the system prompt and Site Purpose.
4. Enable reranking if available.
5. Improve source content titles and body text.

See [Troubleshooting](help.md) for common issues.

## Theme compatibility

The UI is designed to work with standard WordPress themes. If your theme has aggressive CSS resets, adjust chat colors and layout settings, add custom CSS, or disable the bundled CSS/JS under Advanced settings → Assets Loading.
