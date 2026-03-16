# Folder Data Model and Filtering

## Storage

Folders are stored as terms in a custom hierarchical taxonomy called `foldsnap_folder`, registered on the `attachment` post type. This leverages WordPress's built-in term infrastructure for hierarchies, counts, and relationships.

### Taxonomy Term

Each folder corresponds to one row in `wp_terms` + `wp_term_taxonomy`. The taxonomy is hierarchical, so `parent` in `wp_term_taxonomy` represents the folder tree structure.

### Term Meta

| Meta key                    | Type   | Description                   |
|-----------------------------|--------|-------------------------------|
| `foldsnap_folder_color`     | string | Hex color (e.g., `#ff0000`)   |
| `foldsnap_folder_position`  | string | Sort position (stored as string, cast to int) |

### FolderModel

`FolderModel` is a DTO built from a `WP_Term`. It captures the term data plus computed properties:

- **Direct properties** — id, name, slug, parentId, mediaCount, color, position
- **Injected at tree build time** — `directSize` (bytes of media directly in this folder)
- **Recursive getters** — `getTotalMediaCount()` and `getTotalSize()` walk the children tree

The model is constructed by `FolderRepository::getTree()`, which fetches all terms, computes folder sizes, injects them, and assembles the parent-child tree.

## Folder Assignment

Each media item (attachment) can belong to **one folder** at a time. Assignment is a standard WordPress term relationship (`wp_term_relationships`).

- **Assigning** uses `wp_set_object_terms()` with `append=false`, which replaces any existing folder assignment.
- **Removing** uses `wp_remove_object_terms()`.
- **Deleting a folder** — WordPress automatically removes term relationships, so media items return to "unassigned" (root).

## Filtering

Three mechanisms filter media by folder, covering all Media Library contexts:

### Grid Mode (AJAX)

The native WordPress media grid uses Backbone and loads attachments via the `query-attachments` AJAX endpoint. `MediaLibraryController::filterAttachmentsByFolder` hooks into the `ajax_query_attachments_args` filter.

The React store's media-mode-bridge sets `foldsnap_folder_id` on the Backbone collection's props, which arrive as `$_REQUEST['query']['foldsnap_folder_id']` in PHP.

### List Mode (Server-Side)

In list mode, `upload.php` renders a standard `WP_Posts_List_Table` using `WP_Query`. `MediaLibraryController::filterListModeByFolder` hooks into `pre_get_posts` and reads `foldsnap_folder_id` from the URL.

When the user clicks a folder in list mode, the JS bridge redirects to `upload.php?mode=list&foldsnap_folder_id=ID`.

### REST API

`RestApiController::getMedia` accepts a `folder_id` parameter and builds a `WP_Query` with the appropriate `tax_query`.

### Tax Query Construction

All three paths use `TaxonomyService::buildFolderTaxQuery()` as the single source of truth:

- **folder_id = 0** (root/unassigned) — `NOT EXISTS` operator: matches attachments with no term in `foldsnap_folder`
- **folder_id > 0** (specific folder) — Matches by `term_id` with `include_children => false`, so only direct children of a folder are shown, not media in subfolders

## Caching

Folder size computations involve joining `wp_term_relationships`, `wp_term_taxonomy`, and `wp_postmeta`. These are cached using WordPress object cache (`wp_cache_get/set`) under the `foldsnap` group:

| Cache key            | Contents                                    |
|----------------------|---------------------------------------------|
| `folder_sizes`       | Map of folder term ID → total bytes         |
| `root_total_size`    | Bytes of unassigned media                   |
| `root_media_count`   | Count of unassigned media                   |

All three keys are invalidated by `FolderRepository::invalidateSizeCache()`, called after any assignment, removal, or deletion.
