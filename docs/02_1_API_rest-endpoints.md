# REST API Endpoints

All endpoints are under the `foldsnap/v1` namespace and require the `upload_files` capability.

## Folders

### GET /foldsnap/v1/folders

Returns the full folder tree with media counts and sizes.

**Response (200):**

```json
{
  "folders": [
    {
      "id": 1,
      "name": "Photos",
      "slug": "photos",
      "parent_id": 0,
      "media_count": 5,
      "total_media_count": 12,
      "color": "#ff0000",
      "position": 0,
      "direct_size": 10240,
      "total_size": 25600,
      "children": []
    }
  ],
  "root_media_count": 42,
  "root_total_size": 102400
}
```

- `media_count` — direct attachments in this folder (from taxonomy term count)
- `total_media_count` — recursive total including all descendants
- `direct_size` / `total_size` — bytes, computed from `_wp_attachment_metadata`
- `root_media_count` / `root_total_size` — unassigned media (not in any folder)

### POST /foldsnap/v1/folders

Creates a new folder.

| Parameter   | Type   | Required | Description                          |
|-------------|--------|----------|--------------------------------------|
| `name`      | string | yes      | Folder name (max 200 chars)          |
| `parent_id` | int    | no       | Parent folder ID (0 = root, default) |
| `color`     | string | no       | Hex color (e.g., `#ff0000`)          |
| `position`  | int    | no       | Sort position (default 0)            |

Duplicate names under the same parent are auto-suffixed (e.g., "Photos (2)").

**Response (201):** The created folder object.

### PUT /foldsnap/v1/folders/{id}

Updates a folder. Only provided fields are changed.

| Parameter   | Type   | Required | Description                         |
|-------------|--------|----------|-------------------------------------|
| `name`      | string | no       | New name                            |
| `parent_id` | int    | no       | New parent (-1 or omit = no change) |
| `color`     | string | no       | New hex color                       |
| `position`  | int    | no       | New position (-1 or omit = no change) |

**Response (200):** The updated folder object.

### DELETE /foldsnap/v1/folders/{id}

Deletes a folder. Media items lose their folder assignment and return to root.

**Response (200):** `{ "deleted": true }`

## Media Assignment

### POST /foldsnap/v1/folders/{id}/media

Assigns media items to a folder. Replaces any existing folder assignment for each item.

| Parameter   | Type  | Required | Description             |
|-------------|-------|----------|-------------------------|
| `media_ids` | int[] | yes      | Attachment post IDs     |

**Response (200):** `{ "assigned": true }`

### DELETE /foldsnap/v1/folders/{id}/media

Removes media items from a folder.

| Parameter   | Type  | Required | Description             |
|-------------|-------|----------|-------------------------|
| `media_ids` | int[] | yes      | Attachment post IDs     |

**Response (200):** `{ "removed": true }`

## Media Query

### GET /foldsnap/v1/media

Returns paginated media for a folder.

| Parameter   | Type | Required | Description                                 |
|-------------|------|----------|---------------------------------------------|
| `folder_id` | int  | yes      | Folder ID (0 = unassigned/root)             |
| `page`      | int  | no       | Page number (default 1)                     |
| `per_page`  | int  | no       | Items per page (1–100, default 40)          |

**Response (200):**

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

Response headers include `X-WP-Total` and `X-WP-TotalPages`.

## Error Responses

All endpoints return `WP_Error` on failure:

| Code                | Status | Meaning                          |
|---------------------|--------|----------------------------------|
| `missing_name`      | 400    | Folder name not provided         |
| `missing_folder_id` | 400    | folder_id parameter missing      |
| `missing_media_ids` | 400    | media_ids empty or not an array  |
| `invalid_argument`  | 400    | Validation failed (e.g., folder not found) |
| `server_error`      | 500    | Unexpected server error          |
