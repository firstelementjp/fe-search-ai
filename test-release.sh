#!/bin/bash

# Test release script for FE Search AI
# This script creates a release ZIP from the current git branch for quick testing

set -e

# Get current branch and version from plugin file
BRANCH=$(git branch --show-current)
VERSION=$(grep "Version:" fe-search-ai.php | awk '{print $3}')
TAG="v$VERSION"

echo "Testing release process for branch: $BRANCH (version: $VERSION)"

# Create temporary directory
TMPDIR=$(mktemp -d)
trap "rm -rf $TMPDIR" EXIT

# Install dependencies (no-dev)
echo "=== Install dependencies (no-dev) ==="
composer install --no-dev --prefer-dist

# Build frontend assets in current directory
echo "=== Build frontend assets ==="
npm install --silent
npm run build --silent

# Create release tree from git archive (respects .gitattributes export-ignore)
echo "=== Create release tree from git archive ==="
git archive --format=tar --prefix=fe-search-ai/ --worktree-attributes HEAD | tar -x -C "$TMPDIR"

# Remove development files explicitly (additional safety)
echo "=== Remove development files ==="
rm -rf "$TMPDIR/fe-search-ai/tests"
rm -rf "$TMPDIR/fe-search-ai/docs"
rm -f "$TMPDIR/fe-search-ai/SECURITY.md"
rm -f "$TMPDIR/fe-search-ai/CONTRIBUTING.md"
rm -f "$TMPDIR/fe-search-ai/wp-tests-config.php.example"
rm -f "$TMPDIR/fe-search-ai/phpunit.xml"

# Inject vendor directory (required for WordPress plugin)
echo "=== Inject vendor directory ==="
cp -r vendor "$TMPDIR/fe-search-ai/"

# Copy built/minified assets from working directory into the release tree
echo "=== Copy minified assets ==="
mkdir -p "$TMPDIR/fe-search-ai/assets/js"
mkdir -p "$TMPDIR/fe-search-ai/assets/css"
cp -f assets/js/*.min.js "$TMPDIR/fe-search-ai/assets/js/" || true
cp -f assets/css/*.min.css "$TMPDIR/fe-search-ai/assets/css/" || true

# Copy vendor assets (Pickr color picker)
echo "=== Copy vendor assets ==="
if [ -d "assets/vendor" ]; then
    mkdir -p "$TMPDIR/fe-search-ai/assets/vendor"
    cp -r assets/vendor/* "$TMPDIR/fe-search-ai/assets/vendor/"
fi

# Security checks in release tree
echo "=== Security checks in release tree ==="

echo "Files starting with underscore (should be 0):"
UNDERSCORE_COUNT=$(find "$TMPDIR/fe-search-ai" -name "_*" | wc -l | tr -d ' ')
echo "       $UNDERSCORE_COUNT"
if [ "$UNDERSCORE_COUNT" -gt 0 ]; then
    echo "ERROR: Found files starting with underscore:"
    find "$TMPDIR/fe-search-ai" -name "_*"
    exit 1
fi

echo "Files starting with dot (should be 0, excluding vendor):"
DOT_COUNT=$(find "$TMPDIR/fe-search-ai" -name ".*" -not -path "*/vendor/*" | wc -l | tr -d ' ')
echo "       $DOT_COUNT"
if [ "$DOT_COUNT" -gt 0 ]; then
    echo "ERROR: Found files starting with dot:"
    find "$TMPDIR/fe-search-ai" -name ".*" -not -path "*/vendor/*"
    exit 1
fi

# Sanity checks in release tree
echo "=== Sanity checks in release tree ==="

echo "Vendor directory exists:"
if [ -d "$TMPDIR/fe-search-ai/vendor" ]; then
    echo "Yes"
else
    echo "No - FAILED"
    exit 1
fi

echo "php-stemmer library exists:"
if [ -d "$TMPDIR/fe-search-ai/vendor/wamania/php-stemmer/src/Stemmer" ]; then
    echo "Yes"
else
    echo "No - FAILED"
    exit 1
fi

echo "TinySegmenter library exists:"
if [ -f "$TMPDIR/fe-search-ai/vendor/u7aro/tinysegmenter-php/src/TinySegmenter.php" ]; then
    echo "Yes"
else
    echo "No - FAILED"
    exit 1
fi

echo "PHPCS libraries (should be 0):"
PHPCS_COUNT=$(find "$TMPDIR/fe-search-ai/vendor" -name "*phpcs*" -type f 2>/dev/null | wc -l | tr -d ' ')
echo "       $PHPCS_COUNT"

# Create ZIP
echo "=== Create ZIP ==="
cd "$TMPDIR"
ZIPNAME="fe-search-ai-${TAG#v}.zip"
zip -r "../$ZIPNAME" fe-search-ai -q
cd - > /dev/null
mv "$TMPDIR/../$ZIPNAME" .

echo "Release archive created: $ZIPNAME"
SIZE=$(du -h "$ZIPNAME" | cut -f1)
echo "Size: $SIZE"
