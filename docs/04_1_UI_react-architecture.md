# React UI Architecture

## Mount Point

The JS entry point (`template/js/index.js`) creates a `<div id="foldsnap-sidebar">` before `#wpbody-content` in the WordPress admin DOM, then mounts the React app using `createRoot`. This runs only on the Media Library screen (`upload.php`).

## Component Hierarchy

```
FolderSidebar          (DndContext provider for folder reordering)
└── FolderTree         (search, root item, folder list, "New Folder" button)
    ├── FolderItem     (single folder node, recursive for children)
    │   └── FolderItem (nested children)
    ├── CreateFolderModal (modal for new folder creation)
    └── MediaGrid      (paginated media grid for selected folder)
        └── MediaItem  (single media thumbnail, draggable)
```

## State Management

Uses `@wordpress/data` (Redux-based) with store name `foldsnap/folders`.

### State Shape

| Key               | Type         | Description                              |
|-------------------|--------------|------------------------------------------|
| `folders`         | array        | Full folder tree from REST API           |
| `selectedFolderId`| number\|null | Currently selected folder (null = root)  |
| `isLoading`       | boolean      | Folder tree loading state                |
| `error`           | string\|null | Last error message                       |
| `rootMediaCount`  | number       | Unassigned media count                   |
| `rootTotalSize`   | number       | Unassigned media total bytes             |
| `searchQuery`     | string       | Folder search filter text                |
| `media`           | array        | Current page of media items              |
| `mediaIsLoading`  | boolean      | Media loading state                      |
| `mediaTotal`      | number       | Total media items for current folder     |
| `mediaTotalPages` | number       | Total pages for current query            |

### Actions (Generator-Based)

Async actions use generator functions with a custom `API_FETCH` control that delegates to `@wordpress/api-fetch`:

- `fetchFolders` — GET /folders, updates tree + root counts
- `createFolder`, `updateFolder`, `deleteFolder` — mutate then refetch full tree
- `assignMedia`, `removeMedia` — mutate then refetch tree + media list
- `fetchMedia` — GET /media with folder_id and pagination
- `setSelectedFolder`, `setSearchQuery` — synchronous state updates

### Selectors

- `getFolders`, `getSelectedFolderId`, `isLoading`, `getError` — direct state access
- `getFilteredFolders` — filters tree by search query, preserving ancestor chains
- `getFolderById` — recursive tree search
- `getMedia`, `isMediaLoading`, `getMediaTotal`, `getMediaTotalPages` — media state

### Resolver

`getFolders` has an auto-resolver: the first time any component calls `store.getFolders()`, it triggers `fetchFolders()` automatically.

## Drag and Drop

Two separate drag-and-drop systems handle different use cases:

### Folder Reordering (@dnd-kit)

`FolderSidebar` wraps the tree in a `DndContext`. Each `FolderItem` uses `useSortable` (for reordering among siblings) and `useDroppable` (for reparenting — dropping a folder onto another folder's drop zone).

On drag end, `FolderSidebar` calls `updateFolder` with the new `parentId` or `position`.

### Media-to-Folder Assignment (jQuery UI)

The native WordPress media grid renders Backbone-managed `<li class="attachment">` elements. Since these are outside React, a separate jQuery UI bridge (`foldsnap-dragdrop.js`) makes them draggable.

- **Draggable:** Each `.attachment` element gets jQuery UI `draggable`. The drag helper shows the count of selected items.
- **Droppable:** Each `.foldsnap-folder-item` (rendered by React, identified by `data-folder-id`) gets jQuery UI `droppable`.
- **On drop:** The bridge calls `wp.data.dispatch('foldsnap/folders').assignMedia(folderId, mediaIds)`.
- **MutationObserver** watches the DOM for new attachment elements (infinite scroll) and new folder items (React re-renders) to reinitialize draggable/droppable.

## Media Mode Bridge

`media-mode-bridge.js` synchronizes folder selection between the React store and the native WordPress media display:

- **Grid mode:** Sets/unsets `foldsnap_folder_id` on the Backbone `Attachments` collection, triggering a native AJAX refetch.
- **List mode:** Redirects to `upload.php?foldsnap_folder_id=ID`.
- **Mode toggle links:** Updates the grid/list switch links to preserve the current folder across mode changes.
- **URL persistence:** Reads `foldsnap_folder_id` from the URL on load and pre-selects the folder in the store.

## Build Pipeline

- **Toolchain:** `@wordpress/scripts` (webpack + Babel + ESLint + Jest)
- **Entry:** `template/js/index.js` → `assets/js/foldsnap-admin.js`
- **Standalone:** `template/js/foldsnap-dragdrop.js` is copied (not bundled) to `assets/js/` because it depends on jQuery UI globals, not the React bundle
- **Asset manifest:** `foldsnap-admin.asset.php` lists WordPress script dependencies (auto-detected from imports) and a content hash for cache busting
