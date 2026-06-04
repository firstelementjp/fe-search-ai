# Sync System Troubleshooting

> Common issues and solutions for FE Search AI sync system.

## Sync Not Starting

### Problem: Sync button doesn't start sync

**Possible Causes**:
- JavaScript errors in browser console
- AJAX endpoint not registered
- User lacks required capabilities
- Plugin not properly activated

**Solutions**:

1. Check browser console for errors:
   ```javascript
   // Open DevTools (F12) → Console tab
   // Look for red error messages
   ```

2. Verify AJAX endpoint is registered:
   ```php
   // Check if action is registered
   add_action( 'wp_ajax_fe_search_ai_sync', 'fe_search_ai_sync_handler' );
   add_action( 'wp_ajax_nopriv_fe_search_ai_sync', 'fe_search_ai_sync_handler' );
   ```

3. Check user capabilities:
   ```php
   // Verify user can manage options
   if ( ! current_user_can( 'manage_options' ) ) {
       return;
   }
   ```

## Sync Timeout

### Problem: Sync process times out

**Possible Causes**:
- PHP execution time limit too low
- Large content set
- Slow API responses
- Memory limit exceeded

**Solutions**:

1. Increase PHP execution time:
   ```php
   // In wp-config.php
   ini_set( 'max_execution_time', 300 );
   ```

2. Reduce batch size in settings:
   ```php
   // Default: 100 posts per batch
   // Reduce to 50 or 25 for large content sets
   ```

3. Increase memory limit:
   ```php
   // In wp-config.php
   ini_set( 'memory_limit', '512M' );
   ```

4. Implement progress tracking:
   ```php
   // Use AJAX-based sync with progress updates
   // Allow resuming from last processed item
   ```

## Embedding Generation Failures

### Problem: Embeddings not generated for content

**Possible Causes**:
- API key invalid or missing
- API rate limit exceeded
- Content encoding issues
- Network connectivity problems

**Solutions**:

1. Verify API key:
   ```php
   // Check settings
   $api_key = get_option( 'fe_search_ai_api_key' );
   if ( empty( $api_key ) ) {
       error_log( 'API key not configured' );
   }
   ```

2. Implement exponential backoff:
   ```php
   $retry_count = 0;
   $max_retries = 3;

   while ( $retry_count < $max_retries ) {
       try {
           $embedding = $api->generate( $text );
           break;
       } catch ( RateLimitException $e ) {
           $retry_count++;
           sleep( pow( 2, $retry_count ) );
       }
   }
   ```

3. Check content encoding:
   ```php
   // Ensure UTF-8 encoding
   $text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
   ```

## Database Table Missing

### Problem: Sync fails due to missing database table

**Possible Causes**:
- Plugin activation hook not fired
- Database permissions insufficient
- Manual installation without activation

**Solutions**:

1. Manually create table:
   ```sql
   CREATE TABLE wp_fe_search_ai_embeddings (
       id bigint(20) NOT NULL AUTO_INCREMENT,
       post_id bigint(20) NOT NULL,
       embedding longtext NOT NULL,
       created_at datetime DEFAULT CURRENT_TIMESTAMP,
       updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY  (id),
       KEY post_id (post_id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
   ```

2. Re-activate plugin:
   ```php
   // Deactivate and reactivate in WordPress admin
   // This will trigger activation hook
   ```

3. Check database permissions:
   ```sql
   -- Verify user has CREATE TABLE permission
   SHOW GRANTS FOR CURRENT_USER();
   ```

## Sync Progress Not Updating

### Problem: Progress bar doesn't update during sync

**Possible Causes**:
- AJAX polling not working
- Session issues
- JavaScript errors
- Server caching

**Solutions**:

1. Verify AJAX polling:
   ```javascript
   // Check if polling is active
   setInterval( function() {
       fetch( ajaxurl, {
           method: 'POST',
           body: new FormData( formData )
       } )
       .then( response => response.json() )
       .then( data => {
           updateProgress( data.progress );
       } );
   }, 1000 );
   ```

2. Disable server caching for AJAX:
   ```php
   // Add to AJAX handler
   nocache_headers();
   ```

3. Check session configuration:
   ```php
   // Ensure session is started
   if ( ! session_id() ) {
       session_start();
   }
   ```

## Duplicate Embeddings

### Problem: Same content synced multiple times

**Possible Causes**:
- Sync not idempotent
- Post ID not checked before insertion
- Concurrent sync processes

**Solutions**:

1. Check for existing embeddings:
   ```php
   // Before inserting, check if exists
   $exists = $wpdb->get_var(
       $wpdb->prepare(
           "SELECT id FROM {$wpdb->prefix}fe_search_ai_embeddings WHERE post_id = %d",
           $post_id
       )
   );

   if ( $exists ) {
       // Update instead of insert
       $wpdb->update(
           $table_name,
           array( 'embedding' => $embedding ),
           array( 'post_id' => $post_id )
       );
   } else {
       // Insert new
       $wpdb->insert(
           $table_name,
           array( 'post_id' => $post_id, 'embedding' => $embedding )
       );
   }
   ```

2. Add unique constraint:
   ```sql
   ALTER TABLE wp_fe_search_ai_embeddings
   ADD UNIQUE KEY unique_post_id (post_id);
   ```

3. Implement sync lock:
   ```php
   // Prevent concurrent syncs
   $sync_lock = get_transient( 'fe_search_ai_sync_lock' );
   if ( $sync_lock ) {
       return new WP_Error( 'sync_in_progress', 'Sync already in progress' );
   }

   set_transient( 'fe_search_ai_sync_lock', true, 3600 );
   ```

## Sync Not Completing

### Problem: Sync starts but never completes

**Possible Causes**:
- Infinite loop in sync logic
- Memory leak
- Unhandled exception
- Background process killed

**Solutions**:

1. Add timeout protection:
   ```php
   $start_time = time();
   $max_execution_time = ini_get( 'max_execution_time' );

   foreach ( $posts as $post ) {
       if ( time() - $start_time > $max_execution_time - 5 ) {
           // Stop and resume in next batch
           break;
       }
       // Process post
   }
   ```

2. Clear memory between batches:
   ```php
   unset( $batch_data );
   gc_collect_cycles();
   ```

3. Add exception handling:
   ```php
   try {
       $this->process_post( $post );
   } catch ( Exception $e ) {
       error_log( 'Sync error for post ' . $post->ID . ': ' . $e->getMessage() );
       // Continue with next post
   }
   ```

## Debugging Sync Issues

### Enable Detailed Logging

```php
// In wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );

// In sync code
error_log( 'FE Search AI Sync: Processing post ' . $post_id );
error_log( 'FE Search AI Sync: Batch size ' . count( $batch ) );
error_log( 'FE Search AI Sync: Memory usage ' . memory_get_usage() );
```

### Check Database

```sql
-- Check total embeddings
SELECT COUNT(*) FROM wp_fe_search_ai_embeddings;

-- Check recent embeddings
SELECT * FROM wp_fe_search_ai_embeddings
ORDER BY created_at DESC
LIMIT 10;

-- Check for missing posts
SELECT p.ID, p.post_title
FROM wp_posts p
LEFT JOIN wp_fe_search_ai_embeddings e ON p.ID = e.post_id
WHERE p.post_type IN ('post', 'page')
AND p.post_status = 'publish'
AND e.post_id IS NULL;
```

### Monitor API Usage

```php
// Log API calls
error_log( 'FE Search AI API: Call to ' . $endpoint );
error_log( 'FE Search AI API: Response time ' . $response_time );
error_log( 'FE Search AI API: Rate limit remaining ' . $rate_limit_remaining );
```
