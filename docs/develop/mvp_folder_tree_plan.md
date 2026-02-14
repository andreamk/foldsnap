# FoldSnap MVP — Folder Tree Implementation Plan

## Context

FoldSnap e' un plugin WordPress per la gestione di cartelle nella Media Library. Il framework (controllers, template engine, React setup, build toolchain, quality tools) e' gia' pronto. Questo piano implementa il primo MVP funzionante: la creazione e gestione dell'albero delle cartelle con UI React.

**Approccio storage:** Custom taxonomy `foldsnap_folder` su `attachment` post type (gerarchica). Tutti i media senza associazione a un termine sono considerati nella "root" virtuale. Campi aggiuntivi (colore, posizione) salvati come `term_meta`. Il conteggio media per cartella e' automatico tramite `WP_Term->count`.

---

## Panoramica file

### PHP — Nuovi (4 sorgenti)
| File | Scopo |
|------|-------|
| `src/Services/TaxonomyService.php` | Registra la taxonomy `foldsnap_folder` |
| `src/Models/FolderModel.php` | DTO: rappresenta una cartella con conversione da/a `WP_Term` |
| `src/Services/FolderRepository.php` | Astrazione DB: CRUD cartelle, assegnazione media |
| `src/Controllers/RestApiController.php` | Endpoint REST `foldsnap/v1` |

### PHP — Da modificare (3)
| File | Modifica |
|------|----------|
| `src/Core/Bootstrap.php` | Aggiungere `TaxonomyService::register()` e `RestApiController::getInstance()` |
| `src/Controllers/MainPageController.php` | Aggiungere `wp_localize_script()` e enqueue CSS |
| `src/Core/Uninstall.php` | Aggiungere cleanup dei termini taxonomy |

### JS — Nuovi (10)
| File | Scopo |
|------|-------|
| `template/js/store/constants.js` | Action types e store name |
| `template/js/store/reducer.js` | Reducer con default state |
| `template/js/store/actions.js` | Actions (fetch, create, update, delete, assign/remove media) |
| `template/js/store/selectors.js` | Selectors |
| `template/js/store/resolvers.js` | Auto-fetch folders al primo accesso |
| `template/js/store/controls.js` | Control per `apiFetch` |
| `template/js/store/index.js` | Registrazione store `foldsnap/folders` |
| `template/js/components/FolderTree.jsx` | Sidebar con albero cartelle |
| `template/js/components/FolderItem.jsx` | Singolo nodo cartella nel tree |
| `template/js/components/CreateFolderModal.jsx` | Modale creazione cartella |

### JS — Da modificare (2)
| File | Modifica |
|------|----------|
| `template/js/components/App.jsx` | Sostituire demo card con layout sidebar + content |
| `package.json` | Aggiungere `@wordpress/api-fetch` e `@wordpress/data` |

### CSS — Nuovo (1)
| File | Scopo |
|------|-------|
| `assets/css/foldsnap-admin.css` | Stili sidebar e folder tree |

---

## Step di implementazione

Ogni step include codice + test relativi. I test si scrivono e si eseguono nello stesso step.

### Step 0 — Preparazione ✅

- Rimuovere `disable-model-invocation: true` da `.claude/skills/test-coverage/SKILL.md` per permettere l'invocazione automatica della skill `/test-coverage`

### Step 1 — TaxonomyService + Bootstrap + test ✅

**Crea `src/Services/TaxonomyService.php`:**
- Classe `final` con costanti `TAXONOMY_NAME = 'foldsnap_folder'` e `POST_TYPE = 'attachment'`
- Metodo statico `register()` → `register_taxonomy()` con: `hierarchical: true`, `public: false`, `show_ui: false`, `show_in_rest: false`, `rewrite: false`
- Labels con text domain `foldsnap`

**Modifica `src/Core/Bootstrap.php`:**
- Aggiungere `TaxonomyService::register()` in `onInit()` **PRIMA** di `is_admin()` (taxonomy va registrata su ogni request)

**Test:** `tests/Unit/Services/TaxonomyServiceTests.php`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 2 — FolderModel + test ✅

**Crea `src/Models/FolderModel.php`:**
- Proprieta' private: `id`, `name`, `slug`, `parentId`, `mediaCount`, `color`, `position`, `children[]`
- Costruttore tipizzato, getters per tutte le proprieta'
- `addChild(FolderModel): void`
- `static fromTerm(WP_Term): self` — mappa `term_id`, `name`, `slug`, `parent`, `count`; legge `term_meta` per `foldsnap_folder_color` e `foldsnap_folder_position`
- `toArray(): array` — serializzazione ricorsiva (include children)

**Test:** `tests/Unit/Models/FolderModelTests.php`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 3 — FolderRepository + test

**Crea `src/Services/FolderRepository.php`:**
- Classe **non statica** (iniettabile), costanti `META_COLOR`, `META_POSITION`

| Metodo | Wrappa |
|--------|--------|
| `getAll(): FolderModel[]` | `get_terms()` con `hide_empty: false` |
| `getTree(): FolderModel[]` | `getAll()` + `buildTree()` in-memory, ordinato per position |
| `getById(int): ?FolderModel` | `get_term()` |
| `create(name, parentId, color, position): FolderModel` | `wp_insert_term()` + `update_term_meta()` |
| `update(termId, name, parentId, color, position): FolderModel` | `wp_update_term()` + `update_term_meta()` |
| `delete(int): bool` | `wp_delete_term()` — media tornano in root automaticamente |
| `assignMedia(folderId, int[]): void` | `wp_set_object_terms()` con `append=false` |
| `removeMedia(folderId, int[]): void` | `wp_remove_object_terms()` |
| `getRootMediaCount(): int` | `WP_Query` con `tax_query NOT EXISTS` |

- Helper privati: `buildTree()`, `validateTermId()`
- `update()` usa `-1` come sentinel per "non cambiare" su parentId e position

**Test:** `tests/Feature/Services/FolderRepositoryTests.php`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 4 — RestApiController + Bootstrap + MainPageController + test

**Crea `src/Controllers/RestApiController.php`:**
- Singleton, riceve `FolderRepository` nel costruttore
- Hook su `rest_api_init` per registrare le routes
- `checkPermission(): bool` → `current_user_can('upload_files')`

| Endpoint | Handler | Note |
|----------|---------|------|
| `GET /foldsnap/v1/folders` | `getFolders` | Ritorna `{ folders: tree, root_media_count: int }` |
| `POST /foldsnap/v1/folders` | `createFolder` | `name` required, `parent_id`/`color`/`position` optional |
| `PUT /foldsnap/v1/folders/(?P<id>\d+)` | `updateFolder` | Tutti i campi opzionali |
| `DELETE /foldsnap/v1/folders/(?P<id>\d+)` | `deleteFolder` | Media tornano in root |
| `POST /foldsnap/v1/folders/(?P<id>\d+)/media` | `assignMedia` | `media_ids[]` required |
| `DELETE /foldsnap/v1/folders/(?P<id>\d+)/media` | `removeMedia` | `media_ids[]` required |

- Sanitizzazione: `sanitize_text_field()`, `absint()`, `sanitize_hex_color()`
- Error handling: `InvalidArgumentException` → 400, `Exception` → 500

**Modifica `src/Core/Bootstrap.php`:**
- Aggiungere `RestApiController::getInstance()` in `onInit()` prima di `is_admin()`

**Modifica `src/Controllers/MainPageController.php`:**
- In `pageScripts()`: `wp_localize_script('foldsnap-admin', 'foldsnap_data', ['restUrl' => ..., 'restNonce' => ...])`
- In `pageStyles()`: `wp_enqueue_style('foldsnap-admin', FOLDSNAP_PLUGIN_URL . '/assets/css/foldsnap-admin.css', ...)`

**Test:** `tests/Feature/Controllers/RestApiControllerTests.php`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 5 — Uninstall cleanup + test

**Modifica `src/Core/Uninstall.php`:**
- Aggiungere `self::deleteTaxonomyTerms()` in `cleanSite()`
- Nuovo metodo `deleteTaxonomyTerms()`: registra taxonomy temporaneamente, recupera tutti i termini, elimina con `wp_delete_term()`

**Test:** Aggiornare `tests/Unit/Core/UninstallTests.php`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 6 — Dipendenze npm + Store @wordpress/data + test

**Installa:** `npm install @wordpress/api-fetch @wordpress/data`

**Crea store files:**
- `template/js/store/constants.js` — `STORE_NAME = 'foldsnap/folders'` + action types
- `template/js/store/reducer.js` — State: `{ folders, isLoading, error, selectedFolderId, rootMediaCount }`
- `template/js/store/actions.js` — Generator-based: `fetchFolders`, `createFolder`, `updateFolder`, `deleteFolder`, `assignMedia`, `removeMedia`, `setSelectedFolder`
- `template/js/store/selectors.js` — `getFolders`, `getSelectedFolderId`, `isLoading`, `getError`, `getRootMediaCount`, `getFolderById`
- `template/js/store/resolvers.js` — Auto-fetch su `getFolders`
- `template/js/store/controls.js` — `API_FETCH` → `apiFetch(request)`
- `template/js/store/index.js` — `createReduxStore` + `register`

Strategia MVP: dopo ogni mutazione, re-fetch dell'intero albero (semplice, affidabile).

**Test:** `template/js/store/__tests__/reducer.test.js`, `selectors.test.js`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 7 — Componenti React + CSS + test

**Crea `template/js/components/FolderItem.jsx`:**
- Props: `folder`, `selectedFolderId`, `onSelect`, `depth`
- Indentazione per depth, chevron expand/collapse, color dot, nome, media count badge
- Menu azioni (rename, delete, add subfolder)
- Rendering ricorsivo children quando expanded

**Crea `template/js/components/FolderTree.jsx`:**
- "All Media" root item in cima (`selectedFolderId = null`)
- Lista `FolderItem` ricorsiva, spinner loading, error notice
- Bottone "New Folder" → apre `CreateFolderModal`

**Crea `template/js/components/CreateFolderModal.jsx`:**
- `Modal` con `TextControl` (nome), `SelectControl` (parent), bottoni Cancel/Create

**Aggiorna `template/js/components/App.jsx`:**
- Layout: `div.foldsnap-sidebar` con `FolderTree` + `div.foldsnap-content` placeholder
- Import dello store per inizializzarlo

**Crea `assets/css/foldsnap-admin.css`:**
- Classi `foldsnap-` (BEM-like): container, sidebar, content, folder-tree, folder-item, folder-item--selected, root-item
- Stile minimale, coerente con admin WP

**Test:** `__tests__/FolderItem.test.jsx`, `FolderTree.test.jsx`, `CreateFolderModal.test.jsx`, aggiornare `App.test.jsx`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 8 — Verifica finale

```bash
composer fullcheck
```

Esegue in ordine: npm build → npm lint → npm test → phpcbf → phpcs → plugin-check → phpstan → phpunit.
