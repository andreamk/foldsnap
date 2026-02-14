# Prefix Standards Reference

Standard prefixes for FoldSnap codebase.

## Prefix by Context

| Context | Prefix | Case Style | Example |
|---------|--------|------------|---------|
| **PHP** | | | |
| Functions | `foldsnap_` | snake_case | `foldsnap_get_folders()` |
| Hooks (actions/filters) | `foldsnap_` | snake_case | `do_action('foldsnap_folder_created')` |
| Constants | `FOLDSNAP_` | UPPER_SNAKE | `FOLDSNAP_VERSION` |
| Options (wp_options) | `foldsnap_opt_` | snake_case | `get_option('foldsnap_opt_settings')` |
| User Meta (wp_usermeta) | `foldsnap_opt_` | snake_case | `get_user_meta($id, 'foldsnap_opt_display')` |
| AJAX Actions | `foldsnap_` | snake_case | `wp_ajax_foldsnap_create_folder` |
| Temp Files | `foldsnap_` | snake_case | `foldsnap_cache_12345.tmp` |
| Query Parameters | `foldsnap_` | snake_case | `?foldsnap_action=create` |
| **CSS/HTML** | | | |
| Classes | `foldsnap-` | kebab-case | `class="foldsnap-folder-tree"` |
| IDs | `foldsnap-` | kebab-case | `id="foldsnap-media-grid"` |
| Input Names | `foldsnap-` | kebab-case | `name="foldsnap-folder-name"` |
| **JavaScript** | | | |
| Global Namespace | `FoldsnapJs` | PascalCase | `FoldsnapJs.Folder.create()` |
| jQuery Selectors | `foldsnap-` | kebab-case | `$('.foldsnap-folder-tree')` |
| **WordPress** | | | |
| Script/Style Handles | `foldsnap-` | kebab-case | `wp_enqueue_script('foldsnap-admin')` |
| Localized Data | `foldsnap_` | snake_case | `wp_localize_script('foldsnap-admin', 'foldsnap_data')` |
| Text Domain | `foldsnap` | lowercase | `__('text', 'foldsnap')` |
| **Files** | | | |
| File Names | `foldsnap-` | kebab-case | `foldsnap-admin.css` |

## Key Rules

**Options/Meta:** Use `foldsnap_opt_` prefix to avoid collision with other plugin options.

**Constants vs Values:** Constant name uses standard prefix:
```php
define('FOLDSNAP_VERSION', '1.0.0');
```

**Text Domain:** Always use `foldsnap` as text domain (WordPress standard for plugin slug).

**PHP Namespace:** Use `FoldSnap\` as root namespace (PSR-4 mapped to `src/`).
