# FE Search AI Documentation

> AI-powered semantic search for WordPress

FE Search AI is a WordPress plugin that provides intelligent semantic search using vector embeddings and AI reranking. It goes beyond traditional keyword matching to understand the meaning behind queries.

## Features

- **Semantic Search**: Understands the meaning behind queries, not just keywords
- **Vector Embeddings**: Converts content to vector representations for better matching
- **AI Reranking**: Uses Cohere API to improve search result relevance
- **Multi-language Support**: Japanese (TinySegmenter) and other languages (php-stemmer)
- **Sync System**: Automatically syncs WordPress content to vector database
- **Real-time Search**: Fast and accurate search results

## Installation

### From WordPress.org

1. Go to **Plugins → Add New**
2. Search for "FE Search AI"
3. Click **Install Now**
4. Activate the plugin

### Manual Installation

1. Download the latest [release](https://github.com/firstelementjp/fe-search-ai/releases)
2. Upload to `/wp-content/plugins/`
3. Activate the plugin

## Quick Start

### 1. Configure API Keys

Go to **FE Search AI → Settings** and configure your AI provider API keys:

- **Cohere API Key**: For AI reranking (optional but recommended)
- **OpenAI API Key**: For embedding generation (if using OpenAI)

### 2. Sync Your Content

Go to **FE Search AI → Sync** and click **Start Sync** to index your WordPress content:

- Posts
- Pages
- Custom post types
- Custom fields
- Taxonomies

### 3. Add Search to Your Site

Use the shortcode or PHP function to add search to your site:

```php
// Shortcode
[fe_search_ai]

// PHP function
<?php echo fe_search_ai_search_form(); ?>
```

## Configuration

### General Settings

- **Search Results per Page**: Number of results to display
- **Enable AI Reranking**: Use Cohere API for better results
- **Minimum Score Threshold**: Filter low-confidence results

### Sync Settings

- **Post Types**: Select which post types to sync
- **Sync Frequency**: Automatic sync schedule
- **Batch Size**: Number of items per sync batch

### License Settings

- **License Key**: Enter your Pro license key
- **Auto-updates**: Enable automatic plugin updates

## API Integration

### Cohere Reranking

FE Search AI uses Cohere's reranking API to improve search result relevance:

```php
// Example: Enable reranking in settings
add_filter('fe_search_ai_enable_reranking', function() {
    return true;
});
```

### Custom Embedding Providers

You can integrate custom embedding providers:

```php
add_filter('fe_search_ai_embedding_provider', function($text) {
    // Custom embedding logic
    return $embedding;
});
```

## Troubleshooting

### Search Not Working

1. Check that content has been synced (FE Search AI → Sync)
2. Verify API keys are configured correctly
3. Check error logs in WordPress debug log

### Sync Fails

1. Check PHP memory limits
2. Verify database connection
3. Check for timeout issues in server configuration

### API Errors

1. Verify API key is valid
2. Check API quota limits
3. Review error logs for specific error messages

## Developer Guide

### Contributing

See [CONTRIBUTING.md](../CONTRIBUTING.md) for contribution guidelines.

### Code Standards

- Follow WordPress Coding Standards (WPCS)
- Add PHPDoc to all classes and methods
- Comments in English
- Use Yoda conditions where appropriate

### Testing

```bash
# Run PHP CodeSniffer
composer phpcs

# Build assets
npm run build

# Test release
./test-release.sh
```

## Support

- **Documentation**: [https://github.com/firstelementjp/fe-search-ai](https://github.com/firstelementjp/fe-search-ai)
- **Issues**: [GitHub Issues](https://github.com/firstelementjp/fe-search-ai/issues)
- **Security**: See [SECURITY.md](../SECURITY.md) for security reporting

## License

GPL-2.0 or later

## Changelog

See [GitHub Releases](https://github.com/firstelementjp/fe-search-ai/releases) for version history.
