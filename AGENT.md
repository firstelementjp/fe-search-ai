# AGENT.md - AI Assistant Guide for FE Search AI

> Read this file first when working on this project.

## Project Overview

**FE Search AI** is a WordPress plugin that provides AI-powered semantic search.
Uses vector embeddings and AI reranking for intelligent content discovery.

- **Version**: 1.0.0
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
    class-fe-search-ai-chat-handler.php             # Chat AJAX request handling
    class-fe-search-ai-sync-handler.php             # Sync AJAX request handling
  core/
    class-fe-search-ai-activator.php                # Plugin activation/deactivation
    class-fe-search-ai-assets.php                   # Script/style registration
    class-fe-search-ai-cohere-reranker.php          # Cohere AI reranking
    class-fe-search-ai-defaults.php                 # Default settings
    class-fe-search-ai-encryption-helper.php        # Encryption utilities
    class-fe-search-ai-license-handler.php          # License validation
    class-fe-search-ai-license.php                  # License model
    class-fe-search-ai-logger.php                   # Logging utilities
    class-fe-search-ai-retrieval-trace.php          # Retrieval trace data model
    class-fe-search-ai-retrieval-trace-recorder.php # Retrieval trace recording
    class-fe-search-ai-sync-hooks.php               # Sync hooks and handlers
  frontend/
    class-fe-search-ai-chat-ui.php                  # Frontend chat UI rendering
  i18n/                                             # Translation dictionaries
  update/
    class-fe-search-ai-github-update-checker.php    # GitHub Releases updater
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

- **Hybrid Search**: Vector retrieval, BM25 keyword ranking, RRF fusion, and optional reranking
- **Retrieval Trace**: Debug traces with BM25, Qdrant, RRF, and Cohere score metadata
- **AI Reranking**: Cohere API for result reranking
- **Multi-language Support**: Japanese (TinySegmenter) and other languages (php-stemmer)
- **Sync System**: WordPress content synchronization with index health metrics
- **GitHub Updates**: GitHub Releases updater for non-WordPress.org distribution
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

When preparing a release, update the canonical versioned files:

- `fe-search-ai.php` plugin header `Version` and `FE_SEARCH_AI_VERSION`
- `package.json` and `package-lock.json` `version`
- `readme.txt` `Stable tag` and changelog section
- `README.md` version badge and recent highlights if behavior changed
- `docs/changes.md` and `docs/ja/changes.md`
- `docs/README.md` current release and highlights
- `AGENT.md` and `.github/skills/SKILL.md` when architecture or troubleshooting guidance changes
- `test-release.sh` `TAG` and `ZIP_NAME`

Validation command sequence:

```bash
composer test
composer phpcs
npm ci
npm run lint
npm run build
./test-release.sh
git diff --check
```

Notes:

- Do not update `@since` tags globally just because the release version changed.
- Do not treat `composer.json` as a required release-version file unless it later adds a top-level `version` field.
- Ignore generated files under `test-release/`.

## GitHub Actions Workflows

- **ci.yml**: Code quality checks (push/PR)
- **release.yml**: Release ZIP creation and GitHub Release (tags), plus WordPress.org SVN deploy
- **dependency-review.yml**: Dependency vulnerability checks (PR)
- **deploy-staging.yml**: Staging/production deployment (optional, requires server)

Required GitHub Secrets for WordPress.org SVN deploy:

- `SVN_USERNAME`
- `SVN_PASSWORD`

## BM25 Keyword Search

- DB schema uses `fe_search_ai_vectors.keyword_token_count` and `fe_search_ai_keyword_index.term_frequency`.
- `SyncHandler` builds keyword index data during batch sync.
- `find_similar_chunks_via_keyword_index()` ranks results by BM25.
- Filters: `fe_search_ai_bm25_candidate_limit`, `fe_search_ai_bm25_k1`, `fe_search_ai_bm25_b`.
- Realtime indexing in `SyncHooks` stores the same metadata.

## GitHub Releases Updater

- `includes/update/class-fe-search-ai-github-update-checker.php` provides fallback plugin updates while WordPress.org approval is pending.
- Fetches `https://api.github.com/repos/firstelementjp/fe-search-ai/releases/latest`.
- Prefers release asset ZIPs matching `fe-search-ai(-version).zip`; falls back to `zipball_url`.
- Caches releases in `fe_search_ai_github_latest_release` for one hour.
- Can be disabled with the `fe_search_ai_enable_github_updates` filter (default `true`).
- Registered during `plugins_loaded` in `fe-search-ai.php`.

## Retrieval Trace Diagnostics

- Retrieval trace logs expose BM25, Qdrant, RRF, and Cohere scores.
- The Sync screen shows index health metrics and retrieval trace `source_counts`.
- If final trace items lack BM25, check whether `wp_fe_search_ai_vectors.keyword_token_count` is zero while `keyword_index` rows exist. Running **Rebuild Index** normally resolves this.
- Typical validation query: `店頭販売` should rank the expected post first/second after the index is rebuilt.
