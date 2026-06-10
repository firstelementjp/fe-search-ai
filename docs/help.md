# Troubleshooting

## The chat does not answer

Check these items first:

1. Chat provider API key is configured.
2. Embedding provider API key is configured.
3. Qdrant settings are valid.
4. Content has been synced.
5. The frontend page contains `[fe-search-ai]` or floating chat is enabled.

## Answers are not relevant

- Rebuild the index after changing sync settings.
- Confirm the relevant posts are included in sync targets.
- Improve source content titles and headings.
- Enable Cohere reranking if available.
- Adjust the system prompt.

## Sync fails

- Reduce batch size.
- Check server memory and timeout limits.
- Confirm provider API quota.
- Confirm Qdrant collection exists and vector size matches the embedding model.

## API key test fails

- Confirm there are no extra spaces in the key.
- Check provider account billing and quota status.
- Confirm the selected model is available for your account.
- Review provider dashboard logs if available.

## Japanese search quality is poor

- Confirm the site locale and tokenizer settings.
- Try Yahoo! JAPAN Japanese MA API if TinySegmenter is insufficient.
- Add important terms explicitly to page titles or headings.

## Where to get help

- [GitHub Issues](https://github.com/firstelementjp/fe-search-ai/issues)
- [Support](https://www.firstelement.co.jp/en/contact)
