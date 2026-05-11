# UI Preferences

Per-user preferences for the admin UI. Stored server-side so they follow the user across browsers and devices. PHP ships the current values into the page at enqueue time, so the JS bundle has them synchronously at boot with zero round trips and no client-side cache.

## Storage

A single `user_meta` row carries the whole preferences map as one serialised array. Reads happen once per boot; writes merge per-key into the stored map so unrelated keys are preserved untouched. This keeps cross-key consistency trivial and avoids one `user_meta` row per preference.

## Schema

The schema is **closed**: every preference is declared in `UserPreferencesService::SCHEMA` with its type and default (plus optional range bounds for numeric types). Unknown keys are rejected at the boundary. The schema file in the service is the single source of truth — we deliberately do **not** mirror the field list in this doc, so adding or renaming a preference is a one-place change.

Each declared key carries:

- a **type** (e.g. boolean, bounded integer, array of integers),
- a **default**, returned for any user who never wrote that key,
- optional **range bounds** for numeric types.

## Coercion

Every write goes through the service's coercion step. The contract:

- Numeric values are cast and, if the schema declares bounds, **clamped silently** into range — out-of-range input is corrected, not rejected, so an outdated client can't poison the store.
- Collection values drop elements that can't be coerced to the declared element type.
- Values that can't be coerced at all are **rejected**: the REST endpoint returns 400, the service's setter returns `false`. The stored map is never partially updated.

The supported type set is open-ended — new types are added to the service's coercion switch as new preferences need them.

## REST endpoints

Two endpoints under `/foldsnap/v1/preferences`, both requiring `upload_files` and scoped implicitly to the current user (no user ID in the URL):

- **GET** returns the full map with defaults filled in for unset keys, so the client never has to handle a missing field.
- **PUT** on a single key writes that one preference. The response carries the coerced value actually persisted (so the client sees the effect of any clamping).

Errors use two distinct codes — one for unknown keys, one for invalid values — so the client can tell a schema-mismatch from a coercion failure. Full contract: [REST endpoints › Preferences](02_1_API_rest-endpoints.md#preferences).

The GET endpoint exists for completeness but is **not used at boot**, because the same map is already on the page.

## Boot path

`MediaLibraryController` reads the full preferences map for the current user and ships it onto the page as a global JS variable, attached to the admin script as an inline `'before'` payload. The bundle reads that variable synchronously at module load — no fetch, no race, no flash of default state.

## Client module

`template/js/preferences.js` is the only place in the JS bundle that knows about the preferences subsystem. It owns:

- a frozen map of **canonical key constants** (must match the PHP schema by string value),
- a **synchronous read** of the localised payload, used at boot,
- a **per-key debounced PUT** for writes (multiple updates to the same key within the debounce window collapse into one request),
- a test helper that flushes pending writes.

The Redux store hydrates from the synchronous read at registration time and routes subsequent changes through the debounced writer. One preference (`sidebarWidth`) bypasses the store entirely because no in-app consumer reads the live value — see [React UI Architecture › Persistence](04_1_UI_react-architecture.md#persistence) for the rationale.

## Adding a new preference

1. **Declare it in the PHP schema** with its type, default, and (if numeric) bounds. If the type isn't yet supported, add a coercion branch to the service.
2. **Add the matching key constant** on the JS side so consumers reference it by name, not by string literal.

That's it. No doc edit, no REST registration, no migration: the GET/PUT endpoints already serve every declared key, and existing users without a stored value see the declared default on first read.

## Limitations

- **Debounced writes.** Closing the tab before the debounce window elapses drops that pending write. Acceptable for transient UI state (next boot reads the previously persisted value); not suitable for preferences that must be durable on every keystroke.
- **No cross-tab live sync.** A second tab picks up new values only when it boots. There is no `BroadcastChannel` or similar mechanism — the trade-off is intentional given the use case (UI state, not collaborative data).
