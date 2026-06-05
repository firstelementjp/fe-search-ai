# Contributing to FE Search AI

Thanks for taking the time to contribute!

This project is a WordPress plugin that provides AI-powered semantic search using vector embeddings and AI reranking.

## Where to Start

- **Bug reports**: open an issue with steps to reproduce and expected/actual behavior.
- **Feature requests**: open an issue describing the use case and desired UX.
- **Security issues**: please do **not** open a public issue. Contact the maintainers privately.

## Development Setup

### Prerequisites

- PHP **7.4+**
- Node.js **18+**
- Composer
- OpenSSL extension

### Install dependencies

```bash
composer install
npm ci
```

### Build assets

The admin UI uses minified assets built by esbuild.

```bash
npm run build
```

### Watch mode

```bash
npm run dev
```

## Coding Standards

### PHP

- Follow WordPress Coding Standards (WPCS).
- Add **PHPDoc** for classes, functions, and methods.
- Write code comments in **English**.
- Use Yoda conditions where appropriate (e.g., `0 === $var`)

Run linting:

```bash
composer phpcs
```

Auto-fix when possible:

```bash
composer phpcbf
```

### JavaScript / CSS

- Write code comments in **English**.
- After editing JavaScript or CSS files in `assets/js/` or `assets/css/`, rebuild minified files:

```bash
npm run build
```

The build process handles:

- `assets/js/admin-scripts.js` → `assets/js/admin-scripts.min.js`
- `assets/js/frontend-scripts.js` → `assets/js/frontend-scripts.min.js`
- `assets/css/admin-styles.css` → `assets/css/admin-styles.min.css`
- `assets/css/frontend-styles.css` → `assets/css/frontend-styles.min.css`

```bash
npm run lint:js
npm run format
```

## Regression Test Checklist (Sync System)

When you refactor sync code or touch content synchronization / vector embedding / AI API integration, run the checklist below in a real WordPress environment.

### 1) Sync Test

1. Open **Admin Dashboard → FE Search AI → Sync**
2. Run a sync for a small set of posts (10-20 posts)
3. Confirm:
    - Sync completes without errors
    - Progress UI updates and reaches 100%
    - Browser console has no JSON parse errors
    - Log summary shows synced/errors with expected labels
    - Vector embeddings are created in the database

### 2) Search Test

1. Perform a search query on the frontend
2. Confirm:
    - Search results are returned
    - Results are relevant to the query
    - AI reranking works (if enabled)
    - No errors in browser console
    - `debug.log` does not contain PHP fatal errors

### 3) Asset rebuild (when JS/CSS changes)

If you changed files under `assets/js/` or `assets/css/`, rebuild and include the updated minified outputs:

```bash
npm run build
```

## Project Conventions

### AI API Integration

- Always handle API errors gracefully
- Provide user feedback for API failures
- Implement fallback logic when possible
- Encrypt API keys before storage

### Sync System

- Use batch processing for large content sets
- Implement progress tracking
- Support resume capability
- Log sync errors for debugging

### JavaScript internationalization

- **Never** use `__()` or other i18n functions directly in JavaScript
- All translations should be handled in PHP using i18n functions
- Pass translated strings to JavaScript via `wp_localize_script`
- JavaScript should only reference the localized array strings

## Branching / Pull Requests

### Branch strategy

- `main`: stable releases
- `develop`: ongoing development
- `feature/*`: feature branches
- `fix/*`: bugfix branches

### Recommended workflow

1. Fork the repo
2. Create a branch from `develop`
3. Make changes in small, reviewable commits
4. Open a Pull Request targeting `develop`

### Pull Request checklist

- [ ] The change is described clearly (what/why/how)
- [ ] You tested the feature/bugfix in a real WordPress environment
- [ ] `composer phpcs` passes (or you explain why it cannot)
- [ ] JS/CSS changes include updated minified files (`npm run build`)
- [ ] Docs updated if behavior changed
- [ ] PHP version requirement (7.4+) is still appropriate
- [ ] No debug code or `error_log()` statements left in committed code

## Reporting Bugs

Please include:

- WordPress version
- PHP version
- Steps to reproduce
- Expected behavior vs actual behavior
- Relevant logs (e.g., `debug.log`)
- AI provider being used (if applicable)

## License

By contributing, you agree that your contributions will be licensed under the project license (GPL-2.0+).
