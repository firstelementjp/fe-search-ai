=== FE Search AI ===
Contributors: firstelementjp
Tags: ai, search, chat, conversational, semantic, vector, embedding, rag
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 0.9.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered, conversational search for your WordPress site.

== Description ==

FE Search AI replaces your standard WordPress search with a smart, conversational AI chat. It uses a RAG (Retrieval-Augmented Generation) model to provide accurate answers based only on your website's content, preventing hallucinations.

This plugin indexes your posts, pages, and custom post types into a custom database table, creating vector embeddings and keyword indexes. When a user asks a question, it finds the most relevant content from your site and uses it to generate a precise, helpful answer.

== Features ==

* **Conversational AI Chat UI**: Adds a customizable chat bubble and window (via Shortcode, Block, or PHP function)
* **Multiple AI Providers**: Supports major Generation Models (LLMs) out of the box
  * OpenAI (GPT-4o mini, etc.)
  * Google (Gemini)
  * Anthropic (Claude)
* **Multiple Embedding Models**: Supports major vectorization models
  * OpenAI (text-embedding-3-small)
  * Google (text-embedding-004)
* **Content Synchronization**:
  * **Manual Sync**: A dashboard to build or rebuild the entire search index
  * **Smart Sync**: Processes only new, updated, or deleted content to save time and API costs
  * **Real-time Sync**: Automatically indexes new or updated posts the moment they are published
* **Advanced Japanese Support**:
  * Includes the lightweight TinySegmenter for Japanese word segmentation
  * Optional Yahoo! Japanese Morphological Analysis API integration for higher-accuracy tokenization
* **Developer Friendly**: Packed with filter hooks to customize everything from the UI to the tokenizer

== Installation ==

1. Download the latest stable release from the WordPress.org Plugin Directory
2. Go to your WordPress Admin > Plugins > Add New > Upload Plugin
3. Upload the .zip file, install, and activate

== Frequently Asked Questions ==

= Does this plugin work with any WordPress theme? =

Yes, FE Search AI is theme-independent and works with any WordPress theme.

= Do I need an API key? =

Yes, you need an API key from at least one of the supported AI providers (OpenAI, Google, or Anthropic).

= Is my data sent to third-party services? =

Only the content you want to search and user questions are sent to your chosen AI provider for processing. No data is stored on third-party servers.

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
