# FoldSnap MVP — Folder Tree Implementation Plan

## Context

FoldSnap e' un plugin WordPress per la gestione di cartelle nella Media Library. Il framework (controllers, template engine, React setup, build toolchain, quality tools) e' gia' pronto. Questo piano implementa il primo MVP funzionante: albero cartelle, griglia media, drag & drop, ricerca.

**Approccio storage:** Custom taxonomy `foldsnap_folder` su `attachment` post type (gerarchica). Tutti i media senza associazione a un termine sono considerati nella "root" virtuale. Campi aggiuntivi (colore, posizione) salvati come `term_meta`. Conteggio diretto tramite `WP_Term->count`; conteggio ricorsivo e size totale calcolati in-memory durante la costruzione dell'albero.

---

## Stato attuale (allineamento con il codice — aggiornato 2026-05-09)

Questa sezione riflette lo stato del codice oggi. Le sezioni "Panoramica file" e "Step di implementazione" sotto sono storiche: descrivono l'intento del piano al momento della scrittura, non lo stato finale.

### Stato per step

| Step | Stato | Note |
|------|-------|------|
| 0 — Preparazione | ✅ Completato | |
| 1 — TaxonomyService + Bootstrap | ✅ Completato | |
| 2 — FolderModel | ✅ Completato | Modello rifattorizzato in Step 8 (rimossi `addChild`, `children[]`, `directSize`, totali ricorsivi) |
| 3 — FolderRepository | ✅ Completato | Refattorizzato in Step 8 (rimossi `getTree`, `buildTree`, `computeFolderSizes`) |
| 3b — Size + sanitizzazione nomi | ✅ Completato | Sanitizzazione estratta in `FolderNameSanitizer` (service dedicato) |
| 4 — RestApiController + endpoint | ✅ Completato | Controller splittato in `RestApiController`, `RestApiFolderMutationsController`, `RestApiFolderPresenter`, `RestApiRequestUtils` |
| 5 — Store @wordpress/data | ✅ Completato | Stato shape totalmente rivisto in Step 8; **slice `media` poi rimossa** (vedi Step 7) |
| 6 — Folder tree + ricerca + DnD | ✅ Completato | |
| 6b — Integrazione Media Library nativa | ✅ Completato | `App.jsx` rimosso, mount diretto su `#foldsnap-sidebar` |
| 7 — Media grid React | ⚠️ Deviazione | `MediaGrid.jsx`/`MediaItem.jsx` **rimossi** dal commit `b51fdd3` (2026-05-09). La griglia visibile è la Backbone nativa di WP; il DnD media→folder gira via `template/js/foldsnap-dragdrop.js`. Decisione coerente con TODO-6 del piano (opzione "rimuovere") |
| 8 — Architettura pulita (DTO + API flat) | ✅ Completato | |
| 9 — Contatori incrementali | ✅ Completato | Logica spalmata su `FolderCounterService`, `CountersRecalculator`, `AttachmentLifecycleService`, `MediaFolderAssignmentService` |
| 10 — Uninstall cleanup | ⚠️ Aperto | `src/Core/Uninstall.php` pulisce options, transients e disarma il cron `foldsnap_recalc_chunk`, ma **NON** chiama `deleteTaxonomyTerms()`. I termini `foldsnap_folder` e i loro term meta restano in DB dopo uninstall. Da chiudere se si vuole completare lo step come scritto |
| 11 — Verifica finale | n/a | Verifica continua via `composer fullcheck` |

### File aggiunti rispetto al piano originale

**PHP** (oltre ai 4 previsti in "Panoramica file"):
- `src/Controllers/MediaLibraryController.php` (Step 6b)
- `src/Controllers/RestApiFolderMutationsController.php`, `RestApiFolderPresenter.php`, `RestApiRequestUtils.php` (refactor di Step 4)
- `src/Services/FolderNameSanitizer.php` (estratto da Step 3b)
- `src/Services/FolderTreeNavigator.php` (Step 8)
- `src/Services/MediaFolderAssignmentService.php` (estratto da `FolderRepository`)
- `src/Services/FolderCounterService.php`, `CountersRecalculator.php`, `AttachmentLifecycleService.php` (Step 9)
- `src/Services/Database.php` (helper SQL condiviso, Step 8/9)

**JS** (oltre ai 12 previsti in "Panoramica file"):
- `template/js/components/FolderSidebar.jsx` (sostituisce `App.jsx` in Step 6b)
- `template/js/components/FolderPicker.jsx` (estratto per CreateFolderModal con lazy load)
- `template/js/components/SearchResultsList.jsx` (Step 8B)
- `template/js/store/persistence.js` (Step 8B)
- `template/js/utils/build-children-map.js`, `format-size.js` (Step 8B + utility)
- `template/js/hooks/useDebouncedCallback.js`, `useLocalFolderSearch.js`
- `template/js/services/media-mode-bridge.js` (bridge React store ↔ Backbone), `grid-reflector.js`, `list-mode-redirector.js`, `view-switch-links.js`
- `template/js/foldsnap-dragdrop.js` (jQuery UI bridge per DnD su griglia nativa, Step 7 sostitutivo)

**File previsti dal piano ma rimossi/non più presenti:**
- `template/js/components/App.jsx` (rimosso in Step 6b come previsto)
- `template/js/components/MediaGrid.jsx`, `MediaItem.jsx` e relativi test (rimossi in commit `b51fdd3`, deviazione da Step 7)
- Slice `media` dello store: state (`media`, `mediaTotal`, `mediaTotalPages`, `mediaIsLoading`), action `fetchMedia`, selectors `getMedia`/`isMediaLoading`/`getMediaTotal`/`getMediaTotalPages` (rimossi insieme alla MediaGrid React)

### Punto attuale

Il piano è funzionalmente completo. La branch corrente lavora su rifiniture UI (refactor bootstrap, modularizzazione service bridge, miglioramenti UX della sidebar). Restano aperti:

1. **Step 10** — `deleteTaxonomyTerms()` in Uninstall (vedi tabella sopra)
2. I **TODO post-MVP** (1–14) elencati in fondo al documento

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

### Step 3b — FolderModel e FolderRepository: size, conteggi ricorsivi, sanitizzazione nomi ✅

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

### Step 4 — RestApiController + Bootstrap + MainPageController + test ✅

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

### Step 5 — Dipendenze npm + Store @wordpress/data + test

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

### Step 6 — Folder tree + ricerca + drag & drop cartelle + CSS + test

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

### Step 6b — Integrazione Media Library nativa + refactoring entry point

**Obiettivo:** Iniettare `FolderTree` direttamente nella Media Library nativa di WordPress (`upload.php`), usando lo stesso pattern di FileBird: hook PHP su `upload.php`, container iniettato nel DOM, filtro media via `wp.media` Backbone. Eliminare `App.jsx` come wrapper standalone (era solo un test).

**Crea `src/Controllers/MediaLibraryController.php`:**
- Singleton, registrato in `Bootstrap::onInit()` prima di `is_admin()`
- Hook `admin_enqueue_scripts`: controlla `get_current_screen()->id === 'upload'`, enqueue script/style solo su quella pagina
- `wp_localize_script('foldsnap-admin', 'foldsnap_data', ['restUrl' => ..., 'restNonce' => ...])`
- Hook `admin_footer` su `upload.php`: stampa `<div id="foldsnap-sidebar"></div>` nel body (il JS lo posizionerà)
- Nessun menu WordPress, nessuna pagina admin — solo asset injection

**Aggiorna `src/Core/Bootstrap.php`:**
- Aggiungere `MediaLibraryController::getInstance()` in `onInit()` prima di `is_admin()`
- Lasciare `MainPageController` invariato (servirà per settings futuri)

**Aggiorna `template/js/index.js`:**
- Rimuovere mount su `#foldsnap-app` (era il test)
- Montare `FolderTree` su `#foldsnap-sidebar` (iniettato dal PHP nel footer di `upload.php`)
- Inizializzare `DndContext` attorno a `FolderTree` direttamente qui
- Estendere `wp.media` Backbone per intercettare la selezione cartella e filtrare la griglia nativa:
  - Override di `wp.media.view.Attachments.initialize` per iniettare `foldsnap_folder_id` nei `props` della collection
  - Quando `setSelectedFolder` viene chiamato dallo store, aggiorna `collection.props.set({ foldsnap_folder_id: id })` → WordPress re-fetcha i media filtrati

**Aggiorna `src/Controllers/MainPageController.php`:**
- Rimuovere `renderContent()` (il `#foldsnap-app` non esiste piu')
- Rimuovere `pageScripts()` e `pageStyles()` (gli asset ora li gestisce `MediaLibraryController`)
- Lasciare il controller vuoto/minimal — servirà come placeholder per la pagina settings futura

**Elimina `template/js/components/App.jsx` e `__tests__/App.test.jsx`:**
- Era solo un test di bootstrap React, non fa parte dell'architettura finale

**Aggiorna `assets/css/foldsnap-admin.css`:**
- `.foldsnap-sidebar` deve posizionarsi come pannello laterale sinistro nella Media Library
- Override stili WordPress per accomodare la sidebar (`.media-frame` layout)

**Test:** `tests/Unit/Controllers/MediaLibraryControllerTests.php`
- Verifica che gli script vengano enqueued solo su `upload.php`
- Verifica che `wp_localize_script` venga chiamato con i dati corretti

**Verifica:** `composer fullcheck`

### Step 7 — Media grid + drag & drop media + test ⚠️ DEVIATO

**Stato (2026-05-09)**: implementato originariamente come previsto, poi **rimosso** nel commit `b51fdd3`. `MediaGrid.jsx`, `MediaItem.jsx` e la slice `media` dello store sono stati eliminati a favore della griglia Backbone nativa di WordPress. Il DnD media→folder gira via `template/js/foldsnap-dragdrop.js` (jQuery UI bridge sulla griglia nativa). Decisione coerente con TODO-6. La descrizione qui sotto è storica.


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

---

## ⚠️ Cambio di rotta dopo Step 7

Dopo lo Step 7 il piano originale prevedeva: Step 8 = contatori incrementali, Step 9 = uninstall, Step 10 = verifica finale.

**Decisione presa**: invertire l'ordine. Prima si pulisce l'architettura (DTO puro + API flat con lazy load + frontend ridisegnato), poi si introducono i contatori incrementali. Motivazioni:

1. `FolderModel` attuale è un DTO impuro (ha `addChild`, `children[]` mutabile, totali ricorsivi sui children) — scelta sbagliata da rifare
2. `GET /folders` ritorna l'albero ricorsivo intero: non scala su siti con migliaia di cartelle
3. Il frontend si aspetta l'albero completo: ogni mutazione re-fetcha tutto, lo stato di espansione si perde a ogni reload
4. Implementare i contatori incrementali sopra un'architettura sbagliata significherebbe rifare poi il lavoro

Lo Step 8 è grosso (backend + frontend riscritti) ma viene mergiato in **una sola PR**: A e B sono sotto-step organizzativi di un unico delivery, non punti di merge intermedi. Il sistema non viene mai pubblicato in stato rotto.

Numerazione aggiornata:
- **Step 8** = Architettura pulita (era: contatori)
- **Step 9** = Contatori incrementali (era: Step 8)
- **Step 10** = Uninstall cleanup (era: Step 9)
- **Step 11** = Verifica finale (era: Step 10)

---

### Step 8 — Architettura pulita: DTO puro + API flat con lazy load

**Obiettivo**: trasformare `FolderModel` in un DTO immutabile, eliminare l'albero ricorsivo server-side, esporre API flat con lazy load on-expand, ridisegnare lo store frontend con persistenza dell'expansion state.

#### Sotto-step A — Backend

**Crea `src/Services/FolderTreeNavigator.php`** (nuovo):
- `computeTotals(FolderModel[] $folders): array` — calcola `total_media_count` e `total_size` per le folder richieste, scendendo nei discendenti via `Database::getDescendantIds()`
- `hasChildren(int[] $folderIds): array<int, bool>` — singola query GROUP BY parent
- `resolvePath(int $folderId): FolderModel[]` — catena root→target

**Vincolo SQL**: usare solo costrutti MySQL/MariaDB di base (SELECT, JOIN, WHERE, GROUP BY, IN). Niente CTE ricorsive, niente window functions, niente JSON functions avanzate. Il plugin deve girare su qualsiasi hosting WordPress, anche con MySQL datati. Per le discese ricorsive usare iterazione applicativa con query batched per livello.

**Refactor `src/Models/FolderModel.php`**:
- Rimuovere proprietà `children[]` e `directSize`
- Rimuovere metodi `addChild`, `getChildren`, `getTotalMediaCount`, `getTotalSize`, `setDirectSize`, `getDirectSize`
- `toArray()` ritorna SOLO le 7 proprietà core (`id`, `name`, `slug`, `parent_id`, `media_count`, `color`, `position`). I totali e `has_children` sono decorati a livello controller, non dal modello.

**Refactor `src/Services/FolderRepository.php`**:
- Rimuovere `getTree()`, `buildTree()`, `computeFolderSizes()`, `extractFileSize()`, `sumFileSizesFromMeta()`, costante `CACHE_FOLDER_SIZES`
- Aggiungere `getByParents(int[] $parentIds): FolderModel[]` (`WP_Term_Query` con `parent__in`)
- Aggiungere `getByParent(int $parentId): FolderModel[]` (wrapper)
- Aggiungere `search(string $query, int $page, int $perPage): array` — `WP_Term_Query` con `name__like`, paginato. Ritorna `{ folders, total, total_pages }`
- Aggiungere `getPath(int $folderId): FolderModel[]` (delega a navigator)

**Niente `getAllFlat()` / `getAllFoldersFlat()`**: qualsiasi accesso "tutte le cartelle" deve essere paginato (`page`, `per_page`). CreateFolderModal usa lazy load on-expand sul SelectControl/combobox: si parte dai root con paginazione e si espandono i parent on-demand, stesso pattern del FolderTree principale.

**Refactor `src/Services/Database.php`**:
- Aggiungere `getDescendantIds(int[] $rootIds, string $taxonomy): array<int, int[]>` — implementazione iterativa BFS: una query per livello (`SELECT term_id, parent FROM wp_term_taxonomy WHERE taxonomy = ? AND parent IN (...)`), si itera finché non ci sono nuovi figli. Niente CTE.
- Aggiungere `getChildrenCounts(int[] $parentIds, string $taxonomy): array<int, int>`
- Aggiungere `getDirectSizesForFolders(int[] $folderIds, string $taxonomy): array<int, int>` (versione mirata della vecchia query)

**Refactor `src/Controllers/RestApiController.php`**:
- `getFolders` riscritto con due modalità mutuamente esclusive, entrambe paginate:
  - **Children fetch** (default): `?parent_ids[]=0&parent_ids[]=42&page=1&per_page=100` → `{ mode: "children", folders: [...], root_media_count, root_total_size, requested_parent_ids, total, total_pages }`
  - **Search**: `?search=foo&page=1&per_page=50` → `{ mode: "search", results: [{folder, breadcrumb}], query, total, total_pages }`
- Nuovo handler `GET /folders/{id}/path` → `{ path: [...] }`
- Niente endpoint `all-flat`: il dropdown parent in CreateFolderModal usa lo stesso `getFolders` paginato con lazy expand
- Response di mutazioni (`POST /folders`, `PUT /folders/{id}`, `DELETE /folders/{id}`, `assign/removeMedia`) include in modo uniforme:
  - `affected_parents`: `[{id, has_children}]` — per refresh chevron sui parent vecchio/nuovo
  - `path`: catena antenati aggiornata con i totali — per refresh client-side

**Test PHP**:
- Rimuovere test obsoleti in `FolderModelTests` (addChild, totali ricorsivi, direct_size) e in `FolderRepositoryTests` (test_get_tree_*)
- Aggiungere `FolderTreeNavigatorTests` (Feature)
- Aggiornare `RestApiControllerTests` per i 3 modi + nuovi endpoint
- Stima: ~30 test rimossi/riscritti, ~40 test nuovi

**Verifica A** (interna allo step, non punto di merge): `composer phpunit` + `composer fullcheck`. Il frontend è temporaneamente disallineato (chiama API vecchie); si procede direttamente al sotto-step B nello stesso PR.

#### Sotto-step B — Frontend

**Crea `template/js/store/persistence.js`** (nuovo):
- `loadExpandedIds()` / `saveExpandedIds(ids)` con localStorage key `foldsnap.expandedFolders`
- Tollerante a JSON malformato e quotaExceeded

**Crea `template/js/utils/build-children-map.js`** (nuovo):
- Helper puro per aggiornare immutabilmente `state.foldersByParent[parentId]`

**Crea `template/js/components/SearchResultsList.jsx`** (nuovo):
- Lista risultati search con breadcrumb. Click → `setSelectedFolder` + `expandPathTo` + clear search

**Refactor `template/js/store/reducer.js`** — nuovo state shape:
```
{
  foldersByParent: {},      // Record<int, FolderModel[]>
  foldersById: {},          // Record<int, FolderModel> per O(1) lookup
  loadedParents: [],        // int[] — parent fetchati
  expandedIds: [],          // int[] — persistito in localStorage
  fetchingParents: [],      // int[] — dedupe
  parentsPagination: {},    // Record<int, {page, totalPages}> — paginazione children per parent
  selectedFolderId, rootMediaCount, rootTotalSize,
  searchQuery, searchResults, searchPage, searchTotalPages, searchIsLoading,
  isLoading, error,
  media, mediaTotal, mediaTotalPages, mediaIsLoading
}
```

**Refactor `template/js/store/actions.js`**:
- `fetchChildren(parentId, page = 1)` — generator, dedupe via `loadedParents`/`fetchingParents`, paginato
- `fetchChildrenBatch(parentIds[])` — single-request multi-parent (prima pagina)
- `loadMoreChildren(parentId)` — pagina successiva quando l'utente scrolla/clicca "load more"
- `expandFolder(id)` / `collapseFolder(id)` — aggiornano `expandedIds` + persistenza
- `expandPathTo(id)` — GET path + fetchChildrenBatch (2 request totali)
- `setSearchQuery(query)` — debounced 300ms in component (non reducer)
- `loadMoreSearchResults()` — pagina successiva search
- `createFolder` — dopo POST: applica `affected_parents` (aggiorna `has_children`) e re-fetcha la pagina del parent. Niente cache flat da invalidare
- `updateFolder` — applica `affected_parents` per vecchio + nuovo parent se reparent
- `deleteFolder` — rimuovi da foldersById/foldersByParent/expandedIds/loadedParents (ricorsivo per discendenti); applica `affected_parents`
- `assignMedia` / `removeMedia` — dopo mutation: rimpiazza nel store i FolderModel degli antenati con i totali aggiornati (response include `path`)

**Refactor `template/js/store/selectors.js`**:
- Nuovi: `getRootFolders`, `getChildrenOf`, `getFolderById` (O(1)), `isFolderExpanded`, `isFolderLoaded`, `isFolderFetching`, `getSearchResults`, `getParentPagination(parentId)`, `getSearchPagination`
- Rimosso: `getFolders`, `getFilteredFolders`

**Refactor `template/js/components/FolderTree.jsx`**:
- Niente `getFilteredFolders`. `searchQuery` non vuota → `<SearchResultsList>`. Vuota → `getRootFolders()` + `<FolderItem>`
- Debounce 300ms su TextControl ricerca

**Refactor `template/js/components/FolderItem.jsx`**:
- Prop ora è `folderId` (non `folder`)
- `isExpanded` viene da `isFolderExpanded(folderId)` selector (niente `useState` locale)
- Children da `getChildrenOf(folderId)` (niente `folder.children`)
- Click chevron → `expandFolder` / `collapseFolder` action
- Spinner se `isFetching && !isLoaded`

**Refactor `template/js/components/CreateFolderModal.jsx`**:
- Picker del parent basato su lazy-loaded tree (stesso store del FolderTree principale): mostra i root, espandibili on-demand. Niente caricamento di tutte le cartelle in memoria.
- Per UX su molte cartelle: input di ricerca dentro il picker che riusa l'endpoint search paginato

**Refactor `template/js/services/media-mode-bridge.js`**:
- Al boot, se URL ha `?foldsnap_folder_id=N`: `setSelectedFolder(N)` + `expandPathTo(N)`

**Test JS**:
- Aggiornare `reducer.test.js`, `actions.test.js`, `selectors.test.js`, `FolderTree.test.jsx`, `FolderItem.test.jsx`, `CreateFolderModal.test.jsx`
- Nuovi: `persistence.test.js`, `build-children-map.test.js`, `SearchResultsList.test.jsx`
- Invariati: `MediaGrid.test.jsx`, `MediaItem.test.jsx`, `FolderSidebar.test.jsx`

**Verifica B**: `npm test` + `composer fullcheck` + verifica manuale end-to-end (boot, persistenza expansion, deep-link, search, create/move/delete, assign media, performance con 1000 folder).

### Step 9 — Contatori incrementali (size + count) + recalculate chunked + hook cancellazione media

**Obiettivo**: sostituire il calcolo on-demand di `total_media_count`/`total_size` (oggi via `FolderTreeNavigator::computeTotals()` introdotto nello Step 8) con term meta letti in O(1). La firma API resta identica — cambia solo l'implementazione interna.

**Invariante bottom-up**: per ogni cartella vale

```
total_count(f) = direct_count(f) + Σ total_count(child)   ∀ child ∈ children(f)
total_size(f)  = direct_size(f)  + Σ total_size(child)    ∀ child ∈ children(f)
```

con direct = media direttamente assegnati alla cartella (non ai discendenti). Per la Root:

```
total_count(Root) = direct_count_unassigned + Σ total_count(child)   ∀ child top-level
total_size(Root)  = direct_size_unassigned  + Σ total_size(child)    ∀ child top-level
```

Tutti i meccanismi (incrementale e recalculate) preservano questa invariante.

**Semantica counters Root** (chiarimento): `foldsnap_opt_root_count` e `foldsnap_opt_root_size` rappresentano i totali **globali del sito** (tutti gli attachment, equivalente a `direct_unassigned + Σ total(top-level)`). Quindi:
- `add_attachment` → root counters +
- `delete_attachment` → root counters −
- assign / remove / reparent / delete-folder → root counters **invariati** (un media spostato è ancora dentro Root)

**Nuovi term meta**:
- `foldsnap_folder_size` (int) — size totale ricorsivo in bytes
- `foldsnap_folder_count` (int) — conteggio totale ricorsivo dei media

**Nuove opzioni** (per la root, che non ha term_id):
- `foldsnap_opt_root_size`, `foldsnap_opt_root_count`

**Aggiorna `src/Services/FolderRepository.php`**:

*Aggiornamento incrementale bulk-aware su mutazioni*:

Tutte le mutazioni devono fare **una singola query SQL per aggiornare tutti i contatori coinvolti**, non N×D `update_term_meta`. Pattern:

```sql
UPDATE wp_termmeta
SET meta_value = CAST(meta_value AS SIGNED) + ?
WHERE term_id IN (...) AND meta_key = ?
```

*Normalizzazione meta — pre-condizione per il bulk update*:

`wp_termmeta` non ha un UNIQUE constraint su `(term_id, meta_key)` (PRIMARY KEY è solo `meta_id`), quindi `INSERT ... ON DUPLICATE KEY UPDATE` non è utilizzabile per upsert. Per evitare branch query ed avere bulk UPDATE sempre safe: **tutti i 4 term meta sono inizializzati esplicitamente per ogni folder, sempre**.

- `foldsnap_folder_color`, `foldsnap_folder_position`, `foldsnap_folder_size`, `foldsnap_folder_count`
- `create()`: chiama `add_term_meta()` per i 4 meta con valori di default (color vuoto, position 0, size 0, count 0) subito dopo `wp_insert_term()`
- Migrazione iniziale (`recalculateChunk` primo run, offset=0): per ogni termine esistente che non ha già tutti i 4 meta, li crea con valori 0
- Da quel momento ogni UPDATE bulk con `WHERE term_id IN (...) AND meta_key = ?` aggiorna sempre N righe esistenti, mai 0

Questo semplifica drasticamente: niente check di esistenza, niente fallback INSERT, niente race su upsert. Una sola query UPDATE per ogni delta.

- `assignMedia(folderId, mediaIds[])`:
  1. **Pre-filtro idempotenza**: leggi gli attuali term di ogni media; escludi dal calcolo i media già assegnati a `folderId` (delta sia origine sia destinazione = 0 per loro). Solo i media che cambiano davvero folder contribuiscono ai delta.
  2. Per i media che si muovono: calcola `totalSizeDelta` e `totalCountDelta` sommando filesize
  3. Identifica i media già assegnati altrove (raggruppa per folder origine)
  4. Per ogni gruppo origine: una singola UPDATE sui meta degli antenati di origine con il delta negativo aggregato
  5. Una singola UPDATE sui meta degli antenati del target con il delta positivo aggregato
  6. **Root counters globali invariati**: il media resta dentro Root, cambia solo la sua posizione nel sottoalbero
- `removeMedia(folderId, mediaIds[])`:
  - Calcola `totalSizeDelta` e `totalCountDelta` sui media effettivamente associati a `folderId`
  - Singola UPDATE sui meta degli antenati di `folderId` con delta negativo
  - Root counters globali **invariati**: il media diventa unassigned (passa da "in sottocartella" a "diretto in Root"), ma resta nel totale globale di Root
- `update()` con `parent_id` cambiato (reparent di una folder X):
  - Legge `total_size(X)` e `total_count(X)` (l'intero sottoalbero spostato)
  - Singola UPDATE sui meta degli antenati di **vecchia** catena (escludendo X stessa e i suoi discendenti) con delta negativo
  - Singola UPDATE sui meta degli antenati di **nuova** catena con delta positivo
  - I counter di X e dei suoi discendenti restano invariati: il sottoalbero non cambia, cambiano solo gli antenati che lo aggregano
  - Spostamento di un sottoalbero con migliaia di media: O(D) UPDATE, non O(N×D)
  - Root counters globali **invariati**
- `delete()` di una folder X:
  - Pre-delete: leggi `direct_size(X)` e `direct_count(X)` (i media diretti di X tornano in Root come unassigned dopo `wp_delete_term`)
  - Singola UPDATE sui meta degli antenati di X (esclusa X) con delta negativo `(direct_size(X), direct_count(X))`
  - Le sottocartelle di X vengono promosse al grandparent da `wp_delete_term`. I loro counter `total_*` restano invariati (sottoalberi intatti); gli antenati nuovi (= antenati di X) **già tengono conto** di quei sottoalberi perché prima li contavano via X. Quindi nessun delta da applicare per il movimento delle sottocartelle.
  - I term meta di X vengono cancellati automaticamente da `wp_delete_term`
  - Root counters globali **invariati**
- Nuovo `getMediaFileSizes(int[] $mediaIds): array<int, int>` — bulk lookup
- Nuovo `bulkAdjustCounters(int[] $termIds, int $sizeDelta, int $countDelta): void` — singola UPDATE
- Nuovo `updateRootCounters(int $sizeDelta, int $countDelta): void` — usato solo dagli hook attachment lifecycle (add/delete), MAI dalle mutazioni folder

*Hook ciclo di vita attachment — strategia deferred batch*:

Per evitare N hook = N×D write su bulk operations, usare batching deferred su entrambi gli hook:

- Filter `wp_generate_attachment_metadata` (priority 10, args 3): fires una sola volta per upload, dopo che `_wp_attachment_metadata` è già scritto in DB con `filesize`. Si controlla `$context === 'create'` per distinguere upload iniziale da rigenerazioni (Regenerate Thumbnails et al.). Si accumula `{id, filesize}` in una static queue `$pendingAdditions[]`. Il filter va sempre ritornato invariato. Il media nasce non assegnato, quindi tocca solo i root counters globali.
- Hook `delete_attachment` (priority 10): legge folder + filesize del media (a questo punto il post c'è ancora e il metadata è leggibile) e li accumula in `$pendingDeletions[]`
- Hook `shutdown` (priority bassa): processa entrambe le queue:
  - additions → singola UPDATE su `foldsnap_opt_root_*` (sommando i delta)
  - deletions → raggruppa per folder origine, una `bulkAdjustCounters` per ogni catena di antenati toccata + UPDATE root counters
- Se la richiesta termina anormalmente prima dello shutdown: il drift viene corretto dal `recalculateChunk` (safety net)

Note sugli hook scartati e perché:
- `add_attachment` fires troppo presto: `_wp_attachment_metadata` non è ancora stato generato, `filesize` non leggibile. Inutilizzabile per il size delta.
- `wp_update_attachment_metadata` (filter) fires N volte (una per subsize) dopo WP 5.3, non è un buon segnale di "upload complete".
- `updated_post_meta` con key-filter su `_wp_attachment_metadata` fires multiple volte per upload immagine; più scomodo del filtro `wp_generate_attachment_metadata`.

*Aggiorna lettura totali*:
- `FolderTreeNavigator::computeTotals()` ora legge dai term meta invece di scendere nei discendenti — operazione O(1) per folder
- Rimuovere `Database::getDescendantIds()` se non più usato altrove
- Rimuovere `Database::getDirectSizesForFolders()` (sostituito dai meta)

*Ricalcolo chunked (safety net + migrazione iniziale) — strategia bottom-up con pila LIFO*:

Strategia: post-order traversal sull'albero delle cartelle implementato come **pila LIFO persistita**. Si parte dalle foglie, si calcola `total = direct + Σ total(figli)` per ogni nodo (i figli sono già stati calcolati), si scrivono i meta del nodo, si sale al parent. **Niente reset globale a inizio passata**: ogni nodo si auto-aggiorna con il suo valore corretto. Idempotente: rieseguire produce esattamente gli stessi numeri.

Insight: una BFS top-down dalla Root, salvando gli id nell'ordine in cui vengono scoperti, produce una lista a **profondità crescente** (Root prima, foglie ultime). Usata come stack LIFO → `pop()` restituisce le foglie per prime, poi i parent, poi la Root: esattamente il post-order che serve. Niente offset, niente cursori: lo stato della migrazione è la pila stessa.

Algoritmo:
1. **Build della pila (una sola volta, al primo chunk)**: BFS top-down su `foldsnap_folder` partendo da `parent = 0`, una query per livello (stesso pattern di `Database::descendantsOfSingleRoot`). Append degli id man mano che si scoprono. Risultato: array `[top-level..., livello1..., livello2..., foglie...]`. Salvato in option `foldsnap_opt_recalc_stack` (array di int — option WP gestisce la serializzazione).
   Normalizzazione meta in questa stessa fase: per ogni term scoperto, `add_term_meta` con valori 0 per `foldsnap_folder_count`/`foldsnap_folder_size` se mancanti (preserva la pre-condizione "ogni term ha sempre i 4 meta").
2. **Iterazione chunked**: ogni chunk fa `array_pop` di `limit` elementi dalla pila. Per ogni folder estratto:
   - Leggi `direct_count` (`term_taxonomy.count`) e `direct_size` (singola query batched a inizio chunk via `Database::getDirectSizesForFolders` su tutti i folderId del chunk)
   - Leggi `total_count` e `total_size` dei **figli diretti** dai loro term meta (già calcolati nei chunk precedenti perché la pila restituisce le foglie prima — query batched a inizio chunk via `getChildrenTotalsForFolders` su tutti i folderId del chunk)
   - Calcola `total = direct + Σ total(figli)` e applica `update_term_meta` (singola UPDATE bulk per chunk: una per `foldsnap_folder_count`, una per `foldsnap_folder_size`)
   - Salva la pila accorciata nell'option
3. **Root al termine**: quando la pila si svuota, calcola i root counter globali: `total_count(Root) = countUnassignedMedia + Σ total_count(top-level folders)` e analogo per size. Salva in `foldsnap_opt_root_count`/`foldsnap_opt_root_size`. Cancella la option `foldsnap_opt_recalc_stack`. Setta `foldsnap_opt_counters_initialized`.
4. **Idempotenza per chunk**: il valore scritto dipende solo dal direct del nodo + total dei figli (già stabili). Se l'esecuzione si interrompe a metà, la pila in option contiene esattamente i nodi rimanenti; ripartire dallo stesso punto produce lo stesso risultato.

Note implementative:
- Nuovo `Database::getChildrenTotalsForFolders(int[] $parentIds, string $taxonomy): array<int, array{count:int, size:int}>` — singola query JOIN su `wp_term_taxonomy` + `wp_termmeta` per leggere `foldsnap_folder_count`/`foldsnap_folder_size` di tutti i figli diretti dei parent richiesti, raggruppati per parent.
- Nuovo `recalculateChunk(int $limit): array` — ritorna `{processed, remaining, done}`. Se la pila non esiste in option, la costruisce; altrimenti la legge e fa pop.
- Nuovo endpoint `POST /foldsnap/v1/folders/recalculate?limit=Y` (richiede `manage_options`)
- Cron wrapper: hook `foldsnap_recalc_chunk` chiama `recalculateChunk()` e ri-schedula `wp_schedule_single_event` finché `done: false`
- Migrazione iniziale: al primo boot dopo l'aggiornamento, se flag `foldsnap_opt_counters_initialized` assente, schedula il primo chunk. La pila si costruisce automaticamente al primo chunk (vedi punto 1).

**Test**:
- Aggiornare `FolderRepositoryTests` per verificare i delta sui contatori (assign, remove, update reparent, delete)
- Test per hook `delete_attachment`
- Test per `recalculateChunk` (verifica idempotenza, ricostruzione da zero, gestione root)
- Test endpoint REST recalculate

**Verifica**: `/test-coverage` sui file modificati, poi `composer fullcheck`. Test manuale: caricare un file in root → contatore root incrementa; spostare in cartella nested → root decrementa, cartella+antenati incrementano; cancellare → decremento; corrompere a mano un meta e chiamare recalculate → ripristino.

### Step 10 — Uninstall cleanup + test ⚠️ APERTO

**Stato (2026-05-09)**: parzialmente implementato. `src/Core/Uninstall.php` pulisce options e transients (prefisso `foldsnap_opt_`/`foldsnap_`), disarma il cron `foldsnap_recalc_chunk` e fa `wp_cache_flush()`, ma **non chiama** `deleteTaxonomyTerms()`. I termini `foldsnap_folder` e i loro term meta restano in DB dopo uninstall. Da chiudere se si vuole completare lo step come scritto.


**Modifica `src/Core/Uninstall.php`:**
- Aggiungere `self::deleteTaxonomyTerms()` in `cleanSite()`
- Nuovo metodo `deleteTaxonomyTerms()`: registra taxonomy temporaneamente, recupera tutti i termini, elimina con `wp_delete_term()`
- `wp_delete_term()` cancella automaticamente i term meta associati (`foldsnap_folder_color`, `foldsnap_folder_position`, `foldsnap_folder_size`, `foldsnap_folder_count`) — verificare in test
- Le opzioni root counters (`foldsnap_opt_root_size`, `foldsnap_opt_root_count`) e il flag `foldsnap_opt_counters_initialized` vengono già puliti da `deleteOptions()` (prefisso `foldsnap_opt_`)

**Test:** Aggiornare `tests/Unit/Core/UninstallTests.php`

**Verifica:** `/test-coverage` sui file sorgente dello step, poi `composer fullcheck`

### Step 11 — Verifica finale

```bash
composer fullcheck
```

Esegue in ordine: npm build → npm lint → npm test → phpcbf → phpcs → plugin-check → phpstan → phpunit.

---

## TODO post-MVP (da valutare a fine progetto)

Questi punti sono stati identificati durante la pianificazione ma rinviati per non gonfiare lo scope. Si valuta a fine progetto se vale la pena affrontarli.

Filosofia: prima far funzionare il plugin con la nuova struttura (Step 8 + Step 9), poi tornare su race conditions e performance. Non gonfiare gli step 8 e 9 con ottimizzazioni premature.

### TODO-1 — Cron ricorrente per recalculate

**Problema**: lo Step 9 schedula il recalculate solo come migrazione iniziale (`wp_schedule_single_event`, ri-schedulato finché `done: false`). Una volta completata la migrazione, nessun cron ricorrente corregge il drift accumulato (terminazioni anomale che bypassano lo `shutdown` hook, race conditions, hook saltati).

**Da fare**: aggiungere un cron ricorrente (settimanale, `wp_schedule_event` con `recurrence: weekly`) che invoca `recalculateChunk` in modalità chunked finché `done: false`. Stesso codice già scritto in Step 9, solo schedulazione diversa.

Rinviato per non gonfiare lo Step 9: prima si verifica che l'aggiornamento incrementale funzioni correttamente sul caso normale, poi si aggiunge la safety net periodica.

### TODO-2 — Concorrenza sui contatori incrementali

**Problema**: due richieste concorrenti che mutano gli stessi contatori (es. due `assignMedia` sulla stessa folder) possono produrre drift.

**Mitigazione già nel piano**: l'uso di `UPDATE ... SET meta_value = meta_value + delta` è atomico a livello SQL (single-row update), quindi il rischio è già parzialmente contenuto. Restano da analizzare i casi di reparent (lettura `total_size` seguita da update degli antenati) che fanno read-modify-write applicativo.

**Da fare se serve**: misurare se il drift è osservabile. Se sì, valutare:
- Lock applicativo via `GET_LOCK()` MySQL su un identificatore stabile
- Lettura+scrittura in transazione con `SELECT ... FOR UPDATE`
- Accettare il drift e affidarsi al recalculate ricorrente (TODO-1)

### TODO-3 — Test di concorrenza

Scrivere test che simulano mutazioni concorrenti sui contatori (assignMedia parallele, delete parallele, reparent parallelo). Da fare a fine progetto, una volta stabilizzata l'architettura.

### TODO-4 — Performance budget

Definire target numerici misurabili e aggiungerli alla suite di verifica:
- Tree iniziale (root folders): < 200ms su 1000 cartelle
- Expand di un parent: < 100ms
- Search: < 300ms su 1000 cartelle
- assignMedia di 500 media: < 1s
- delete di 100 attachment (con bulk batching): < 500ms

Aggiungere benchmark eseguibili in CI.

### TODO-5 — Scala della search oltre N folder

`WP_Term_Query` con `name__like` genera `LIKE '%query%'` con leading wildcard, che invalida l'indice `KEY name` di `wp_terms`. Su 1000 folder è ok, su 50k+ diventa scan completa.

Quando si supera la soglia definita in TODO-4 (search < 300ms), valutare:
- Indice fulltext su `wp_terms.name` (richiede MySQL 5.6+ con InnoDB FT)
- Colonna name normalizzata (lowercase, ASCII fold) per query più mirate
- Cache applicativa dei risultati search frequenti

### Note di rollback (Step 9)

I contatori incrementali non possono essere "buggati" nel senso di rompere il sistema: nel caso peggiore producono valori non coerenti con la realtà. La funzionalità di **recalculate chunked** è il rollback: può essere lanciata manualmente (endpoint REST con `manage_options`) o automaticamente via cron (TODO-1). Non serve un feature flag o un fallback al calcolo on-demand — il recalculate è la safety net definitiva.

### TODO-9 — Selezione multipla media per drag & drop bulk

**Stato attuale**: il drag&drop sposta un media alla volta. La griglia nativa di WordPress in `upload.php` espone già un `Bulk Select` mode (bottone "Bulk select"), e ogni `li.attachment` ha la classe `selected` quando l'utente lo seleziona.

**Da fare**:
1. **Detection**: in `template/js/foldsnap-dragdrop.js` (bridge jQuery UI sulla griglia nativa), al `dragstart` su un `li.attachment`, controllare se è `.selected` E se la griglia è in bulk-select mode. Se sì, raccogliere TUTTI gli `li.attachment.selected` come payload del drag.
2. **Visual feedback**: durante il drag, mostrare un badge col conteggio (es. "5 items") sull'helper drag invece di una sola thumbnail. jQuery UI lo permette via `helper: () => $('<div class="foldsnap-drag-ghost">5 items</div>')`.
3. **Drop**: al drop su una folder della sidebar, dispatchare `assignMedia(folderId, mediaIds[])` con l'intero array invece di un solo id. La REST API lo supporta già (`media_ids` accetta array).
4. **Sicurezza UX**: se l'utente prova a draggare un `.selected` non in bulk mode, fallback al single-item drag (oggi).
5. **Test manuali**: verificare con 1 / 2 / 50+ media selezionati che assignMedia non saturi il payload e che la response envelope (`paths[]` con catene origine) refreshi correttamente tutte le folder origine toccate (l'envelope è già pronto per questo).

**Per la MediaGrid React** (sidebar interna, non quella di `upload.php`): vedere TODO-6 — la `useDraggable` su `MediaItem` è oggi dead code. Prima decidere se la MediaGrid React serve davvero, poi eventualmente cablare la stessa logica bulk via dnd-kit (`useSortable`/`useDraggable` su set di id).

Rinviato perché non bloccante per l'MVP single-drag e perché richiede di chiarire prima TODO-6.

### TODO-8 — Resize sidebar: handle full-height + persistenza larghezza

**Stato attuale**: la sidebar ha `resize: horizontal` CSS, che disegna solo la maniglia nell'angolo in basso-destra (default browser). Larghezza default 280px, clamp 200–600px. La larghezza non è persistita: ad ogni reload torna a 280px.

**Da fare**:
1. Sostituire la maniglia CSS con una drag handle dedicata sul bordo destro della sidebar, full-height, larga ~4px, con cursor `col-resize` e hover state visibile (es. background azzurrino). Pattern stile VSCode/Finder.
2. Implementazione: hook custom `useResizableSidebar` (`template/js/hooks/use-resizable-sidebar.js`) che gestisce `mousedown` sulla handle → `mousemove` su window → `mouseup`. Calcola la larghezza dal `clientX - sidebar.left` e la applica via `style.width`.
3. Aggiungere supporto touch (`touchstart`/`touchmove`/`touchend`) per trackpad e touchscreen.
4. Persistenza in localStorage: chiave `foldsnap.sidebarWidth`, helper `loadSidebarWidth`/`saveSidebarWidth` in `persistence.js`. Ripristinata al boot.
5. Test JS per l'hook (mock di mousedown/move/up + verifica setStyle).

Rinviato per non gonfiare il fix del Root: la maniglia angolare di default basta come MVP funzionale.

### TODO-7 — Persistenza completa dello stato sidebar (expansion + selezione + "All Media")

**Contesto**: lo Step 8B introduce `loadExpandedIds`/`saveExpandedIds` (set di folder espanse) e `loadAllMediaActive`/`saveAllMediaActive` (toggle "All Media"). Sono già persistiti, ma il quadro è ancora parziale e la riapertura della Media Library non sempre ricostruisce lo stato che l'utente aveva.

**Da fare** (raccolto in un unico TODO perché tutto lo stato sidebar è correlato e va affrontato in una passata):

1. **Root espansa di default al primo boot**. Se `localStorage` è vuoto, la sidebar deve aprirsi con Root espansa (così l'utente vede subito le top-level folders senza dover cliccare la chevron).
2. **Selezione folder corrente**. Oggi `selectedFolderId` non è persistito: ad ogni reload si torna a `null`. Va salvato in localStorage e ripristinato al boot, con priorità al `?foldsnap_folder_id` da URL (deep link batte memoria).
3. **Sincronizzazione cross-tab**. Se l'utente espande una folder in una tab e apre una seconda tab, le due dovrebbero condividere expansion + selezione. Valutare `storage` event listener su `window` per propagare i cambiamenti.
4. **Garbage collection delle folder cancellate**. `expandedIds` può accumulare ID di folder che non esistono più (cancellate da un'altra sessione, da un altro utente, o via uninstall parziale). Filtrare al boot rispetto a `foldersById` dopo il primo fetch.
5. **TTL / versioning**. Cambi di schema o reset dei dati richiedono un modo per invalidare la cache. Aggiungere una chiave `foldsnap.stateVersion` e azzerare se non corrisponde.

**Verifica**: test di integrazione che simula reload, deep link, multi-tab, e folder cancellate.

Rinviato per non gonfiare lo Step 8B: la persistenza minima (set espansione + toggle All Media) è già attiva, gli altri pezzi sono raffinamenti UX da fare in una passata dedicata dopo aver verificato l'esperienza reale.

### TODO-10 — Filesize meta numerico dedicato (P6/P7)

**Problema**: `Database::getGlobalTotalSize` e `Database::getUnassignedAttachmentMeta` caricano tutta la `_wp_attachment_metadata` serializzata in PHP e ne fanno `maybe_unserialize` per estrarre `filesize`. Su 10k attachment significa 10–30MB caricati e 10k unserialize per ogni cache miss di `getUnassignedSize()` (che si invalida ad ogni assign/unassign/remove/delete-attachment) e ad ogni `finalizeRoot()` del recalc.

**Da fare**:
1. Nuovo meta key `_foldsnap_filesize` (numerico, intero bytes).
2. `AttachmentLifecycleService::onMetadataGenerated` popola il meta dopo che WP ha generato la metadata standard.
3. Backfill one-shot dentro `CountersRecalculator` (o uno step dedicato del recalc) che legge `_wp_attachment_metadata`, ne estrae `filesize` e scrive `_foldsnap_filesize`. Idempotente: skip se il meta esiste già.
4. Riscrivere `getGlobalTotalSize` e `getUnassignedAttachmentMeta` come `SUM(meta_value)` su `_foldsnap_filesize` con LEFT JOIN su `wp_term_relationships` per la versione "unassigned". Niente più PHP-side unserialize.
5. Rimuovere `Database::extractFileSize` se non serve più (resta utile per `getMediaFileSizes` e `getDirectSizesForFolders` che leggono per-attachment in altri code path — valutare se conviene migrare anche quelli).

**Impatto utente**: scompare la latenza visibile sulla sidebar dopo mutazioni media su siti con migliaia di attachment. È la fix con il valore pratico più alto del round.

### TODO-11 — Cache group `foldsnap` non registrato come non-persistent (PA3)

**Problema**: `FolderCounterService` usa `wp_cache_set/get(..., 'foldsnap')` ma il gruppo non è dichiarato. Su siti con object cache persistente (Redis/Memcached, comuni su WordPress.com / Pantheon / Kinsta), il gruppo viene persistito tra request. L'invalidazione c'è ad ogni mutation ma è facile rompere l'invariante con request concorrenti, e si possono lasciare valori stale persistiti.

**Da fare**: una riga in `Bootstrap::onInit()`:
```php
wp_cache_add_non_persistent_groups(['foldsnap']);
```

Va chiamata prima di qualunque accesso a `wp_cache_*` con quel gruppo, quindi presto in `onInit`.

**Verifica**: test che mocka `wp_cache_add_non_persistent_groups` e verifica la chiamata. In integrazione, comportamento invariato sui setup di test (no persistent backend).

### TODO-12 — Race read-then-write sui Root counters (P3)

**Problema**: `FolderCounterService::adjustRoot` legge l'option, somma il delta in PHP, riscrive. Due upload concorrenti che leggono lo stesso valore producono lost-update. Il recalc cron è la safety net (TODO-1) ma nel frattempo l'utente vede numeri sbagliati.

**Da fare** (in due passi indipendenti):
1. **Subito (banale)**: passare `false` come 3° argomento a tutti i `update_option` in `FolderCounterService` (`OPT_ROOT_SIZE`, `OPT_ROOT_COUNT`) e in `CountersRecalculator::processChunk` (`OPT_INITIALIZED`, `OPT_STACK`). Evita che le option vengano caricate via `wp_load_alloptions` su ogni request. Non risolve la race, è igiene.
2. **Per la race**: sostituire il read-modify-write con un UPDATE atomico SQL-level:
   ```sql
   UPDATE wp_options
   SET option_value = GREATEST(0, CAST(option_value AS SIGNED) + :delta)
   WHERE option_name = :name
   ```
   Bypassa l'API `update_option`, va inserito in `Database` con cache invalidation manuale (`wp_cache_delete($name, 'options')`). Pattern già usato da `bulkAdjustTermMeta`.

**Da valutare**: se in pratica il drift è osservabile su siti reali con multi-editor. Se no, basta il punto 1 + affidarsi al recalc cron (TODO-1). Il punto 2 si fa solo se i support tickets lo richiedono.

### TODO-13 — Bug latente: `?? 1` vs `?? totalPages` in `loadMoreChildren` (E5)

**Problema**: in `template/js/store/actions.js`, `fetchChildren` fa `response.total_pages ?? 1` mentre `loadMoreChildren` fa `response.total_pages ?? totalPages`. Se in futuro il backend smette di emettere `total_pages` (es. refactor della response shape), `fetchChildren` blocca l'utente a "pagina 1 di 1" mentre `loadMoreChildren` mantiene il valore precedente — comportamento divergente sullo stesso campo.

Oggi non si attiva perché il backend emette sempre `total_pages`. Va sistemato quando si collassano le due funzioni in un'unica generator parametrizzata (E5 del review).

**Da fare**: collassare `fetchChildren` e `loadMoreChildren` in una sola generator parametrizzata da `{ startAction, successAction, errorAction, page }`. Usare un default coerente per `total_pages` (probabilmente `?? totalPages` in entrambi i casi, perché "1" è una bugia se la pagina è già caricata).

### TODO-14 — Allineamento documenti `docs/` con il codice (D1–D5)

**Problema**: la review ha trovato 5 imprecisioni nei documenti `docs/` (source of truth):

1. **`docs/04_1_UI_react-architecture.md:76`** — cita `getRootFolder()` (singolare) che non esiste. I selettori reali sono `getRootFolders()` (plurale, top-level) e `getFolderById(0)` (root model). `getSelectedFolderId` è in tabella ma non nel prose summary.
2. **`docs/04_1_UI_react-architecture.md:80`** — dice che `getChildrenOf(0)` è wired a un resolver. I resolver veri sono `getRootFolders` e `getFolderById` (`template/js/store/resolvers.js`). Il secondo (con expandPathTo per id positivi) non è documentato.
3. **`docs/03_1_MEDIA_folder-system.md:103`** — cita il cron event `foldsnap_counters_recalculate`. Il valore reale di `CountersRecalculator::CRON_ACTION` è `foldsnap_recalc_chunk`. Chi debugga seguendo il doc cerca il nome sbagliato.
4. **`docs/01_1_ARCH_overview.md:43`** — dice "On every admin request Bootstrap checks…". `Bootstrap::onInit` è hookato su `'init'`, che fira anche su frontend. Solo `ControllersManager` è dentro `is_admin()`. Va sostituito con "On every WordPress request".
5. **`docs/03_1_MEDIA_folder-system.md:41`** — cita `unassignMedia()` come metodo, ma l'API attuale è `MediaFolderAssignmentService::unassign()` / `remove()`. La regola concettuale ("assegnare a Root = unassign") è corretta e va tenuta, va solo rimosso il nome del metodo.

**Da fare**: 5 edit puntuali ai 3 file. Nessun cambio di codice.

### TODO-6 — Chiarire `MediaItem` `useDraggable`: completare o rimuovere

**Problema**: `template/js/components/MediaItem.jsx` usa `useDraggable` di `@dnd-kit/core` per rendere draggable i media della `MediaGrid` interna (sidebar React). Ma:

- `template/js/components/FolderItem.jsx` registra `useDroppable` con `data: { type: 'folder', folderId }` e accetta drop senza filtrare il tipo del draggable.
- `template/js/components/FolderSidebar.jsx::handleDragEnd` gestisce **solo** il caso `activeType === 'folder' && overType === 'folder'` (riordino/reparenting cartelle). Non c'è un branch `media → folder`.
- Il drag di media verso le cartelle dalla griglia WP nativa funziona via il bridge jQuery UI in `template/js/foldsnap-dragdrop.js` (legge `li.attachment` Backbone), che è fisiologicamente necessario perché la griglia WP nativa non è React e quindi `useDraggable` non è applicabile lì.

Quindi `useDraggable` su `MediaItem` o è dead code, o è feature incompleta (manca l'handler in `handleDragEnd` e il filtro di tipo in `FolderItem`).

**Da fare**: decidere quale dei due:

1. **Rimuovere**: se la `MediaGrid` React serve solo a visualizzare i media di una cartella selezionata (e non si vuole spostare media tra cartelle drag-and-drop dalla MediaGrid React), togliere `useDraggable` da `MediaItem.jsx`, rimuovere `dragIds`, `isDragging`, `attributes`, `listeners` e i relativi stili `--dragging`. Aggiornare i test `MediaItem.test.jsx`.

2. **Completare**: se si vuole permettere il drag di media dalla MediaGrid React verso le cartelle in sidebar:
   - In `FolderSidebar.jsx::handleDragEnd` aggiungere il branch: se `activeType === 'media' && overType === 'folder'` → `assignMedia(overData.folderId, active.data.current.mediaIds)`.
   - In `FolderItem.jsx::useDroppable` valutare di esporre l'`active` per dare feedback visivo solo quando il tipo è compatibile.
   - Aggiungere test di integrazione per il drop media→folder via @dnd-kit.

**Nota**: indipendentemente dalla scelta, il bridge jQuery UI (`foldsnap-dragdrop.js`) resta necessario per la griglia WP nativa di `upload.php`. Il dual-system non è eliminabile finché ci si aggancia alla Media Library nativa di WordPress.
