# FoldSnap — Project Brief

> Snap your media into place.
> Media folders for WordPress. Unlimited. Free. Forever.

**Slug:** `foldsnap`

---

## What It Is

A WordPress plugin dedicated **exclusively** to folder management in the Media Library. Not a commercial product — it's a personal branding tool and reputation builder in the WordPress community.

## Core Principles

1. **100% free** — No Pro version, no upsell, no monetization. Ever.
2. **One thing, done right** — Media folders only. Nothing outside scope.
3. **Zero bloat** — Minimal codebase, no unnecessary dependencies, no jQuery.
4. **Zero tracking** — No analytics, no phone-home, no telemetry.
5. **Zero nag screens** — No banners, no popups, no "upgrade" prompts.

---

## Feature Set

### In Scope (build these)

- Unlimited folders and subfolders
- Drag & drop files between folders
- Drag & drop to reorder/nest folders
- Bulk select and move files
- Search files and folders
- Sort by name, date, type, size
- File count per folder
- Folder colors
- Default upload folder
- Collapsible and resizable sidebar
- Gutenberg editor integration
- Migration tool (import from FileBird, Folders, Real Media Library)

### Out of Scope (never build these)

- Galleries or gallery shortcodes
- Cloud integration (Dropbox, Google Drive, S3, etc.)
- Post/page/CPT organization
- Watermarks
- SEO file renaming
- User-based folders or permissions
- Analytics or usage tracking
- Any form of monetization or upsell

---

## Technical Requirements

### Architecture

- Virtual folders using custom taxonomy on `attachment` post type
- No changes to actual file paths or URLs — zero risk of broken links
- **React UI via `wp-element`** — WordPress ships React natively since 6.5, no need to bundle it. Use `@wordpress/element`, `@wordpress/components`, `@wordpress/api-fetch`, `@wordpress/data` as dependencies
- REST API endpoints for all folder operations
- Virtual scrolling for large folder trees
- Lazy loading of folder contents
- Indexed database queries for performance at scale

### Compatibility

- WordPress 6.5+ (required — leverages native React/wp-element)
- PHP 7.4+
- Must work with: Gutenberg, Classic Editor, Elementor, Divi
- Must integrate with the native Media Library grid and list views
- Must work inside the media modal (post editor media picker)

### Code Quality

- WordPress Coding Standards (WPCS) compliant
- Inline documentation on all public methods
- Internationalization ready (i18n with text domain `foldsnap`)
- WCAG 2.1 AA accessibility
- Escaped output, sanitized input, nonce verification on all forms
- No direct database queries where WP APIs exist

### Performance Targets

- < 50KB total plugin size (excluding assets)
- < 10ms added to admin page load
- Handles 10,000+ media files without UI slowdown
- No additional HTTP requests on frontend (admin-only plugin)
- React bundle excluded from plugin — loaded via WP core `wp-element` dependency

---

## Competitive Context

FoldSnap enters a market where every competitor uses freemium with deliberately limited free tiers:

| Plugin | Free Limit | Price |
|---|---|---|
| FileBird | 10 folders max | $49 lifetime |
| Folders (Premio) | No subfolders | $19/year |
| Real Media Library | Unlimited | $79 lifetime |
| WP Media Folder | No free version | $39+ |
| HappyFiles | No free version | $49 lifetime |

**FoldSnap's edge:** Unlimited everything, truly free, no strings. No competitor can match this without destroying their revenue model.

---

## Project Structure

```
foldsnap/
├── foldsnap.php                  # Main plugin file (WP header)
├── foldsnap-main.php             # Constants, autoloader, bootstrap
├── readme.txt                    # WordPress.org readme
├── uninstall.php                 # Clean uninstall
├── index.php                     # Silence is golden
├── composer.json                 # PHP deps, quality scripts
├── package.json                  # Node deps (@wordpress/scripts)
│
├── src/                          # PHP source code (PSR-4 namespace FoldSnap\)
│   │── Controllers/              # PHP controllers (route logic, permissions)
│   │   └── MainPageController.php
│   │── Core/                     # PHP core framework
│   │   ├── Bootstrap.php         # Plugin initialization
│   │   ├── Views/
│   │   │   └── TplMng.php        # Template manager
│   │   └── Controllers/          # Base controller classes
│   │       ├── ControllerInterface.php
│   │       ├── ControllersManager.php
│   │       ├── AbstractMenuPageController.php
│   │       ├── AbstractSinglePageController.php
│   │       ├── SubMenuItem.php
│   │       └── PageAction.php
│   │── Models/                   # Data models (to be created)
│   │── Services/                 # Business logic (to be created)
│   │   ├── TaxonomyService.php   # Folder taxonomy registration
│   │   ├── RestApiService.php    # REST API endpoints
│   │   └── MigrationService.php  # Import from other plugins
│   └── Utils/                    # Utility classes
│       ├── Autoloader.php        # PSR-4 autoloader
│       ├── Sanitize.php
│       ├── SanitizeInput.php
│       └── ExpireOptions.php
│
├── assets/                       # Compiled/static assets (served to browser)
│   ├── js/                       # Built JS output (wp-scripts build)
│   └── css/                      # Built CSS output
│
├── template/                     # Frontend: PHP templates + React source
│   ├── js/                       # React source (compiled by wp-scripts)
│   │   ├── index.js              # Entry point
│   │   ├── components/           # React components
│   │   │   ├── App.jsx           # Root component
│   │   │   ├── FolderTree.jsx    # Sidebar folder tree
│   │   │   ├── FolderItem.jsx    # Single folder node
│   │   │   ├── DragLayer.jsx     # Drag & drop handler
│   │   │   └── Toolbar.jsx       # Search, sort, bulk actions
│   │   ├── hooks/                # Custom React hooks
│   │   │   ├── useFolders.js     # Folder CRUD via REST API
│   │   │   └── useDragDrop.js    # Drag & drop logic
│   │   └── store/                # @wordpress/data store
│   │       └── index.js          # Folder state management
│   ├── page/                     # Full page PHP templates
│   │   ├── page_header.php
│   │   └── page_footer.php
│   └── parts/                    # Partial PHP templates
│       ├── messages.php
│       ├── tabs_menu_l3.php
│       └── admin_headers/
│           └── wpbody_header.php
│
├── tests/                        # Test suite
│   ├── bootstrap.php
│   ├── Unit/                     # Isolated unit tests
│   ├── Feature/                  # Integration tests
│   └── TestsUtils/               # Reusable test helpers
│       └── TestsAutoloader.php
│
├── tools/                        # Dev tooling (not shipped)
│   ├── release.sh                # Release automation
│   ├── ruleset.xml               # PHPCS ruleset
│   ├── ruleset_plugin_check.xml  # Plugin check ruleset
│   ├── phpstan/                  # PHPStan config
│   ├── extra-composer/           # Isolated composer deps for tools
│   └── install-wp-tests.sh       # WP test framework installer
│
└── languages/                    # i18n
    └── foldsnap.pot              # Translation template
```

### PHP Architecture

PSR-4 autoloading with namespace `FoldSnap\` mapped to `src/`:

- **Controllers** — Handle HTTP requests, permissions, page routing
- **Services** — Business logic (taxonomy, REST API, migration)
- **Models** — Data representation
- **Core** — Framework: bootstrap, base classes, template engine
- **Utils** — Reusable helpers (sanitization, autoloading)

### Frontend Architecture

React source lives in `template/js/`, compiled output goes to `assets/js/`:

- Source in `template/js/` is compiled by `@wordpress/scripts` (webpack)
- Output in `assets/js/` is what gets enqueued in WordPress
- No React bundled — uses `wp-element` dependency (React shipped with WP core)
- SCSS compiled to `assets/css/`
- Both source and compiled files are included in the distributed plugin (WordPress.org requires inspectable source)

### Build Tooling

- `@wordpress/scripts` for build/dev (webpack preconfigured for WP)
- `npm run build` — production bundle in `assets/js/`
- `npm run start` — dev mode with hot reload
- `composer fullcheck` — phpcbf + phpcs + plugin-check + phpstan + phpunit

---

## WordPress.org Readme Tone

```
=== FoldSnap — Media Folders for WordPress ===

Snap your media into place. Unlimited folders. Truly free. Forever.

No folder limits. No nag screens. No Pro version. No tracking.
Just fast, clean media organization that works.
```

---

## Key Development Rules

1. **Resist feature creep.** When users request features outside scope, the answer is: "FoldSnap does folders only. There are great plugins for that."
2. **Performance first.** Every feature must be benchmarked. If it slows things down, optimize or cut it.
3. **Clean code is marketing.** Developers will read the source. It should be exemplary.
4. **Support response < 24h.** Reputation is built in the support forum.
5. **Test every WP major release.** Compatibility is non-negotiable.
