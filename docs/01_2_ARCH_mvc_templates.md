# MVC and Template System

Admin interface uses an MVC-style separation between controllers (page lifecycle), models (data), and templates (HTML output). Two cooperating components form the backbone: the **`ControllersManager`** registry that owns admin-page controllers, and the **`TplMng`** template engine that renders PHP views with a typed data API.

This system is derived from the Duplicator Pro `Core/Controllers` + `Core/Views` stack. The shape of the API is the same; FoldSnap simplifies it (single menu page, no `CapMng`, no blank-page abstraction) and tightens it (PHP 7.4 strict types, typed properties, full type hints).

## Layout

| Layer | Location | Role |
|-------|----------|------|
| Controllers | `src/Controllers/` | Concrete controllers (e.g. `MainPageController`) |
| Controller framework | `src/Core/Controllers/` | Abstract base classes, manager, `PageAction`, `SubMenuItem` |
| Models | `src/Models/` | Entities (e.g. `FolderModel`) |
| Views | `template/` | PHP templates rendered by `TplMng` |
| Template engine | `src/Core/Views/TplMng.php` | Singleton renderer + typed data getters |

## Controller Framework

### Class hierarchy

```
ControllerInterface
    ↑
AbstractSinglePageController        ← admin page without a menu entry
    ↑
AbstractMenuPageController          ← admin page registered in WP menu
```

`ControllerInterface` requires three methods: `hookWpInit()`, `run()`, and `render()`.

`AbstractSinglePageController` implements the page lifecycle and exposes a per-class singleton via `static::getInstance()`. `AbstractMenuPageController` extends it with WordPress menu registration (`add_menu_page` / `add_submenu_page`), level-2/3 sub-menu handling, and a default render flow that wraps body content in header/footer templates.

> **Difference from Duplicator Pro:** Duplicator Pro also ships `AbstractBlankPageController` for fully self-rendered pages. FoldSnap does not need it and omits the class.

### `ControllersManager`

Singleton at `src/Core/Controllers/ControllersManager.php`. Two responsibilities:

1. **Registry** — `getMenuPages()` returns the list of `AbstractMenuPageController` instances, filtered by `foldsnap_menu_pages`. New menu pages register themselves by hooking this filter.
2. **URL/state helpers** — translates the current request into menu levels (`page` / `tab` / `subtab`), resolves the current action, and builds menu links via `getMenuLink()`.

It also exposes the menu-slug constants used everywhere:

| Constant | Value | Purpose |
|----------|-------|---------|
| `MAIN_MENU_SLUG` | `foldsnap` | Top-level plugin slug |
| `QUERY_STRING_MENU_KEY_L1` | `page` | Level-1 (page) query var |
| `QUERY_STRING_MENU_KEY_L2` | `tab` | Level-2 query var |
| `QUERY_STRING_MENU_KEY_L3` | `subtab` | Level-3 query var |
| `QUERY_STRING_MENU_KEY_ACTION` | `action` | Action key |
| `QUERY_STRING_INNER_PAGE` | `inner_page` | Inner-page slug within a tab |

`registerMenu()` runs in two passes — main pages first, secondary pages after — so submenu registration can target an already-registered parent slug.

### Page lifecycle

The full request cycle for an admin page is driven by `AbstractSinglePageController::run()` (called on `admin_init`) and `render()` (called by WordPress when the menu page is opened). The order is:

1. **`hookWpInit()`** — wired to WordPress `init`. Override for early hook setup.
2. **`run()`** — gated on `isEnabled()` and the request matching `pageSlug`:
   - `setTemplateData()` — push global template values (`pageTitle`, `currentLevelSlugs`, `currentInnerPage`).
   - `capabilityCheck()` — `wp_die` if the current user lacks `$capatibility`.
   - Apply `foldsnap_page_template_data_{pageSlug}` filter onto global data.
   - `setActionsAvailables()` — collect `PageAction` instances applicable to the current page and publish them under the `actions` global key.
   - `runActions()` — find the matching `PageAction` for `?action=…` and execute it; capture errors into `actionsError` / `errorMessage`.
3. **`render()`** — fires `foldsnap_before_render_page_{pageSlug}`, renders the standard envelope, fires `foldsnap_render_page_content_{pageSlug}` (where concrete controllers attach their body), then `foldsnap_after_render_page_{pageSlug}`.

`AbstractMenuPageController::render()` adds the `page/page_header`, `parts/messages`, body header (default `parts/admin_headers/wpbody_header`), `parts/tabs_menu_l3`, and `page/page_footer` templates around the content hook.

### Page actions (`PageAction`)

`PageAction` represents an executable action bound to a page (e.g. a form submission). Each instance carries: a key, a callable, the menu slugs it applies to, an optional inner page, and a capability check. Controllers register actions through the `foldsnap_page_actions_{pageSlug}` filter; `runActions()` dispatches the one whose key matches `?action=…` and whose menu slugs match the current page. Templates retrieve actions through `$tplMng->getAction($key)` to render nonce fields and submit URLs.

### Concrete controller: `MainPageController`

`src/Controllers/MainPageController.php` registers a single submenu under `upload.php` (Media → FoldSnap), titled "FoldSnap", requiring `manage_options`. It hooks `foldsnap_render_page_content_foldsnap` to render `page/settings`, and overrides `pageScripts()` to enqueue the settings bundle (`assets/js/foldsnap-settings.js`) and `pageStyles()` to enqueue the matching stylesheet (`assets/css/foldsnap-settings.css`).

This is currently the **only** menu controller. The framework supports more — additional controllers register themselves through the `foldsnap_menu_pages` filter — but FoldSnap's UI lives inside the WordPress media library and reaches the React app from there, not through a dedicated plugin page.

### Non-page controllers

`src/Controllers/` also contains controllers that do **not** extend the menu-page framework, because their job is not to render an admin page:

- **`MediaLibraryController`** — singleton that hooks `admin_enqueue_scripts`, `ajax_query_attachments_args`, and `pre_get_posts` to inject the React bundle and filter the media library by folder.
- **`RestApiController`** — singleton that registers the `foldsnap/v1` REST namespace and owns read endpoints; write endpoints are delegated to `RestApiFolderMutationsController`.

These do not implement `ControllerInterface` and are not visible to `ControllersManager`. They are wired directly from `FoldSnap\Core\Bootstrap`. The "Controller" suffix here follows the project naming convention rather than the menu-page contract.

## Template Engine — `TplMng`

`src/Core/Views/TplMng.php` is a `final` singleton that renders PHP templates from the `template/` directory.

```php
TplMng::getInstance()->render('page/settings', ['foo' => 'bar']);
```

### Template file conventions

Every template file must declare the variables available in scope:

```php
/** @var \FoldSnap\Core\Views\TplMng $tplMng */
```

Templates **must not** read `$tplData` (it does not exist). Read data through `$tplMng` typed getters; read actions through `$tplMng->getAction()`.

### Typed data access

Templates access render data through typed getters on `$tplMng`, one per scalar type plus `array` and typed `object`. Each getter comes in two flavours:

- **Optional** (`getDataValue<Type>`) — returns a default if the key is missing or the value has the wrong type. Use when the key may be absent in some render paths.
- **Required** (`getDataValue<Type>Required`) — throws `Exception` if the key is missing or has the wrong type. Use when the controller, a parent template, or global data guarantees the key is present; this surfaces wiring bugs early.

Page actions registered through `setActionsAvailables()` are retrieved via `$tplMng->getAction($key)`, which throws if the key is unknown. `dataValueExists()` and `actionExists()` are available for conditional checks.

### Data flow: how templates receive data

Every template reads through `$tplMng->getDataValue*()`. From the template's perspective there is **no difference** between global data, render args, and inherited parent data — they are all merged into one flat array.

The merge happens in three layers, each overriding the previous:

1. **Global data (base)** — set via `TplMng::setGlobalValue()` / `updateGlobalData()`. Present in every template.
2. **Render `$args`** — passed to `TplMng::render()`. Override globals for this template and its children.
3. **Parent inheritance** — nested templates inherit the parent's full `renderData`. Child `$args` override inherited values.

After a child finishes rendering, the parent's `renderData` is restored — nested rendering has no side effects on the caller's view of data.

### `setGlobalValue` vs render `$args`

**Prefer render `$args`** when you have access to the `render()` call. Args propagate to children automatically — no need to re-pass at each level — and the data flow is traceable by reading the render call.

**Use `setGlobalValue` only when:**
- The value is genuinely global (e.g. `pageTitle`, `actions`, `currentLevelSlugs`).
- The code has no access to the `render()` call (e.g. data prepared in `setTemplateData()` or `runActions()`, both of which run before `render()`).

`AbstractSinglePageController` sets these globals automatically: `pageTitle`, `currentLevelSlugs`, `currentInnerPage`, `actions`, `actionsError`, `errorMessage`, `successMessage`. `AbstractMenuPageController` adds `menuItemsL2`, `menuItemsL3`, `currentSubMenuObj`.

### Templates must not contain business logic

Templates render HTML from **pre-computed data**. They must not instantiate domain objects, query the database, touch globals like `$wpdb`, or call services/factories. Show/hide conditionals and getter calls on objects already passed in are fine.

**Where to put logic:**
- **Single controller** — prepare data in the controller's content callback (e.g. the method hooked on `foldsnap_render_page_content_{pageSlug}`) or in `setTemplateData()` / `pageAfterActions`-style hooks; pass via render `$args`.
- **Multiple controllers** — extract a shared static helper, or compute in the closest shared parent template.
- **Hook** — `TplMng::getDataHook($slugTpl)` returns the `foldsnap_template_data_{slug}` filter applied to `renderData` immediately before each template renders.

### Render variants and filters

`render()` echoes by default and can return the captured string instead. Two thin variants wrap it for common consumers: a JSON-encoded form for AJAX responses, and an `esc_attr`-escaped form for embedding rendered output inside HTML attributes. A static `setStripSpaces` flag collapses whitespace between tags, used by the menu-page render flow.

Each render exposes two filters keyed on the template slug — one over the merged `renderData` before the template is required, one over the captured output before it is returned. Slugs are normalised by replacing `\`, `/`, and `.` with `_` so they are safe to use in hook names.

A small set of static helpers (`getInputName`, `getInputId`) produces namespaced form-field names prefixed with `foldsnap_input_`, keeping inputs rendered across templates consistently scoped.

## Differences from Duplicator Pro

For developers familiar with the Duplicator Pro originals, the meaningful deltas are:

| Area | Duplicator Pro | FoldSnap |
|------|----------------|----------|
| PHP types | Untyped properties, partial type hints | `declare(strict_types=1)`, typed properties, full type hints |
| Class shape | Abstract bases used widely | `TplMng` and `ControllersManager` are `final` |
| Hook prefix | `duplicator_` | `foldsnap_` |
| Capability checks | `CapMng` indirection | Direct `current_user_can()` |
| Page abstractions | `AbstractBlank` / `AbstractSingle` / `AbstractMenu` | `AbstractSingle` and `AbstractMenu` only |
| Menu surface | Top-level Duplicator menu + many tabs | Single submenu under Media (`upload.php`) |
| Non-page controllers | All controllers extend the framework | `MediaLibraryController`, `RestApiController` are independent singletons |
| Sanitization helpers | `SnapUtil` | `Sanitize` / `SanitizeInput` |

The conceptual model — singleton template manager, typed render-data getters, three-layer data merge with parent inheritance, page-action dispatching — is unchanged.

## Related

- [Architecture Overview](01_1_ARCH_overview.md) — system layers and request flows
- [REST API Endpoints](02_1_API_rest-endpoints.md) — REST controller surface
- [Prefix Standards](99_3_APPENDIX_prefix_standards.md) — hook and naming conventions
