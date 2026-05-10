# UI Preferences

Per-user preferences for the admin UI (currently: which folders are expanded in the sidebar, and whether the "All Media" override is on). Stored server-side so they follow the user across browsers and devices, with a localStorage cache for instant boot.

## Why a dedicated subsystem

Before this layer existed each preference was wired ad-hoc to `localStorage`, which made cross-device sync impossible and forced every new preference to reinvent the same read/write pattern. Centralising the schema in a single PHP class and exposing a tiny REST surface keeps per-key code in consumers down to one line of read and one line of write.

Standard WordPress packages were considered (`@wordpress/preferences`, `@wordpress/preferences-persistence`) and rejected: their footprint and indirection are disproportionate for the handful of keys this plugin actually needs.

## Storage

| Layer        | Where                                                | Source of truth |
|--------------|------------------------------------------------------|-----------------|
| Server       | `user_meta` row keyed by `foldsnap_opt_preferences` (the entire map is stored as a single serialised array) | Yes |
| Client cache | `localStorage` key `foldsnap.preferencesCache` (JSON-encoded map) | No — refreshed from server |

The whole preferences map sits in one `user_meta` entry so a hydrate is one read and a write is atomic. Per-key writes merge on top of the stored map to protect unrelated keys.

## Declared keys

Schema lives inside `UserPreferencesService::SCHEMA` (`src/Services/UserPreferencesService.php`). The current set:

| Key               | Type        | Default | Used by                          |
|-------------------|-------------|---------|----------------------------------|
| `expandedFolders` | `int_array` | `[]`    | Sidebar tree expansion state     |
| `allMedia`        | `bool`      | `false` | Sidebar "All Media" override toggle |

Supported type tokens: `bool`, `int_array`. (`int` and `string` are reserved for future keys; their coerce branches will be added when first needed.)

### Coercion rules

- `bool` — `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)`. Accepts `true`/`false`, `1`/`0`, `'true'`/`'false'`, `'yes'`/`'no'`, `'on'`/`'off'`. Rejects `null` and non-coercible strings.
- `int_array` — root must be an array; each element is cast to int and dropped if it is non-numeric, zero, or negative; duplicates are removed. The same filter rules are mirrored in the JS layer so a round-trip is symmetric.

A value that fails its type's coercion is rejected (the REST endpoint returns 400, the service's `set()` returns `false`). This is deliberate: preferences come from code that serialises typed values, so a mismatch is a bug, not a soft user mistake to silently fix.

## REST endpoints

See [02_1_API_rest-endpoints.md](02_1_API_rest-endpoints.md#preferences) for the full contract. Quick reference:

- `GET  /foldsnap/v1/preferences` — full map (defaults filled in for unset keys).
- `PUT  /foldsnap/v1/preferences/{key}` — body `{ "value": <any> }` → 200 with the coerced value, or 400 (`foldsnap_unknown_preference` / `foldsnap_invalid_preference_value`).

Both endpoints require `upload_files` and target the current user implicitly.

## Client module

`template/js/preferences.js` — a single file, no sub-folders. Public API:

| Export                     | Purpose |
|----------------------------|---------|
| `PREF_KEYS`                | Frozen object with the canonical key strings |
| `readCachedPreferences()`  | Synchronous read of the cache, filled with defaults. Used by the store at boot for an instant first render. |
| `loadPreferences()`        | Async fetch from the REST endpoint. Refreshes the cache. On error, returns the cache (or defaults). |
| `savePreference(key, val)` | Write-through cache (immediate) + per-key debounced PUT (800 ms). Server failures are swallowed silently — the cache already holds the new value, so UI state is not lost. |
| `flushPendingSaves()`      | Forces every pending PUT to fire immediately. Test helper. |

The store (`template/js/store/index.js`) wires the bridge in three steps:

1. Hydrate synchronously from `readCachedPreferences()` so the very first render shows the user's expanded folders.
2. Kick off `loadPreferences()` in the background; if the server map differs from the cache, dispatch a second hydrate.
3. Subscribe to store changes and route them through `savePreference`.

## Adding a new preference

Three edits:

1. **PHP schema** — add an entry to `UserPreferencesService::SCHEMA` with `type` and `default`.
2. **JS key constant** — add the key to `PREF_KEYS` in `template/js/preferences.js` and to `DEFAULTS`.
3. **Consumer wiring** — in the relevant store/component, read with `readCachedPreferences()[PREF_KEYS.X]` (boot) and write with `savePreference(PREF_KEYS.X, value)`.

If the new key needs a type that the service doesn't yet support, add the branch in `UserPreferencesService::coerce()`.

## Limitations

- The PUT debounce is 800 ms. Closing the tab during that window means the cache holds the new value but the server doesn't see it until the next successful boot reconciles via `loadPreferences()`. Acceptable for UI state; not suitable for anything safety-critical.
- Cross-tab sync happens at boot only (the second tab picks up the new server values when it loads `loadPreferences()`). No real-time `BroadcastChannel` wiring.
