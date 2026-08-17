# Changelog

## 1.1.0 (2026-08-17)

### Search Observability

- Added retrieval trace score details for BM25, Qdrant, RRF, and Cohere ranking diagnostics
- Added source count metadata to retrieval traces for easier search quality troubleshooting
- Added sync index health metrics to help identify stale or incomplete keyword indexes

### Search Tuning

- Added a hybrid candidate limit setting for balancing rerank quality and performance

### Maintenance

- Improved autoloader compatibility and namespace matching
- Improved documentation navigation and updated Japanese documentation
- Updated CI dependency maintenance and audit behavior

## 1.0.0 (2026-08-10)

### Search Relevance

- Added BM25 keyword ranking with term frequency indexing to improve keyword-based search relevance
- Stored keyword token counts and term frequencies during batch and real-time synchronization

### Updates and Distribution

- Added GitHub Releases-based automatic updates for installations distributed outside WordPress.org
- Added `no_update` support so WordPress can recognize GitHub-distributed installs as update-supported
- Added a filter to disable GitHub updates when switching to WordPress.org distribution

### Sync and Admin Experience

- Separated full sync and real-time sync timestamps for more accurate sync status tracking
- Added a Cohere Rerank reference link to API key settings
- Improved admin UI styling and header layout with WordPress theme colors

### Maintenance

- Updated Japanese translations and POT files
- Updated WordPress Coding Standards to 3.4.1 security release
- Added and updated unit tests for keyword indexing and database schema changes

## 0.9.1 (2026-06-11)

### Bug Fixes

- Fixed last sync timestamp display to show correct local time instead of UTC
- Fixed missing vendor assets in release ZIP (marked.min.js, codemirror, pickr)
- Fixed JS translation for consent pages warning

### Developer Experience

- Added debug logging for context chunks sent to LLM (permalinks and content preview)
- Updated dependency packages (GitHub Actions, Composer, NPM)

### Documentation

- Restructured documentation with focused guides and improved navigation

## 0.9.0

Initial release of FE Search AI.

### Highlights

- AI-powered conversational search for WordPress
- RAG-based answers grounded in site content
- Hybrid vector and keyword search
- OpenAI, Google Gemini, and Anthropic Claude support
- OpenAI and Google embedding support
- Qdrant vector database integration
- Optional Cohere reranking
- Japanese tokenization support
- Customizable chat UI
- Developer hooks and filters

## Release history

See GitHub Releases for downloadable ZIP archives and generated release notes.

[GitHub Releases](https://github.com/firstelementjp/fe-search-ai/releases)
