# FoldSnap MVP — Folder Tree Implementation Plan

## Context

FoldSnap e' un plugin WordPress per la gestione di cartelle nella Media Library. Il framework (controllers, template engine, React setup, build toolchain, quality tools) e' gia' pronto. Questo piano implementa il primo MVP funzionante: albero cartelle, griglia media, drag & drop, ricerca.

**Approccio storage:** Custom taxonomy `foldsnap_folder` su `attachment` post type (gerarchica). Tutti i media senza associazione a un termine sono considerati nella "root" virtuale. Campi aggiuntivi (colore, posizione) salvati come `term_meta`. Conteggio diretto tramite `WP_Term->count`; conteggio ricorsivo e size totale calcolati in-memory durante la costruzione dell'albero.

---

## Panoramica file

### PHP — Nuovi (4 sorgenti)
| File | Scopo |
|------|-------|
| `src/Services/TaxonomyService.php` | Registra la taxonomy `foldsnap_folder` |
| `src/Models/FolderModel.php` | DTO: rappresenta una cartella con conversione da/a `WP_Term` |
| `src/Services/FolderRepository.php` | Astrazione DB: CRUD cartelle, assegnazione media, calcolo size |
| `src/Controllers/RestApiController.php` | Endpoint REST `foldsnap/v1` |

### PHP — Da modificare (3)
| File | Modifica |
|------|----------|
| `src/Core/Bootstrap.php` | Aggiungere `TaxonomyService::register()` e `RestApiController::getInstance()` |
| `src/Controllers/MainPageController.php` | Aggiungere `wp_localize_script()` e enqueue CSS |
| `src/Core/Uninstall.php` | Aggiungere cleanup dei termini taxonomy |

### JS — Nuovi (12)
| File | Scopo |
|------|-------|
| `template/js/store/constants.js` | Action types e store name |
| `template/js/store/reducer.js` | Reducer con default state |
| `template/js/store/actions.js` | Actions (fetch, create, update, delete, assign/remove media, fetch media) |
| `template/js/store/selectors.js` | Selectors |
| `template/js/store/resolvers.js` | Auto-fetch folders al primo accesso |
| `template/js/store/controls.js` | Control per `apiFetch` |
| `template/js/store/index.js` | Registrazione store `foldsnap/folders` |
| `template/js/components/FolderTree.jsx` | Sidebar con albero cartelle, ricerca, drag & drop |
| `template/js/components/FolderItem.jsx` | Singolo nodo cartella nel tree (draggable + droppable) |
| `template/js/components/CreateFolderModal.jsx` | Modale creazione cartella |
| `template/js/components/MediaGrid.jsx` | Griglia media con thumbnails (draggable) |
| `template/js/components/MediaItem.jsx` | Singolo media nella griglia (draggable) |

### JS — Da modificare (2)
| File | Modifica |
|------|----------|
| `template/js/components/App.jsx` | Layout sidebar + content con DndContext |
| `package.json` | Aggiungere `@wordpress/api-fetch`, `@wordpress/data`, `@dnd-kit/core`, `@dnd-kit/sortable`, `@dnd-kit/utilities` |

### CSS — Nuovo (1)
| File | Scopo |
|------|-------|
| `assets/css/foldsnap-admin.css` | Stili sidebar, folder tree, media grid, drag & drop |

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

### Step 3 — FolderRepository + test ✅

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

### Step 3b — FolderModel e FolderRepository: size, conteggi ricorsivi, sanitizzazione nomi

**Aggiorna `src/Models/FolderModel.php`:**
- Nuova proprieta' `private int $directSize = 0` (size in bytes dei media direttamente assegnati)
- `setDirectSize(int $bytes): void`
- `getDirectSize(): int`
- `getTotalMediaCount(): int` — ricorsivo: `$this->mediaCount` + somma dei `getTotalMediaCount()` di tutti i children
- `getTotalSize(): int` — ricorsivo: `$this->directSize` + somma dei `getTotalSize()` di tutti i children
- Aggiornare `toArray()` per includere `total_media_count`, `direct_size`, `total_size`

**Aggiorna `src/Services/FolderRepository.php`:**

*Sanitizzazione nomi:*
- Nuovo metodo privato `sanitizeName(string $name): string`:
  - `sanitize_text_field()` come base
  - Rimuovere caratteri pericolosi per Excel all'inizio del nome: `=`, `+`, `@`, `|`
  - Rimuovere caratteri di controllo
  - Trim whitespace
  - Limite lunghezza (250 caratteri)
  - Lanciare `InvalidArgumentException` se il nome risultante e' vuoto

*Auto-rename su conflitto:*
- Nuovo metodo privato `ensureUniqueName(string $name, int $parentId, int $excludeId = 0): string`:
  - Query `get_terms()` per cercare sibling con lo stesso nome nello stesso parent
  - Se esiste conflitto, appendere suffisso incrementale: `"nome (2)"`, `"nome (3)"`, etc.
  - Il parametro `$excludeId` serve per `update()` (escludere il termine corrente dal check)

*Applicare sanitizzazione e unicita':*
- `create()` chiama `sanitizeName()` poi `ensureUniqueName()` prima di `wp_insert_term()`
- `update()` chiama `sanitizeName()` poi `ensureUniqueName()` quando il nome cambia

*Calcolo size per cartella:*
- Nuovo metodo privato `computeFolderSizes(): array<int, int>` (mappa `folderId => bytes`):
  - Query SQL con `$wpdb->prepare()` su `wp_term_relationships` + `wp_term_taxonomy` + `wp_postmeta`
  - Legge `_wp_attachment_metadata` per ogni attachment, unserializza, estrae chiave `filesize` (disponibile da WP 6.0+, FoldSnap richiede WP 6.5+)
  - Raggruppa e somma per folder ID
- Nuovo metodo `getRootTotalSize(): int` — stessa logica per media non assegnati
- Aggiornare `getTree()`:
  1. `getAll()` → lista flat
  2. `computeFolderSizes()` → mappa size
  3. `setDirectSize()` su ogni FolderModel
  4. `buildTree()` → albero (i totali ricorsivi si calcolano automaticamente via `getTotalMediaCount()` e `getTotalSize()`)

**Test:** Aggiornare `tests/Unit/Models/FolderModelTests.php` e `tests/Feature/Services/FolderRepositoryTests.php`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 4 — RestApiController + Bootstrap + MainPageController + test

**Crea `src/Controllers/RestApiController.php`:**
- Singleton, riceve `FolderRepository` nel costruttore
- Hook su `rest_api_init` per registrare le routes
- `checkPermission(): bool` → `current_user_can('upload_files')`

| Endpoint | Handler | Note |
|----------|---------|------|
| `GET /foldsnap/v1/folders` | `getFolders` | Ritorna `{ folders: tree, root_media_count: int, root_total_size: int }` |
| `POST /foldsnap/v1/folders` | `createFolder` | `name` required, `parent_id`/`color`/`position` optional |
| `PUT /foldsnap/v1/folders/(?P<id>\d+)` | `updateFolder` | Tutti i campi opzionali |
| `DELETE /foldsnap/v1/folders/(?P<id>\d+)` | `deleteFolder` | Media tornano in root |
| `POST /foldsnap/v1/folders/(?P<id>\d+)/media` | `assignMedia` | `media_ids[]` required |
| `DELETE /foldsnap/v1/folders/(?P<id>\d+)/media` | `removeMedia` | `media_ids[]` required |
| `GET /foldsnap/v1/media` | `getMedia` | `folder_id` param (0 = unassigned), paginato |

*Endpoint `GET /foldsnap/v1/media`:*
- Parametri: `folder_id` (int, required), `page` (int, default 1), `per_page` (int, default 40)
- Se `folder_id = 0`: media senza cartella (`tax_query NOT EXISTS`)
- Se `folder_id > 0`: media nella cartella specificata (`tax_query` con term ID)
- Response: `{ media: [...], total: int, total_pages: int }`
- Ogni media item: `{ id, title, filename, thumbnail_url, url, file_size, mime_type, date }`
- Headers: `X-WP-Total`, `X-WP-TotalPages`

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

**Installa:** `npm install @wordpress/api-fetch @wordpress/data @dnd-kit/core @dnd-kit/sortable @dnd-kit/utilities`

**Crea store files:**
- `template/js/store/constants.js` — `STORE_NAME = 'foldsnap/folders'` + action types
- `template/js/store/reducer.js` — State: `{ folders, isLoading, error, selectedFolderId, rootMediaCount, rootTotalSize, media, mediaTotal, mediaTotalPages, mediaIsLoading, searchQuery }`
- `template/js/store/actions.js` — Generator-based: `fetchFolders`, `createFolder`, `updateFolder`, `deleteFolder`, `assignMedia`, `removeMedia`, `setSelectedFolder`, `fetchMedia`, `setSearchQuery`
- `template/js/store/selectors.js` — `getFolders`, `getSelectedFolderId`, `isLoading`, `getError`, `getRootMediaCount`, `getRootTotalSize`, `getFolderById`, `getMedia`, `isMediaLoading`, `getMediaTotal`, `getMediaTotalPages`, `getSearchQuery`, `getFilteredFolders`
- `template/js/store/resolvers.js` — Auto-fetch su `getFolders`
- `template/js/store/controls.js` — `API_FETCH` → `apiFetch(request)`
- `template/js/store/index.js` — `createReduxStore` + `register`

`getFilteredFolders` selector: filtra l'albero cartelle client-side in base a `searchQuery` (match case-insensitive sul nome). Se una sottocartella matcha, include anche i suoi antenati nel risultato.

Strategia MVP: dopo ogni mutazione, re-fetch dell'intero albero (semplice, affidabile). Dopo assign/remove media, re-fetch anche la lista media.

**Test:** `template/js/store/__tests__/reducer.test.js`, `selectors.test.js`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 7 — Folder tree + ricerca + drag & drop cartelle + CSS + test

**Crea `template/js/components/FolderItem.jsx`:**
- Props: `folder`, `selectedFolderId`, `onSelect`, `depth`
- Indentazione per depth, chevron expand/collapse, color dot, nome, total media count badge, size formattato
- Menu azioni (rename, delete, add subfolder)
- Rendering ricorsivo children quando expanded
- `useSortable()` da `@dnd-kit/sortable` per drag & drop reordering tra sibling
- `useDroppable()` per accettare drop di media items (assegnazione) e altre cartelle (reparenting)
- Feedback visivo su drag over (highlight, indicatore posizione)

**Crea `template/js/components/FolderTree.jsx`:**
- `TextControl` in cima per ricerca cartelle (filtra via `getFilteredFolders` selector)
- "All Media" root item (`selectedFolderId = null`) con conteggio totale
- Lista `FolderItem` ricorsiva, spinner loading, error notice
- Bottone "New Folder" → apre `CreateFolderModal`
- `SortableContext` da `@dnd-kit/sortable` per gestire il reordering delle cartelle root-level

**Crea `template/js/components/CreateFolderModal.jsx`:**
- `Modal` con `TextControl` (nome), `SelectControl` (parent), bottoni Cancel/Create

**Aggiorna `template/js/components/App.jsx`:**
- `DndContext` da `@dnd-kit/core` come wrapper principale
- Layout: `div.foldsnap-sidebar` con `FolderTree` + `div.foldsnap-content` con `MediaGrid`
- Import dello store per inizializzarlo
- Handler `onDragEnd`: gestisce drop di cartelle (reorder/reparent) e drop di media su cartelle (assign)

**Crea `assets/css/foldsnap-admin.css`:**
- Classi `foldsnap-` (BEM-like): container, sidebar, content, folder-tree, folder-item, folder-item--selected, folder-item--drag-over, root-item, media-grid, media-item, media-item--dragging, search-input
- Stile minimale, coerente con admin WP
- Feedback visivo per drag & drop (opacita', bordi, sfondo highlight)

**Test:** `__tests__/FolderItem.test.jsx`, `FolderTree.test.jsx`, `CreateFolderModal.test.jsx`, aggiornare `App.test.jsx`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 8 — Media grid + drag & drop media + test

**Crea `template/js/components/MediaGrid.jsx`:**
- Usa `useSelect` per leggere `getMedia`, `isMediaLoading`, `getMediaTotal`, `getMediaTotalPages` dallo store
- Fetcha media quando `selectedFolderId` cambia (via `useEffect` + `fetchMedia` action)
- Griglia responsive di `MediaItem` componenti
- Paginazione in fondo (bottoni Previous/Next o pagine numerate)
- Stato vuoto: messaggio "No media in this folder" o "Drop media here"
- Spinner durante il caricamento

**Crea `template/js/components/MediaItem.jsx`:**
- Props: `media` (singolo oggetto media)
- Mostra: thumbnail (immagine o icona per tipo mime), titolo, file size formattato
- `useDraggable()` da `@dnd-kit/core` per drag verso cartelle nella sidebar
- Supporto selezione multipla: click per selezionare, shift+click per range, ctrl+click per toggle
- Gli item selezionati si draggano insieme (bulk drag)
- Feedback visivo durante il drag (opacita', badge con conteggio se multipli)

**Aggiornare `assets/css/foldsnap-admin.css`:**
- Stili media grid: layout griglia, thumbnail sizing, hover states
- Stili selezione: bordo/sfondo per item selezionati
- Stili drag: ghost element, drop zone indicators

**Test:** `__tests__/MediaGrid.test.jsx`, `MediaItem.test.jsx`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 9 — Contatori incrementali (size + count) + hook cancellazione media

**Obiettivo:** Eliminare le query aggregate pesanti (`computeFolderSizes`, `getRootTotalSize`) che fanno JOIN su migliaia di righe ad ogni `getTree()`. Sostituirle con contatori incrementali salvati come `term_meta` e aggiornati solo quando i dati cambiano.

**Nuovi term meta:**
- `foldsnap_folder_size` (int) — size totale ricorsivo in bytes (cartella + tutti i discendenti)
- `foldsnap_folder_count` (int) — conteggio totale ricorsivo dei media (cartella + tutti i discendenti). Diverso da `WP_Term::count` che conta solo i media direttamente assegnati

**Aggiorna `src/Services/FolderRepository.php`:**

*Aggiornamento contatori su mutazioni:*
- `assignMedia()`: dopo `wp_set_object_terms()`, per ogni media leggere filesize da `_wp_attachment_metadata`, poi aggiornare `foldsnap_folder_size` (+bytes) e `foldsnap_folder_count` (+1) sulla cartella target e su tutti i suoi antenati
- `removeMedia()`: stessa logica ma sottraendo
- Nuovo metodo privato `getMediaFileSize(int $mediaId): int` — legge `_wp_attachment_metadata`, estrae `filesize`
- Nuovo metodo privato `updateAncestorCounters(int $folderId, int $sizeDelta, int $countDelta): void` — risale il ramo dei parent e aggiorna entrambi i contatori su ciascun antenato

*Hook cancellazione media:*
- Hook su `before_delete_post` (o `delete_attachment`): se il post e' un attachment associato a una cartella, sottrarre filesize e conteggio dalla cartella e da tutti gli antenati
- Registrare l'hook in `Bootstrap::onInit()`

*Aggiorna `getTree()`:*
- Leggere `foldsnap_folder_size` e `foldsnap_folder_count` dal term meta invece di chiamare `computeFolderSizes()` e calcolare ricorsivamente in-memory
- Rimuovere `computeFolderSizes()` e le relative query in `Database`
- Rimuovere `getTotalMediaCount()` e `getTotalSize()` ricorsivi da `FolderModel` (i totali vengono dal term meta)

*Ricalcolo globale (safety net):*
- Nuovo metodo `recalculateAllCounters(): void` — ricalcola tutti i size e count da zero interrogando `_wp_attachment_metadata` e `WP_Term::count`
- Esporre come endpoint REST o WP-CLI command per uso manuale/schedulato

**Test:** Aggiornare i test di `FolderRepositoryTests.php` per verificare che `assignMedia`/`removeMedia` aggiornino size e count, aggiungere test per l'hook di cancellazione e per il ricalcolo globale

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 10 — Verifica finale

```bash
composer fullcheck
```

Esegue in ordine: npm build → npm lint → npm test → phpcbf → phpcs → plugin-check → phpstan → phpunit.
