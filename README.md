# FE Search AI

![FE Search AI Banner](docs/assets/images/img-sns-banner-100.jpg)

[![Version](https://img.shields.io/badge/version-0.9.0-green.svg)](https://github.com/firstelementjp/fe-search-ai/releases)
[![License](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-6.6%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/firstelementjp/fe-search-ai)

AI-powered, conversational search for WordPress. This repository contains the plugin source code, tests, and developer resources. For end-user guides and full documentation, see the links below.

## ✨ Recent Highlights

- Comprehensive unit test suite with 105 tests covering core functionality
- Support for multiple AI providers (OpenAI, Google, Anthropic)
- Advanced Japanese tokenization with TinySegmenter and optional Yahoo! MA API
- Real-time post synchronization hooks
- Cohere reranker integration for improved search relevance
- Extensive filter hooks for customization

## 📖 Overview

FE Search AI replaces standard WordPress search with a conversational AI chat using RAG (Retrieval-Augmented Generation). It indexes your content into vector embeddings and keyword indexes, providing accurate answers based only on your website's content.

This plugin is designed to be highly extensible and performant, running on standard MySQL databases without requiring external vector databases.

## ⚡ Quick Links

- **User Documentation**: https://firstelementjp.github.io/fe-search-ai/
- **Installation Guide**: https://firstelementjp.github.io/fe-search-ai/#/installation
- **Configuration**: https://firstelementjp.github.io/fe-search-ai/#/configuration
- **Hooks Reference**: https://firstelementjp.github.io/fe-search-ai/#/hooks
- **Releases**: https://github.com/firstelementjp/fe-search-ai/releases
- **Issues**: https://github.com/firstelementjp/fe-search-ai/issues

## ⚙️ Requirements

- WordPress 6.6 or higher
- PHP 7.4 or higher (PHP 8.0+ required for built-in Japanese tokenizer)
- MySQL 5.7 or higher
- OpenSSL extension

## 🚀 Installation

### From WordPress Admin

1. Download the latest release ZIP from the [Releases page](https://github.com/firstelementjp/fe-search-ai/releases)
2. Go to **Plugins → Add New → Upload Plugin**
3. Select the ZIP file and upload it
4. Activate the plugin

### Manual Installation

1. Download the latest release ZIP from the [Releases page](https://github.com/firstelementjp/fe-search-ai/releases)
2. Extract the ZIP file
3. Upload the `fe-search-ai/` directory to `/wp-content/plugins/`
4. Activate the plugin from the WordPress admin screen

> [!IMPORTANT]
> Use the release ZIP file for installation, not **Source code (zip)**.

## 💻 Local Development

```bash
git clone https://github.com/firstelementjp/fe-search-ai.git
cd fe-search-ai
composer install
npm install
```

After installing dependencies, place the plugin in your local WordPress environment and activate it from the admin dashboard.

## 🧪 Testing

PHPUnit for FE Search AI is designed to run in a local WordPress environment.

### PHPUnit setup for Local by Flywheel

1. Copy `wp-tests-config.php.example` to `wp-tests-config.php`
2. If you use `direnv`, copy or create a `.envrc` file for `fe-search-ai`
3. Set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `MYSQL_UNIX_PORT` for your Local site
4. Approve the environment with `direnv allow`
5. Run `composer install`

Example setup command:

```bash
cp wp-tests-config.php.example wp-tests-config.php
```

Example environment values:

```bash
export DB_HOST=localhost
export DB_NAME=local
export DB_USER=root
export DB_PASSWORD=root
export MYSQL_UNIX_PORT="/Users/your-name/Library/Application Support/Local/run/xxxxxxx/mysql/mysqld.sock"
```

Verify the MySQL socket path from the **Database** tab in Local before running tests.

Run PHPUnit from the plugin root:

```bash
cd wp-content/plugins/fe-search-ai
```

Run all tests:

```bash
composer test
```

The equivalent direct PHPUnit command is:

```bash
./vendor/bin/phpunit
```

Run coverage:

```bash
composer run test-coverage
```

Coverage reports are generated in:

```
tests/coverage/
```

If the test database connection fails in Local, confirm that `MYSQL_UNIX_PORT` is loaded in the current shell:

```bash
printenv | grep MYSQL_UNIX_PORT
```

If `.envrc` was updated, reload it before running PHPUnit:

```bash
direnv allow
direnv reload
composer test
```

## 🛠️ Development Commands

```bash
# PHP/Composer
composer test              # Run test suite
composer phpcs             # Check coding standards
composer phpcbf            # Fix coding standards automatically

# Node.js/npm
npm run build              # Build/minify frontend assets
npm run lint:js            # Check JavaScript coding standards
npm run lint:js:fix        # Fix JavaScript coding standards
npm run dev                # Development build with source maps
npm run watch:all          # Watch for changes and rebuild automatically
```

See `package.json` for the complete list of available npm scripts.

## 📝 Developer Notes

### Japanese Tokenization

The plugin includes built-in support for Japanese tokenization using **TinySegmenter** (lightweight, dictionary-free). For higher accuracy, you can optionally enable the **Yahoo! Japanese Morphological Analysis API (MAService)** by configuring an App ID in the settings.

### Key Filter Hooks

- `fe_search_ai_chat_ui_html`: Override the chat UI HTML
- `fe_search_ai_system_prompt`: Modify the system prompt sent to AI
- `fe_search_ai_tokenize_text`: Implement custom tokenizers
- `fe_search_ai_stop_words`: Add/remove stop words
- `fe_search_ai_retrieved_chunks`: Modify retrieved context chunks
- `fe_search_ai_rate_limit_settings`: Adjust rate limit configuration

For detailed implementation notes, see the [hooks documentation](https://firstelementjp.github.io/fe-search-ai/#/hooks).

## 🤝 Contributing

Contributions are welcome.

Basic workflow:

- Fork the repository
- Create a feature branch from develop
- Make changes and run tests
- Open a Pull Request against develop

Please check existing issues before opening a new feature request or bug report.

## 📄 License

GPLv2+

See LICENSE for details.

### Third-Party Libraries

This plugin incorporates the following third-party libraries:

- **php-stemmer** (MIT License)
    - Source: https://github.com/wamania/php-stemmer
- **TinySegmenter** (Modified BSD License)
    - Source: https://github.com/u7aro/tinysegmenter-php
    - Full license text: LICENSE-TinySegmenter.txt
- **Web Services by Yahoo! JAPAN**
    - Source: https://developer.yahoo.co.jp/sitemap/
- **Pickr** (MIT License)
    - Author: Simon Wep
    - Source: https://github.com/Simonwep/pickr

## 👨‍💻 Authors

- [Daijiro Miyazawa](https://x.com/dxd5001)
- [FirstElement K.K.](https://www.firstelement.co.jp)
