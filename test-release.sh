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

# Create release tree from git archive
echo "=== Create release tree from git archive ==="
git archive --format=tar HEAD | tar -x -C "$TMPDIR"

# Inject vendor directory
echo "=== Inject vendor directory ==="
cp -r vendor "$TMPDIR/"

# Build frontend assets
echo "=== Build frontend assets ==="
cd "$TMPDIR"
npm install --silent
npm run build --silent
cd - > /dev/null

# Remove node_modules from release
rm -rf "$TMPDIR/node_modules"

# Sanity checks in release tree
echo "=== Sanity checks in release tree ==="

echo "Vendor directory exists:"
if [ -d "$TMPDIR/vendor" ]; then
    echo "Yes"
else
    echo "No - FAILED"
    exit 1
fi

echo "php-stemmer library exists:"
if [ -d "$TMPDIR/vendor/wamania/php-stemmer/src/Stemmer" ]; then
    echo "Yes"
else
    echo "No - FAILED"
    exit 1
fi

echo "TinySegmenter library exists:"
if [ -f "$TMPDIR/vendor/u7aro/tinysegmenter-php/src/TinySegmenter.php" ]; then
    echo "Yes"
else
    echo "No - FAILED"
    exit 1
fi

echo "PHPCS libraries (should be 0):"
PHPCS_COUNT=$(find "$TMPDIR/vendor" -name "*phpcs*" -type f 2>/dev/null | wc -l | tr -d ' ')
echo "       $PHPCS_COUNT"

# Create ZIP
echo "=== Create ZIP ==="
# Create directory structure with correct folder name
mkdir -p "$TMPDIR/fe-search-ai"
mv "$TMPDIR"/* "$TMPDIR/fe-search-ai/" 2>/dev/null || true

cd "$TMPDIR"
ZIPNAME="fe-search-ai-${TAG#v}.zip"
zip -r "../$ZIPNAME" fe-search-ai -q
cd - > /dev/null
mv "$TMPDIR/../$ZIPNAME" .

echo "Release archive created: $ZIPNAME"
SIZE=$(du -h "$ZIPNAME" | cut -f1)
echo "Size: $SIZE"
