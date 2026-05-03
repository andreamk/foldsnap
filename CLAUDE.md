# CLAUDE.md

**MANDATORY:** Before working on any task:
1. Read [docs/00_DOCS_INDEX.md](docs/00_DOCS_INDEX.md) to know what documentation is available
2. Identify ALL documentation files relevant to the request
3. Read the relevant documentation FIRST
4. Only AFTER reading the documentation, begin analyzing code or working on the request

This sequence is mandatory, not optional. Documentation must be read before code exploration.

## Documentation Scope — Source of Truth

- **`docs/`** (top level, excluding `docs/develop/`) is the **single source of truth** for how the code currently works. Always trust it over memory or assumptions.
- **`docs/develop/`** contains development plans, design analyses, and migration trackers. These are historical or in-progress artifacts: they may describe code that has since been refactored, partially completed work, or abandoned approaches. **Do not treat `docs/develop/` as authoritative for current behavior.** When a `docs/develop/` file conflicts with `docs/` or the code, the code and `docs/` win.
- Read `docs/develop/` only when explicitly asked, or when investigating the historical intent behind a feature. Otherwise ignore it.

## Project Overview

WordPress plugin that adds folder management to the admin Media Library. React frontend.

**Requirements:** PHP 7.4+, WordPress 6.5+, Node.js 22+

## Quick Start

**Entry point:** `foldsnap.php` → `foldsnap-main.php`

```
src/                # PHP backend (FoldSnap\ namespace, PSR-4)
├── Controllers/    # Admin pages, REST endpoints
├── Models/         # Entities (folders, etc.)
├── Services/       # Business logic
└── ...
template/js/        # React source (entry: index.js)
assets/             # Compiled output (gitignored)
docs/               # Authoritative documentation
docs/develop/       # Historical/in-progress — NOT authoritative
tests/              # PHPUnit tests (Unit/, Feature/)
template/js/components/__tests__/  # Jest tests
```

## Programming Principles

- **Single Responsibility:** one class, one purpose. Classes >500–600 lines are doing too much — split.
- **Encapsulation:** private/protected by default. Expose only necessary public interfaces.
- **Single Source of Truth:** no duplicate logic. Extract into helpers.
- **Dependency Injection:** prefer constructor injection. Singletons are acceptable for shared state.
- **Type Safety:** `declare(strict_types=1)`, full type hints, return types. PHPStan must pass. PHP 7.4 has no `mixed` type — never cast `mixed` inline; validate the runtime type first (typically via a typed helper).
- **Fail Fast:** validate early, throw exceptions for invalid states.

## Project Rules

### Forbidden
- Inline styles (use `assets/`)
- Direct `$_GET` / `$_POST` access (always sanitize)
- Unprepared SQL queries (always `$wpdb->prepare()`)
- Direct DOM manipulation from PHP in React-managed areas
- Inline fully-qualified class names (always add a `use` statement at the top)
- Suppressing static analysis errors (`@phpcs:ignore`, `@phpstan-ignore`, `eslint-disable`) except for documented tool false positives

### Required
- Escape all output (`esc_html()`, `esc_attr()`, `wp_kses()`)
- Verify nonces on form submissions and REST/AJAX requests
- Permission callbacks on every REST endpoint, capability checks via `current_user_can()`
- `declare(strict_types=1)` in every PHP file
- Use `@wordpress/scripts` to build React assets
- Use `@wordpress/api-fetch` for REST calls (handles nonces); never raw `fetch()` or `axios`

## Security

Input sanitize → DB `$wpdb->prepare()` → output escape → nonce verify → capability check.

## Quality & Testing

- Before committing: `composer fullcheck`
- Run tests proportional to scope. Avoid the full suite unless changes are cross-cutting.
  - PHP: `composer phpunit -- --filter TestClassName`
  - JS: `npm test -- ComponentName`
- Available commands: [Utility Commands](docs/99_6_APPENDIX_utility_commands.md)
- Test writing standards (PHP and JS): [Test Writing Guide](docs/99_2_APPENDIX_write_tests_guide.md)

## Architecture & Setup Details

- Backend layers and request flows: [Architecture Overview](docs/01_1_ARCH_overview.md)
- React component design, store, drag-and-drop: [React UI Architecture](docs/04_1_UI_react-architecture.md)
- React build, lint, test setup: [React Setup](docs/99_4_APPENDIX_react_setup.md)
- Naming conventions: [Prefix Standards](docs/99_3_APPENDIX_prefix_standards.md)
- Release process: [Release Process](docs/99_7_APPENDIX_release_process.md)
