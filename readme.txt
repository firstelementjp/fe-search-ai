=== FE Search AI ===
Contributors: firstelementjp
Tags: ai, search, chat, semantic, vector
Requires at least: 6.6
Tested up to: 7.0
Stable tag: 0.9.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered, conversational search for your WordPress site.

== Description ==

FE Search AI transforms your WordPress site into an intelligent conversational experience. Replace traditional search with a smart AI chat that understands your content and provides accurate, context-aware answers.

Built on RAG (Retrieval-Augmented Generation) architecture, this plugin ensures responses are grounded in your actual website content—eliminating AI hallucinations while delivering helpful, precise answers to your visitors' questions.

**Key capabilities:**

* Indexes posts, pages, and custom post types with vector embeddings and keyword search
* Retrieves relevant content in real-time to generate accurate answers
* Supports multiple AI providers (OpenAI, Google, Anthropic) for flexibility
* Advanced Japanese language support with built-in tokenization
* Privacy-focused with optional debug logging and rate limiting
* Fully customizable via extensive filter hooks

Perfect for knowledge bases, documentation sites, e-commerce support, and any WordPress site where visitors need quick, accurate answers from your content.

== Features ==

* **RAG-Powered Search**: Uses Retrieval-Augmented Generation to provide accurate answers based only on your website's content, preventing AI hallucinations
* **Conversational AI Chat UI**: Customizable chat bubble and window (via Shortcode, Block, or PHP function)
* **Multiple AI Providers**: Supports major LLMs out of the box
  * OpenAI (GPT-4o mini, etc.)
  * Google (Gemini)
  * Anthropic (Claude)
* **Multiple Embedding Models**: Supports major vectorization models
  * OpenAI (text-embedding-3-small)
  * Google (text-embedding-004)
* **Hybrid Search**: Combines keyword search with vector search for optimal results
* **Content Synchronization**:
  * **Manual Sync**: Dashboard to build or rebuild the entire search index
  * **Smart Sync**: Processes only new, updated, or deleted content to save time and API costs
  * **Real-time Sync**: Automatically indexes new or updated posts when published
* **Advanced Japanese Support**:
  * Built-in TinySegmenter for Japanese word segmentation
  * Optional Yahoo! Japanese MA API for higher-accuracy tokenization
* **Privacy & Security**:
  * Debug mode with privacy-protected logging (no sensitive data by default)
  * Rate limiting (IP-based and global)
  * PII filtering for conversation logs
* **Developer Friendly**: Extensive filter hooks for customization
* **Prompt Customization**: Adjustable system prompts for fine-tuned responses

== Installation ==

1. Download the latest stable release from the WordPress.org Plugin Directory
2. Go to your WordPress Admin > Plugins > Add New > Upload Plugin
3. Upload the .zip file, install, and activate

== External Services ==

FE Search AI communicates with the following external services to provide AI-powered search functionality:

= AI Providers =

The plugin sends user questions and relevant content chunks to your chosen AI provider for answer generation. No data is stored on AI provider servers.

* **OpenAI** - Used for chat completions (GPT models) and text embeddings
* **Google** - Used for chat completions (Gemini models) and text embeddings
* **Anthropic** - Used for chat completions (Claude models)

= Vector Database =

* **Qdrant** - Used for storing and retrieving vector embeddings. Can be self-hosted or use Qdrant Cloud. When using Qdrant Cloud, vector data is stored on their servers.

= Japanese Morphological Analysis =

* **Yahoo! Japanese MA API** - Optional service for Japanese word segmentation. Only used when explicitly configured for Japanese sites. Query text is sent to Yahoo! for tokenization.

= Reranking Service =

* **Cohere** - Optional service for reranking search results to improve answer quality. Content chunks are sent to Cohere for reranking when enabled.

= License Validation (Pro Version) =

FE Search AI Pro uses an external license API service for license validation and activation. This service is used solely for:

* License key validation
* License activation and deactivation
* License status checks

[Privacy Policy](https://www.firstelement.co.jp/en/legal/privacy-policy/privacy-statement-eu/)
[Terms of Service](https://www.firstelement.co.jp/en/legal/terms-of-use/fe-search-ai-pro/)

The license API service is operated by FirstElement K.K. and is used exclusively for license management purposes. No content data is transmitted to this service.

== Pro Version ==

FE Search AI works completely on its own with the free version and provides sufficient functionality, but when used with the Pro version, you can access these advanced features:

* **Advanced Model Selection** - Access to premium AI models including GPT-5.4, Claude Sonnet 4.6, Gemini 2.5 Pro, and Rerank 4.0 with per-model custom system prompts
* **Custom OpenAI-Compatible Endpoints** - Support for self-hosted solutions including Ollama, LocalAI, and other OpenAI-compatible API endpoints
* **Additional AI Providers** - Support for DeepSeek and Mistral (planned)
* **AI Provider Failover** - Automatic failover to backup AI providers when primary provider fails (planned)
* **Custom Field Indexing** - Include custom fields in sync targets for comprehensive search coverage
* **Fullscreen Chat Mode** - Fullscreen chat interface for immersive user experience
* **User Consent (Opt-in)** - Privacy-compliant user consent functionality for conversation logging
* **Enhanced API Management** - Advanced rate limiting, cost tracking and notifications, usage analytics and reporting
* **Security Features** - Securely encrypted "Forbidden Words" list, enhanced content filtering, and advanced access controls
* **MCP Server Integration** - Act as a tool for external AI agents
* **API Token Management** - Generate and manage API tokens for using site search from headless CMSs and external applications
* **Advanced Logging** - Detailed communication logs in dedicated log table with view and download functionality. Conversation logs can be exported for evaluation purposes
* **User Feedback System** - End-user feedback functionality for AI responses to improve answer quality
* **Priority Support** - Dedicated technical assistance from the development team
* **Automatic Updates** - Seamless updates from our update server

[Pro Version](https://www.firstelement.co.jp/en/products/fe-search-ai-plugin/)

== Frequently Asked Questions ==

= Does this plugin work with any WordPress theme? =

Yes, FE Search AI is theme-independent and works with any WordPress theme.

= Do I need an API key? =

Yes, you need an API key from at least one of the supported AI providers (OpenAI, Google, or Anthropic).

= Is my data sent to third-party services? =

Yes, this plugin communicates with external services to provide AI-powered search functionality. Please see the "External Services" section below for detailed information about which services are used and what data is transmitted.

= Can I customize the chat interface? =

Yes, the plugin includes numerous customization options and filter hooks for developers.

== Screenshots ==

1. Admin dashboard with settings and sync options
2. Frontend chat interface example
3. Configuration settings for AI providers

== Changelog ==

= 0.9.0 =
* Initial release
* Core AI search functionality
* Multiple AI provider support
* Japanese language support
* Developer hooks and filters

== Upgrade Notice ==

= 0.9.0 =
Initial release of FE Search AI.

== Arbitrary section ==

This plugin is designed to be highly extensible and developer-friendly. For detailed documentation and API reference, please visit the plugin's GitHub repository.
