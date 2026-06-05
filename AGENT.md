# AGENT.md - AI Assistant Guide for FE Search AI

> Read this file first when working on this project.

## Project Overview

**FE Search AI** is a WordPress plugin that provides AI-powered semantic search.
Uses vector embeddings and AI reranking for intelligent content discovery.

- **Version**: 0.9.0
- **License**: GPL-2.0+
- **PHP**: >= 7.4
- **Repository**: https://github.com/firstelementjp/fe-search-ai

## Architecture

```
fe-search-ai.php                                    # Entry point, constants, bootstrap
includes/
  admin/
    class-fe-search-ai-admin.php                   # Admin bootstrap
    class-fe-search-ai-settings.php                 # Plugin settings UI
    class-fe-search-ai-license-settings.php         # License management UI
  ajax/
    class-fe-search-ai-ajax-handler.php             # AJAX request handler
  core/
    class-fe-search-ai-activator.php                # Plugin activation/deactivation
    class-fe-search-ai-assets.php                   # Script/style registration
    class-fe-search-ai-cohere-reranker.php          # Cohere AI reranking
    class-fe-search-ai-defaults.php                 # Default settings
    class-fe-search-ai-encryption-helper.php        # Encryption utilities
    class-fe-search-ai-license-handler.php          # License validation
    class-fe-search-ai-license.php                  # License model
    class-fe-search-ai-logger.php                   # Logging utilities
    class-fe-search-ai-sync-hooks.php               # Sync hooks and handlers
  frontend/
    class-fe-search-ai-frontend.php                 # Frontend search integration
  i18n/
    class-fe-search-ai-i18n.php                      # Internationalization
assets/
  js/
    admin-scripts.js                                 # Admin JavaScript
    frontend-scripts.js                              # Frontend JavaScript
  css/
    admin-styles.css                                 # Admin styles
    frontend-styles.css                              # Frontend styles
languages/                                          # i18n files
vendor/                                             # Composer dependencies (php-stemmer, tinysegmenter-php)
```

## Key Features

- **Vector Search**: Semantic search using embeddings
- **AI Reranking**: Cohere API for result reranking
- **Multi-language Support**: Japanese (TinySegmenter) and other languages (php-stemmer)
- **Sync System**: WordPress content synchronization to vector database
- **License Management**: Pro version with license validation

## Build & Dev Commands

```bash
npm run build          # Build all minified assets
npm run dev            # Watch mode for JS + CSS
composer phpcs         # Run PHP CodeSniffer
composer phpcbf        # Auto-fix PHP style
npm run lint:js        # ESLint
npm run format         # Prettier
./test-release.sh      # Build a local release ZIP
```

**After editing JS/CSS, always rebuild the minified files.**

## Coding Rules

### PHP

- Follow WordPress Coding Standards (WPCS)
- Add PHPDoc to all classes, functions, and methods
- Comments in English
- Use `sanitize_text_field()`, `intval()`, `check_ajax_referer()` for security
- Use `wp_send_json_success()` / `wp_send_json_error()` for AJAX responses
- Use Yoda conditions where appropriate (e.g., `0 === $var`)

### JavaScript

- Add JSDoc to all functions
- Comments in English
- Use `fetch()` for AJAX (no jQuery dependency)
- Always check DOM element existence before use

### CSS

- Use `.fe-search-ai-` prefix for all custom classes
- Follow WordPress admin UI conventions

## Critical Constraints

### AI API Integration

**Always handle API errors gracefully and provide user feedback.**

```php
// Example: Cohere Reranker error handling
try {
    $results = $reranker->rerank($query, $documents);
} catch ( Exception $e ) {
    error_log( 'Cohere Reranker error: ' . $e->getMessage() );
    return $original_results; // Fallback to original results
}
```

### Sync System

**Sync operations must be idempotent and handle interruptions gracefully.**

- Use batch processing for large content sets
- Implement progress tracking
- Support resume capability
- Log sync errors for debugging

### Encryption

**Sensitive data (API keys) must be encrypted before storage.**

```php
// Use Encryption_Helper for API keys
$encrypted = Encryption_Helper::encrypt( $api_key );
$decrypted = Encryption_Helper::decrypt( $encrypted );
```

## UI Structure

- **Admin Settings**: Tabs for General, Sync, License
- **Sync Progress**: Progress bar + real-time log
- **Log levels**: `info`, `success`, `warning`, `error`, `debug`

## Workflow Checklists

### Adding New AI Provider

1. Create provider class in `includes/core/`
2. Implement interface for consistency
3. Add settings fields in `includes/admin/class-fe-search-ai-settings.php`
4. Add error handling and fallback logic
5. Test with real API calls
6. Update documentation

### Modifying Sync Logic

1. Update sync hooks in `includes/core/class-fe-search-ai-sync-hooks.php`
2. Ensure batch processing is efficient
3. Add progress tracking
4. Test with large content sets
5. Verify resume capability works

### Debugging AI Search

1. Check API key configuration
2. Review error logs in `wp-content/debug.log`
3. Test API calls independently
4. Verify vector embeddings are generated correctly
5. Check reranking API responses

## Related Files

- `README.md` — Project overview and setup instructions
- `readme.txt` — WordPress.org readme
- `phpcs.xml.dist` — PHP CodeSniffer configuration
- `.gitattributes` — Git archive export rules for releases
- `test-release.sh` — Local release testing script

## Release Process

1. Update version in `fe-search-ai.php` and `readme.txt`
2. Run `./test-release.sh` to verify release package
3. Commit changes
4. Create git tag: `git tag v1.0.0`
5. Push tag: `git push origin v1.0.0`
6. GitHub Actions will automatically:
    - Create release ZIP
    - Create GitHub Release with changelog
    - Deploy to WordPress.org SVN (if secrets configured)

## GitHub Actions Workflows

- **ci.yml**: Code quality checks (push/PR)
- **release.yml**: Release ZIP creation and GitHub Release (tags)
- **dependency-review.yml**: Dependency vulnerability checks (PR)
- **deploy-staging.yml**: Staging/production deployment (optional, requires server)
