# Release Process

FoldSnap releases are automated by `tools/release.sh`.

## Running a Release

```
./tools/release.sh <version>
```

Example: `./tools/release.sh 1.2.0`

## Pre-Release Checklist

Before invoking the script:

1. Add a `## [X.Y.Z] - YYYY-MM-DD` section to `CHANGELOG.md` under `[Unreleased]`
2. Ensure all changes are committed (the script requires a clean working tree)

## What the Script Does

The release script executes, in order:

1. **Quality gates** — `npm run build`, `npm run lint`, `npm test`, `phpcs`, `phpstan`, `plugin-check`, `phpunit`
2. **Version bump** — updates the version string in 3 files (see below)
3. **Build zip** — packages the plugin for distribution
4. **Commit + tag + push** — commits the version bump, tags the release, pushes to remote
5. **GitHub release** — creates a GitHub release with the zip attached and the changelog section as release notes

## Version Locations

The version is defined in 3 places. The script bumps all three automatically:

- `foldsnap.php` — `Version:` header
- `foldsnap-main.php` — `FOLDSNAP_VERSION` constant
- `readme.txt` — `Stable tag:`

If you ever need to bump manually, all three must stay in sync.

## Changelog

`CHANGELOG.md` follows the [Keep a Changelog](https://keepachangelog.com/) format.

The release script extracts the section matching the tagged version and uses it as the GitHub release notes. Section headers must match exactly: `## [X.Y.Z] - YYYY-MM-DD`.
