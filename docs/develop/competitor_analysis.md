# FoldSnap — Analisi Competitor

Analisi tecnica dei 3 principali plugin WordPress per folder management nella Media Library.

---

## 1. Profili competitor

### FileBird v6.5.2

- **Storage:** 2 tabelle custom (`fbv` per cartelle, `fbv_attachment_folder` per relazioni)
- **Frontend:** React + RC-Tree (Ant Design), build con Vite (non `@wordpress/scripts`)
- **D&D:** jQuery UI draggable/droppable (non React-native)
- **API:** REST API (`filebird/v1`)
- **Namespace:** `FileBird\` con autoloader PSR-4 custom
- **State:** React hooks + variabile globale `fbv_data` via `wp_localize_script`
- **Architettura:** MVC-like (Controller/Model/Rest/Support/Utils), Singleton diffuso
- **Freemium:** Feature check inline, colori cartelle e folder-per-user nella versione Pro
- **Integrazioni:** 20+ page builder, WPML, Polylang, import da 10 competitor
- **Test:** Nessuno nel pacchetto distribuito
- **Type safety:** No `declare(strict_types=1)`, no type hints, no PHPStan
- **Coding standards:** 11 `phpcs:disable` trovati, no config PHPCS/ESLint visibile

### Folders (Premio)

- **Storage:** Taxonomy WordPress nativa (`media_folder` per attachment, con pattern `{post_type}_folder`)
- **Frontend:** jQuery + jsTree + jQuery UI (nessun framework moderno)
- **D&D:** jQuery UI sortable/draggable/droppable + touch-punch per mobile
- **API:** Solo `admin-ajax.php` (25+ azioni AJAX, nessun REST endpoint)
- **Namespace:** Nessuno. Classi globali con prefisso `WCP_`
- **State:** Variabili globali JS, stato persistito in `wp_options` e `user_meta`
- **Architettura:** God class da 7.530 righe (`folders.class.php`), poche classi satellite
- **Freemium:** Feature gate inline (sticky, locked, colors = Pro)
- **Integrazioni:** WPML, Polylang, import da 7 competitor, supporto custom post types
- **Test:** Nessuno
- **Type safety:** No strict types, no type hints
- **Coding standards:** Nessun tool visibile, nessun `composer.json`/`package.json`

### Real Media Library Lite (RML)

- **Storage:** 3 tabelle custom (`realmedialibrary`, `realmedialibrary_posts`, `realmedialibrary_meta`)
- **Frontend:** React + MobX State Tree, SortableJS, jQuery UI come fallback
- **D&D:** SortableJS 1.15.6 + jQuery UI + touch-punch
- **API:** REST API (`realmedialibrary/v1`), quasi zero admin-ajax
- **Namespace:** `MatthiasWeb\RealMediaLibrary\` con PSR-4 via Composer
- **State:** MobX State Tree per stato reattivo, dati iniziali via `wp_localize_script`
- **Architettura:** Modulare con trait-based Lite/Pro separation, Factory per tipi cartella, Singleton
- **Freemium:** Trait injection + `OnlyInProVersionException` (sottocartelle, ordinamento custom = Pro)
- **Integrazioni:** 15+ page builder, WPML, Polylang, Gutenberg block, shortcode galleria
- **Test:** Nessuno nel pacchetto distribuito (tag `@codeCoverageIgnore` suggeriscono test in repo privato)
- **Type safety:** Type hints presenti ma no `declare(strict_types=1)`
- **Coding standards:** Qualche `phpcs:disable`, documentazione hook estesa

---

## 2. Feature comparison

| Feature | FileBird | Folders | RML Lite | FoldSnap MVP |
|---------|:--------:|:-------:|:--------:|:------------:|
| Cartelle illimitate | Yes | Yes | Yes | Yes |
| Sottocartelle | Yes | Yes | No (Pro) | Yes |
| Colori cartelle | No (Pro) | No (Pro) | No (Pro) | Yes |
| Posizione/ordinamento cartelle | Yes | Yes | Yes | Yes |
| Conteggio media diretto | Yes | Yes | Yes (cached in DB) | Yes |
| Conteggio media ricorsivo | Yes | No | Yes (cached) | Yes |
| Size totale cartella | No | No | No | **Yes** |
| Drag & drop cartelle | Yes | Yes | Yes | Yes |
| Drag & drop media | Yes | Yes | Yes | Yes |
| Ricerca cartelle | Yes | Limitata | Yes | Yes |
| Auto-rename su conflitto | Yes | No | No | Yes |
| Sanitizzazione nomi | Yes (Excel) | No | No | Yes |
| Bulk operations | Yes | Yes | Yes | Yes |
| Selezione multipla + bulk drag | Yes | Yes | Yes | Yes |
| Cartelle per-utente | Yes (Pro) | No | No | Futuro |
| Import da competitor | 10 plugin | 7 plugin | No (Pro) | Futuro |
| Export struttura | CSV | CSV | No | Futuro |
| Shortcode/Gutenberg block | Yes | No | Yes (Pro) | Futuro |
| Shortcuts (media in piu' cartelle) | No | No | Yes (Pro) | No |
| Ordinamento custom media | No | No | Yes (Pro) | Futuro |
| Tipi cartella (Collection, Gallery) | No | No | Yes | No |
| RTL support | Yes | No | Yes | Futuro |

**Vantaggio esclusivo FoldSnap MVP:** Size totale per cartella (ricorsivo). Nessun competitor lo offre.

---

## 3. Scelte architetturali a confronto

| Aspetto | FileBird | Folders | RML Lite | FoldSnap |
|---------|----------|---------|----------|----------|
| **Storage** | 2 tabelle custom | Taxonomy WP nativa | 3 tabelle custom | Taxonomy WP nativa |
| **Gerarchia** | `parent` in tabella custom | `parent` in `wp_term_taxonomy` | `parent` in tabella custom + path assoluto | `parent` in `wp_term_taxonomy` |
| **Relazione media** | Tabella relazione custom | `wp_term_relationships` | Tabella custom (con shortcuts) | `wp_term_relationships` |
| **Metadati cartella** | `wp_options` (colori) | `wp_termmeta` | Tabella `_meta` custom | `wp_termmeta` |
| **Conteggio** | Query on-the-fly, 2 modalita' | `WP_Term->count` diretto | Campo `cnt` cached + batch update | `WP_Term->count` + ricorsivo in-memory |
| **Query ricorsive** | Ricorsione PHP | Ricorsione PHP | MySQL UDF con fallback | Ricorsione in-memory (buildTree) |
| **Frontend** | React (Vite) + RC-Tree | jQuery + jsTree | React + MobX State Tree | React (`@wordpress/scripts`) |
| **State management** | React hooks + global | Variabili globali JS | MobX State Tree | `@wordpress/data` (Redux-like) |
| **Drag & Drop** | jQuery UI | jQuery UI | SortableJS + jQuery UI | `@dnd-kit` (React-native) |
| **Build toolchain** | Vite custom | Nessuno visibile | Webpack custom | `@wordpress/scripts` (webpack) |
| **API** | REST API | admin-ajax (25+ azioni) | REST API | REST API |
| **Namespacing** | `FileBird\` PSR-4 custom | Nessuno (`WCP_` prefix) | `MatthiasWeb\RML\` PSR-4 Composer | `FoldSnap\` PSR-4 Composer |
| **Type safety** | No strict, no hints | No strict, no hints | Hints ma no strict | `strict_types` + hints + PHPStan |
| **Test automatizzati** | Nessuno | Nessuno | Nessuno (nel zip) | PHPUnit + Jest per ogni step |
| **Coding standards** | PHPCS parziale | Nessuno | PHPCS parziale | PHPCS + ESLint + plugin-check |
| **Static analysis** | Nessuno | Nessuno | Nessuno | PHPStan level 6+ |
| **CI quality gate** | Non visibile | Non visibile | Non visibile | `composer fullcheck` |

---

## 4. Analisi storage: Taxonomy vs Custom Tables

### Approccio FoldSnap e Folders: Taxonomy nativa

**Pro:**
- Zero schema custom, zero migration da gestire
- `wp_term_relationships` gia' indicizzata e ottimizzata da WordPress core
- Compatibilita' automatica con `WP_Query`, `tax_query`, `get_terms()`
- Backup/migrazione standard (export XML nativo include taxonomy)
- Meno codice da mantenere, meno superficie d'attacco per bug

**Contro:**
- Nessun campo custom nella tabella termini (servono `term_meta` per colore, posizione, ecc.)
- Conteggio nativo (`WP_Term->count`) solo diretto, ricorsivo richiede logica applicativa
- Vincoli WordPress su slug unici per taxonomy (risolvibile con sanitizzazione)

### Approccio FileBird e RML: Custom tables

**Pro:**
- Schema ottimizzato per il caso d'uso specifico (campi `ord`, `cnt`, `type`, ecc.)
- RML supporta shortcuts (media in piu' cartelle) grazie a chiave primaria composita
- Conteggio cached direttamente in tabella (RML: campo `cnt`)
- RML ha path assoluto pre-calcolato per lookup veloci

**Contro:**
- Richiedono activation hook per creare tabelle, migration per aggiornamenti schema
- Non compatibili con `WP_Query` standard senza hook `posts_clauses`
- Backup/migrazione richiedono logica custom
- Piu' codice da mantenere e testare
- RML usa addirittura una MySQL UDF (funzione stored) per query ricorsive — problematico su hosting condivisi

### Conclusione

La scelta taxonomy di FoldSnap e' coerente con il principio "meno codice, meno bug". Per l'MVP e' la scelta corretta. L'unico competitor che usa taxonomy (Folders/Premio) e' anche quello con la peggiore qualita' del codice, ma questo non invalida la scelta dello storage — semmai dimostra che il vantaggio di una base solida (taxonomy) puo' essere vanificato da un'architettura scadente.

---

## 5. Analisi frontend: Scelte tecnologiche

| | FileBird | Folders | RML | FoldSnap |
|---|---|---|---|---|
| **Framework** | React | jQuery | React + MobX | React |
| **Tree component** | RC-Tree (Ant Design) | jsTree (jQuery plugin) | Custom + SortableJS | Custom con `@dnd-kit` |
| **Build** | Vite | Nessuno | Webpack custom | `@wordpress/scripts` |
| **WP ecosystem alignment** | Basso (Vite custom) | Medio (jQuery nativo WP) | Basso (MobX, custom webpack) | **Alto** (`@wordpress/data`, `@wordpress/scripts`, `@wordpress/components`) |

**Osservazione chiave:** Nessun competitor usa `@wordpress/data` per state management. Tutti usano approcci custom (hooks globali, MobX, variabili globali). FoldSnap e' l'unico allineato all'ecosistema Gutenberg/WordPress moderno.

**D&D:** Tutti e tre i competitor dipendono da jQuery UI per drag & drop (anche quelli con frontend React). FoldSnap usa `@dnd-kit`, una libreria React-native che elimina la dipendenza da jQuery per questa funzionalita'.

---

## 6. Anti-pattern notabili nei competitor

### Folders (Premio) — Il caso peggiore
- **God class 7.530 righe:** Tutto in una classe. CRUD, AJAX, rendering, settings, asset loading
- **No namespace:** Inquinamento globale con prefisso `WCP_`
- **No build process:** JS minificato senza sorgenti o source map
- **No test, no static analysis, no coding standards tools**
- **admin-ajax per tutto:** 25+ endpoint AJAX anziche' REST API
- **N+1 queries:** `get_term_meta()` multipli in loop nel tree rendering
- **Server-side HTML rendering:** Genera `<ul><li>` nested lato server, poi jsTree li arricchisce client-side

### FileBird — Solido ma con zone grigie
- **`rawInsert()` senza prepare:** Query SQL concatenate → rischio SQL injection
- **`extract()` in Helpers:** Potenziale collisione variabili
- **Vite custom anziche' `@wordpress/scripts`:** Disallineamento dall'ecosistema WP
- **jQuery UI per D&D anche con frontend React:** Incoerenza architetturale
- **ID magici:** `-1` (All), `0` (Uncategorized), `-2` (Previous) senza costanti

### RML Lite — Il piu' sofisticato, ma over-engineered
- **MySQL UDF:** `fn_realmedialibrary_childs()` — funzione stored creata in activation. Problematica su hosting condivisi che limitano `CREATE FUNCTION`
- **Singleton ovunque:** Ogni service e' singleton → testing difficile
- **`wpbody { opacity: 0 }` durante il load:** Hack per evitare flickering
- **Path assoluto cascading:** Rinominare una cartella richiede update di tutti i figli — costoso su alberi profondi
- **Silent error handling:** `catch (Exception $e) {}` in alcuni punti

---

## 7. Spunti utili per FoldSnap

### Da prendere

1. **Count caching (RML):** Il campo `cnt` cached di RML evita query ripetute. FoldSnap fa gia' qualcosa di simile con il calcolo in-memory durante `buildTree()`, ma per alberi grandi si potrebbe valutare caching in `term_meta` in futuro.

2. **Batch update conteggi (RML):** RML aggiorna i conteggi alla fine della request (`wp_die` hook) per evitare aggiornamenti multipli durante operazioni bulk. Valutare come ottimizzazione post-MVP.

3. **Esclusione attachment speciali (FileBird):** `ModuleExclude` esclude dal conteggio gli attachment generati da plugin (screenshot Elementor, thumbnail PDF, cache W3TC). Da valutare post-MVP per conteggi accurati.

4. **Import da competitor (FileBird):** Factory pattern pulito con metodi specifici per ogni sorgente. Quando implementeremo l'import, seguire questo pattern.

5. **Protezione Excel injection (FileBird):** Sanitizzazione caratteri `=`, `+`, `@`, `|` all'inizio dei nomi. FoldSnap lo implementa gia' in `sanitizeName()`.

6. **Hook system per estensibilita' (RML):** 100+ action/filter documentati. Post-MVP, esporre hook su operazioni chiave (folder created, media assigned, ecc.) per permettere integrazioni terze.

### Da evitare

1. **Custom tables (FileBird, RML):** Complessita' aggiuntiva senza beneficio chiaro per il nostro use case. La taxonomy nativa e' sufficiente.

2. **MySQL UDF (RML):** Dipendenza da funzionalita' DB non garantite su tutti gli hosting. La ricorsione in-memory di FoldSnap e' piu' portabile.

3. **jQuery UI per D&D (tutti):** Legacy. `@dnd-kit` e' la scelta corretta per un frontend React moderno.

4. **God class (Folders):** L'anti-esempio per eccellenza.

5. **Singleton ovunque (RML, FileBird):** FoldSnap usa singleton solo dove serve (controller), preferendo dependency injection.

6. **admin-ajax (Folders):** REST API e' lo standard moderno per WordPress, con supporto nonce, permission callback, e schema validation built-in.

7. **No test (tutti):** Il punto debole piu' grave di tutti e tre i competitor. FoldSnap ha test per ogni step come requisito non negoziabile.

---

## 8. Conclusioni

FoldSnap e' sulla strada giusta. Le scelte architetturali (taxonomy nativa, `@wordpress/data`, `@dnd-kit`, REST API, strict types, test automatizzati) sono piu' moderne e solide di tutti e tre i competitor analizzati.

Il **vantaggio competitivo tecnico** principale non e' nelle feature (sono sostanzialmente allineate), ma nella **qualita' del codice**: test automatizzati, static analysis, type safety, e aderenza agli standard WordPress moderni. Nessun competitor distribuisce test o usa PHPStan.

Il **vantaggio funzionale esclusivo** dell'MVP e' il **calcolo size per cartella**, feature non presente in nessun competitor (nemmeno nelle versioni Pro).

Le aree dove i competitor sono piu' avanti (integrazioni page builder, import da altri plugin, cartelle per-utente, shortcode/Gutenberg) sono tutte feature post-MVP che possono essere aggiunte incrementalmente senza cambi architetturali.
