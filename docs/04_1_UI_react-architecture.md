# React UI Architecture

## Mount point

`template/js/index.js` creates a `<div id="foldsnap-sidebar">` before `#wpbody-content` and mounts the React app with `createRoot`. It runs only on `upload.php`.

## Component hierarchy

```
FolderSidebar              (DndContext + All Media toggle)
└── FolderTree             (search box, root item, folder list)
    ├── FolderItem         (single folder node, recursive for children;
    │   │                   owns its rename modal locally — no separate component)
    │   └── FolderItem     (nested children)
    ├── SearchResultsList  (rendered instead of the tree while a query is active)
    └── CreateFolderModal  (lazy-loaded; receives a fixed parentId from the caller)
```

The visible media grid is the **native WordPress Backbone grid** (or list-table in list mode), not a React component. React only owns the sidebar; the grid is reflected to the current folder selection by `services/media-mode-bridge.js`.

`CreateFolderModal` takes a fixed `parentId` from its caller (the "Add subfolder" entry in `FolderItem`'s dropdown — including the Root item, which is the only entry point for creating a top-level folder) and shows that parent's name in its title. There is no in-modal parent picker.

`SearchResultsList` replaces the tree (not the whole sidebar) whenever `getSearchQuery()` is non-empty, so the search input in `FolderTree` stays mounted. Clicking a result selects the folder, calls `expandPathTo`, and clears the search so the tree comes back focused on the chosen folder.

## State management

The store is registered with `@wordpress/data` under the name `foldsnap/folders`.

### Lazy children-by-parent shape

The store does not hold a fully expanded tree. Children are loaded lazily, one parent at a time:

| Slice                | Shape                                  | Purpose                                            |
|----------------------|----------------------------------------|----------------------------------------------------|
| `foldersByParent`    | `{ [parentId]: Folder[] }`             | Children list per parent, in display order        |
| `foldersById`        | `{ [id]: Folder }`                     | Flat lookup for `getFolderById`                    |
| `loadedParents`      | `number[]`                             | Parents whose first page has been fetched          |
| `fetchingParents`    | `number[]`                             | Parents currently in flight (for de-duping)        |
| `parentsPagination`  | `{ [parentId]: { page, totalPages } }` | Per-parent pagination cursor for "load more"       |
| `expandedIds`        | `number[]`                             | Persisted via the preferences subsystem (server-synced, `localStorage`-cached) — see [UI Preferences](04_2_UI_preferences.md) |
| `selectedFolderId`   | `number\|null`                         |                                                    |
| `allMediaActive`     | `boolean`                              | Bypass mode: sidebar inert, grid unfiltered        |
| `searchQuery`        | `string`                               |                                                    |
| `searchResults`      | `{ folder, breadcrumb }[]`             | One entry per match                                |
| `searchPage`, `searchTotalPages`, `searchTotal`, `searchIsLoading` | various | Search pagination state |
| `error`              | `string\|null`                         |                                                    |

The full Root folder object (with its global counters) lives inside `foldersById[0]` like any other folder; there are no separate `rootMediaCount` / `rootTotalSize` slices.

### Generator-based actions

Async actions use generators with a custom `API_FETCH` control that delegates to `@wordpress/api-fetch`. Read actions:

- `fetchChildren(parentId, { page, perPage })` — first or refreshed page for one parent. De-duped against `fetchingParents`.
- `loadMoreChildren(parentId, { perPage })` — next page using the stored cursor; no-op past the last page.
- `fetchChildrenBatch(parentIds, { perPage })` — fetches first-and-subsequent pages for many parents, chunked at `BATCH_PARENTS_PER_REQUEST` (10) parents per request and paginated up to `BATCH_MAX_PER_PAGE` (200) per page; used by `expandPathTo`.
- `expandFolder(folderId)` — marks expanded and triggers `fetchChildren` if not already loaded.
- `expandPathTo(folderId)` — GETs `/folders/{id}/path`, dispatches `APPLY_PATH_TOTALS`, then `fetchChildrenBatch` for every ancestor so the tree is inflated with one fetch per level.
- `searchFolders(query, { perPage })` / `loadMoreSearchResults` / `clearSearch`.

Mutation actions hit the REST endpoint, then run `applyMutationEnvelope(response)` which dispatches:

- `UPSERT_FOLDER` — insert/replace `folder` in its parent slot.
- `APPLY_PATH_TOTALS` — for each chain in `paths`, merge `total_*` updates into `foldersById` and the parent slots.
- `APPLY_AFFECTED_PARENTS` — flip `has_children` on the parents in the list.

`createFolder` and `updateFolder` also refresh the affected parent slot(s) to pick up server-side ordering. `updateFolder` detects reparenting from the envelope and refreshes both the old and new parent slots in a single `fetchChildrenBatch([oldParentId, newParentId])` call; non-reparent edits refresh just the owning parent via `fetchChildren`.

After the refresh, both actions also adjust the visible tree state:

- `createFolder` dispatches `EXPAND_FOLDER` for the parent (when non-root) so the freshly created subfolder is immediately visible without an extra click.
- `updateFolder` on a reparent dispatches `EXPAND_FOLDER` for the new parent **and** re-selects the moved folder (`setSelectedFolder(id)`), so the user keeps focus on the row they just moved.

The rename action is owned by `FolderItem` itself: a `Rename` entry in its dropdown opens a local `Modal` containing a `TextControl` pre-filled with `folder.name`. Submit calls `updateFolder(id, { name: trimmed })`; the submit button is disabled when the trimmed value is blank or unchanged. The virtual Root folder hides the entry entirely.

`assignMedia` only patches the React store; the visible Backbone grid is refreshed independently by the dragdrop bridge via `window.foldsnap.refreshGrid()`.

`deleteFolder` dispatches `REMOVE_FOLDER` (drops it from `foldersById` and its parent slot, clears it from `expandedIds`), then merges the envelope's `root` and `affected_parents`.

### Selectors

Direct slice access (`getFolderById`, `getChildrenOf`, `getExpandedIds`, `isFolderLoaded`, `isFolderFetching`, `getParentPagination`), search selectors, plus `getRootFolder()` which returns `foldersById[0]`.

### Resolvers

`getRootFolders` is a `@wordpress/data` resolver: on first read it triggers `fetchChildren(ROOT_PARENT_ID)`, then re-fetches children for every persisted-expanded id so previously open branches refill themselves. Resolvers are matched to selectors by **function name** in `@wordpress/data`, so the resolver's identifier must equal the selector's identifier (`getRootFolders`). The expanded-branch refill happens in two places: this resolver on direct load, and `bootFromUrl` on deep-link boot.

### Persistence

`expandedIds` and `allMediaActive` are persisted through the **preferences subsystem** ([`template/js/preferences.js`](../template/js/preferences.js)), which keeps a server-side source of truth in user_meta and a `localStorage` cache for instant boot. Full design and REST contract: [UI Preferences](04_2_UI_preferences.md).

The store ([`template/js/store/index.js`](../template/js/store/index.js)) wires the bridge in three steps:

1. **Sync hydrate** at module load: `readCachedPreferences()` reads the cache and a single `HYDRATE` action seeds `expandedIds` + `allMediaActive` so the very first render reflects the user's last state.
2. **Async reconcile**: `loadPreferences()` fetches the server map; if it differs from the cache, a second `HYDRATE` is dispatched. The subscriber's "last seen" baseline is updated *before* this dispatch so the server-supplied values are not echoed back as a redundant PUT.
3. **Subscriber**: every store change is compared against the baseline and routed through `savePreference()` (write-through cache + per-key debounced PUT).

`localStorage` and REST failures degrade silently — the store stays usable; the cache absorbs the new value and either the next boot or the next online PUT reconciles.

`bootFromUrl` (called once from `index.js`) finishes the hydration: after selecting the URL folder and expanding its ancestor path, it walks every persisted-expanded id and fires `fetchChildrenBatch` for those whose children are not yet loaded. Without this, the chevron icon would render in its expanded state but the children list would stay empty until the user collapsed and re-expanded the row.

## Hooks

The `template/js/hooks/` directory holds reusable hooks consumed by multiple components.

- **`useDebouncedCallback(callback, delay)`** — returns a stable wrapper that resets a shared timer on every call and only fires `callback(...args)` once `delay` ms have passed without further calls. Pending timer is cleared on unmount. Used by `FolderTree` to debounce the sidebar search input (`SEARCH_DEBOUNCE_MS = 300`).

## Drag and drop

Two independent systems, by necessity:

### Folder reordering — `@dnd-kit`

`FolderSidebar` wraps the tree in a `DndContext` with `collisionDetection={ pointerWithin }`: this resolves the ambiguous case of a folder with no children, where the sortable rect and the droppable rect coincide. With `pointerWithin`, the cursor's exact position picks the explicit droppable id (`folder-drop-{id}`) over the sortable's numeric id, so the drop is routed to the reparent branch.

Each `FolderItem` registers `useDroppable` **before** `useSortable` for the same reason: when both rects coincide, the droppable wins. The drag handle uses `setActivatorNodeRef` from `useSortable` and is rendered as a separate `<button>` next to the row, so clicking the row body still selects the folder while only the handle initiates the drag.

On drag end, `FolderSidebar` calls `updateFolder` with the new `parent_id` (reparent) or `position` (sibling reorder).

### Media-to-folder assignment — jQuery UI

The native WordPress media grid renders Backbone-managed `<li class="attachment">` elements outside React, so a separate non-bundled script (`assets/js/foldsnap-dragdrop.js`) makes them draggable:

- jQuery UI `draggable` on each `.attachment`. The drag helper shows the count of selected items.
- jQuery UI `droppable` on each `.foldsnap-folder-item` (rendered by React, identified by `data-folder-id`).
- On drop, the bridge calls `wp.data.dispatch('foldsnap/folders').assignMedia(folderId, mediaIds)`.
- A `MutationObserver` reinitialises draggable/droppable when new attachments load (infinite scroll) or React re-renders the folder list.

## Media-mode bridge

`media-mode-bridge.js` is a thin orchestrator that subscribes to the store and dispatches each folder change to one of the focused side-effect modules in `services/`:

| Module | Responsibility |
|--------|---------------|
| `grid-reflector.js` | Writes `foldsnap_folder_id` on the live Backbone `Attachments` collection (AJAX refetch). Caches the collection across modal open/close. Polls until `wp.media.frame` exists. Installs `window.foldsnap.refreshGrid()` for cross-bundle calls from the dragdrop script. |
| `list-mode-redirector.js` | List mode: redirects to `upload.php?foldsnap_folder_id=ID`. |
| `view-switch-links.js` | Patches the `.view-switch` grid/list toggle hrefs so the current folder survives the mode change. |

When `allMediaActive` is on, the orchestrator passes `null` as the effective folder so the grid is unfiltered.

URL → store bootstrap is performed by the `bootFromUrl` action (dispatched once from `index.js` before the bridge runs): it reads `foldsnap_folder_id` from `window.location.search` and selects that folder, defaulting to root (id 0) when the parameter is absent so the grid is always filtered by some folder.

## Build pipeline

- **Toolchain** — `@wordpress/scripts` (webpack + Babel + ESLint + Jest).
- **Entries** —
  - `template/js/index.js` → `assets/js/foldsnap-admin.js` (sidebar bundle for the Media Library screen).
  - `template/js/settings.js` → `assets/js/foldsnap-settings.js` (Settings page; drives the Recount maintenance tool).
- **Standalone** — `template/js/foldsnap-dragdrop.js` is copied (not bundled) to `assets/js/` because it depends on jQuery UI globals, not the React bundle.
- **Asset manifests** — each entry emits its own `.asset.php` (`foldsnap-admin.asset.php`, `foldsnap-settings.asset.php`) listing WordPress script dependencies (auto-detected from imports) and a content hash for cache busting.
