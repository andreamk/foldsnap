# React UI Architecture

## Mount point

`template/js/index.js` creates a `<div id="foldsnap-sidebar">` before `#wpbody-content` and mounts the React app with `createRoot`. It runs only on `upload.php`.

## Component hierarchy

```
FolderSidebar              (DndContext + All Media toggle)
└── FolderTree             (search box, root item, folder list, "New Folder" button)
    ├── FolderItem         (single folder node, recursive for children)
    │   └── FolderItem     (nested children)
    ├── SearchResultsList  (rendered instead of the tree while a query is active)
    └── CreateFolderModal  (lazy-loaded; uses FolderPicker for parent selection)
        └── FolderPicker   (mini-tree + search, also reusable elsewhere)
```

The visible media grid is the **native WordPress Backbone grid** (or list-table in list mode), not a React component. React only owns the sidebar; the grid is reflected to the current folder selection by `services/media-mode-bridge.js`.

`FolderPicker` is a self-contained mini-tree that reads the same `foldsnap/folders` store: it lists root folders, allows expanding any branch, and exposes its own debounced search. It is rendered inside `CreateFolderModal` to pick a parent and is reusable for any future "pick a folder" flow.

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
| `expandedIds`        | `number[]`                             | Persisted across reloads via `localStorage`        |
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
- `fetchChildrenBatch(parentIds, { perPage })` — first page for many parents in one round-trip; used by `expandPathTo`.
- `expandFolder(folderId)` — marks expanded and triggers `fetchChildren` if not already loaded.
- `expandPathTo(folderId)` — GETs `/folders/{id}/path`, dispatches `APPLY_PATH_TOTALS`, then `fetchChildrenBatch` for every ancestor so the tree is inflated with one fetch per level.
- `searchFolders(query, { perPage })` / `loadMoreSearchResults` / `clearSearch`.

Mutation actions hit the REST endpoint, then run `applyMutationEnvelope(response)` which dispatches:

- `UPSERT_FOLDER` — insert/replace `folder` in its parent slot.
- `APPLY_PATH_TOTALS` — for each chain in `paths`, merge `total_*` updates into `foldersById` and the parent slots.
- `APPLY_AFFECTED_PARENTS` — flip `has_children` on the parents in the list.

`createFolder` and `updateFolder` also refresh the affected parent slot(s) via `fetchChildren` to pick up server-side ordering. `updateFolder` detects reparenting from the envelope and refreshes both the old and new parent slots.

`assignMedia` only patches the React store; the visible Backbone grid is refreshed independently by the dragdrop bridge via `window.foldsnap.refreshGrid()`.

`deleteFolder` dispatches `REMOVE_FOLDER` (drops it from `foldersById` and its parent slot, clears it from `expandedIds`), then merges the envelope's `root` and `affected_parents`.

### Selectors

Direct slice access (`getFolderById`, `getChildrenOf`, `getExpandedIds`, `isFolderLoaded`, `isFolderFetching`, `getParentPagination`), search selectors, plus `getRootFolder()` which returns `foldersById[0]`.

### Resolvers

`getChildrenOf(0)` is wired to a resolver that triggers `fetchChildren(0)` on first read, so the root's children load automatically without a manual call.

### Persistence

Two independent slices survive page reloads via `localStorage` (see [`template/js/store/persistence.js`](../template/js/store/persistence.js)):

- `expandedIds` — written under `foldsnap_expanded_ids` whenever the set changes.
- `allMediaActive` — written under a separate key (only when `true`; cleared when toggled off).

On store init, both keys are read and dispatched as a single `HYDRATE` action that merges them into the initial state. `localStorage` failures (unavailable, quota exceeded) degrade silently — the store stays usable, just non-persistent for that session.

## Hooks

The `template/js/hooks/` directory holds reusable hooks consumed by multiple components.

- **`useDebouncedCallback(callback, delay)`** — returns a stable wrapper that resets a shared timer on every call and only fires `callback(...args)` once `delay` ms have passed without further calls. Pending timer is cleared on unmount. Used by `FolderTree` and indirectly by `useLocalFolderSearch` (`SEARCH_DEBOUNCE_MS = 300`).
- **`useLocalFolderSearch({ perPage })`** — self-contained folder search isolated from the global store search slice. Returns `{ inputValue, activeQuery, results, isLoading, setInput }`. Discards out-of-order responses via a ref. Used by `FolderPicker`; reusable for any future "pick a folder" flow that must not overwrite the sidebar's active query.

## Drag and drop

Two independent systems, by necessity:

### Folder reordering — `@dnd-kit`

`FolderSidebar` wraps the tree in a `DndContext`. Each `FolderItem` uses `useSortable` for sibling reorder and `useDroppable` for reparenting. On drag end, `FolderSidebar` calls `updateFolder` with the new `parent_id` or `position`.

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
- **Entry** — `template/js/index.js` → `assets/js/foldsnap-admin.js`.
- **Standalone** — `template/js/foldsnap-dragdrop.js` is copied (not bundled) to `assets/js/` because it depends on jQuery UI globals, not the React bundle.
- **Asset manifest** — `foldsnap-admin.asset.php` lists WordPress script dependencies (auto-detected from imports) and a content hash for cache busting.
