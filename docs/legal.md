# License and External Services

## License

FE Search AI is licensed under GPL-2.0 or later.

[GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html)

## External services

FE Search AI communicates with external services selected by the site administrator.

| Service | Data sent | Purpose |
| --- | --- | --- |
| OpenAI | Questions, selected context, or content for embeddings | Chat completions and embeddings |
| Google | Questions, selected context, or content for embeddings | Gemini chat completions and embeddings |
| Anthropic | Questions and selected context | Claude chat completions |
| Qdrant | Vector data and metadata | Vector storage and retrieval |
| Cohere | Retrieved content chunks | Optional reranking |
| Yahoo! JAPAN Japanese MA API | Text for tokenization | Optional Japanese tokenization |

## Administrator responsibility

Site administrators should review each provider's terms, privacy policy, billing, data retention, and regional compliance requirements before enabling the service.

## Pro license validation

FE Search AI Pro may communicate with a FirstElement license API for license validation, activation, deactivation, and update checks. Site content is not sent to the license service.
