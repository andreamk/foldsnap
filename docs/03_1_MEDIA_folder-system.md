# Folder Data Model and Filtering

Folders live as terms in a custom hierarchical taxonomy. Each folder carries pre-aggregated `total_media_count` and `total_size` term meta that the plugin maintains incrementally on every mutation. The Media Library is filtered by folder through three integration points (REST, AJAX grid, list-mode `pre_get_posts`) that all share a single `tax_query` builder.

## Storage

### Taxonomy

`foldsnap_folder` is a hierarchical taxonomy attached to the `attachment` post type, registered with `show_ui = false`, `show_in_rest = false`, and the `_update_generic_term_count` callback. Folders are real WordPress terms; the parent–child relationship in `wp_term_taxonomy.parent` is the folder tree.

### Term meta

Each folder term carries four meta entries: presentation (color, sibling sort position) and aggregates (recursive byte total, recursive media count). Meta keys are exposed as `public const` on `FolderModel` (`META_COLOR`, `META_POSITION`, `META_SIZE`, `META_COUNT`) — callers reference the constants, never the literal strings, so renames are a one-place change.

Meta values are stored as strings (WordPress meta storage convention) and cast on read. They are initialized at folder creation and kept in sync by the repository and the lifecycle service — see [Incremental counters](#incremental-counters).

### Options

Root has no backing term, so its two recursive totals (bytes and media count) live in `wp_options`. The option names are exposed as `public const` on `FolderCounterService` (`OPT_ROOT_SIZE`, `OPT_ROOT_COUNT`). These are global site-wide totals — when the user views Root, they see the whole site.

## The virtual Root

There is no term for Root. `FolderModel::ROOT_ID = 0` and `FolderModel::NO_PARENT = -1` are sentinels:

- `id = 0` represents Root.
- `parent_id = -1` (`NO_PARENT`) only ever appears on the Root model itself, distinguishing it from a real top-level folder whose `parent_id = 0`.
- Top-level folders (children of Root) have `parent_id = 0`.

`FolderRepository::getById(0)` returns a synthetic `FolderModel::root(...)` populated from the option-backed counters. Mutating Root (rename, reparent, delete, removeMedia) is rejected by `FolderRepository::guardNotRoot()`. Assigning media to Root is semantically "unassign from any folder" and routes through `unassignMedia()`.

## FolderModel

`FolderModel` is an immutable DTO. All counter and metadata values are populated when the model is built — there is no lazy computation. The model carries:

- **Identity from the term** — `id`, `name`, `slug`, `parent_id` (the parent column on `wp_term_taxonomy`, with `-1` reserved for the synthetic Root).
- **Direct count** — `media_count` from the term's own count column (attachments directly assigned, descendants excluded).
- **Recursive aggregates** — `total_media_count` and `total_size` from the term-meta keys declared on `FolderModel` (see [Term meta](#term-meta)).
- **Presentation** — `color` and `position` from term meta.
- **Tree-shape hint** — `has_children`, resolved at build time from a bulk children-count lookup so individual terms don't fan out into per-row queries.
- **Root marker** — `is_root`, true only for the synthetic Root.

The bulk constructor (`fromTerms`) primes the term-meta cache and the children-counts in two queries, then walks the input terms — the resulting list is fully populated without per-term round-trips. A `fromTerm` convenience wraps it for single-term call sites.

## Incremental counters

`total_media_count` and `total_size` are not computed on demand: they are stored on each folder and adjusted in place. Every mutation that changes a folder's contents emits a signed `(size, count)` delta and applies it to a chain of term IDs in a single bulk `UPDATE`.

### Ancestor chain

Given a folder `F`, the chain is `[F, parent(F), grandparent(F), ...]` up to (but not including) Root. The chain is exactly the set of terms whose `total_*` aggregates include `F`. Root is excluded because its totals live in options, not term meta.

### Bulk delta

`Database::bulkAdjustTermMeta($termIds, $metaKey, $delta)` runs:

```sql
UPDATE wp_termmeta
SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + :delta)
WHERE meta_key = :key
  AND term_id IN (:ids)
```

`GREATEST(0, ...)` clamps at zero so a stale value can never go negative. The pre-condition is that every term in `$termIds` already has a row for the meta key — folders created via `FolderRepository::create()` satisfy this on insert; older terms are normalized by `Database::ensureFolderCountersInitialized()`.

### Mutation flows

| Trigger              | Origin chain delta              | Destination chain delta         | Root option delta                      |
|----------------------|---------------------------------|---------------------------------|----------------------------------------|
| Reparent folder      | `−(subtreeSize, subtreeCount)`  | `+(subtreeSize, subtreeCount)`  | none — content stays inside Root total |
| Delete folder        | `−(directSize, directCount)` from parent chain (subfolders are promoted, already counted) | n/a | none |
| Assign media         | per-origin `−(size, count)`     | `+(totalMovedSize, count)`      | none |
| Remove media         | `−(size, count)`                | n/a (media unassigns, stays in site) | none |
| Upload (lifecycle)   | n/a                             | n/a                             | `+(size, 1)`                           |
| Delete attachment    | `−(size, 1)` on its folder chain | n/a                             | `−(size, 1)`                           |

Folder mutations leave the global Root counters invariant: media stays inside the site, only the sub-folder it lives in changes.

### Lifecycle bridge

`AttachmentLifecycleService` hooks `wp_generate_attachment_metadata` (filter, `'create'` context only — regenerations don't bump the counter) and `delete_attachment`. Both handlers queue work and register a `shutdown` action that flushes once at end of request, so a bulk upload of N files produces a single counter write per affected folder.

### Recalculate

`POST /foldsnap/v1/folders/recalculate` (admin only) runs a chunked, bottom-up rebuild of all `foldsnap_folder_size` and `foldsnap_folder_count` meta in case a third-party plugin (or a previous bug) leaves the values drifted. The recalculator walks leaf-first using `Database::getChildrenTotalsForFolders` so each parent's totals are summed from already-fixed children.

**First-boot auto-schedule.** `Bootstrap::onInit` checks the "counters initialized" option exposed by `CountersRecalculator`. While that flag is unset, every page load schedules a single WP-Cron event (de-duped if one is already pending). The cron handler runs one chunk and reschedules itself until the recalculator reports `done`, at which point the flag is set and the auto-schedule path becomes a no-op. The exact short delays for first-schedule and self-reschedule are tunable constants in `Bootstrap` and `CountersRecalculator`. The manual REST endpoint above (with `reset=true`) clears the flag so the same chunked path runs again on demand.

## Folder assignment

Each attachment can belong to **one** folder at a time:

- Assigning uses `wp_set_object_terms()` with `append=false`.
- Removing uses `wp_remove_object_terms()`.
- Assigning to Root (`folder_id = 0`) clears all folder relationships — the media surfaces as unassigned.
- Deleting a folder removes term relationships; orphaned media surfaces as unassigned. Direct subfolders are promoted to the deleted folder's parent.

After every assignment/removal the repository calls `wp_update_term_count_now()` on the affected folders so subsequent reads of `term_taxonomy.count` in the same request see fresh values (WordPress otherwise defers term recounts).

## Filtering the Media Library

`TaxonomyService::buildFolderTaxQuery(int $folderId): array` is the single source of truth for translating a folder selection into a `tax_query`:

- `folder_id = 0` (Root) → `[['taxonomy' => 'foldsnap_folder', 'operator' => 'NOT EXISTS']]` — matches attachments with no folder term.
- `folder_id > 0` → `[['taxonomy' => 'foldsnap_folder', 'field' => 'term_id', 'terms' => $folderId, 'include_children' => false]]` — only direct children, not descendant folders.

Three callers feed this builder:

- **Grid mode (AJAX).** `MediaLibraryController::filterAttachmentsByFolder` hooks `ajax_query_attachments_args`. The folder ID arrives in `$_REQUEST['query']['foldsnap_folder_id']`.
- **List mode.** `MediaLibraryController::filterListModeByFolder` hooks `pre_get_posts` and reads `foldsnap_folder_id` from `$_GET`. Only fires for the main attachment query in admin list mode.
- **REST.** `RestApiController::getMedia` reads `folder_id` from the request and builds a `WP_Query` with the same `tax_query`.
