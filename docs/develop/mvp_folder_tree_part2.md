# FoldSnap — Parte Due: Piano di rifinitura UX e completamento integrazione Media Library

## Context

Il piano MVP `docs/develop/mvp_folder_tree_plan.md` è funzionalmente completo (Step 1–11), ma l'esperienza utente sulla Media Library presenta gap noti che impediscono al plugin di essere fruibile in produzione:

- alcuni bug di integrazione con la griglia nativa (loader upload, drag&drop cartelle che si è rotto in qualche refactor recente)
- mancanze di scope rispetto al piano originale (sidebar non presente nel modale wp.media, upload non in folder corrente, dettaglio attachment senza folder path)
- TODO post-MVP già censiti (1, 7, 8, 9, 10, 11, 14) che restano aperti
- mancanza di un sistema unico di persistenza preferenze utente: oggi convivono localStorage (sidebar state) e WordPress options (contatori), senza un modello generale per "user settings"

Questo piano è la **Parte Due** del lavoro: riprende i TODO esistenti e li mergia con i 10 punti UX segnalati durante la sessione di review del 2026-05-09. Non è un'unica task: è una mappa di lavori indipendenti, raggruppati per area e ordinati per priorità ragionata. Ogni task è autonoma e può essere implementata in PR separate.

Output atteso: una Media Library navigabile per cartelle in TUTTI i contesti dove WordPress espone media (upload.php grid, upload.php list, modale wp.media in post editor, edit screen attachment), con stato sidebar persistente e preferenze utente centralizzate.

---

## Stato verificato (sessione 2026-05-09)

Verifiche fatte sul codice prima di scrivere il piano:

- **Drag&drop cartelle "rotto"**: causa identificata `[v]`. In `template/js/components/FolderSidebar.jsx:44` `handleDragEnd` ritorna se `allMediaActive` è true; in `FolderSidebar.jsx:109` il tree riceve `inert` quando All Media è on. Il drag continua a non funzionare anche se l'utente disattiva "All Media" perché il listener jQuery sui sortables sopravvive solo finché DnDContext non è inerted. Da indagare se la rottura sia limitata al caso "All Media on" o se ci sia un'altra regressione.
- **Ordinamento media griglia**: il plugin **non altera** `orderby`/`order` della griglia nativa `[v]`. `MediaLibraryController` aggiunge solo `tax_query` su `ajax_query_attachments_args` e `pre_get_posts`. Eventuali ordinamenti strani arrivano da WP core o da altri plugin. Da verificare se è bug FakerPress o sputtanamento DB.
- **Loader mancante post-upload**: il plugin **non si aggancia** all'evento Backbone collection `add` né a `wp.Uploader` events `[v]`. Quando l'utente carica un file, WP aggiorna la collection ma non viene triggerata alcuna refresh sul filtro folder corrente, e il media (che nasce unassigned) potrebbe non comparire se è attivo un filtro folder ≠ Root.
- **Path folder via REST**: già esposto da `GET /foldsnap/v1/folders/{id}/path` in `RestApiController.php:221-242` `[v]`. Riusabile per il dettaglio attachment.
- **Sidebar nel modale wp.media**: `MediaLibraryController::enqueueAssets()` conditiona su `screen->id === 'upload'` `[v]`. Non c'è enqueue su `post.php`/`post-new.php` né mount JS sul modale.
- **Settings page**: `MainPageController` è un placeholder vuoto `[v]`. Non esiste ancora alcuna pagina settings, né alcun `update_user_meta`/`update_option` per preferenze UI.
- **Persistenza preferenze**: oggi solo localStorage (`foldsnap.expandedFolders`, `foldsnap.allMedia`) `[v]`. Niente sync server-side, niente multi-device.
- **Chevron icone**: in `assets/css/foldsnap-admin.css:192-209` `.foldsnap-folder-item__chevron` ha `font-size: 10px` `[v]`. Caratteri Unicode `▾▸` resi minuscoli, hard da cliccare.

## API WordPress raccomandate (verificate)

Per le nuove integrazioni, useremo:

- **`@wordpress/preferences-persistence`** per il sistema preferenze unico — auto-sync su `wp_usermeta` via REST con fallback localStorage `[v]`. Standard WP core 6.1+. Riferimento: @wordpress/preferences-persistence.
- **`@wordpress/components` `ResizableBox`** per resize sidebar — pattern usato dal block editor `[v]`. Riferimento: ResizableBox.
- **`attachment_submitbox_misc_actions`** per iniettare il folder path nella edit page legacy dell'attachment `[v]`. Riferimento: attachment_submitbox_misc_actions.
- **`attachment_fields_to_edit`** per il modale Attachment Details — funziona ancora ma solo per la edit page legacy nel modale Backbone moderno; per il modale Backbone serve override fragile di `wp.media.view.AttachmentDetails`. Decidere caso per caso `[v]`.
- **Plupload `multipart_params`** + lettura `$_POST` in `add_attachment` per upload folder-aware `[v]`. Plupload è il layer sotto `wp.Uploader`.
- **`ajax_query_attachments_args`** già usato dal plugin — funziona identicamente per la griglia di upload.php e per il modale wp.media (stesso endpoint AJAX `wp_ajax_query_attachments`) `[v]`.

---

## Lista task — ordinata per priorità

Ogni task è autonoma. Non c'è dipendenza forte tra le aree (eccetto dove indicato).

### Tier 1 — Bug fix (priorità massima, breve, sblocca uso quotidiano)

#### Task 1.1 — Fix drag&drop cartelle nella sidebar
**Problema**: il drag&drop cartelle ha smesso di funzionare. Causa probabile identificata: gating su `allMediaActive` in `FolderSidebar.jsx`. Da verificare se è l'unica causa.

**File**: `template/js/components/FolderSidebar.jsx`, `template/js/components/FolderItem.jsx`

**Da fare**:
1. Riprodurre il bug con All Media OFF (caso normale). Se il drag funziona qui, è solo un edge case da documentare. Se non funziona, indagare oltre.
2. Aggiungere logging temporaneo in `handleDragEnd` per capire se l'evento arriva.
3. Verificare che `setNodeRef` di `useDroppable` sia bound al div giusto (oggi `FolderItem.jsx:128`).
4. Considerare se rimuovere il gate `if ( allMediaActive ) { return; }` e usare invece feedback visivo (es. drop disabilitato ma sortable visibile).

**Verifica**: drag di una folder figlio sopra un'altra folder → deve fare reparenting; drag di una folder sopra una sibling → deve fare reorder.

---

#### Task 1.3 — Aumentare visibilità chevron e drag handle nella sidebar
**Problema**: chevron `▾▸` resi a `font-size: 10px`, drag handle `⠿` a 14px. Microscopici.

**File**: `assets/css/foldsnap-admin.css`, opzionale `template/js/components/FolderItem.jsx`

**Da fare**:
1. Aumentare font-size del chevron a 16–18px, area cliccabile a 24×24px minimo.
2. Considerare sostituzione con `<Icon icon={ chevronDown } />` di `@wordpress/components` (icone SVG accessibili, dimensioni standard 24px).
3. Stesso trattamento per il drag handle.

**Verifica**: target click area ≥24×24px sui chevron. Visual review.

---

#### Task 1.4 — Verifica ordinamento media (no-op se è solo FakerPress)
**Problema**: ordinamento "strano" dei media nella griglia.

**Da fare** (solo investigazione, nessun codice):
1. Disattivare FoldSnap. Verificare che l'ordinamento resti lo stesso (è atteso, il plugin non lo modifica).
2. Se l'ordinamento è strano anche senza plugin: il problema è in DB / WP / FakerPress. Documentare e chiudere il task.
3. Se invece il plugin altera l'ordine (improbabile): investigare in `MediaLibraryController` e nei service di filter.

**Verifica**: prima/dopo disattivazione plugin produce stesso ordine in upload.php.

---

### Tier 2 — Persistenza preferenze e stato sidebar

Questi task vanno trattati come blocco perché condividono infrastruttura. Fa parte del **TODO-7** del piano originale.

#### Task 2.1 — Sistema unico di persistenza preferenze utente
**Problema**: oggi 2 silos (localStorage + WP options tecniche), nessun sistema centralizzato per "user preferences". Le future feature (settings UI, sidebar width, sidebar visibility per cartella) hanno bisogno di un modello unico.

**Decisione di design**: adottare `@wordpress/preferences-persistence` come standard del plugin.

**File**: nuovo `template/js/preferences/index.js`, refactor di `template/js/store/persistence.js`

**Da fare**:
1. Aggiungere `@wordpress/preferences` e `@wordpress/preferences-persistence` a `package.json`.
2. Setup all'inizio di `template/js/index.js`: `preferencesStore` registrato con scope `foldsnap`, persistenza configurata via `create()` di `preferences-persistence`.
3. Lato PHP: chiamare `wp_register_persisted_preferences_meta()` in un nuovo `src/Services/PreferencesService.php` che dichiara i campi user-meta (es. `foldsnap.expandedFolders`, `foldsnap.sidebarWidth`, `foldsnap.showCounts`, `foldsnap.showSizes`, `foldsnap.selectedFolderId`).
4. Migrare `expandedIds` e `allMediaActive` da localStorage diretto a `preferences` API: mantenere fallback che legge legacy localStorage e migra one-shot (per non perdere stato utenti esistenti).
5. Documentare il pattern in `docs/04_X_UI_preferences.md` (nuovo file).

**Verifica**: cambio preferenza in tab A → riflesso in tab B dopo reload (sync via REST). localStorage usato solo come cache.

**Stima**: 1–2 giornate. È il task abilitante per molti altri.

---

#### Task 2.2 — Persistenza completa stato sidebar (Root espansa di default + selezione + cleanup)
**Riferimento**: TODO-7 del piano originale.

**Dipende da**: Task 2.1 (per sfruttare il nuovo sistema preferenze)

**File**: `template/js/store/reducer.js`, `template/js/store/index.js`, nuovo persistence layer da Task 2.1

**Da fare**:
1. Root espansa di default: se `expandedIds` è vuoto al boot, includere `ROOT_PARENT_ID` (oggi serve cliccare manualmente Root la prima volta).
2. `selectedFolderId` persistito: oggi è `null` ad ogni reload. Da salvare come preferenza, con priorità al `?foldsnap_folder_id` URL param (deep-link batte memoria).
3. Garbage collection: al boot, dopo il primo fetch, filtrare `expandedIds` rispetto a `foldersById` per scartare ID di folder cancellate.
4. Cross-tab sync: già fornito da `@wordpress/preferences-persistence` (se Task 2.1 è in adozione).
5. State versioning: chiave `foldsnap.stateVersion`, azzera preferenze su mismatch.

**Verifica**: reload → stesso stato; deep link → ha precedenza; tab A modifica → tab B vede dopo reload.

---

#### Task 2.3 — Resize sidebar full-height con drag handle persistente
**Riferimento**: TODO-8 del piano originale.

**Dipende da**: Task 2.1 (per persistenza width)

**File**: `template/js/components/FolderSidebar.jsx`, `assets/css/foldsnap-admin.css`

**Da fare**:
1. Rimuovere `resize: horizontal` da CSS.
2. Wrapping della sidebar in `<ResizableBox>` di `@wordpress/components` con `enable={{ right: true }}`. Pattern usato dal block editor.
3. Larghezza salvata via preferences API (Task 2.1) come `foldsnap.sidebarWidth`. Default 280px, clamp 200–600px.
4. Test: resize via mouse, resize via touch, persistenza dopo reload.

**Verifica**: handle visibile a destra, resize fluido, larghezza ricordata tra reload e tra dispositivi (con Task 2.1).

---

### Tier 3 — Upload folder-aware

#### Task 3.1 — Upload nella folder corrente (default upload destination)
**Problema**: upload va sempre in Root. L'utente vuole che, se è selezionata una folder, l'upload assegni il media a quella folder.

**Include anche il bug "loader/refresh post-upload"** (ex Task 1.2): oggi dopo l'upload il media non appare nella griglia finché non si ricarica la pagina, perché nasce in Root mentre il filtro attivo è una folder ≠ Root. Assegnando l'upload alla folder corrente (Task 3.1), il media appartiene già al filtro attivo e la collection Backbone lo mostra senza requery hack. Il primo tentativo di fix isolato (event `add` sulla collection) è stato rimosso perché loopava sui fetch normali — la soluzione corretta è agganciarsi a `wp.Uploader` events (`FileUploaded`/`uploadSuccess` di Plupload) o, ancora meglio, far sì che il media appartenga al filtro fin da subito (questo task).

**File**: `template/js/index.js` o nuovo `template/js/services/upload-folder-bridge.js`, `src/Services/AttachmentLifecycleService.php`, `src/Services/MediaFolderAssignmentService.php`

**Da fare**:

**Lato JS:**
1. Hookare `wp.Uploader.queue` o l'init di `wp.Uploader` per iniettare in `multipart_params` il `foldsnap_folder_id` dello store al momento dell'upload.
2. Aggiornare dinamicamente quando lo `selectedFolderId` cambia (event `BeforeUpload` di Plupload).

**Lato PHP:**
1. In `AttachmentLifecycleService` o nuovo handler, hook su `add_attachment` (priority 10): leggere `$_POST['foldsnap_folder_id']`, validare via `current_user_can('upload_files')` + sanitize_int, e se >0 chiamare `MediaFolderAssignmentService::assign($folderId, [$attachmentId])`.
2. Sicurezza: nonce verification su `$_POST['foldsnap_folder_nonce']` (set dal lato JS via `wp.api.settings.nonce` o nonce custom in `wp_localize_script`).
3. Se folder non esiste o utente non ha capability → silenzio e media resta unassigned (no errore bloccante upload).

**Verifica**: selezionare folder X, drop file → media compare in X senza drag&drop manuale; in Root → media compare in Root.

**Nota**: questo task **risolve di fatto Task 1.2** perché il media viene già assegnato alla folder corrente che è il filtro attivo, quindi compare immediatamente.

---

### Tier 4 — Sidebar nel modale wp.media (post editor)

**Riferimento**: nuova feature segnalata. Il piano originale non la copriva.

#### Task 4.1 — Sidebar nel modale wp.media + filtro folder + upload-aware
**Problema**: oggi la sidebar funziona solo in upload.php. Quando crei un post e clicchi "Add Media", la sidebar non c'è.

**File**: `src/Controllers/MediaLibraryController.php`, `template/js/index.js`, nuovo `template/js/services/modal-mount.js`

**Da fare**:

**Lato PHP:**
1. Estendere `MediaLibraryController::enqueueAssets()` per enqueue anche su screen `post.php`, `post-new.php` (e tutti gli screen che possono aprire wp.media — usare check `did_action('wp_enqueue_media')` quando possibile).
2. Aggiungere `wp_enqueue_media()` se non già chiamato dal core.

**Lato JS:**
1. Detectare apertura del modale wp.media via `wp.media.view.MediaFrame.Post` events (es. `open`, `ready`).
2. Iniettare un container DOM custom dentro la regione `.media-frame-content` o accanto (decidere se sidebar interna o esterna al frame). Mountare React FolderTree lì.
3. Sincronizzare lo store con il modale: `setSelectedFolder` → aggiornare `wp.media.frame.library.props.set('foldsnap_folder_id', folderId)` → trigger refresh collection.
4. Il filtro server-side via `ajax_query_attachments_args` già funziona (stesso endpoint `wp_ajax_query_attachments`).
5. UX: sidebar **sempre visibile** nel modale (decisione utente 2026-05-09). Motivazione: chi installa il plugin lo fa per navigare cartelle quando seleziona media in lista infinita — la sidebar è fondamentale proprio nella creazione di post. Niente toggle.

**Sotto-task implicito**: l'upload dal modale deve anch'esso rispettare la folder selezionata (lato già coperto da Task 3.1, da verificare che `multipart_params` venga propagato anche in contesto modale).

**Verifica**: aprire post editor → "Add Media" → sidebar visibile, click su folder → griglia modale filtrata; upload dal modale → media in folder corrente.

**Stima**: 2–3 giornate. È il task più sostanzioso.

---

### Tier 5 — Dettaglio attachment (folder path navigabile)

#### Task 5.1 — Folder path nella edit page singolo attachment (post.php legacy)
**Problema**: nella edit screen di un attachment manca info sulla cartella. L'utente vuole un breadcrumb di folder cliccabili dopo "Original image: filename.jpg".

**Decisione utente (2026-05-09)**: fare la versione semplice. Solo edit page legacy, no modale Backbone.

**File**: nuovo `src/Controllers/AttachmentEditController.php` o aggiunta in `MediaLibraryController.php`

**Da fare**:
1. Hook su `attachment_submitbox_misc_actions` (action, riceve `$post`).
2. Per il `$post->ID`, recuperare i term `foldsnap_folder` via `wp_get_object_terms`. Se >0, usare `FolderRepository::getPath($termId)` per la catena.
3. Renderizzare HTML: una `<div class="misc-pub-section">` con label "Folder:" e i nomi delle folder come `<a href="upload.php?foldsnap_folder_id=N">`. Ogni link rimanda alla griglia filtrata su quella folder (deep link già supportato).
4. Escape: `esc_html` su nomi, `esc_url` su href.
5. Se attachment è in Root, mostrare "Root" come label statico (no link).

**Verifica**: aprire un attachment in edit screen → vedere "Folder: Root > Foo > Bar" cliccabile.

---

#### Task 5.2 — Folder path nel modale Attachment Details (Backbone) — RINVIATO
**Stato**: rinviato per scelta utente. Se in futuro emerge la necessità (utenti che usano principalmente il modale popup invece della edit page), si valuta override Backbone di `wp.media.view.Attachment.Details.TwoColumn` con recupero path via REST `GET /foldsnap/v1/folders/{id}/path`. Pattern usato da FileBird (presunto, non verificato direttamente). Trade-off: fragile a future versioni WP.

---

### Tier 6 — Settings page

#### Task 6.1 — Pagina settings con preferenze visualizzazione
**Problema**: l'utente vuole poter mostrare/nascondere conteggio media e size per folder nella sidebar. Più in generale, il plugin ha bisogno di una pagina settings.

**Dipende da**: Task 2.1 (sistema preferenze)

**File**: `src/Controllers/MainPageController.php` (oggi placeholder), nuovo `template/js/settings/SettingsApp.jsx`

**Da fare**:
1. Implementare `MainPageController` per registrare la submenu `Media > FoldSnap Settings` (o simile).
2. Pagina React montata su `#foldsnap-settings-app`.
3. Settings iniziali (tutte via preferences API di Task 2.1):
   - `foldsnap.showCounts` (boolean, default true): mostra/nasconde badge conteggio media in sidebar
   - `foldsnap.showSizes` (boolean, default true): mostra/nasconde badge size
   - `foldsnap.sidebarWidth` (int): readonly, mostrato come info
4. UI: usare `@wordpress/components` `ToggleControl`, `Card`, `Panel`.
5. `FolderItem.jsx` deve leggere queste preferenze per condizionare il rendering dei badge.

**Verifica**: cambiare toggle → sidebar in upload.php riflette il cambio immediatamente.

---

### Tier 7 — TODO infrastrutturali del piano originale (non strettamente UX)

Questi sono i TODO 1, 10, 11, 12, 13, 14 del piano originale. Restano tali quali, da affrontare quando ha senso. Li riepilogo qui per completezza ma non li espando.

- **TODO-1**: cron ricorrente per recalculate (safety net contatori)
- **TODO-10**: `_foldsnap_filesize` meta dedicato (perf su siti grandi)
- **TODO-11**: registrare cache group `foldsnap` come non-persistent (one-liner critico per hosting con Redis)
- **TODO-12**: race read-then-write Root counters (pulizia + atomic UPDATE)
- **TODO-13**: bug latente `?? 1` vs `?? totalPages` in `loadMoreChildren`
- **TODO-14**: 5 imprecisioni nei `docs/`

**Raccomandazione**: TODO-11 e TODO-14 sono da fare a tempo perso (one-liner / pulizia docs). TODO-1 e TODO-10 prima di una release pubblica. TODO-12 e TODO-13 quando si tocca codice adiacente.

---

### Tier 8 — Drag&drop in modalità lista (TODO-9 + nuova feature)

#### Task 8.1 — Selezione multipla bulk drag (TODO-9 originale)
**Riferimento**: TODO-9 del piano originale.

Già documentato nel piano originale, lo riprendo qui per completezza. File: `template/js/foldsnap-dragdrop.js`.

**Da fare**: vedi piano originale TODO-9.

---

#### Task 8.2 — Drag&drop media in modalità lista
**Problema (segnalato dall'utente, low priority)**: in upload.php?mode=list non c'è DnD.

**Sfida**: la list mode è server-rendered (`WP_List_Table`), non Backbone. Il drag jQuery di `foldsnap-dragdrop.js` cerca elementi `.attachment` della grid che non esistono in list mode.

**Da fare** (se si decide di farlo):
1. Detectare list mode (presenza tabella `.wp-list-table.media`).
2. Bind jQuery UI draggable sulle righe `<tr>` della tabella, leggendo `data-id` o estraendo dall'href.
3. Al drop su una folder della sidebar → stesso `assignMedia` REST call.
4. Refresh post-drop: in list mode si può solo ricaricare la pagina mantenendo i query param (la tabella è server-rendered, non c'è collection da invalidare).

**Verifica**: in list mode, drag di una riga → drop su folder → media spostato, lista refreshata con folder corrente.

**Trade-off**: interazione meno fluida del grid mode (refresh pagina post-drop), ma copertura UX completa. Bassa priorità per l'utente.

---

## Ordine di esecuzione raccomandato

```
Tier 1 (bug fix rapidi) — ✅ COMPLETATO
  ├── 1.1 Drag&drop cartelle  ✅
  ├── 1.3 Chevron visibili    ✅
  ├── 1.4 Verifica ordine     ✅ (no-op: plugin innocente, FakerPress)
  └── (1.2 spostato in Tier 3 — risolto da 3.1)

Tier 2 (preferences foundation) ← BLOCCO COESO, fare insieme
  ├── 2.1 Sistema preferenze  ← 1-2 giornate (abilitante)
  ├── 2.2 Stato sidebar       ← 0.5 giornata
  └── 2.3 Resize sidebar      ← 0.5 giornata

Tier 3 (upload UX)
  └── 3.1 Upload in folder    ← 1 giornata (include refresh post-upload)

Tier 4 (modal integration)
  └── 4.1 Sidebar nel modale  ← 2-3 giornate (sostanzioso)

Tier 5 (attachment details)
  └── 5.1 Path in edit page   ← 0.5 giornata (5.2 rinviato per scelta utente)

Tier 6 (settings)
  └── 6.1 Settings page       ← 1 giornata (richiede 2.1)

Tier 7 (TODO infra)           ← a tempo perso o pre-release

Tier 8 (DnD esteso)
  ├── 8.1 Bulk drag           ← 1 giornata
  └── 8.2 List mode DnD       ← 1-2 giornate (low priority)
```

**Stima totale**: ~11–14 giornate di sviluppo, distribuibili in più PR indipendenti (Task 5.2 rinviato fuori scope).

## Dipendenze critiche

- **Task 2.1 abilita 2.2, 2.3, 6.1**: fare 2.1 prima.
- **Task 3.1 include il fix del refresh post-upload** (ex Task 1.2): non c'è più un task separato, è parte di 3.1.
- **Task 4.1 si appoggia su 3.1** per upload nel modale.
- Tutte le altre task sono indipendenti.

## Verifica end-to-end (post-implementazione completa)

1. **Bug fix**: drag&drop cartelle funziona; chevron cliccabile; ordinamento confermato non-bug del plugin.
2. **Persistenza**: reload pagina → stato sidebar identico (espansione, selezione, larghezza); cambio in tab A → riflesso in tab B.
3. **Upload**: in folder X, upload file → media compare immediatamente in X.
4. **Modale**: aprire post editor → "Add Media" → sidebar visibile e funzionante; selezionare folder → griglia filtrata; upload → in folder selezionata.
5. **Attachment details**: aprire attachment in edit page → folder path cliccabile.
6. **Settings**: toggle counts/sizes → sidebar riflette il cambio.

## File principali toccati

**PHP** (modifiche o nuovi):
- `src/Controllers/MediaLibraryController.php` — Task 4.1 (estensione enqueue)
- `src/Controllers/MainPageController.php` — Task 6.1 (settings page)
- nuovo `src/Controllers/AttachmentEditController.php` — Task 5.1
- nuovo `src/Services/PreferencesService.php` — Task 2.1
- `src/Services/AttachmentLifecycleService.php` — Task 3.1 (upload assignment)

**JS** (modifiche o nuovi):
- `template/js/components/FolderSidebar.jsx` — Task 1.1, 2.3
- `template/js/components/FolderItem.jsx` — Task 1.3, 6.1
- `template/js/store/persistence.js` — Task 2.1, 2.2 (refactor verso preferences API)
- `template/js/store/index.js` — Task 2.1
- `template/js/index.js` — Task 4.1, 3.1
- nuovo `template/js/preferences/index.js` — Task 2.1
- nuovo `template/js/services/upload-folder-bridge.js` — Task 3.1
- nuovo `template/js/services/modal-mount.js` — Task 4.1
- nuovo `template/js/settings/SettingsApp.jsx` — Task 6.1
- `template/js/foldsnap-dragdrop.js` — Task 8.1, 8.2

**CSS**:
- `assets/css/foldsnap-admin.css` — Task 1.3, 2.3

**Docs** (nuovi/aggiornati):
- nuovo `docs/04_X_UI_preferences.md` — documenta sistema preferenze
- nuovo `docs/develop/mvp_folder_tree_part2.md` — copia di questo piano in repo
- aggiornamento `docs/01_1_ARCH_overview.md` — TODO-14 + nuove integrazioni
