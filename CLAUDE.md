# CLAUDE.md

Quick reference for FoldSnap codebase. Full documentation: [docs/00_DOCS_INDEX.md](docs/00_DOCS_INDEX.md)

## Project Overview

WordPress plugin that adds folder management capabilities to the admin media library. Uses React for the frontend UI.

**Requirements:** PHP 7.4+, WordPress 6.5+

## Programming Principles

**Single Responsibility:** One class, one purpose. One function, one task. Separate concerns clearly. If a class grows beyond ~500-600 lines, it's doing too much — split it. No god classes that handle everything.

**Encapsulation:** Hide implementation details. Use private/protected. Expose only necessary public interfaces.

**Single Source of Truth:** No duplicate logic. Extract common code into reusable helpers.

**Dependency Injection:** Prefer passing dependencies to constructors. Singleton is acceptable when a single shared instance is needed.

**Type Safety:** Always use `declare(strict_types=1)`, type hints, and return types. PHP 7.4 does not support `mixed` — you cannot cast from it directly. PHPStan will flag unsafe casts from `mixed`. When a WordPress/PHP function returns `mixed` (e.g., `get_option`), create typed helper methods instead of inline casting. Validate the type, cast explicitly, and return a typed result or default. For example, wrap `get_option` calls in a utility class with methods like `getOptionString(string $key, string $default): string`.

**Fail Fast:** Validate early. Throw exceptions for invalid states. Don't silently fail.

## Architecture

### Backend (PHP)

PHP backend follows an MVC-like pattern. Controllers handle logic and permissions, services manage business rules, models represent data. Templates are used only for server-side admin markup when needed.

### Frontend (React)

The media library UI is built with React (via `@wordpress/scripts` build toolchain). React source lives in `template/js/` and is compiled to `assets/js/`. Use WordPress REST API for communication between frontend and backend. Follow WordPress React patterns (`@wordpress/element`, `@wordpress/components`, `@wordpress/data`).

## Project Rules

### Naming Conventions
See [docs/99_3_APPENDIX_prefix_standards.md](docs/99_3_APPENDIX_prefix_standards.md) for complete prefix standards by context (PHP, CSS, JS, WordPress).

**PHP Namespace:** `FoldSnap\` (PSR-4 mapped to `src/`)

### Forbidden
- Inline styles (use `assets/`)
- Direct `$_GET`/`$_POST` access (always sanitize)
- Unprepared SQL queries (always `$wpdb->prepare()`)
- Direct DOM manipulation from PHP in React-managed areas

### Required
- Escape all output (`esc_html()`, `esc_attr()`, etc.)
- Verify nonces on form submissions and REST/AJAX requests
- Follow WordPress coding standards
- `declare(strict_types=1)` in every PHP file
- Use `@wordpress/scripts` for building React assets

## Security

- **Input:** Sanitize all user input
- **Database:** Always use `$wpdb->prepare()` for queries
- **Output:** Escape with WordPress functions (`esc_html`, `esc_attr`, `wp_kses`)
- **Forms:** Verify nonces before processing
- **REST API:** Use permission callbacks on all endpoints
- **Capabilities:** Check permissions with `current_user_can()`

## Quality Checks

Run **all** static analysis and tests in one command:

```
composer fullcheck
```

This executes in order: phpcbf (auto-fix) → phpcs → plugin-check → phpstan → phpunit. **Run this as the last step after completing a series of changes**, before committing.

Individual commands: `composer phpcs .`, `composer plugin-check .`, `composer phpstan`, `composer phpunit`.

For React/JS: `npm run lint`, `npm run build`, `npm test`.

**Fix errors, don't suppress them.** When phpcs or phpstan report issues, the correct approach is to fix the underlying code. Do NOT use `@phpcs:ignore`, `@phpstan-ignore`, or similar suppression comments to hide problems. Suppression is acceptable only as a last resort when there is genuinely no way to fix the issue (e.g., a false positive from the tool itself).

## Testing ([full guide](docs/99_2_APPENDIX_write_tests_guide.md))

- **Location:** `tests/` directory
- **Pattern:** Files ending in `*Tests.php`
- **Structure:** `tests/Unit/` for isolated tests, `tests/Feature/` for integration
- **Utilities:** Reusable helpers in `tests/TestsUtils/`
- **Run:** `composer phpunit`
- **JS Tests:** `npm test`

## Releasing

Run `./tools/release.sh <version>` (e.g. `./tools/release.sh 1.2.0`).

Before releasing:
1. Add a `## [X.Y.Z] - YYYY-MM-DD` section to `CHANGELOG.md` under `[Unreleased]`
2. Ensure all changes are committed (script requires a clean working tree)

The script handles: quality gates (phpcs, phpstan, plugin-check, phpunit) → version bump in 3 files → build zip → commit + tag + push → GitHub release with zip attached.

**Version is defined in 3 places** (auto-bumped by the script):
- `foldsnap.php` — `Version:` header
- `foldsnap-main.php` — `FOLDSNAP_VERSION` constant
- `readme.txt` — `Stable tag:`

**Changelog:** `CHANGELOG.md` (Keep a Changelog format). The release script extracts the section for the tagged version and uses it as GitHub release notes.

## Documentation

- **Index:** [docs/00_DOCS_INDEX.md](docs/00_DOCS_INDEX.md)
- **Doc Writing:** [docs/99_1_APPENDIX_doc_writing_guide.md](docs/99_1_APPENDIX_doc_writing_guide.md)
- **Test Writing:** [docs/99_2_APPENDIX_write_tests_guide.md](docs/99_2_APPENDIX_write_tests_guide.md)
- **Prefix Standards:** [docs/99_3_APPENDIX_prefix_standards.md](docs/99_3_APPENDIX_prefix_standards.md)
