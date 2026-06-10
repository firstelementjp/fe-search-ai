# Getting Started

FE Search AI adds AI-powered natural language search and chat to WordPress. This page explains the minimum setup flow and the main concepts used by the plugin.

## Setup flow

1. Install and activate the plugin.
2. Configure at least one chat provider.
3. Configure an embedding provider.
4. Connect Qdrant for vector storage.
5. Select sync targets and run the initial sync.
6. Add the chat UI with `[fe-search-ai]` or enable floating chat.
7. Test real visitor questions and tune the prompt and UI settings.

## Main concepts

### RAG

RAG stands for Retrieval-Augmented Generation. FE Search AI first retrieves relevant site content, then passes that context to an AI provider to generate an answer grounded in your own content.

### Hybrid search

The plugin combines vector search and keyword search. Vector search helps find semantically related content, while keyword search helps preserve exact term matching.

### Sync

Content must be indexed before it can be used for AI answers. The sync system sends selected content to the embedding provider and stores vectors in Qdrant.

## Recommended first configuration

| Setting | Recommended value |
| --- | --- |
| Chat provider | OpenAI, Google, or Anthropic |
| Embedding provider | OpenAI or Google |
| Vector database | Qdrant Cloud for easiest setup |
| Rerank provider | Cohere, optional |
| Japanese tokenizer | TinySegmenter first, Yahoo! JAPAN MA API if needed |

## Next steps

- [Installation](install.md)
- [Configuration](config.md)
- [Sync System](sync.md)
- [Search Integration](search.md)
