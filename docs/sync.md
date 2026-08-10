# Sync System

The sync system builds and maintains the searchable index used by FE Search AI.

## What sync does

During sync, the plugin extracts content from selected WordPress posts, pages, and custom post types. It then creates embeddings, stores them in Qdrant with metadata used for retrieval, and builds keyword index data used by BM25 ranking.

## Sync modes

### Manual sync

Run a full sync when setting up the plugin for the first time or when rebuilding the index.

### Smart sync

Smart sync processes changed content to reduce API usage and execution time.

### Real-time sync

When enabled, published or updated content can be indexed automatically.

Version 1.0.0 tracks full sync and real-time sync timestamps separately, so the admin screen can show sync status more accurately.

## Recommended workflow

1. Start with a small content set.
2. Run manual sync.
3. Test common visitor questions.
4. Expand post types and content scope.
5. Enable real-time sync after confirming quality.

## Japanese content

For Japanese sites, tokenization affects BM25 keyword matching. TinySegmenter is available without external API setup. Yahoo! JAPAN Japanese MA API can be configured when higher-accuracy tokenization is required.

## Troubleshooting sync

- Confirm API keys are valid.
- Confirm Qdrant endpoint and collection settings.
- Reduce batch size on limited hosting environments.
- Check PHP memory limit and max execution time.
- Review WordPress debug logs if sync stops unexpectedly.
