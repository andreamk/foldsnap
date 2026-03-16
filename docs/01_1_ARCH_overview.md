# System Architecture

FoldSnap adds folder management to the WordPress admin Media Library. It uses a custom taxonomy for storage, a REST API for operations, and a React sidebar injected into the native `upload.php` screen.

## Layers

```
┌─────────────────────────────────────────────────┐
│  React Sidebar (FolderSidebar)                  │
│  ┌──────────┐  ┌──────────┐  ┌───────────────┐ │
│  │FolderTree│  │MediaGrid │  │media-mode-    │ │
│  │          │  │          │  │bridge         │ │
│  └────┬─────┘  └────┬─────┘  └───────┬───────┘ │
│       │              │                │         │
│  @wordpress/data store (foldsnap/folders)       │
│       │              │                │         │
│  REST API (apiFetch)  │    Backbone grid sync   │
└───────┼──────────────┼────────────────┼─────────┘
        │              │                │
┌───────┼──────────────┼────────────────┼─────────┐
│  PHP  │              │                │         │
│  RestApiController   │   MediaLibraryController │
│       │              │                │         │
│  FolderRepository + TaxonomyService             │
│       │                                         │
│  WordPress DB (wp_terms, wp_term_taxonomy,      │
│                wp_term_relationships)           │
└─────────────────────────────────────────────────┘
```

**Frontend** — React sidebar mounted before `#wpbody-content`. Uses `@wordpress/data` for state management and `@wordpress/api-fetch` for REST communication. A bridge module synchronizes folder selection with the native WordPress media grid (Backbone) or list table.

**Backend** — Two controllers: `RestApiController` handles folder CRUD and media queries via REST endpoints; `MediaLibraryController` enqueues assets and filters the native media grid/list by folder. Both delegate to `FolderRepository` for data access.

**Storage** — Folders are terms in a custom hierarchical taxonomy (`foldsnap_folder`) attached to the `attachment` post type. Folder metadata (color, position) is stored as term meta.

## Request Flow

### Folder Operations (React → REST API → DB)

1. User action in React dispatches a store action (e.g., `createFolder`)
2. Store control yields an `API_FETCH` to the REST endpoint
3. `RestApiController` validates input, delegates to `FolderRepository`
4. Repository performs the taxonomy operation and returns a `FolderModel`
5. Controller returns a JSON response; store updates state
6. After mutations, the store refetches the full folder tree to stay consistent

### Media Filtering (Folder Selection → Grid Update)

**Grid mode:** The media-mode-bridge subscribes to store changes and sets `foldsnap_folder_id` on the Backbone `Attachments` collection, triggering an AJAX refetch. `MediaLibraryController::filterAttachmentsByFolder` reads this parameter via the `ajax_query_attachments_args` filter and adds a `tax_query`.

**List mode:** The bridge redirects to `upload.php?foldsnap_folder_id=ID`. `MediaLibraryController::filterListModeByFolder` reads the parameter via `pre_get_posts` and modifies the main query.

## Key Design Decisions

- **Taxonomy-based storage** — Leverages WordPress's existing term infrastructure (hierarchies, counts, relationships) instead of custom tables.
- **Full tree refetch after mutations** — Simpler than optimistic client-side merging. The folder tree is small (tens of terms), and folder sizes are cached server-side.
- **Dual drag-and-drop systems** — `@dnd-kit` handles folder reordering within React; jQuery UI handles dragging native grid attachments onto folder items (the native grid is Backbone, not React).
- **Singleton controllers** — Both controllers use the singleton pattern with hook registration in the constructor, ensuring hooks are registered exactly once.
