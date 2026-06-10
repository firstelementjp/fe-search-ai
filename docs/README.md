# FE Search AI Documentation

> AI-powered natural language search and chat for WordPress

FE Search AI adds a conversational AI search experience to WordPress. It indexes your site content, retrieves relevant context with hybrid search, and generates natural language answers grounded in your own posts, pages, and custom post types.

![FE Search AI](assets/images/img-sns-banner-100.jpg)

## What FE Search AI does

FE Search AI is designed for WordPress sites where visitors need quick, accurate answers from existing content.

- **Conversational AI search**: Answer visitor questions in natural language.
- **RAG architecture**: Retrieve relevant content before generating answers.
- **Hybrid search**: Combine vector search and keyword search for better recall.
- **Multiple AI providers**: Use OpenAI, Google Gemini, or Anthropic Claude.
- **Embedding support**: Generate vectors with OpenAI or Google embedding models.
- **Qdrant integration**: Store and retrieve vectors with Qdrant Cloud or self-hosted Qdrant.
- **Japanese support**: Use TinySegmenter or Yahoo! JAPAN Japanese MA API.
- **Customizable chat UI**: Embed a floating or inline chat interface with a shortcode.
- **Developer hooks**: Extend providers, prompts, sync behavior, and frontend output.

## Recommended reading

- [Getting Started](start.md): Understand the basic setup flow.
- [Installation](install.md): Install the plugin and confirm requirements.
- [Configuration](config.md): Configure providers, Qdrant, sync, and chat UI.
- [Search Integration](search.md): Add the chat/search UI to your site.
- [Sync System](sync.md): Build and maintain the content index.
- [Troubleshooting](help.md): Resolve common setup and API issues.

## Basic workflow

1. Install and activate FE Search AI.
2. Configure API keys for chat, embedding, and optional reranking.
3. Connect Qdrant for vector storage.
4. Select content types and build the initial index.
5. Add `[fe-search-ai]` to a page or enable the floating chat UI.
6. Test visitor questions and adjust prompts, colors, and sync settings.

## External services

FE Search AI communicates with external services selected by the site administrator.

| Service                      | Purpose                                |
| ---------------------------- | -------------------------------------- |
| OpenAI                       | Chat completions and embeddings        |
| Google                       | Gemini chat completions and embeddings |
| Anthropic                    | Claude chat completions                |
| Qdrant                       | Vector storage and retrieval           |
| Cohere                       | Optional reranking                     |
| Yahoo! JAPAN Japanese MA API | Optional Japanese tokenization         |

Review each provider's terms and privacy policy before enabling it.

## Pro version

FE Search AI works as a free plugin, and FE Search AI Pro adds advanced features for production and business use.

- Advanced model selection and model-specific prompts
- Custom OpenAI-compatible endpoints
- Custom field indexing
- Fullscreen chat mode
- User consent and enhanced privacy controls
- External API token management
- MCP server integration for AI agents
- Advanced logging and export features
- Priority support and automatic updates

[Learn more about FE Search AI Pro](https://www.firstelement.co.jp/en/products/fe-search-ai-plugin/)

## Support

- [GitHub Repository](https://github.com/firstelementjp/fe-search-ai)
- [GitHub Issues](https://github.com/firstelementjp/fe-search-ai/issues)
- [Documentation](https://firstelementjp.github.io/fe-search-ai/#/)
- [Support](https://www.firstelement.co.jp/en/contact)
