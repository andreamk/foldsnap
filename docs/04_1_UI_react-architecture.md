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
    ├── CreateFolderModal  (lazy-loaded; uses FolderPicker for parent selection)
    │   └── FolderPicker   (mini-tree + search, also reusable elsewhere)
    └── MediaGrid          (paginated media grid for the selected folder)
        └── MediaItem      (single thumbnail, draggable via jQuery UI)
```

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
| `media`              | `MediaItem[]`                          | Current page of media for the selected folder      |
| `mediaTotal`, `mediaTotalPages`, `mediaIsLoading`                  | various | Media list state |
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
- `fetchMedia(folderId, page, perPage)`.

Mutation actions hit the REST endpoint, then run `applyMutationEnvelope(response)` which dispatches:

- `UPSERT_FOLDER` — insert/replace `folder` in its parent slot.
- `APPLY_PATH_TOTALS` — for each chain in `paths`, merge `total_*` updates into `foldersById` and the parent slots.
- `APPLY_AFFECTED_PARENTS` — flip `has_children` on the parents in the list.

`createFolder`, `updateFolder`, and the media actions also refresh the affected parent slot(s) via `fetchChildren` to pick up server-side ordering. `updateFolder` detects reparenting from the envelope and refreshes both the old and new parent slots.

`deleteFolder` dispatches `REMOVE_FOLDER` (drops it from `foldersById` and its parent slot, clears it from `expandedIds`), then merges the envelope's `root` and `affected_parents`.

### Selectors

Direct slice access (`getFolderById`, `getChildrenOf`, `getExpandedIds`, `isFolderLoaded`, `isFolderFetching`, `getParentPagination`), search and media selectors, plus `getRootFolder()` which returns `foldersById[0]`.

### Resolvers

`getChildrenOf(0)` is wired to a resolver that triggers `fetchChildren(0)` on first read, so the root's children load automatically without a manual call.

### Persistence

Two independent slices survive page reloads via `localStorage` (see [`template/js/store/persistence.js`](../template/js/store/persistence.js)):

- `expandedIds` — written under `foldsnap_expanded_ids` whenever the set changes.
- `allMediaActive` — written under a separate key (only when `true`; cleared when toggled off).

On store init, both keys are read and dispatched as a single `HYDRATE` action that merges them into the initial state. `localStorage` failures (unavailable, quota exceeded) degrade silently — the store stays usable, just non-persistent for that session.

## Hooks

The `template/js/hooks/` directory holds reusable hooks consumed by multiple components.

- **`useDebouncedCallback(callback, delay)`** — returns a stable wrapper that resets a shared timer on every call and only fires `callback(...args)` once `delay` ms have passed without further calls. Pending timer is cleared on unmount. Used by `FolderTree` and `FolderPicker` for the search input (`SEARCH_DEBOUNCE_MS = 300`).

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

`media-mode-bridge.js` synchronises the React folder selection with the native WordPress media display:

- **Grid mode** — sets/unsets `foldsnap_folder_id` on the Backbone `Attachments` collection, triggering an AJAX refetch.
- **List mode** — redirects to `upload.php?foldsnap_folder_id=ID`.
- **Mode toggle links** — patches the grid/list switch URLs so the current folder survives the mode change.
- **URL persistence** — reads `foldsnap_folder_id` on load and pre-selects the folder in the store.
- **All Media bypass** — when `allMediaActive` is on, the bridge clears the folder filter so the native grid shows every attachment.

## Build pipeline

- **Toolchain** — `@wordpress/scripts` (webpack + Babel + ESLint + Jest).
- **Entry** — `template/js/index.js` → `assets/js/foldsnap-admin.js`.
- **Standalone** — `template/js/foldsnap-dragdrop.js` is copied (not bundled) to `assets/js/` because it depends on jQuery UI globals, not the React bundle.
- **Asset manifest** — `foldsnap-admin.asset.php` lists WordPress script dependencies (auto-detected from imports) and a content hash for cache busting.
