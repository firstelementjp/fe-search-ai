# FE Search AI

[](https://fe-search.com/ai/)
[](https://www.google.com/search?q=https://wordpress.org/plugins/fe-search-ai/)
[](https://www.gnu.org/licenses/gpl-2.0.html)

**AI-powered, conversational search for your WordPress site.**

FE Search AI replaces your standard WordPress search with a smart, conversational AI chat. It uses a RAG (Retrieval-Augmented Generation) model to provide accurate answers based _only_ on your website's content, preventing hallucinations.

---

## Overview

This plugin indexes your posts, pages, and custom post types into a custom database table, creating vector embeddings and keyword indexes. When a user asks a question, it finds the most relevant content from your site and uses it to generate a precise, helpful answer.

It supports multiple major AI providers and is built to be highly extensible and performant, even on standard MySQL databases.

## Features

- **Conversational AI Chat UI**: Adds a customizable chat bubble and window (via Shortcode, Block, or PHP function).
- **Multiple AI Providers**: Supports major Generation Models (LLMs) out of the box.
    - OpenAI (GPT-4o mini, etc.)
    - Google (Gemini)
    - Anthropic (Claude)
- **Multiple Embedding Models**: Supports major vectorization models.
    - OpenAI (`text-embedding-3-small`)
    - Google (`text-embedding-004`)
- **Content Synchronization**:
    - **Manual Sync**: A dashboard to build or rebuild the entire search index.
    - **Smart Sync**: Processes only new, updated, or deleted content to save time and API costs.
    - **Real-time Sync**: Automatically indexes new or updated posts the moment they are published.
- **Advanced Japanese Support**:
    - Includes the lightweight `TinySegmenter` for Japanese word segmentation (わかち書き).
    - Optional **Yahoo! Japanese Morphological Analysis API (MAService)** integration for higher-accuracy tokenization when an App ID is configured.
- **Developer Friendly**: Packed with filter hooks to customize everything from the UI to the tokenizer.

## Pro & Add-Ons

Supercharge your AI search with powerful add-ons:

- **[FE Search AI Pro](https://fe-search.com/ai/)**:
    - Advanced model selection (e.g., GPT-4o, Claude 3.5 Sonnet).
    - Support for **custom OpenAI-compatible endpoints** (for `Ollama`, `LocalAI`, etc.).
    - Per-model custom system prompts.
    - Advanced API cost management (rate limiting, notifications).
    - Securely encrypted "Forbidden Words" list.
    - **MCP Server**: Act as a tool for external AI agents.

---

## Installation

### For General Users (Recommended)

1.  Download the latest stable release from the [WordPress.org Plugin Directory](https://wordpress.org/plugins/fe-search-ai/).
2.  Go to your WordPress Admin \> Plugins \> Add New \> Upload Plugin.
3.  Upload the `.zip` file, install, and activate.

### For Developers (from GitHub)

This repository is for development. The `vendor/` directory (containing required libraries) is not included here. You must use Composer to build the plugin.

1.  Clone this repository into your `wp-content/plugins/` directory:
    ```bash
    git clone https://github.com/firstelementjp/fe-search-ai.git
    ```
2.  Navigate into the plugin's directory:
    ```bash
    cd fe-search-ai
    ```
3.  Install the PHP dependencies:
    ```bash
    composer install --no-dev
    ```
4.  Activate the plugin from your WordPress Admin \> Plugins.

---

## Configuration

1.  Go to **FE Search AI \> Settings**.
2.  **API Settings**: Enter your API keys for OpenAI, Google, or Anthropic.
3.  **Sync Options**: Select which post types and content (titles, content, author nicknames) you want to include in the index.
4.  **Run the Indexer**: Go to the **Sync** tab and click **"Rebuild Index"**. This is a mandatory first step to build your search index.

You're all set\! The chat UI will now be available on your site.

---

## For Developers

This plugin is designed to be highly extensible.

### Japanese Tokenization with Yahoo! Japanese MA API

This plugin includes built-in support for Japanese tokenization. By default, it uses a lightweight PHP implementation of **TinySegmenter** to split Japanese text into words.

For higher accuracy, especially on longer or more complex Japanese content, you can optionally enable the **Yahoo! Japanese Morphological Analysis API (MAService)**:

1.  Obtain a Yahoo! JAPAN Developer Network **Application ID (App ID)**.
2.  Go to **FE Search AI  Settings  Advanced settings** and select **"Yahoo! Japanese MA API"** as the tokenizer.
3.  Enter your App ID in the **Yahoo! Japanese MA API App ID** field.

When an App ID is configured and the Yahoo! tokenizer is selected, FE Search AI will call the Yahoo! MA API to perform morphological analysis and use the returned tokens as the basis for Japanese search.

For privacy, debug logs related to Yahoo! MA calls do **not** store the full user question text. Instead, only metadata such as token counts and status codes are recorded.

### Key Filter Hooks

- **`fe_ai_search_chat_ui_html`**: Completely override the HTML of the chat UI.
- **`fe_ai_search_system_prompt`**: Modify the base system prompt before it's sent to the AI.
- **`fe_ai_search_tokenize_text`**: Implement your own custom tokenizer for any language.
- **`fe_ai_search_stop_words`**: Add or remove stop words for any language.
- **`fe_ai_search_retrieved_chunks`**: Modify the context chunks _after_ they are retrieved from the database but _before_ they are sent to the AI.

---

## Development

This project uses GitHub Actions for continuous integration and deployment.

### CI/CD Pipeline

- **Continuous Integration**: Runs on every push and pull request to `main` and `develop` branches
    - PHP code quality checks (PHPCS) across PHP 7.4-8.2
    - JavaScript quality checks (ESLint, Prettier)
    - Security scanning (Composer audit, NPM audit)
    - WordPress plugin structure validation
    - Asset building verification

- **Release Pipeline**: Triggered by version tags (e.g., `v1.0.0`)
    - Automated release zip creation
    - GitHub release with changelog
    - WordPress.org SVN deployment (if configured)

### Required GitHub Secrets

For the release workflow to work properly, configure these secrets in your GitHub repository:

- `GITHUB_TOKEN`: Automatically provided by GitHub Actions
- `SVN_USERNAME`: WordPress.org SVN username (optional)
- `SVN_PASSWORD`: WordPress.org SVN password (optional)

### Local Development Setup

1. Clone the repository
2. Install PHP dependencies: `composer install`
3. Install Node.js dependencies: `npm install`
4. Run code quality checks: `composer run phpcs`
5. Build assets: `npm run build` (if available)

### Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/new-feature`
3. Make your changes and ensure they pass CI checks
4. Submit a pull request to the `develop` branch

---

## License

**FE Search AI**
Copyright (C) 2025 FirstElement, Inc.
This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

### Third-Party Libraries

This plugin incorporates the following third-party libraries:

- **php-stemmer**
    - License: MIT
    - Source: [https://github.com/wamania/php-stemmer](https://github.com/wamania/php-stemmer)
- **TinySegmenter**
    - License: Modified BSD
    - Source: [https://github.com/u7aro/tinysegmenter-php](https://github.com/u7aro/tinysegmenter-php)
    - The full license text is included in the `LICENSE-TinySegmenter.txt` file.
- **Web Services by Yahoo! JAPAN**
    - Source: [https://developer.yahoo.co.jp/sitemap/](https://developer.yahoo.co.jp/sitemap/)
- **Pickr**
    - Author: Simon Wep
    - License: MIT
    - Source: [https://github.com/Simonwep/pickr](https://github.com/Simonwep/pickr)
