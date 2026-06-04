# Coding Standards

> FE Search AI follows WordPress Coding Standards (WPCS) with additional conventions for consistency and maintainability.

## PHP Standards

### WordPress Coding Standards

Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/):

- Use Yoda conditions: `if ( true === $condition )`
- Use spaces around array brackets: `$array = array( 1, 2, 3 );`
- Use single quotes for strings: `'string'`
- Use double quotes only for HTML or escaped characters
- Use 4 spaces for indentation (no tabs)
- Use trailing commas in multi-line arrays

### PHPDoc

Add PHPDoc to all classes, methods, and functions:

```php
/**
 * Generate embedding for text content.
 *
 * @since 1.0.0
 * @param string $text Text content to embed.
 * @param int    $post_id Post ID.
 * @return array Embedding vector.
 */
public function generate_embedding( $text, $post_id ) {
    // Implementation
}
```

**PHPDoc Rules**:
- First line: Brief description (no period)
- `@since` tag for version when introduced
- `@param` tags for all parameters
- `@return` tag for return values
- Use object types for IDE compatibility: `@param object $util`

### Comments

- Write comments in English
- End inline comments with period: `// Process the data.`
- No period on PHPDoc first line
- Comment complex logic
- Avoid obvious comments

```php
// Good
// Calculate similarity score using cosine similarity.
$score = $this->cosine_similarity( $a, $b );

// Bad
// Calculate score.
$score = $this->cosine_similarity( $a, $b );
```

### Naming Conventions

- Classes: PascalCase: `class FE_Search_AI_Embedding`
- Methods: snake_case: `public function generate_embedding()`
- Variables: snake_case: `$embedding_vector`
- Constants: UPPER_CASE: `const MAX_BATCH_SIZE = 100`
- Files: kebab-case: `class-fe-search-ai-embedding.php`

### Security

Always sanitize input and escape output:

```php
// Sanitize input
$text = sanitize_text_field( $_POST['text'] );
$post_id = intval( $_POST['post_id'] );

// Escape output
echo esc_html( $text );
echo esc_url( $url );
echo esc_attr( $attribute );
```

### Database Queries

Use `$wpdb->prepare()` for all queries:

```php
// Good
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fe_search_ai_embeddings WHERE post_id = %d",
        $post_id
    )
);

// Bad
$results = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}fe_search_ai_embeddings WHERE post_id = " . $post_id
);
```

### Error Handling

Use WordPress error logging:

```php
try {
    $result = $api->call();
} catch ( Exception $e ) {
    error_log( 'FE Search AI: API call failed - ' . $e->getMessage() );
    return false;
}
```

## JavaScript Standards

### JSDoc

Add JSDoc to all functions:

```javascript
/**
 * Perform search query.
 *
 * @param {string} query Search query.
 * @param {Object} options Search options.
 * @return {Promise<Array>} Search results.
 */
async function performSearch( query, options ) {
    // Implementation
}
```

### Naming Conventions

- Functions: camelCase: `performSearch()`
- Variables: camelCase: `searchResults`
- Constants: UPPER_CASE: `const MAX_RESULTS = 10`
- Classes: PascalCase: `class SearchEngine`

### Async/Await

Use async/await for asynchronous operations:

```javascript
// Good
async function search() {
    try {
        const results = await api.search( query );
        return results;
    } catch ( error ) {
        console.error( 'Search failed:', error );
        return [];
    }
}

// Avoid
function search() {
    return api.search( query )
        .then( results => results )
        .catch( error => {
            console.error( 'Search failed:', error );
            return [];
        } );
}
```

### DOM Manipulation

Always check element existence:

```javascript
const container = document.querySelector( '.fe-search-ai-results' );
if ( ! container ) {
    return;
}

container.innerHTML = results;
```

### Event Listeners

Use event delegation where possible:

```javascript
// Good (delegation)
document.addEventListener( 'click', ( e ) => {
    if ( e.target.matches( '.fe-search-ai-button' ) ) {
        handleClick( e );
    }
} );

// OK (direct)
document.querySelectorAll( '.fe-search-ai-button' ).forEach( button => {
    button.addEventListener( 'click', handleClick );
} );
```

## CSS Standards

### Naming Conventions

- Use BEM-like naming: `.fe-search-ai__element--modifier`
- Prefix all classes: `.fe-search-ai-*`
- Use kebab-case: `.search-results-container`

### Organization

Group related styles:

```css
/* Search Form */
.fe-search-ai-form {
    /* Styles */
}

.fe-search-ai-form__input {
    /* Styles */
}

/* Search Results */
.fe-search-ai-results {
    /* Styles */
}
```

### Responsive Design

Use mobile-first approach:

```css
/* Mobile first */
.fe-search-ai-container {
    width: 100%;
}

/* Tablet */
@media ( min-width: 768px ) {
    .fe-search-ai-container {
        width: 50%;
    }
}

/* Desktop */
@media ( min-width: 1024px ) {
    .fe-search-ai-container {
        width: 33.33%;
    }
}
```

## File Organization

### PHP Files

```
includes/
├── admin/
│   ├── class-fe-search-ai-admin.php
│   ├── class-fe-search-ai-settings.php
│   └── class-fe-search-ai-license-settings.php
├── core/
│   ├── class-fe-search-ai-activator.php
│   ├── class-fe-search-ai-assets.php
│   └── class-fe-search-ai-sync-hooks.php
├── ajax/
│   └── class-fe-search-ai-ajax-handler.php
└── frontend/
    └── class-fe-search-ai-frontend.php
```

### JavaScript Files

```
assets/js/
├── admin-scripts.js
├── frontend-scripts.js
└── search-engine.js
```

### CSS Files

```
assets/css/
├── admin-styles.css
├── frontend-styles.css
└── search-styles.css
```

## Git Commit Messages

Follow conventional commits:

```
feat: add custom embedding provider support
fix: resolve sync timeout issues
docs: update API integration guide
refactor: improve search performance
test: add unit tests for embedding generation
```

## Code Review Checklist

- [ ] Follows WPCS
- [ ] Has PHPDoc for all classes/methods
- [ ] Comments in English
- [ ] Uses Yoda conditions where appropriate
- [ ] Sanitizes input and escapes output
- [ ] Uses `$wpdb->prepare()` for queries
- [ ] Has error handling
- [ ] Tests edge cases
- [ ] No debug code left in
- [ ] No `var_dump()` or `print_r()` left in

## Linting Tools

### PHP

```bash
# Run PHPCS
composer phpcs

# Auto-fix with PHPCBF
composer phpcbf
```

### JavaScript

```bash
# Run ESLint
npm run lint:js

# Format with Prettier
npm run format
```

### CSS

```bash
# Format with Prettier
npm run format
```

## Common Pitfalls

### PHP

- Don't use `extract()` - it's unsafe
- Don't use `eval()` - it's unsafe
- Don't use `$_GET`, `$_POST` directly - sanitize first
- Don't concatenate SQL - use `$wpdb->prepare()`
- Don't forget to check capabilities

### JavaScript

- Don't use `var` - use `const` or `let`
- Don't forget to check element existence
- Don't use inline event handlers
- Don't forget error handling in async functions
- Don't use jQuery unless necessary

### CSS

- Don't use `!important` unless absolutely necessary
- Don't use IDs for styling
- Don't use inline styles
- Don't forget responsive design
- Don't use vendor prefixes manually (use autoprefixer)

## Performance

### PHP

- Use object caching for expensive operations
- Batch database queries
- Use indexes on frequently queried columns
- Avoid N+1 query problems

### JavaScript

- Debounce/throttle event handlers
- Use requestAnimationFrame for animations
- Lazy load images and resources
- Minimize DOM manipulation

### CSS

- Minimize repaints and reflows
- Use CSS transforms instead of position changes
- Use will-change sparingly
- Optimize images

## Accessibility

- Use semantic HTML
- Add ARIA labels where needed
- Ensure keyboard navigation works
- Provide alt text for images
- Use sufficient color contrast
