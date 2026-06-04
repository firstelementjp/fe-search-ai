# Environment Setup

> Development environment setup guide for FE Search AI.

## Prerequisites

- PHP **7.4+** (8.2+ recommended)
- Node.js **18+**
- Composer
- npm
- Git
- Local WordPress environment (Local by Flywheel, WP-CLI, or similar)

## Local Development Setup

### 1. Clone Repository

```bash
git clone https://github.com/firstelementjp/fe-search-ai.git
cd fe-search-ai
```

### 2. Install PHP Dependencies

```bash
composer install
```

### 3. Install Node Dependencies

```bash
npm install
```

### 4. Build Assets

```bash
npm run build
```

### 5. Link to WordPress

#### Local by Flywheel

1. Open Local by Flywheel
2. Create or select a site
3. Right-click site → Open Site SSH
4. Navigate to plugins directory:
   ```bash
   cd app/public/wp-content/plugins
   ```
5. Create symlink or copy plugin:
   ```bash
   ln -s /path/to/fe-search-ai fe-search-ai
   ```

#### Manual WordPress Installation

```bash
cd /path/to/wordpress/wp-content/plugins
ln -s /path/to/fe-search-ai fe-search-ai
```

### 6. Activate Plugin

1. Log in to WordPress admin
2. Go to Plugins
3. Activate FE Search AI

## Development Tools

### PHP CodeSniffer

```bash
# Run PHPCS
composer phpcs

# Auto-fix with PHPCBF
composer phpcbf
```

### JavaScript Linting

```bash
# Run ESLint
npm run lint:js

# Format with Prettier
npm run format
```

### Watch Mode

```bash
# Watch for JS/CSS changes
npm run dev
```

### Test Release

```bash
# Build local release ZIP
./test-release.sh
```

## API Keys Setup

### Cohere API Key (Optional)

1. Get API key from [Cohere](https://cohere.com/)
2. Go to FE Search AI → Settings
3. Enter API key in "Cohere API Key" field
4. Save settings

### OpenAI API Key (Optional)

1. Get API key from [OpenAI](https://openai.com/)
2. Go to FE Search AI → Settings
3. Enter API key in "OpenAI API Key" field
4. Save settings

## Database Setup

### Manual Table Creation

If automatic table creation fails, run this SQL:

```sql
CREATE TABLE wp_fe_search_ai_embeddings (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    post_id bigint(20) NOT NULL,
    embedding longtext NOT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY post_id (post_id),
    KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Debugging

### Enable WordPress Debug Mode

Add to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

### View Debug Log

```bash
tail -f wp-content/debug.log
```

### Browser Console

Open browser DevTools (F12) to view:
- Console errors
- Network requests
- AJAX responses

## Testing

### Unit Tests

```bash
# Install test dependencies
composer install --dev

# Run tests
vendor/bin/phpunit
```

### Manual Testing

1. Sync content: FE Search AI → Sync → Start Sync
2. Test search: Use search form on frontend
3. Check results: Verify relevance and accuracy
4. Test API: Check API calls in debug log

## Common Issues

### Composer Install Fails

```bash
# Clear composer cache
composer clear-cache

# Update composer
composer self-update

# Try again
composer install
```

### NPM Install Fails

```bash
# Clear npm cache
npm cache clean --force

# Delete node_modules
rm -rf node_modules package-lock.json

# Try again
npm install
```

### Asset Build Fails

```bash
# Check Node version
node --version  # Should be 18+

# Clear cache
npm cache clean --force

# Rebuild
npm run build
```

### Plugin Not Activating

1. Check PHP version (7.4+ required)
2. Check for errors in debug log
3. Verify all dependencies are installed
4. Check file permissions

### Sync Not Working

1. Check API keys are configured
2. Check post types are selected in settings
3. Check for errors in debug log
4. Verify database tables exist
5. Check PHP memory limit

### Search Not Working

1. Check content has been synced
2. Check vector embeddings exist in database
3. Check API keys are valid
4. Check for errors in debug log
5. Verify search form is properly rendered

## IDE Configuration

### VS Code

Install extensions:
- PHP Intelephense
- ESLint
- Prettier
- WordPress Snippets

Recommended settings:
```json
{
    "php.validate.executablePath": "/usr/bin/php",
    "editor.formatOnSave": true,
    "editor.defaultFormatter": "esbenp.prettier-vscode"
}
```

### PHPStorm

1. Enable WordPress plugin
2. Configure PHP interpreter
3. Set up code style to WPCS
4. Enable PHPCS integration

## Git Workflow

### Branch Strategy

- `main`: Stable releases
- `develop`: Ongoing development
- `feature/*`: Feature branches
- `fix/*`: Bugfix branches

### Commit Messages

Follow conventional commits:
```
feat: add custom embedding provider
fix: resolve sync timeout issues
docs: update API integration guide
```

### Pull Request Process

1. Create branch from `develop`
2. Make changes
3. Run tests and linting
4. Push to GitHub
5. Open PR targeting `develop`
6. Wait for review
7. Address feedback
8. Merge to `develop`

## Performance Optimization

### PHP

- Increase memory limit in `php.ini`:
  ```ini
  memory_limit = 512M
  ```
- Increase execution time:
  ```ini
  max_execution_time = 300
  ```

### Database

- Add indexes to frequently queried columns
- Optimize tables regularly
- Use query caching

### Assets

- Minify JavaScript and CSS
- Use browser caching
- Lazy load images

## Security

### API Keys

- Never commit API keys to repository
- Use environment variables
- Encrypt keys in database
- Rotate keys regularly

### File Permissions

```bash
# Set appropriate permissions
chmod 644 *.php
chmod 755 directories
```

### .gitignore

Ensure `.gitignore` excludes:
- `node_modules/`
- `vendor/`
- `.env`
- `*.log`
- `debug.log`
- `.DS_Store`

## Deployment

### Release Process

1. Update version in `fe-search-ai.php`
2. Update `readme.txt`
3. Run `./test-release.sh`
4. Commit changes
5. Create tag: `git tag v1.0.0`
6. Push tag: `git push origin v1.0.0`
7. GitHub Actions will auto-deploy

### Manual Deployment

1. Build release ZIP
2. Test locally
3. Upload to WordPress.org
4. Update version in readme
5. Release to public

## Resources

- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [PHP Documentation](https://www.php.net/docs.php)
- [JavaScript MDN](https://developer.mozilla.org/en-US/docs/Web/JavaScript)
- [Cohere API Docs](https://docs.cohere.com/)
- [OpenAI API Docs](https://platform.openai.com/docs)
