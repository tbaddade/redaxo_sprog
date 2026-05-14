# Coder-Session

Du bist die **Coder-Session** in einem Zwei-Session-Workflow. Eine parallele
Reviewer-Session prüft deine Commits und schreibt Findings nach
`review/feedback.md`.

## Workflow-Regeln

Vor jedem neuen logischen Arbeitsschritt:

1. Wenn `review/feedback.md` existiert: vollständig lesen.
2. Alle **BLOCKER** und **MAJOR** abarbeiten, bevor du an neuen Features
   weiterbaust.
3. **NIT**-Punkte sammelst du, arbeitest sie aber nicht zwingend sofort ab.
4. Nach dem Abarbeiten: `review/feedback.md` löschen (`rm review/feedback.md`),
   die Antwort kommt im nächsten Review-Zyklus.

Nach jedem logisch abgeschlossenen Stück:

1. Commit auf den aktuellen Feature-Branch mit aussagekräftiger Message.
2. Pro Feature ein eigener Branch (`feat/<kurzname>`), nicht direkt auf `main`.

## Kommunikation mit der Reviewer-Session

Die Reviewer-Session liest deine Commits per `git fetch` aus einem parallelen
Worktree. Sie schreibt ausschließlich nach `review/feedback.md`. Du
kommunizierst zurück, indem du:

- die genannten Punkte im Code adressierst,
- die Datei nach dem Abarbeiten löschst,
- bei Uneinigkeit einen Kommentar `// REVIEWER-NOTE: ...` im Code hinterlässt,
  den der Reviewer im nächsten Durchgang sieht.

## Branch-Strategie

- Niemals direkt auf `main`/`master` committen
- Pro Aufgabe ein Branch `feat/<kurzname>` oder `fix/<kurzname>`
- Vor Branch-Erstellung: `git fetch && git checkout main && git pull`

## Was *nicht* hier reingehört

Linter-Regeln, Test-Commands, Architektur-Erklärungen → in eine separate
`CLAUDE.md` im Projekt-Wurzelverzeichnis oder die bestehende ergänzen.
Diese Datei ist nur für die Workflow-Mechanik.


This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`sprog` is a REDAXO 5 AddOn (>= 5.11) that provides multilingual features for REDAXO websites:

- **Wildcards** — placeholders like `{{ foo }}` written into code/templates/content that are replaced at output time with the translation for the current `clang_id`.
- **Abbreviations** — auto-wraps configured terms inside `<body>` with `<abbr title="…">…</abbr>`, per language.
- **Foreignwords** — marks foreign-language terms in the rendered HTML, per language.
- **Sync** — keeps article/category names, status, template, and selected MetaInfo fields in sync across `rex_clang` languages.
- **Copy** — copy article/category content or metadata from one language to another (incl. popup + chunked generate pages).
- **Artefact** — CSV import/export of wildcards (uses `symfony/serializer`).

The addon is German-first; UI labels, lang files (`lang/*.lang`), and code comments are in German.

## Architecture

### Entry points
- `boot.php` — runs on every request. Registers permissions (`sprog[abbreviation]`, `sprog[wildcard]`), loads helper functions, builds the filter registry, hooks REDAXO extension points, and dynamically builds clang sub-pages in `PAGES_PREPARED`. Frontend rewriting (wildcards, abbreviations, foreignwords) is wired here via three `OUTPUT_FILTER` registrations — none of them run in the backend.
- `install.php` — creates three tables: `rex_sprog_wildcard`, `rex_sprog_abbreviation`, `rex_sprog_foreignword`. Wildcard rows are scoped by `(clang_id, wildcard)`; the addon links them across languages via a shared `id` column (distinct from the `pid` primary key) so the same wildcard in different languages share an `id`.
- `package.yml` — declares the page tree, registers the eight built-in filters under the `filter:` key, and seeds `wildcard_open_tag`/`wildcard_close_tag` config.

### Namespace and autoloading
PSR-4-style: classes live under `lib/Sprog/` in the `Sprog\` namespace. REDAXO's class loader picks them up automatically — no `composer dump-autoload` step. Note `composer.json`'s `post-install-cmd` deliberately deletes `vendor/composer` and `vendor/autoload.php` after install so Composer's autoloader doesn't conflict with REDAXO's.

A legacy `class_alias('\Sprog\Wildcard', 'Wildcard')` lives in `boot.php` for back-compat with pre-1.3 code; do not rely on the global alias in new code.

### Wildcard pipeline
1. Tags are configurable (`wildcard_open_tag`, `wildcard_close_tag`, default `{{ ` / ` }}`) and the regexp is built in `Wildcard::getRegexp()`. It captures `wildcard`, `filter`, and `arguments`.
2. `Wildcard::parse()` resolves all matches in a string for one `clang_id` in a single SQL query; `Wildcard::get()` resolves one wildcard.
3. **clang_base** (config key `clang_base`, an array `clang_id => clang_id`) lets one language reuse another language's replacements. Any lookup applies this remapping before hitting the DB — keep this in mind when adding new wildcard read paths.
4. **Clang switch mode** (`wildcard_clang_switch` config) toggles the wildcard backend page between two layouts: `pages/wildcard.clang_switch.php` (one language at a time, with per-language subnav) and `pages/wildcard.clang_all.php` (all languages in one form). The page tree is built dynamically in `boot.php`'s `PAGES_PREPARED` hook; languages where a user lacks `complexPerm('clang')` are excluded, as are languages whose `clang_base` points elsewhere.

### Filters
`Sprog\Filter` is an abstract base with `name()` and `fire($value, $arguments)`. Built-ins live in `lib/Sprog/Filter/*` and are listed under `filter:` in `package.yml`. Third-party code can register more via the `SPROG_FILTER` extension point; `boot.php` instantiates each and stores `name => instance` in `rex::setProperty('SPROG_FILTER', …)`.

### Sync
`Sprog\Sync` is wired in `Extension::articleUpdated/categoryUpdated/articleMetadataUpdated` and gated by individual config keys (`sync_structure_article_name_to_category_name`, `sync_structure_category_name_to_article_name`, `sync_structure_status`, `sync_structure_template`, `sync_metainfo_art`, `sync_metainfo_cat`). The `ART_META_UPDATED` / `CAT_UPDATED` hooks are registered with `rex_extension::LATE` so MetaInfo writes its data first. Media sync is intentionally commented out (the REDAXO media pool is not multilingual).

### Helper functions (`functions/sprog.php`)
Global helpers used in templates/modules:
- `sprogdown($text, $clang_id = null)` — parse a string (wraps `Wildcard::parse`).
- `sprogcard($wildcard, $clang_id = null)` — resolve a single wildcard (wraps `Wildcard::get`).
- `sprogfield($field, $sep = '_')` — append the current clang_id, e.g. `sprogfield('name')` → `name_1`.
- `sprogarray($array, $fields, $fallback_clang_id = 0, $sep = '_')` / `sprogvalue(...)` — pick the right clang-suffixed key with a fallback chain.

### Pages
Live under `pages/`. The `copy.*` and `sprog.copy.*` pages implement a popup + chunked generator flow; `chunkSizeArticles` in `boot.php` (default 4) tunes how many articles each generate request handles. The artefact import/export pages live under `pages/artefact.*.php` and use `symfony/serializer` via `lib/Sprog/Export/CsvExport.php`.

## Running, testing, building

This is a plain REDAXO addon — there is no build system, no lint config, and no test suite. To work on it you need a running REDAXO 5.11+ install with the addon symlinked or copied into `redaxo/src/addons/sprog/`.

- **Install vendor deps** (rarely needed; `vendor/` is committed and the post-install script removes Composer's autoloader): `composer install` from the addon directory.
- **Apply install.php** (creates/updates DB tables): re-install the addon via the REDAXO backend (System → AddOns), or call its `install.php` through REDAXO's API.
- **Frontend assets**: `assets/css/sprog.css` and `assets/js/sprog.js` are loaded directly in `boot.php` with `?v=` cache-busting from `$this->getVersion()`. There is no JS/CSS build step — edit them in place.

## Conventions

- All user-facing strings go through `rex_i18n` keys defined in `lang/*.lang` (German is canonical in `de_de.lang`).
- Permission checks: page-level perms live in `package.yml` (`sprog[]`, `sprog[abbreviation]`, `sprog[foreignword]`, `admin[]`). For per-language sub-pages, gate on `rex::getUser()->getComplexPerm('clang')->hasPerm($id)` as `boot.php` does.
- Backend-only behavior (sub-page registration, asset loading) must stay inside the `if (rex::isBackend() && rex::getUser())` block in `boot.php`; the frontend `OUTPUT_FILTER` hooks live in the `if (!rex::isBackend())` block above it.
- When adding extension-point handlers that observe metadata, register them `LATE` so MetaInfo writes finish first (mirrors the existing `ART_META_UPDATED` / `CAT_UPDATED` registrations).
