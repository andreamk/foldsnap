# System Architecture

FoldSnap adds folder management to the WordPress admin Media Library. Folders are stored as terms in a custom hierarchical taxonomy with pre-aggregated counters maintained incrementally. A REST API exposes folder discovery and mutations; a React sidebar consumes the API and bridges into the native `upload.php` screen.

## Layers

```
┌─────────────────────────────────────────────────┐
│  React Sidebar (FolderSidebar)                  │
│  ┌──────────┐                  ┌───────────────┐│
│  │FolderTree│                  │media-mode-    ││
│  │          │                  │bridge         ││
│  └────┬─────┘                  └───────┬───────┘│
│       │                                 │        │
│  @wordpress/data store (foldsnap/folders)       │
│       │                                 │        │
│  REST API (apiFetch)        Backbone grid sync  │
└───────┼─────────────────────────────────┼────────┘
        │              │                │
┌───────┼──────────────┼────────────────┼─────────┐
│  PHP  │              │                │         │
│  RestApiController + Mutations  MediaLibraryCtl │
│       │                                         │
│  FolderRepository    AttachmentLifecycleService │
│       │                       │                 │
│       ├── FolderNameSanitizer │                 │
│       ├── FolderTreeNavigator │                 │
│       ├── MediaFolderAssignmentService          │
│       └── FolderCounterService ◄────────────────┤
│                       │                         │
│       FolderModel     │                         │
│                       │                         │
│  CountersRecalculator │  (WP-Cron, first boot)  │
│       │               │                         │
│  Database (raw $wpdb queries) + TaxonomyService │
│       │                                         │
│  WP DB (wp_terms, wp_term_taxonomy,             │
│          wp_term_relationships, wp_termmeta,    │
│          wp_options for Root counters + recalc) │
└─────────────────────────────────────────────────┘
```

**WP-Cron bootstrap.** On every admin request `Bootstrap` checks the
`foldsnap_opt_counters_initialized` option. If unset, it schedules a
`foldsnap_recalc_chunk` event 5 seconds out; the cron callback runs one
chunk via `CountersRecalculator::processChunk()` and self-reschedules
30 seconds later until the bottom-up walk drains. This is the only
automatic entry point for the recalculate; the REST endpoint is for
manual recovery.

**Frontend.** React sidebar mounted before `#wpbody-content`. Uses `@wordpress/data` for state management (lazy children-by-parent map, see [React UI](04_1_UI_react-architecture.md)) and `@wordpress/api-fetch` for REST. A bridge module synchronises folder selection with the native Backbone media grid in grid mode, or with `upload.php` URL parameters in list mode.

**Backend.**

- `RestApiController` registers all `foldsnap/v1` routes and serves the read endpoints (children fetch, search, path, media listing, recalculate).
- `RestApiFolderMutationsController` serves the write endpoints (create / update / delete / assign / remove media). Every write returns the same [mutation envelope](02_1_API_rest-endpoints.md#mutation-envelope).
- `MediaLibraryController` enqueues assets and filters the native grid/list by folder via the WordPress hooks.
- `MainPageController` registers the **FoldSnap** sub-menu under Media (capability `manage_options`) and renders the Settings page. Enqueues the `foldsnap-settings` script bundle (whose only feature today is the **Recount folders** maintenance tool, which drives the existing `POST /folders/recalculate` endpoint until completion).
- `FolderRepository` is the focused CRUD surface for the taxonomy: lookup, create, update, delete. It composes the helpers below for everything else.
- `FolderNameSanitizer` validates and uniquifies folder names. Stateless so the rules are testable without touching the taxonomy.
- `FolderTreeNavigator` walks the parent chain (instance: path resolution to `FolderModel[]`; static: ancestor-id list for delta math). Read-only.
- `MediaFolderAssignmentService` owns every code path that changes which folder an attachment lives in (assign, remove, "assign to Root"), keeping ancestor-chain counters coherent via `FolderCounterService`.
- `FolderCounterService` is the single write path for counter math: applies signed `(size, count)` deltas to ancestor chains, owns the two `wp_options` rows backing the virtual Root totals, and invalidates the wp_cache entries that memoize Root unassigned counters.
- `CountersRecalculator` rebuilds drifted counters via a bottom-up chunked walk. Used on first boot (WP-Cron) and as a manual recovery tool (REST `POST /folders/recalculate`).
- `Database` holds the raw `$wpdb` queries that have no high-level WordPress equivalent (children counts, descendant BFS, bulk meta UPDATE, file-size lookups, etc.).
- `AttachmentLifecycleService` hooks the WordPress upload and delete lifecycle, queuing counter updates to flush at `shutdown` so bulk uploads collapse to a single round-trip.

**Storage.** See [Folder system](03_1_MEDIA_folder-system.md) for the term-meta schema, the virtual Root convention (`id = 0`, `parent_id = -1`), and the incremental counter contract.

## Request flows

### Folder mutation (React → REST → DB)

1. User action dispatches a store action (e.g. `createFolder`).
2. The store yields an `API_FETCH` control to the REST endpoint.
3. `RestApiFolderMutationsController` parses input and delegates to `FolderRepository`.
4. The repository performs the WordPress taxonomy operation, applies signed `(size, count)` deltas to every affected ancestor chain via `Database::bulkAdjustTermMeta`, and returns the affected `FolderModel`.
5. The controller builds the mutation envelope (folder, paths, affected parents, refreshed Root) and returns it as JSON.
6. The store's `applyMutationEnvelope` patches the cached tree in place — no full refetch — and refreshes the parent slot(s) for any folder whose siblings changed order.

### Tree expansion (lazy fetch)

1. User clicks a folder chevron. The store dispatches `expandFolder`.
2. If that parent's children are not yet loaded, the store calls `fetchChildren(parentId)`, which hits `GET /folders?parent_ids[]=...` and stores the page in `foldersByParent[parentId]`.
3. Subsequent reads come from the cache; "load more" uses `parentsPagination[parentId]` to fetch the next page.
4. Deep-linking to a nested folder uses `expandPathTo`, which hits `GET /folders/{id}/path` and then a single batched `GET /folders?parent_ids[]=...` for every ancestor.

### Media filtering (selection → grid update)

- **Grid mode.** The bridge writes `foldsnap_folder_id` onto the Backbone collection's props. `MediaLibraryController::filterAttachmentsByFolder` reads it from `$_REQUEST['query']` via `ajax_query_attachments_args` and adds a `tax_query`.
- **List mode.** The bridge redirects to `upload.php?foldsnap_folder_id=ID`. `MediaLibraryController::filterListModeByFolder` reads it from `$_GET` via `pre_get_posts` and modifies the main query.
- **REST.** `RestApiController::getMedia` accepts `folder_id` and builds its own `WP_Query`.

All three paths share `TaxonomyService::buildFolderTaxQuery()` as the single source of truth for the `tax_query` shape.

### Attachment lifecycle (incremental Root counters)

1. New uploads — `wp_generate_attachment_metadata` (filter, `'create'` context only) queues a `(size, +1)` addition.
2. Deletions — `delete_attachment` reads the term assignment + filesize before WordPress strips them, then queues a deletion entry.
3. At `shutdown`, the service flushes both queues: additions hit the Root option counters; deletions adjust the folder's ancestor chain and decrement the Root options.

The deferred flush means a bulk upload of N files produces a single counter write, regardless of N.

## Key design decisions

- **Taxonomy-based storage.** Folders are real WordPress terms, leveraging the existing hierarchy, count, and relationship infrastructure rather than custom tables.
- **Pre-aggregated recursive counters.** `total_media_count` and `total_size` are stored on each folder as term meta and maintained incrementally on every mutation. Reads are a single `get_term_meta`; recursive aggregation on read is never needed.
- **Virtual Root.** `id = 0` is a synthetic folder with no backing term. Its counters live in two `wp_options` keys (`foldsnap_opt_root_size`, `foldsnap_opt_root_count`) representing the global site totals.
- **Incremental, never on-demand.** Folder mutations apply signed deltas to the affected ancestor chain in one bulk `UPDATE`. Lifecycle hooks defer to `shutdown` so bulk uploads collapse to a single round-trip. A chunked `recalculate` admin endpoint exists to rebuild drifted values.
- **Mutation envelope.** Every write endpoint returns `{ folder, paths, affected_parents, root }` so the client patches its cache without a full tree refetch.
- **Lazy children-by-parent in the store.** The React store does not hold a pre-expanded tree; it loads children one parent at a time and caches them under `foldersByParent[parentId]`.
- **Dual drag-and-drop.** `@dnd-kit` handles folder reordering inside React; jQuery UI handles dragging native (Backbone) grid attachments onto folder items rendered by React.
- **Singleton controllers.** `RestApiController` and `MediaLibraryController` use the singleton pattern with hook registration in the constructor, so hooks are registered exactly once per request.
