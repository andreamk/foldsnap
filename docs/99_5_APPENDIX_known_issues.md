# Known Issues

False positives intentionally left unfixed.

## `missing_direct_file_access_protection` on class files

WP.org Plugin Check flags class files that call `add_action(`, `add_filter(`, or `define(` from a method body. Its regex fallback (`has_procedural_code`) doesn't distinguish between top-level and method-body calls.

Acknowledged upstream as non-blocking — see [issue #1286](https://github.com/WordPress/plugin-check/issues/1286).

Affected: `RestApiController`, `MediaLibraryController`, `Bootstrap`, `ControllersManager`, `AbstractMenuPageController`, `AbstractSinglePageController`, `AttachmentLifecycleService`.

Not adding the guard: PSR-4 autoloaded files aren't directly accessible, and the guard would conflict with `PSR1.Files.SideEffects`. If a reviewer pushes back at submission, add `defined('ABSPATH') || exit;` after each `namespace` and exclude those files in `tools/ruleset.xml` (same pattern as `foldsnap.php`).

## `NonPrefixedVariableFound` / `DynamicHooknameFound`

Silenced inline with explicit justification:

- `template/parts/*.php` — variables are local to `TplMng::render()`'s include scope, not global.
- `src/Core/Views/TplMng.php` — hook names come from `getDataHook()`/`getRenderHook()` which always return `foldsnap_`-prefixed strings; PHPCS can't resolve the return value.
