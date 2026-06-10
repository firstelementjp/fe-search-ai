# Search Integration

FE Search AI provides a chat-style search UI that can be embedded into WordPress pages.

## Shortcode

Add the shortcode to any post, page, or block editor content:

```text
[fe-search-ai]
```

## Floating chat

The floating chat UI can be enabled from the display settings. This is useful when you want the AI search assistant to be available across the site.

## Recommended placement

- Documentation top page
- Help center or FAQ page
- Product support pages
- Knowledge base pages
- Landing pages where visitors often ask questions

## Answer behavior

The chat UI sends the visitor's question to WordPress, retrieves relevant indexed content, and streams or returns an AI-generated answer depending on provider behavior and browser support.

## Tuning answer quality

If answers are too broad or inaccurate:

1. Check whether the relevant content is indexed.
2. Review sync target settings.
3. Adjust the system prompt.
4. Enable reranking if available.
5. Improve source content titles and body text.

## Theme compatibility

The UI is designed to work with standard WordPress themes. If your theme has aggressive CSS resets, adjust chat colors and layout settings or add custom CSS.
