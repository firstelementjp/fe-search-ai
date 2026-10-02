# Examples

Practical setups for common site types. Each recipe assumes the plugin is installed and at least one chat provider, an embedding provider, and a storage backend are configured.

## Documentation site

Index documentation pages and let visitors ask questions instead of browsing a TOC.

1. Enable the `page` post type (or your docs CPT) in Sync Targets with title and content checked.
2. If docs are organized by taxonomy, include the relevant taxonomy terms in chunk data.
3. Add `[fe-search-ai]` to the docs top page, or enable floating chat only on `page`/docs post IDs.
4. Set Site Purpose (AI) to describe the docs scope, e.g. what product/version it covers.
5. Test the top-10 support questions and iterate on the system prompt.

## Product support

1. Sync product pages, FAQ articles, and support docs.
2. Exclude checkout/account pages via Exclude Specific Posts.
3. Enable reranking (Cohere) — product names often appear in many chunks, and reranking helps the right one surface.
4. Add the floating chat site-wide, but exclude the cart/checkout pages by ID.

## Knowledge base

1. Create or reuse a custom post type for articles; enable it in Sync Targets.
2. Include taxonomies so the model can cite categories in answers.
3. If articles carry custom fields (version numbers, applicability), Pro can add them to the index.
4. Use `[fe-search-ai]` on the knowledge base top page rather than floating chat to keep the scope obvious.

## Japanese website

1. On the Advanced settings tab, confirm the Japanese Tokenizer is `Built-in (TinySegmenter)` (PHP 8.0+) or configure a Yahoo! App ID for the MA API.
2. Keep hybrid search enabled — BM25 catches exact term matches that vectors can miss, which matters more for Japanese than for space-delimited languages.
3. After changing the tokenizer, run **Rebuild Index**.
4. Test both natural-language questions and exact-term queries; put critical terms in titles and headings.

## Cost-conscious rollout

1. Set Sync Limit (e.g. `50`) and Only Sync Specific Posts for a pilot set.
2. Run **Rebuild Index** and evaluate answer quality.
3. Keep Embeddings from Summaries enabled for quality, or disable it if sync cost matters more than marginal matching gains.
4. Raise Sync Limit gradually; the built-in rate limits (50/hour per IP, 1,000/day per site) already cap chat API usage.

## Shortcode and template tag

```text
[fe-search-ai]
```

```php
<?php
if ( function_exists( 'fe_search_ai' ) ) {
    fe_search_ai();
}
```

## Setup checklist

- Configure providers.
- Choose data storage (WordPress DB, Qdrant, or both for hybrid).
- Select sync targets.
- Run **Rebuild Index**.
- Add the chat UI.
- Test and tune prompts.
