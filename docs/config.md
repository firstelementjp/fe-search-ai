# Configuration

FE Search AI settings are organized by provider, sync, display, and advanced options.

## Provider settings

Configure API keys for the services you want to use.

| Provider | Purpose |
| --- | --- |
| OpenAI | Chat completions and embeddings |
| Google | Gemini chat completions and embeddings |
| Anthropic | Claude chat completions |
| Cohere | Optional reranking |
| Qdrant | Vector storage |
| Yahoo! JAPAN Japanese MA API | Optional Japanese tokenization |

## Qdrant settings

Qdrant stores vector embeddings for your site content.

Required values usually include:

- Endpoint URL
- API key
- Collection name
- Vector size matching the selected embedding model

If you use Qdrant Cloud, confirm your cluster is active before syncing content.

## Chat provider

Select the provider used to generate answers. The free version supports OpenAI, Google, and Anthropic. Pro may add more model choices and OpenAI-compatible custom endpoints.

## Embedding provider

Select the provider used to convert content into vectors. The embedding provider must match the vector size configured in Qdrant.

## Rerank provider

Cohere reranking is optional. It can improve answer quality by reordering retrieved chunks before they are sent to the chat model.

## Sync settings

Choose which post types should be indexed. Start with a small set of content, verify answer quality, then expand the scope.

## Display settings

Customize frontend chat labels, colors, greeting text, placeholders, and floating chat behavior.

## Privacy and rate limiting

Use rate limiting to protect your API quota. Review external service terms and privacy policies before enabling providers.
