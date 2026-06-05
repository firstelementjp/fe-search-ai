# Hook Architecture

> FE Search AI uses WordPress hooks to provide extensibility for sync, search, and API integration.
> Hooks are strategically placed to allow customization without modifying core code.

## Sync System Hooks

### Sync Flow

```
1. Sync initiation       → fe_search_ai_sync_start
2. Content processing    → fe_search_ai_process_content
3. Embedding generation  → fe_search_ai_generate_embedding
4. Database storage      → fe_search_ai_store_embedding
5. Sync completion       → fe_search_ai_sync_complete
```

### Sync Hooks

| Hook                                  | Parameters                              | Purpose                                          |
| ------------------------------------- | --------------------------------------- | ------------------------------------------------ |
| `fe_search_ai_sync_start`             | `$post_types, $args`                    | Run before sync starts                           |
| `fe_search_ai_pre_sync_post`          | `$post_id, $post, $args`                | Modify post data before processing                |
| `fe_search_ai_generate_embedding`     | `$text, $post_id, $args`               | Customize embedding generation                    |
| `fe_search_ai_store_embedding`        | `$embedding, $post_id, $args`           | Modify embedding before storage                   |
| `fe_search_ai_post_sync_post`         | `$post_id, $success, $args`             | Run after post is synced                         |
| `fe_search_ai_sync_complete`          | `$stats, $args`                         | Run after sync completes                          |
| `fe_search_ai_sync_batch_size`        | `$batch_size, $total_items`             | Customize sync batch size                         |
| `fe_search_ai_sync_post_types`         | `$post_types`                           | Filter which post types to sync                  |

## Search System Hooks

### Search Flow

```
1. Search query          → fe_search_ai_search_query
2. Vector search         → fe_search_ai_vector_search
3. AI reranking          → fe_search_ai_ai_rerank
4. Result filtering      → fe_search_ai_filter_results
5. Result formatting     → fe_search_ai_format_results
```

### Search Hooks

| Hook                                  | Parameters                              | Purpose                                          |
| ------------------------------------- | --------------------------------------- | ------------------------------------------------ |
| `fe_search_ai_search_query`           | `$query, $args`                        | Modify search query before processing             |
| `fe_search_ai_pre_search`             | `$query, $args`                         | Run before search execution                      |
| `fe_search_ai_vector_search`          | `$query, $results, $args`              | Customize vector search results                  |
| `fe_search_ai_ai_rerank`              | `$query, $results, $args`              | Customize AI reranking                           |
| `fe_search_ai_filter_results`         | `$results, $query, $args`              | Filter search results                            |
| `fe_search_ai_format_results`         | `$results, $query, $args`              | Format results for output                        |
| `fe_search_ai_post_search`            | `$results, $query, $args`              | Run after search completes                       |
| `fe_search_ai_min_score_threshold`    | `$threshold, $query`                   | Customize minimum score threshold                 |
| `fe_search_ai_results_per_page`       | `$count, $query`                       | Customize results per page                       |

## API Integration Hooks

### API Flow

```
1. API request preparation → fe_search_ai_prepare_api_request
2. API call               → fe_search_ai_api_call
3. API response handling  → fe_search_ai_handle_api_response
4. API error handling     → fe_search_ai_api_error
```

### API Hooks

| Hook                                  | Parameters                              | Purpose                                          |
| ------------------------------------- | --------------------------------------- | ------------------------------------------------ |
| `fe_search_ai_prepare_api_request`     | `$request, $provider, $args`            | Modify API request before sending                 |
| `fe_search_ai_api_call`               | `$endpoint, $data, $provider`          | Override API call behavior                       |
| `fe_search_ai_handle_api_response`     | `$response, $provider, $args`           | Process API response                             |
| `fe_search_ai_api_error`              | `$error, $provider, $args`             | Handle API errors                                |
| `fe_search_ai_api_timeout`            | `$timeout, $provider`                  | Customize API timeout                            |
| `fe_search_ai_api_retry_count`         | `$count, $provider`                    | Customize API retry count                        |

## Frontend Integration Hooks

### Frontend Flow

```
1. Search form rendering → fe_search_ai_search_form
2. Script enqueueing     → fe_search_ai_enqueue_scripts
3. AJAX request          → fe_search_ai_ajax_request
4. Result display       → fe_search_ai_display_results
```

### Frontend Hooks

| Hook                                  | Parameters                              | Purpose                                          |
| ------------------------------------- | --------------------------------------- | ------------------------------------------------ |
| `fe_search_ai_search_form`             | `$form_html, $args`                     | Customize search form HTML                       |
| `fe_search_ai_enqueue_scripts`         | `$scripts, $args`                       | Modify enqueued scripts/styles                    |
| `fe_search_ai_ajax_request`           | `$request, $args`                       | Modify AJAX request data                         |
| `fe_search_ai_display_results`         | `$results, $query, $args`              | Customize result display                         |
| `fe_search_ai_search_shortcode_atts`   | `$atts, $content`                       | Filter shortcode attributes                      |

## Settings Hooks

### Settings Flow

```
1. Settings registration → fe_search_ai_register_settings
2. Settings validation   → fe_search_ai_validate_settings
3. Settings save         → fe_search_ai_save_settings
```

### Settings Hooks

| Hook                                  | Parameters                              | Purpose                                          |
| ------------------------------------- | --------------------------------------- | ------------------------------------------------ |
| `fe_search_ai_register_settings`      | `$settings`                             | Register custom settings                         |
| `fe_search_ai_validate_settings`       | `$values, $settings`                    | Validate settings before save                    |
| `fe_search_ai_save_settings`           | `$values, $old_values`                  | Run after settings are saved                     |
| `fe_search_ai_setting_sections`        | `$sections`                             | Add custom settings sections                     |
| `fe_search_ai_setting_fields`          | `$fields, $section`                     | Add custom settings fields                       |

## License Hooks

### License Flow

```
1. License validation → fe_search_ai_validate_license
2. License activation  → fe_search_ai_activate_license
3. License deactivation → fe_search_ai_deactivate_license
```

### License Hooks

| Hook                                  | Parameters                              | Purpose                                          |
| ------------------------------------- | --------------------------------------- | ------------------------------------------------ |
| `fe_search_ai_validate_license`        | `$license_key, $response`               | Validate license key                             |
| `fe_search_ai_activate_license`        | `$license_key, $response`               | Run after license activation                     |
| `fe_search_ai_deactivate_license`      | `$license_key, $response`               | Run after license deactivation                   |
| `fe_search_ai_license_status`         | `$status, $license_key`                 | Filter license status                            |
| `fe_search_ai_pro_features_enabled`   | `$enabled, $license_key`               | Enable/disable Pro features based on license     |

## Hook Usage Examples

### Custom Embedding Provider

```php
add_filter( 'fe_search_ai_generate_embedding', function( $embedding, $text, $post_id, $args ) {
    // Use custom embedding service
    $custom_embedding = my_custom_embedding_service( $text );
    return $custom_embedding;
}, 10, 4 );
```

### Custom Search Results

```php
add_filter( 'fe_search_ai_filter_results', function( $results, $query, $args ) {
    // Filter results based on custom criteria
    $filtered = array_filter( $results, function( $result ) use ( $query ) {
        return $result->score > 0.8;
    } );
    return $filtered;
}, 10, 3 );
```

### Custom Sync Post Types

```php
add_filter( 'fe_search_ai_sync_post_types', function( $post_types ) {
    // Add custom post type to sync
    $post_types[] = 'my_custom_post_type';
    return $post_types;
} );
```

### Custom Search Form

```php
add_filter( 'fe_search_ai_search_form', function( $form_html, $args ) {
    // Add custom fields to search form
    $custom_field = '<input type="text" name="custom_field">';
    return str_replace( '</form>', $custom_field . '</form>', $form_html );
}, 10, 2 );
```

## Hook Best Practices

### Priority

- Use appropriate priority (default 10)
- Lower priority runs first, higher priority runs last
- Use priority to control execution order

### Parameters

- Always accept all parameters even if not used
- Use parameter counting to ensure compatibility
- Return appropriate value type

### Performance

- Avoid heavy operations in hooks
- Use caching where appropriate
- Keep hook callbacks efficient

### Compatibility

- Always check if function/class exists before using
- Provide fallbacks for missing dependencies
- Document breaking changes in release notes

## Hook Reference Summary

### Sync Hooks (9)
- Start/Complete hooks
- Pre/Post processing hooks
- Embedding generation hooks
- Batch size customization

### Search Hooks (8)
- Query processing hooks
- Vector search hooks
- AI reranking hooks
- Result filtering/formatting hooks

### API Hooks (6)
- Request preparation hooks
- API call hooks
- Response handling hooks
- Error handling hooks

### Frontend Hooks (5)
- Form rendering hooks
- Script enqueueing hooks
- AJAX request hooks
- Result display hooks

### Settings Hooks (5)
- Settings registration hooks
- Validation hooks
- Save hooks
- Section/field hooks

### License Hooks (5)
- Validation hooks
- Activation/deactivation hooks
- Status hooks
- Pro feature hooks
