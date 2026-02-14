#!/usr/bin/env bash
#
# Release script for FoldSnap
#
# Usage: ./tools/release.sh <version>
# Example: ./tools/release.sh 1.0.0
#
# Steps:
#   1. Preflight checks (clean tree, gh CLI, changelog entry)
#   2. Quality gates (phpcs, phpstan, plugin-check, phpunit)
#   3. Bump version in all 3 files
#   4. Build clean zip via deploy.php
#   5. Commit, tag, push, create GitHub release with zip
#

set -euo pipefail

PLUGIN_SLUG="foldsnap"
PLUGIN_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEPLOY_DIR="${PLUGIN_ROOT}/tools/tmp/${PLUGIN_SLUG}"
MAIN_FILE="${PLUGIN_ROOT}/${PLUGIN_SLUG}.php"
BOOT_FILE="${PLUGIN_ROOT}/${PLUGIN_SLUG}-main.php"
README_FILE="${PLUGIN_ROOT}/readme.txt"
CHANGELOG_FILE="${PLUGIN_ROOT}/CHANGELOG.md"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BOLD='\033[1m'
NC='\033[0m'

info()  { echo -e "${GREEN}[INFO]${NC} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
error() { echo -e "${RED}[ERROR]${NC} $*" >&2; }
step()  { echo -e "\n${BOLD}==> $*${NC}"; }

die() {
    error "$@"
    exit 1
}

# --- Argument validation ---------------------------------------------------

if [[ $# -lt 1 ]]; then
    echo "Usage: $0 <version>"
    echo "  e.g. $0 1.0.0"
    exit 1
fi

VERSION="$1"

# Validate semver format (major.minor.patch)
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    die "Version must be semver (e.g. 1.0.0). Got: ${VERSION}"
fi

TAG="v${VERSION}"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="${PLUGIN_ROOT}/tools/tmp/${ZIP_NAME}"

# --- Step 1: Preflight checks ---------------------------------------------

step "Preflight checks"

# Must be in a git repo
if ! git -C "$PLUGIN_ROOT" rev-parse --git-dir >/dev/null 2>&1; then
    die "Not a git repository: ${PLUGIN_ROOT}"
fi

# Clean working tree
if ! git -C "$PLUGIN_ROOT" diff --quiet || ! git -C "$PLUGIN_ROOT" diff --cached --quiet; then
    die "Working tree is dirty. Commit or stash changes first."
fi

if [[ -n "$(git -C "$PLUGIN_ROOT" ls-files --others --exclude-standard)" ]]; then
    die "Untracked files present. Commit or remove them first."
fi

# Tag must not already exist
if git -C "$PLUGIN_ROOT" tag -l "$TAG" | grep -q "$TAG"; then
    die "Tag ${TAG} already exists."
fi

# gh CLI available
if ! command -v gh &>/dev/null; then
    die "GitHub CLI (gh) is required. Install: https://cli.github.com/"
fi

# Changelog entry for this version in CHANGELOG.md
if ! grep -q "^## \[${VERSION}\]" "$CHANGELOG_FILE"; then
    die "No changelog entry for version ${VERSION} in CHANGELOG.md. Add one before releasing."
fi

info "Version:  ${VERSION}"
info "Tag:      ${TAG}"
info "All preflight checks passed."

# --- Step 2: Quality gates -------------------------------------------------

step "Running quality checks"

cd "$PLUGIN_ROOT"

info "phpcs (PSR-12)..."
composer run-script phpcs .

info "phpstan..."
composer run-script phpstan

info "plugin-check..."
composer run-script plugin-check .

info "phpunit..."
composer run-script phpunit

info "All quality checks passed."

# --- Step 3: Bump version --------------------------------------------------

step "Bumping version to ${VERSION}"

CURRENT_VERSION=$(grep 'Version:' "$MAIN_FILE" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
info "Current version: ${CURRENT_VERSION}"

# foldsnap.php  ->  Version: X.Y.Z
sed -i "s/Version: ${CURRENT_VERSION}/Version: ${VERSION}/" "$MAIN_FILE"
info "Updated ${MAIN_FILE##*/}"

# foldsnap-main.php  ->  define('FOLDSNAP_VERSION', 'X.Y.Z')
sed -i "s/define('FOLDSNAP_VERSION', '${CURRENT_VERSION}')/define('FOLDSNAP_VERSION', '${VERSION}')/" "$BOOT_FILE"
info "Updated ${BOOT_FILE##*/}"

# readme.txt  ->  Stable tag: X.Y.Z
sed -i "s/Stable tag: ${CURRENT_VERSION}/Stable tag: ${VERSION}/" "$README_FILE"
info "Updated ${README_FILE##*/}"

# --- Step 4: Build zip -----------------------------------------------------

step "Building distribution zip"

# Clean previous artifacts
rm -rf "$DEPLOY_DIR" "$ZIP_PATH"

# Run deploy script to copy clean files
php tools/deploy.php

# Create zip (from tools/tmp so the zip root is foldsnap/)
cd "${PLUGIN_ROOT}/tools/tmp"
zip -r "$ZIP_PATH" "$PLUGIN_SLUG" -x '*.DS_Store'
cd "$PLUGIN_ROOT"

ZIP_SIZE=$(du -h "$ZIP_PATH" | cut -f1)
info "Created: ${ZIP_PATH} (${ZIP_SIZE})"

# --- Step 5: Commit, tag, and release --------------------------------------

step "Creating release"

# Commit version bump
git -C "$PLUGIN_ROOT" add \
    "$MAIN_FILE" \
    "$BOOT_FILE" \
    "$README_FILE"

git -C "$PLUGIN_ROOT" commit -m "Release ${VERSION}"

# Tag
git -C "$PLUGIN_ROOT" tag -a "$TAG" -m "Release ${VERSION}"
info "Created tag ${TAG}"

# Push commit + tag
info "Pushing to remote..."
git -C "$PLUGIN_ROOT" push
git -C "$PLUGIN_ROOT" push origin "$TAG"

# Create GitHub release with zip
info "Creating GitHub release..."
# Extract changelog section for this version (between ## [X.Y.Z] and next ## [)
CHANGELOG=$(sed -n "/^## \[${VERSION}\]/,/^## \[/p" "$CHANGELOG_FILE" | sed '1d;$d')

gh release create "$TAG" "$ZIP_PATH" \
    --title "${PLUGIN_SLUG} ${VERSION}" \
    --notes "${CHANGELOG:-Release ${VERSION}}"

# --- Done ------------------------------------------------------------------

step "Release ${VERSION} complete!"
info "GitHub release: $(gh release view "$TAG" --json url -q .url)"

# Clean up build artifacts
rm -rf "$DEPLOY_DIR"
info "Cleaned up build artifacts."
