# SKILL.md - Troubleshooting Knowledge Base

> Past bug fixes and troubleshooting patterns for FE Search AI.
> For general development guidance, see [AGENT.md](../../AGENT.md).

## Sync System

### Sync Timeout Issues

**Problem**: Large content sets cause sync to timeout.

**Solution**:
- Reduce batch size in sync settings
- Increase PHP `max_execution_time`
- Use AJAX-based sync with progress tracking

**Code Pattern**:
```php
// Check execution time periodically
if ( time() - $start_time > $max_execution_time - 5 ) {
    return; // Resume in next batch
}
```

### Vector Embedding Failures

**Problem**: Embedding generation fails for certain content.

**Solution**:
- Check API key validity
- Verify content encoding (UTF-8)
- Handle API rate limits with exponential backoff

**Code Pattern**:
```php
try {
    $embedding = $api->generate_embedding( $text );
} catch ( Exception $e ) {
    error_log( 'Embedding failed: ' . $e->getMessage() );
    // Fallback to original search
    return $original_results;
}
```

## Search System

### Empty Search Results

**Problem**: Search returns no results even for existing content.

**Solution**:
- Verify content has been synced
- Check vector embeddings exist in database
- Verify search query is not empty
- Check minimum score threshold settings

**Debug Steps**:
1. Check sync status in admin
2. Query database for embeddings: `SELECT * FROM wp_fe_search_ai_embeddings`
3. Test with simple query
4. Check error logs

### AI Reranking Errors

**Problem**: Cohere API errors during reranking.

**Solution**:
- Verify API key is valid
- Check API quota limits
- Implement graceful fallback to original results

**Code Pattern**:
```php
if ( ! empty( $api_key ) ) {
    try {
        $results = $reranker->rerank( $query, $documents );
    } catch ( Exception $e ) {
        error_log( 'Reranking failed: ' . $e->getMessage() );
        $results = $original_results; // Fallback
    }
}
```

## API Integration

### API Key Encryption

**Problem**: API keys stored in plain text in database.

**Solution**:
- Use OpenSSL encryption for API keys
- Store encrypted keys in options
- Decrypt only when needed

**Code Pattern**:
```php
// Encrypt
$encrypted = openssl_encrypt(
    $api_key,
    'AES-256-CBC',
    $key,
    0,
    $iv
);

// Decrypt
$decrypted = openssl_decrypt(
    $encrypted,
    'AES-256-CBC',
    $key,
    0,
    $iv
);
```

### API Rate Limits

**Problem**: API calls hit rate limits during sync.

**Solution**:
- Implement exponential backoff
- Add delays between batches
- Track API usage and throttle accordingly

**Code Pattern**:
```php
$retry_count = 0;
$max_retries = 3;

while ( $retry_count < $max_retries ) {
    try {
        $result = $api->call();
        break;
    } catch ( RateLimitException $e ) {
        $retry_count++;
        sleep( pow( 2, $retry_count ) ); // Exponential backoff
    }
}
```

## Frontend Integration

### Search Form Not Rendering

**Problem**: Search form shortcode returns empty output.

**Solution**:
- Verify plugin is active
- Check shortcode syntax: `[fe_search_ai]`
- Check for JavaScript errors in browser console
- Verify assets are enqueued

**Debug Steps**:
1. Check browser console for errors
2. Verify assets are loaded in network tab
3. Check if `wp_enqueue_scripts` hook is firing
4. Test with default WordPress theme

### AJAX Request Failures

**Problem**: Search AJAX requests fail with 500 error.

**Solution**:
- Check WordPress debug log
- Verify nonce is valid
- Check user capabilities
- Verify AJAX handler is registered

**Code Pattern**:
```php
// Always verify nonce
check_ajax_referer( 'fe_search_ai_nonce', 'nonce' );

// Check capabilities
if ( ! current_user_can( 'read' ) ) {
    wp_send_json_error( 'Unauthorized' );
}
```

## Database

### Missing Database Tables

**Problem**: Plugin tables not created on activation.

**Solution**:
- Verify activation hook is firing
- Check dbDelta() SQL syntax
- Ensure user has CREATE TABLE permissions

**Code Pattern**:
```php
register_activation_hook( __FILE__, 'fe_search_ai_activate' );

function fe_search_ai_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'fe_search_ai_embeddings';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        embedding longtext NOT NULL,
        PRIMARY KEY  (id),
        KEY post_id (post_id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}
```

### Database Migration Issues

**Problem**: Schema changes cause errors on update.

**Solution**:
- Use version tracking in options
- Implement incremental migrations
- Test migrations on staging first

**Code Pattern**:
```php
$current_version = get_option( 'fe_search_ai_db_version', '1.0.0' );

if ( version_compare( $current_version, '1.1.0', '<' ) ) {
    // Migration to 1.1.0
    fe_search_ai_migrate_to_1_1_0();
    update_option( 'fe_search_ai_db_version', '1.1.0' );
}
```

## Performance

### Slow Search Performance

**Problem**: Search queries take too long.

**Solution**:
- Add database indexes on frequently queried columns
- Implement caching for common queries
- Use vector similarity search optimizations
- Consider pagination for large result sets

**Code Pattern**:
```php
// Add index to post_id column
ALTER TABLE wp_fe_search_ai_embeddings ADD INDEX post_id (post_id);

// Cache search results
$cache_key = 'fe_search_ai_' . md5( $query );
$cached = wp_cache_get( $cache_key );

if ( false === $cached ) {
    $results = fe_search_ai_perform_search( $query );
    wp_cache_set( $cache_key, $results, '', 3600 );
}
```

### Memory Issues During Sync

**Problem**: Sync process runs out of memory.

**Solution**:
- Reduce batch size
- Use memory-efficient data structures
- Clear unnecessary variables between batches
- Increase PHP memory limit if needed

**Code Pattern**:
```php
// Clear memory between batches
unset( $batch_data );
gc_collect_cycles();

// Increase memory limit if needed
ini_set( 'memory_limit', '512M' );
```

## Internationalization

### Japanese Text Segmentation

**Problem**: Japanese text not properly segmented for search.

**Solution**:
- Use TinySegmenter for Japanese text
- Ensure proper UTF-8 encoding
- Test with Japanese-specific queries

**Code Pattern**:
```php
if ( 'ja' === $language ) {
    $segmenter = new TinySegmenter();
    $tokens = $segmenter->segment( $text );
} else {
    $tokens = explode( ' ', $text );
}
```

## Security

### XSS Vulnerabilities

**Problem**: User input not properly escaped in output.

**Solution**:
- Always escape output with WordPress functions
- Use `esc_html()`, `esc_attr()`, `esc_url()`
- Sanitize input with `sanitize_text_field()`

**Code Pattern**:
```php
// Bad
echo $user_input;

// Good
echo esc_html( $user_input );
```

### SQL Injection

**Problem**: User input used directly in SQL queries.

**Solution**:
- Use `$wpdb->prepare()` for all queries
- Never concatenate user input into SQL
- Validate and sanitize input before use

**Code Pattern**:
```php
// Bad
$sql = "SELECT * FROM table WHERE id = " . $_POST['id'];

// Good
$sql = $wpdb->prepare( "SELECT * FROM table WHERE id = %d", $_POST['id'] );
```

## Testing

### Unit Test Failures

**Problem**: Tests fail in CI but pass locally.

**Solution**:
- Check PHP version differences
- Verify test database configuration
- Check for environment-specific dependencies
- Review test isolation

**Debug Steps**:
1. Run tests locally with same PHP version as CI
2. Check test database schema
3. Verify all dependencies are installed
4. Review test logs for specific failures

## Common Patterns

### Error Logging

Always use WordPress error logging for debugging:

```php
error_log( 'FE Search AI: ' . $message );
```

### Capability Checks

Always verify user capabilities before operations:

```php
if ( ! current_user_can( 'manage_options' ) ) {
    return;
}
```

### Nonce Verification

Always verify nonces for AJAX requests:

```php
check_ajax_referer( 'fe_search_ai_nonce', 'nonce' );
```

### Graceful Degradation

Always provide fallbacks when external services fail:

```php
try {
    $result = external_service_call();
} catch ( Exception $e ) {
    error_log( 'External service failed: ' . $e->getMessage() );
    $result = fallback_method();
}
```
