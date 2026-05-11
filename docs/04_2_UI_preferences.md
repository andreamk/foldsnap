# UI Preferences

Per-user preferences for the admin UI. Stored server-side so they follow the user across browsers and devices, with no client-side cache: PHP localises the current values into the page at enqueue time, so the JS bundle has them synchronously at boot with zero round trips.

## Storage

A single `user_meta` row keyed by `foldsnap_opt_preferences` carries the whole map (WordPress serialises it). One read per boot, atomic writes, per-key updates merge on top of the stored map so unrelated keys are preserved.

## Declared keys

Schema lives in `UserPreferencesService::SCHEMA` (`src/Services/UserPreferencesService.php`) — the single source of truth.

| Key               | Type        | Default | Used by                          |
|-------------------|-------------|---------|----------------------------------|
| `expandedFolders`  | `int_array`       | `[0]`   | Sidebar tree expansion state. Default `[0]` means Root is expanded for new users; once the user customises the list (including collapsing Root), their choice is stored verbatim. |
| `allMedia`         | `bool`            | `false` | Sidebar "All Media" override toggle |
| `sidebarWidth`     | `int` (200..600)  | `280`   | Sidebar width in pixels (clamped server-side) |
| `selectedFolderId` | `int` (min 0)     | `0`     | Last-selected folder so returning sessions reopen on the same folder. Overridden by the `foldsnap_folder_id` URL parameter when present. |

Supported type tokens: `bool`, `int`, `int_array`. (`string` is reserved for future keys; its coerce branch will be added when first needed.)

### Coercion rules

- `bool` — `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)`. Accepts `true`/`false`, `1`/`0`, `'true'`/`'false'`, `'yes'`/`'no'`, `'on'`/`'off'`. Rejects `null` and non-coercible strings.
- `int` — accepts numeric values and numeric strings. If the schema entry declares `min` / `max`, the value is clamped into that range (no rejection). Rejects only non-numeric input.
- `int_array` — root must be an array; each element is cast to int and dropped if it is non-numeric or negative (zero is accepted because it is Root's ID); duplicates are removed.

A value that fails its type's coercion is rejected (the REST endpoint returns 400, the service's `set()` returns `false`).

## REST endpoints

See [02_1_API_rest-endpoints.md](02_1_API_rest-endpoints.md#preferences) for the full contract. Quick reference:

- `GET  /foldsnap/v1/preferences` — full map (defaults filled in for unset keys). Available but unused at boot, since the same data ships in `window.foldsnap_data.preferences`.
- `PUT  /foldsnap/v1/preferences/{key}` — body `{ "value": <any> }` → 200 with the coerced value, or 400 (`foldsnap_unknown_preference` / `foldsnap_invalid_preference_value`).

Both endpoints require `upload_files` and target the current user implicitly.

## Initial values from PHP

`MediaLibraryController` ships the user's complete preferences map into `window.foldsnap_data.preferences` via `wp_add_inline_script` (`'before'` position, body assembled with `wp_json_encode`). The JS bundle reads this synchronously at boot — no fetch, no cache, no race conditions.

## Client module

`template/js/preferences.js` exposes:

| Export                       | Purpose |
|------------------------------|---------|
| `PREF_KEYS`                  | Frozen object with the canonical key strings (must match the PHP schema) |
| `getInitialPreferences()`    | Synchronous read of `window.foldsnap_data.preferences` |
| `savePreference(key, val)`   | Per-key debounced PUT (800 ms) to the REST endpoint |
| `flushPendingSaves()`        | Forces every pending PUT to fire immediately. Test helper. |

The store (`template/js/store/index.js`) hydrates from `getInitialPreferences()` once at boot, then subscribes and routes changes through `savePreference`.

## Adding a new preference

1. **PHP schema** — add an entry to `UserPreferencesService::SCHEMA` with `type` and `default`.
2. **JS key constant** — add the key to `PREF_KEYS` in `template/js/preferences.js`.
3. **Consumer wiring** — read with `getInitialPreferences()[PREF_KEYS.X]` and write with `savePreference(PREF_KEYS.X, value)`.

If the new key needs a type that the service doesn't yet support, add the branch in `UserPreferencesService::coerce()`.

## Limitations

- The PUT debounce is 800 ms. Closing the tab during that window means the value never reaches the server. Acceptable for UI state; the next page load reads the previously persisted values from PHP.
- Cross-tab sync happens at boot only (a second tab picks up the new values when it loads). No real-time `BroadcastChannel` wiring.
