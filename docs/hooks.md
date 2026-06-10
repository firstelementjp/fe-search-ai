# Developer Hooks

FE Search AI is designed to be extended with WordPress filters and actions.

## Common extension areas

- Provider lists
- Prompt generation
- Context retrieval
- Sync target selection
- Frontend output
- API key rows and settings UI
- Pro add-on integration

## Development guidelines

- Use prefixed hook names.
- Escape output in UI callbacks.
- Sanitize external input.
- Keep provider API keys server-side.
- Test changes with representative content.

## Examples

### Modify the system prompt

```php
add_filter( 'fe_search_ai_system_prompt', function ( $prompt ) {
    return $prompt . "\nAnswer concisely and cite relevant site content when possible.";
} );
```

### Extend frontend output

```php
add_filter( 'fe_search_ai_chat_ui_html', function ( $html ) {
    return $html;
} );
```

## Pro integrations

FE Search AI Pro uses hooks to add advanced settings and behavior without requiring the free plugin to include Pro-only implementation details.

## Source code

See the plugin source for the authoritative list of available hooks.

[GitHub Repository](https://github.com/firstelementjp/fe-search-ai)
