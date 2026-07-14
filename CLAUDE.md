# Coder-Session (REDAXO 5 Edition, Namespace-Variante)

Du bist die **Coder-Session** in einem Zwei-Session-Workflow. Du entwickelst
ein **REDAXO-5-AddOn** mit konsequenter Nutzung von **PHP-Namespaces**.
Eine parallele Reviewer-Session prüft deine Commits und schreibt Findings
nach `review/feedback.md`.

## Projekt-Kontext: REDAXO-5-AddOn mit Namespaces

Dies ist **kein generisches PHP-Projekt**. Du arbeitest mit REDAXO-5-Konventionen:

- AddOn-Struktur: `lib/`, `pages/`, `fragments/`, `boot.php`, `install.php`,
  `update.php`, `uninstall.php`, `package.yml`
- **REDAXO-5-Autoloader durchsucht `lib/` rekursiv** und findet:
  - Klassische Klassen ohne Namespace (z. B. `rex_demo_helper`)
  - **Namespaced Klassen** (z. B. `MyAddon\Helper`) — das ist unsere Wahl
- Konfiguration über `rex_config::set/get`, Defaults in `package.yml`
- Permissions, Pages, Subpages in `package.yml`

**Wichtig:** Ab REDAXO 6 ändert sich das AddOn-Modell auf Composer-Autoload
und PSR-4. Dieses AddOn zielt aber explizit auf REDAXO 5.

## Namespace-Konvention (verbindlich)

Alle eigenen Klassen liegen **mit Namespace** in `lib/`. Mapping:

```
lib/Helper.php              → namespace <Vendor>\<AddOn>;  class Helper
lib/Service/Importer.php    → namespace <Vendor>\<AddOn>\Service;  class Importer
lib/Value/OrderId.php       → namespace <Vendor>\<AddOn>\Value;  class OrderId
```

Der Top-Level-Namespace sollte das AddOn (oder Vendor + AddOn) eindeutig
identifizieren — z. B. `MyVendor\MyAddon` oder bei einfachen AddOns
`MyAddon`. Festlegen, dann konsequent durchziehen.

**Regeln:**
- **Kein** `rex_<addon>_*`-Präfix für neue Klassen. Wenn du auf historische
  Präfix-Klassen triffst, ist das ein MAJOR-Finding für den Reviewer.
- Klassenname = Dateiname (case-sensitive). `lib/Foo/Bar.php` → `Foo\Bar`.
- REDAXO-Core-Klassen (`rex_sql`, `rex_request`, `rex_addon` …) bleiben
  unverändert — die haben keine Namespaces und werden im globalen Namespace
  genutzt:
  ```php
  namespace MyAddon\Service;

  use rex_sql;
  use rex_addon;

  final class Importer {
      public function load(int $id): array {
          $sql = rex_sql::factory();
          // ...
      }
  }
  ```
- `use`-Statements am Anfang der Datei — keine voll-qualifizierten
  REDAXO-Klassenaufrufe (`\rex_sql::factory()`) im Code verstreuen.

## Pflicht-Regeln für REDAXO-Code

Diese Regeln **musst** du einhalten — sonst springt der Pre-Hook an oder der
Reviewer wirft BLOCKER-Findings:

### Eingaben
- **Niemals `$_GET`/`$_POST`/`$_REQUEST` direkt** — nutze `rex_request::get/post`
  bzw. `rex_get($key, $type, $default)` / `rex_post($key, $type, $default)`.
- Type-Casts mitgeben: `'int'`, `'string'`, `'bool'`, `'array'`.

### Datenbank
- **Niemals direktes PDO** — nutze `rex_sql::factory()`.
- **Niemals SQL-Strings mit String-Konkatenation** — Parameter binden:
  ```php
  $sql = rex_sql::factory();
  $sql->setQuery(
      'SELECT * FROM ' . rex::getTable('mytable') . ' WHERE id = ?',
      [$id]
  );
  ```
- Schema-Migrationen in `install.php`/`update.php` idempotent mit
  `rex_sql_table` oder `IF NOT EXISTS`.

### CSRF
- Jedes POST-Formular in `pages/` braucht `rex_csrf_token`:
  ```php
  // im Form:
  echo rex_csrf_token::factory('my-action')->getHiddenField();

  // beim Verarbeiten:
  if (!rex_csrf_token::factory('my-action')->isValid()) {
      throw new rex_exception('CSRF token invalid');
  }
  ```

### Permissions
- Page-Level in `package.yml`: `perm: myaddon[]`
- Code-Level: `if (!rex::getUser()->hasPerm('myaddon[]')) { ... }`

### Pfade
- **Niemals hardcodiert** `/redaxo/data/...`. Stattdessen:
  - `rex_path::addon('myaddon')` — AddOn-Code-Pfad
  - `rex_path::addonData('myaddon')` — schreibbarer Datenpfad
  - `rex_path::addonCache('myaddon')` — Cache
  - `rex_path::base()` — REDAXO-Root

### URLs
- **Niemals hardcodiert** `?page=...&func=...`. Stattdessen:
  - `rex_url::backendPage('myaddon/sub', ['id' => 42])`
  - `rex_url::currentBackendPage(['func' => 'edit', 'id' => 42])`

### Übersetzungen
- **Niemals hardcodierte UI-Strings**. Stattdessen `rex_i18n::msg('my_key')`
  mit Einträgen in `lang/de_de.lang` und `lang/en_gb.lang`.

### Logging
- **Niemals `error_log()`** für AddOn-Logs. Stattdessen `rex_logger::factory()`
  oder `rex_logger::logError(...)`.

### Debug-Output
- **Niemals `var_dump`/`print_r`/`dump`** im Committed Code. Der Hook erkennt
  und sperrt das. Während der Entwicklung OK, aber nicht committen.

## boot.php Sparsamkeit

`boot.php` läuft bei **JEDEM Request**, auch Frontend. Regeln:

- Schwere Logik nur in Backend-Kontext: `if (rex::isBackend()) { ... }`
- Schwere Logik nur für eingeloggte User: `if (rex::getUser()) { ... }`
- EP-Callbacks als statische Methoden namespaced Klassen referenzieren,
  nicht inline als große Closures:
  ```php
  use MyAddon\View\BodyClassExtension;

  rex_extension::register('PAGE_BODY_ATTR', [BodyClassExtension::class, 'add']);
  ```

## Workflow-Regeln

### review/ ist ein Symlink
`./review` zeigt auf den Reviewer-Worktree. Was der Reviewer schreibt, siehst
du **sofort** — kein `git pull` nötig.

### Vor jedem neuen Arbeitsschritt
1. `review/feedback.md` lesen (falls vorhanden).
2. Alle **BLOCKER** und **MAJOR** abarbeiten.
3. **NIT** als TODO-Kommentar im Code sammeln, nicht zwingend sofort fixen.
4. Erledigten Abschnitt aus `feedback.md` löschen (kein commit nötig —
   gitignored).

### Nach jedem logisch abgeschlossenen Stück
1. Commit mit aussagekräftiger Message.
2. Bei thematischen Sammlungen von Findings: eigenen `fix/<thema>`-Branch.

### Kommunikation zurück
- Code-Kommentar `// REVIEWER-NOTE: ...` für Antworten an den Reviewer
- `review/coder-notes.md` für längere Notizen

## Quality-Hooks

Bei jedem `Write`/`Edit` läuft `bin/check.sh` über die geänderte Datei. Es
prüft:

1. `php -l` (Syntax)
2. REDAXO-Anti-Patterns: Superglobals, hardcodierte Pfade, fehlender
   CSRF-Token in Forms, Debug-Output, `die()`/`exit()` in `lib/`
3. **Namespace-Konsistenz**: Klassen unter `lib/` müssen Namespaces nutzen,
   `rex_<addon>_*`-Klassennamen in neuem Code = FAIL
4. Optional: PHPCS, PHP-CS-Fixer, PHPStan, Psalm (wenn Config vorhanden)

Wenn der Hook failt, **siehst du die Fehler in deinem eigenen Context** —
fixe sie sofort.

Beim `git commit` läuft zusätzlich PHPUnit (falls konfiguriert).

**Notausgang** für temporär inkonsistente Zustände:
```bash
git commit --no-verify -m "wip"
```
Sparsam einsetzen, danach sauberen Lauf nachreichen.

## REDAXO-Tooling-Tipps

- DB-Migrationen idempotent: `rex_sql_table::get(rex::getTable('myaddon_things'))
  ->ensureColumn(new rex_sql_column('foo', 'varchar(255)'))->ensure()`.
- YForm-Tabellen: `rex_yform_manager_table::get()`.
- Setup-Issues sichtbar machen:
  `rex_addon::get('myaddon')->setProperty('installmsg', ...)`.
- Statische Analyse: PHPStan mit `staabm/phpstan-redaxo`-Extension hilft
  auch bei Namespace-Setup (siehe README).





This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`sprog` is a REDAXO 5 AddOn (>= 5.11) for multilingual websites. **v2 is an "inbox-first" redesign**: every translatable string is a language-independent **Unit** (`sprog_unit`) with one **Translation** (`sprog_translation`) per language, each carrying a value and a workflow status. A central **Inbox** lets editors filter, machine-translate, edit and review translations; a two-role workflow (translator/reviewer), a glossary, translation memory, coverage stats and per-value history back it.

Units are grouped by **namespace** (`Sprog\Enum\SourceType`):
- **wildcard** — placeholders like `{{ foo }}` in code/templates/content, replaced at output time with the current `clang_id`'s translation.
- **abbreviation** — terms auto-wrapped inside `<body>` as `<abbr title="…">…</abbr>`, per language.
- **foreignword** — foreign-language terms marked in the rendered HTML, per language.
- **article / slice / yform / media / custom** — fed by the sync path or third-party addons (need a `source_ref`); not user-creatable in the UI.

The first three are user-creatable (inbox modal / `pages/create.php`). Further features: **Sync** (keep article/category name, status, template and MetaInfo in sync across `rex_clang`), **Copy** (content/metadata across languages, chunked), **CSV artefact** (import/export via `symfony/serializer`), and an experimental **article language-comparison** in the content edit mask.

The addon is German-first; UI labels, lang files (`lang/*.lang`), and code comments are in German.

## Architecture

### Entry points
- `boot.php` — runs on every request. Registers permissions (`sprog[unit_edit]`, `sprog[translator]`, `sprog[reviewer]`), loads helper functions, builds the filter registry, hooks REDAXO extension points (incl. the article language-comparison via the `STRUCTURE_CONTENT_*` EPs), and persists `clang_base` in `PAGES_PREPARED` via `Sprog\Boot\PageTreeBuilder`. Frontend rewriting (wildcards, abbreviations, foreignwords) is wired here via three `OUTPUT_FILTER` registrations — none of them run in the backend. (The legacy `sprog[wildcard]`/`sprog[abbreviation]` perms and per-language wildcard/abbreviation backend pages were removed in v2; editing runs through the inbox.)
- `install.php` — runs `V1Schema::ensure()` (legacy `rex_sprog_wildcard`/`_abbreviation`/`_foreignword`, kept as a read-only fallback until v3), then `V2Schema::ensure()` (the six v2 tables, see Data model), then a **flag-guarded auto-migration** (`MigrationService::migrateAll()`, config flag `migration_autorun_done`) so a deploy migrates the instance itself. All steps are idempotent; a migration error is caught (installmsg) and never aborts the install. `update.php` just includes `install.php`; `uninstall.php` calls `V2Schema::drop()`.
- `package.yml` — declares the page tree (`dashboard`, `inbox`, hidden `create`, `glossary`, `datenpflege` → copy/CSV/migration, `settings`, `help`), the hidden `sprog.langcompare` AJAX endpoint, the eight built-in filters under `filter:`, and config defaults (`wildcard_open_tag`/`wildcard_close_tag`, `chunk_size_articles`, `workflow_dev_self_approve`).

### Namespace and autoloading
PSR-4-style: classes live under `lib/Sprog/` in the `Sprog\` namespace. REDAXO's class loader picks them up automatically — no `composer dump-autoload` step. Note `composer.json`'s `post-install-cmd` deliberately deletes `vendor/composer` and `vendor/autoload.php` after install so Composer's autoloader doesn't conflict with REDAXO's.

A legacy `class_alias('\Sprog\Wildcard', 'Wildcard')` lives in `boot.php` for back-compat with pre-1.3 code; do not rely on the global alias in new code.

### Data model (v2)
The v2 tables live alongside the v1 ones (v1 stays until v3). `status` and `namespace`/`source_type` are plain `varchar`, **not** MySQL ENUMs — the whitelists are the PHP enums `Sprog\Enum\Status` / `Sprog\Enum\SourceType`, validated in the service/repository layer.
- **`sprog_unit`** — language-independent anchor, one row per translatable thing. UNIQUE on (`namespace`, `context`, `unit_key`); `context` is an optional user-defined area so the same key can live in several scopes. Optional `source_type`/`source_ref` backlink to a REDAXO entity; `source_hash` (SHA-256 of the source value) drives stale-detection; `tags`/`notes` are JSON.
- **`sprog_translation`** — one row per (`unit_id`, `clang_id`) (UNIQUE). Holds `value`, `value_hash`, `source_hash_at_translation` (compared against `unit.source_hash` → *stale*), `status`, MT metadata (`mt_provider`/`mt_confidence`), `translator_id`/`reviewer_id`, and `revision` (optimistic lock).
- **`sprog_glossary`** — binding term pairs per language pair, injected into the MT prompt.
- **`sprog_tm`** — translation memory: non-binding fuzzy-match suggestions.
- **`sprog_activity`** — append-only audit log (hashes only, no plaintext).
- **`sprog_translation_history`** — append-only full-value snapshots for the inbox undo/restore.

### Translation workflow
Status flow (`Sprog\Enum\Status`): `missing → draft → needs_review → approved`, plus `revise` (reviewer returns for rework) and `stale` (system-set when the source changed). Only `approved` is final. Allowed transitions are whitelisted in `Status::allowedNextStates()`; **who** may trigger each is decided by `Sprog\Service\WorkflowService` from roles: `sprog[translator]` submits (》Zur Prüfung《), `sprog[reviewer]` approves/returns, and someone with both (or admin) can direct-approve without the review step. Transition validation lives in `TranslationService`; an empty value resets a translation to `missing` (bypassing the whitelist by design).

### Lib layout
`lib/Sprog/`: `Controller/Inbox/*` (AJAX handlers), `Service/*` (business logic — `TranslationService`, `WorkflowService`, `MtService`, the `*LookupService`s, `MigrationService`, `StructureSyncService`, `GlossaryService`, `CoverageService`, …), `Repository/*` (SQL for units/translations/glossary/activity/history), `Model/*` (value objects), `Enum/*` (`Status`, `SourceType`), `Mt/*` (translation providers), `Migration/*` (v1→v2 migrators), `Compat/*` (deprecated v1 API adapters), `Support/*` (`BaseLang`, `ClangBase`, `ContentHash`, `Labels`), `View/*`, `Boot/*` (asset/filter/page-tree registration), `Filter/*`, `Schema/*`.

### Inbox (backend)
`pages/inbox.php` renders the list; AJAX actions are dispatched by `Sprog\Controller\Inbox\InboxRouter` to focused controllers — `SaveTranslationController`, `TransitionController`, `UpdateUnitController`, `CreateUnitController`, `BatchTranslateController`, `MtController`, `HistoryController`. The filtered/paginated list is built by `TranslationListService` (filters modelled in `TranslationListFilter`); `CoverageService` computes the per-language coverage shown on the dashboard.

### Machine translation (MT)
`Sprog\Service\MtService` orchestrates providers implementing `Sprog\Mt\ProviderInterface`: `NoopProvider` (default), `DeepLProvider`, and `AiPlatformProvider` (LLM via the optional `ai_platform` addon). The provider is chosen from config and the glossary for the language pair is passed in. `AiPlatformProvider` prompts a strict "translate-only" role and rejects implausibly long output (hallucination guard). Results are a `Sprog\Mt\TranslationResult` (`text`, `provider`, `confidence`) and are always a *draft suggestion*, never auto-approved.

### Frontend rendering: wildcards, abbreviations, foreignwords
There is no wildcard/abbreviation/foreignword backend page any more — editing runs through the inbox. Output-time replacement is frontend-only: `boot.php` registers three `OUTPUT_FILTER`s → `Extension::replaceWildcards/replaceAbbreviations/replaceForeignwords` → the deprecated `Sprog\Compat\Wildcard/Abbreviation/Foreignword` adapters → the v2 `*LookupService`s. Each lookup is **v2-first with a v1 fallback** (the v1 table is only read when the entire v2 map for a clang is empty).

Wildcards specifically: tags are configurable (`wildcard_open_tag`/`wildcard_close_tag`, default `{{ ` / ` }}`), the regexp is built in `Wildcard::getRegexp()` (captures `wildcard`, `filter`, `arguments`), `Wildcard::parse()` resolves all matches in a string for one `clang_id`, `Wildcard::get()` resolves one. **clang_base** (config `clang_base`, an array `clang_id => clang_id`) lets one language reuse another's replacements; the remapping is applied before every lookup — keep this in mind on any new read path. `Sprog\Wildcard`/`Abbreviation`/`Foreignword` are deprecated BC aliases over the `Compat\*` classes.

### Filters
`Sprog\Filter` is an abstract base with `name()` and `fire($value, $arguments)`. Built-ins live in `lib/Sprog/Filter/*` and are listed under `filter:` in `package.yml`. Third-party code can register more via the `SPROG_FILTER` extension point; `boot.php` instantiates each and stores `name => instance` in `rex::setProperty('SPROG_FILTER', …)`.

### Sync
The sync mechanics live in `Sprog\Service\StructureSyncService`; the thin `Extension` handlers only read config and dispatch. `Sprog\Compat\Sync` (and its `Sprog\Sync` alias) is a deprecated BC adapter that translates the old EP-param arrays into the service's typed calls.

Two axes, gated by individual config keys:
- **Across languages** (`clang_id != :clang`): status (`sync_structure_status`), template (`sync_structure_template`), MetaInfo fields (`sync_metainfo_art` / `sync_metainfo_cat`).
- **Within one language** (`clang_id = :clang`, start article only): category `catname` ↔ start-article `name` (`sync_structure_category_name_to_article_name` / `sync_structure_article_name_to_category_name`).

Handlers fire only on the matching event: status on `ART_STATUS`/`CAT_STATUS` (→ `Extension::statusUpdated`), name+template on `ART_UPDATED` (`articleUpdated`), article MetaInfo on `ART_META_UPDATED` (`articleMetadataUpdated`), category name+MetaInfo on `CAT_UPDATED` (`categoryUpdated`). The core fires no `CAT_META_UPDATED`, so category MetaInfo rides `CAT_UPDATED`; that and `ART_META_UPDATED` register `rex_extension::LATE` so MetaInfo writes its data first. `StructureSyncService::syncMetainfoAcrossLanguages(..., $toClangId)` is also used by the copy feature (`Copy\StructureMetadata`) to copy into one target language. Media sync is intentionally off (the REDAXO media pool is not multilingual).

### Helper functions (`functions/sprog.php`)
Global helpers used in templates/modules:
- `sprogdown($text, $clang_id = null)` — parse a string (wraps `Wildcard::parse`).
- `sprogcard($wildcard, $clang_id = null)` — resolve a single wildcard (wraps `Wildcard::get`).
- `sprogfield($field, $sep = '_')` — append the current clang_id, e.g. `sprogfield('name')` → `name_1`.
- `sprogarray($array, $fields, $fallback_clang_id = 0, $sep = '_')` / `sprogvalue(...)` — pick the right clang-suffixed key with a fallback chain.

### Copy, CSV artefact, migration (Datenpflege)
Under the admin-only `datenpflege` node: **Copy** (`pages/copy.structure_content.php` / `copy.structure_metadata.php` + `Sprog\Copy\*`) copies/synchronises content or metadata across languages via a chunked generator flow (`chunk_size_articles` config, default 4). **CSV artefact** (`pages/artefact.import.php` / `artefact.export.php` + `Sprog\Export\CsvExport`, using `symfony/serializer`) is the bulk import/export. **Migration** (`pages/migration.php` + `Sprog\Service\MigrationService` + `Sprog\Migration\*Migrator`) is the manual v1→v2 tool — the same code the install-time auto-migration runs — chunked and idempotent.

## Running, testing, building

This is a plain REDAXO addon — there is no build system, no lint config, and no test suite. To work on it you need a running REDAXO 5.11+ install with the addon symlinked or copied into `redaxo/src/addons/sprog/`.

- **Install vendor deps** (rarely needed; `vendor/` is committed and the post-install script removes Composer's autoloader): `composer install` from the addon directory.
- **Apply install.php** (creates/updates DB tables): re-install the addon via the REDAXO backend (System → AddOns), or call its `install.php` through REDAXO's API.
- **Assets**: registered in `Sprog\Boot\AssetRegistry` (not inline in `boot.php`), `?v=` cache-busted from the addon version. v2 styles are in `assets/css/sprog.v2.css`; JS is split into per-page bundles (`sprog.inbox.js`, `sprog.migration.js`, `sprog.copy.js`, `sprog.langcompare.js`) loaded only where needed. No JS/CSS build step — edit in place; REDAXO republishes addon assets on (re)install.

## Conventions

- All user-facing strings go through `rex_i18n` keys defined in `lang/*.lang` (German is canonical in `de_de.lang`).
- Permission checks: page-level perms live in `package.yml` (`sprog[]`, `admin[]`). For per-language access (e.g. the article language-comparison endpoint), gate on `rex::getUser()->getComplexPerm('clang')->hasPerm($id)`.
- Backend-only behavior (sync/language-comparison EP registration, `clang_base` persistence, asset loading) must stay inside the `if (rex::isBackend() && rex::getUser())` block in `boot.php`; the frontend `OUTPUT_FILTER` hooks live in the `if (!rex::isBackend())` block above it.
- When adding extension-point handlers that observe metadata, register them `LATE` so MetaInfo writes finish first (mirrors the existing `ART_META_UPDATED` / `CAT_UPDATED` registrations).
