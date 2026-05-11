# REST API Endpoints

All endpoints live under the `foldsnap/v1` namespace. Folder discovery, navigation, mutations, and media listing share two recurring shapes:

- **Folder object** — the wire form of `FolderModel::toArray()`. Every endpoint that returns folders returns objects of this shape.
- **Mutation envelope** — every write endpoint (create / update / delete / assignMedia / removeMedia) returns the same envelope so clients can patch their cached tree without refetching.

Read endpoints require the `upload_files` capability. The `recalculate` admin endpoint requires `manage_options`.

## Shared shapes

### Folder object

```json
{
  "id": 42,
  "name": "Photos",
  "slug": "photos",
  "parent_id": 0,
  "media_count": 5,
  "total_media_count": 12,
  "total_size": 25600,
  "color": "#ff0000",
  "position": 0,
  "has_children": true,
  "is_root": false
}
```

| Field               | Notes                                                                              |
|---------------------|------------------------------------------------------------------------------------|
| `parent_id`         | `-1` for the synthetic Root, `0` for top-level folders, `> 0` otherwise            |
| `media_count`       | Direct attachments only (`wp_term_taxonomy.count`)                                 |
| `total_media_count` | Recursive: this folder plus every descendant. Stored as term meta, kept in sync incrementally. For Root, this is the global site count. |
| `total_size`        | Recursive bytes, same semantics as `total_media_count`                             |
| `has_children`      | True if the folder has at least one direct sub-folder                              |
| `is_root`           | True only for the synthetic Root model                                             |

### Mutation envelope

```json
{
  "folder": { "...folder object...": "" },
  "paths": [
    [ { "...folder object...": "" }, "..." ]
  ],
  "affected_parents": [
    { "id": 7, "has_children": true }
  ],
  "root": { "...folder object...": "" }
}
```

- `folder` — the created/updated/touched folder.
- `paths` — list of ancestor chains whose `total_*` may have changed. The first chain is for `folder`; additional chains are appended when other folders are also affected (e.g. `assignMedia` returns one chain per origin folder, and `updateFolder` on a reparent returns a second chain for the previous parent). Each chain runs root-first, from Root down to the target folder. The client merges per-chain totals into its cache.
- `affected_parents` — parents whose `has_children` flag may have flipped (after delete or reparent). Root (`id = 0`) is omitted because its chevron is implicit.
- `root` — the refreshed Root folder so the client can update the global counters in the same trip.

`assignMedia` and `removeMedia` envelopes are wrapped in an extra `assigned: true` / `removed: true` flag.

## Folder endpoints

### `GET /folders`

Two mutually exclusive modes, picked from query parameters:

#### Children fetch (default)

| Parameter      | Type    | Default | Notes                                              |
|----------------|---------|---------|----------------------------------------------------|
| `parent_ids[]` | int[]   | `[0]`   | Repeated query param, or comma-separated string    |
| `page`         | int     | `1`     |                                                    |
| `per_page`     | int     | `100`   | Clamped to `1..200`                                |

```json
{
  "mode": "children",
  "folders": [ "...folder objects across all requested parents..." ],
  "requested_parent_ids": [ 0, 42 ],
  "total": 18,
  "total_pages": 1,
  "root": { "...folder object..." }
}
```

`folders` is a flat list — each entry carries its `parent_id`, so the client can group by parent. Sort order matches the repository (`position` ASC, then name).

Response also sets `X-WP-Total` and `X-WP-TotalPages` headers.

#### Search

| Parameter   | Type   | Default | Notes                                       |
|-------------|--------|---------|---------------------------------------------|
| `search`    | string | —       | When non-empty, switches to search mode     |
| `page`      | int    | `1`     |                                             |
| `per_page`  | int    | `50`    | Clamped to `1..100`                         |

```json
{
  "mode": "search",
  "query": "photos",
  "results": [
    {
      "folder": { "...folder object..." },
      "breadcrumb": [
        { "id": 0, "name": "Root", "is_root": true },
        { "id": 7, "name": "2025", "is_root": false }
      ]
    }
  ],
  "total": 23,
  "total_pages": 1
}
```

`breadcrumb` is the ancestor chain (Root first, target excluded), trimmed for tooltip display.

### `POST /folders`

| Parameter   | Type   | Required | Default | Notes                                |
|-------------|--------|----------|---------|--------------------------------------|
| `name`      | string | yes      | —       | Sanitized; max 200 chars             |
| `parent_id` | int    | no       | `0`     | `0` = top-level                      |
| `color`     | string | no       | `''`    | Hex code                             |
| `position`  | int    | no       | `0`     |                                      |

Duplicate names under the same parent are auto-suffixed (`Photos`, `Photos (2)`, `Photos (3)`...).

Returns `201` with a [mutation envelope](#mutation-envelope).

### `PUT /folders/{id}`

Updates a folder. Omitted fields are left unchanged.

| Parameter   | Type   | Required | Notes                                                       |
|-------------|--------|----------|-------------------------------------------------------------|
| `name`      | string | no       |                                                             |
| `parent_id` | int    | no       | Reparent target (`0` = top-level). Omit to leave unchanged. |
| `color`     | string | no       |                                                             |
| `position`  | int    | no       |                                                             |

Mutating Root (`id = 0`) is rejected with `invalid_argument`.

Returns `200` with a [mutation envelope](#mutation-envelope). When the folder is reparented, `paths` includes both the old and the new ancestor chain so the client can apply both deltas.

### `DELETE /folders/{id}`

Deletes the folder. Direct media items return to Root; direct sub-folders are promoted to the deleted folder's parent.

```json
{
  "deleted": true,
  "id": 42,
  "affected_parents": [ { "id": 7, "has_children": false } ],
  "root": { "...folder object..." }
}
```

### `GET /folders/{id}/path`

Returns the ancestor chain from Root to the target, inclusive.

```json
{
  "path": [
    { "...Root folder..." },
    { "...ancestor..." },
    { "...target..." }
  ]
}
```

Returns `{ "path": [] }` if the target does not exist.

## Media assignment

### `POST /folders/{id}/media`

Assigns each media item to the folder, replacing any existing assignment.

| Parameter   | Type  | Required | Notes                  |
|-------------|-------|----------|------------------------|
| `media_ids` | int[] | yes      | Non-empty              |

Assigning to Root (`id = 0`) is the same as unassigning from any folder.

Returns `200` with `{ "assigned": true, ...mutation envelope }`. The envelope's `paths` includes one chain per origin folder so the client can apply the negative deltas to the folders the media just left.

### `DELETE /folders/{id}/media`

Removes each media item from the folder. Media that was not in the folder is silently skipped.

Returns `200` with `{ "removed": true, ...mutation envelope }`.

## Media query

### `GET /media`

Returns paginated attachments for a folder.

| Parameter   | Type | Required | Default | Notes                                |
|-------------|------|----------|---------|--------------------------------------|
| `folder_id` | int  | yes      | —       | `0` = unassigned/Root                |
| `page`      | int  | no       | `1`     |                                      |
| `per_page`  | int  | no       | `40`    | Clamped to `1..100`                  |

```json
{
  "media": [
    {
      "id": 42,
      "title": "Beach Photo",
      "filename": "beach.jpg",
      "thumbnail_url": "https://...",
      "url": "https://...",
      "file_size": 1024000,
      "mime_type": "image/jpeg",
      "date": "2026-01-15 10:30:00"
    }
  ],
  "total": 85,
  "total_pages": 3
}
```

Response sets `X-WP-Total` and `X-WP-TotalPages`. The query is built via `TaxonomyService::buildFolderTaxQuery()` (`NOT EXISTS` for Root, `term_id` match with `include_children = false` otherwise).

## Preferences

Per-user UI preferences are stored in a single `user_meta` entry (`foldsnap_opt_preferences`) with a closed schema declared in `UserPreferencesService`. Both endpoints require `upload_files`; the user scope is implicit (`get_current_user_id()` server-side) — no user ID in the URL.

### `GET /preferences`

Returns the full preferences map for the current user. Missing keys are filled with declared defaults, so the response always contains every declared key.

```json
{
  "preferences": {
    "expandedFolders": [3, 7, 12],
    "allMedia": false,
    "sidebarWidth": 280,
    "selectedFolderId": 0
  }
}
```

### `PUT /preferences/{key}`

Writes one preference. The key segment must match a declared schema key — see [UI Preferences › Declared keys](04_2_UI_preferences.md#declared-keys) for the current list — and the value is validated against the key's declared type.

The route is registered with `WP_REST_Server::EDITABLE`, which is WordPress' standard "writable" alias and matches `POST`, `PUT`, and `PATCH`. `PUT` is the canonical verb for this endpoint; the other two are accepted as a side effect of the alias.

Body:

```json
{ "value": [3, 7, 12] }
```

Response (200):

```json
{ "key": "expandedFolders", "value": [3, 7, 12] }
```

The returned `value` is the type-coerced version actually persisted (e.g. for `int_array` non-positive / non-numeric / duplicate elements are filtered).

## Admin

### `POST /folders/recalculate`

Runs one chunk of the bottom-up counter recalculate. With `reset = true` the persistent stack is cleared so the next call starts from leaves. Used to rebuild drifted `total_*` values.

| Parameter | Type | Default | Notes                              |
|-----------|------|---------|------------------------------------|
| `limit`   | int  | `200`   | Folders processed in this chunk    |
| `reset`   | bool | `false` | Restart from scratch               |

The response is the recalculator's progress envelope (folders processed, remaining, done flag). Requires `manage_options`.

## Error responses

All endpoints return `WP_Error` on failure:

| Code                | Status | Meaning                              |
|---------------------|--------|--------------------------------------|
| `missing_name`                      | 400    | Folder name not provided             |
| `missing_folder_id`                 | 400    | `folder_id` parameter missing        |
| `missing_media_ids`                 | 400    | `media_ids` empty or not an array    |
| `invalid_argument`                  | 400    | Validation failed (e.g. folder not found, mutation against Root) |
| `foldsnap_unknown_preference`       | 400    | `PUT /preferences/{key}` with a key not declared in the schema |
| `foldsnap_invalid_preference_value` | 400    | `PUT /preferences/{key}` with a value not coercible to the declared type |
| `server_error`                      | 500    | Unexpected server error              |
