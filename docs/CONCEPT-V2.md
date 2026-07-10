# Sprog v2 — Konzept

Stand: Entwurf. Lebendes Dokument auf Branch `v2/redesign` (ausgehend von `master`).

## 1. Positionierung

**Heute:** Sprog ist ein gut gemeinter Wildcard-Ersetzer mit ein paar Sync-Komfort-Funktionen drumherum. Die meiste Übersetzungsarbeit passiert ausserhalb des Addons (im Artikel-Editor, in YForm-Datasets, in Modulen mit `_1`/`_2`-Feldern).

**Sprog v2:** *Das* zentrale Sprach-Cockpit für REDAXO. Eine Stelle, an der Übersetzer, Redakteure und Entwickler sehen, was übersetzt ist, was fehlt, was sich geändert hat — und es dort auch erledigen können, mit optionaler KI-Unterstützung. Drei Nutzer, drei klare Modi:

- **Übersetzer:** Side-by-side-Editor, eine Aufgabe nach der anderen, MT-Vorschlag akzeptieren/ablehnen.
- **Redakteur:** Sieht Coverage, schiebt Inhalte zwischen Sprachen, prüft Reviews.
- **Entwickler:** Saubere PHP-API + REST + Twig-Filter, Wildcards funktionieren überall, performant, gecacht.

## 2. Eckdaten

| Punkt | Entscheidung |
|---|---|
| v1-Kompat | Migration mit Frist. v1-API lebt in v2.x mit Deprecation-Notices, v3.0 entfernt sie. Migrations-Befehl ist Tag-1-Feature. |
| Machine Translation | Kernfeature im Core, nicht Plug-in. Provider-Interface ist Teil der Service-Schicht. |
| Scope | Full content translation hub: Wildcards + Abbreviation/Foreignword + Strukturartikel + Slices + YForm + Mediapool. |
| PHP-Min | 8.4 |
| REDAXO-Min | 5.21 |

## 3. Leitprinzipien

1. **Eine Wahrheit pro Übersetzung**, mit Status (`missing / draft / translated / needs_review / approved / stale`) und Audit-Trail.
2. **Performance ist Feature**: gecachte Lookups, getriggerte Cache-Invalidation, kein Full-Scan-Regex pro Request.
3. **Strict-typed PHP 8.4**, Tests, PHPStan Level 8. Der heutige Code (kein `strict_types`, kaum Type-Hints, fehlende Indizes, Composer-Autoload-Hack) wird durchgängig saniert.
4. **API-first**: Jede Backend-Aktion ist auch via REST erreichbar. UI ist nur ein Client.
5. **Erweiterbar statt Monolith**: Wildcards, Abbreviation, Foreignword, Glossar, TM, MT-Provider, Importer/Exporter — alles über klare Interfaces, andere Addons können andocken.
6. **Migration vor Bruch**: v1-Daten bleiben lesbar, Helper (`sprogdown`, `sprogcard`, `sprogfield`) bleiben in v2.x erhalten und werden intern auf das neue Modell gemappt.

## 4. Roadmap

### v1.7 — Stabilisierung (auf `master`, kein Bruch)

Schmales, schnelles Release für Bestandsprojekte:

- Fehlende Indizes auf `rex_sprog_wildcard` (`UNIQUE (clang_id, wildcard)`, plus `INDEX (id)` für Cross-Lang-Lookups).
- Request-Cache für `Wildcard::get/parse`, persistenter Cache pro `clang_id` via `rex_cache` mit Invalidation bei DB-Write.
- Aho-Corasick-Matcher für Abbreviation/Foreignword (heute N × `preg_replace_callback`).
- OUTPUT_FILTER nur aktivieren, wenn Body Open-Tag-Sequence enthält (Pre-Check spart bei Wildcard-freien Seiten praktisch alles).
- Deprecation-Notices auf alten Klassen-Aliasen / Helper-Signaturen.
- Kein Datenmodell-Bruch, kein UX-Umbau.

### v2.0-alpha (Branch `v2/redesign`) — neuer Kern

- Neues Schema (`sprog_unit`, `sprog_translation`, `sprog_glossary`, `sprog_tm`, `sprog_activity`) **parallel** zu v1-Tabellen.
- Migration v1 → v2: `Wildcard`-Rows werden zu `sprog_unit` (`type=wildcard`) + n × `sprog_translation`. `Abbreviation`/`Foreignword` gespiegelt mit Backlink.
- Service-Layer (`TranslationService`, `GlossaryService`, `TmService`, `MtService`, `CoverageService`, `ImportService`, `ExportService`).
- Neue Backend-UI: Coverage-Dashboard, zentraler Translation-Inbox-View, Side-by-side-Editor.
- v1-API bleibt vollständig erreichbar, intern via Compat-Layer auf v2 gemappt.

### v2.1 — MT-Provider + Content-Module

- DeepL, OpenAI, Anthropic Claude als drei initiale Provider. Provider-Interface ist von Beginn an stabil dokumentiert, damit Community weitere ergänzen kann.
- Glossar-Awareness in MT-Prompts (Claude/OpenAI: Glossar in System-Prompt; DeepL: native Glossary-API).
- Slice-Translation-View für Module mit `_<clang>`-Spalten oder benannten Multi-Lang-Feldern (Modul-Manifest).
- YForm-Bridge: Datasets mit `clang_id`-Spalte oder Sprach-Suffix-Spalten erscheinen automatisch in Coverage und Inbox.

### v2.2 — Mediapool + SEO

- Mediapool-Metadaten mehrsprachig (Titel/Description/Copyright pro `clang_id`), optional alternative Datei pro Sprache.
- `hreflang`-Generator, Sprachweiche-Komponente, YRewrite-Brücke (Auto-Mapping `clang_base` ↔ YRewrite-Domain, Konsistenz-Check).

### v3.0 — v1-API entfernt

- Migrations-Befehl wird Pflicht.
- v1-Tabellen werden per `uninstall.php`-Schritt entfernbar.
- Frist: 12 Monate nach v2.0-final.

## 5. Architektur

```
┌────────────────────────────────────────────────────────────────────┐
│  UI: Coverage · Inbox · Side-by-side Editor · Settings · Reports   │
│  REST API (rex_api_function-basiert)                               │
│  Frontend Helpers (PHP, Twig, Web Component)                       │
├────────────────────────────────────────────────────────────────────┤
│  Sprog\Service                                                     │
│   TranslationService  CoverageService  GlossaryService             │
│   TmService           MtService        ActivityService             │
│   ImportService       ExportService                                │
├────────────────────────────────────────────────────────────────────┤
│  Sprog\Source            Sprog\Mt\Provider                         │
│   WildcardSource          DeepLProvider                            │
│   ArticleSource           OpenAiProvider                           │
│   SliceSource             ClaudeProvider                           │
│   YFormSource             (Interface: MtProviderInterface)         │
│   MediapoolSource                                                  │
│  (Interface: TranslationSourceInterface)                           │
├────────────────────────────────────────────────────────────────────┤
│  Sprog\Repository    Sprog\Cache    Sprog\Events    Sprog\Compat   │
├────────────────────────────────────────────────────────────────────┤
│  DB + REDAXO Kern (rex_clang, rex_article, rex_media, …)           │
└────────────────────────────────────────────────────────────────────┘
```

Zwei Erweiterungspunkte sind explizit als Interfaces ausgelegt:

- **`TranslationSourceInterface`** — jede Inhaltsart (Wildcards, Artikel, Slices, YForm-Datasets, Medienpool) implementiert diese Schnittstelle. Drittaddons können eigene Quellen registrieren: ein Shop-Addon registriert Produkt-Texte als Source, sie tauchen automatisch in Coverage und Inbox auf.
- **`MtProviderInterface`** — drei Provider im Core, jeder weitere als Drop-in. Provider deklariert Sprachpaare, Kosten-Schätzung, Glossar-Support.

## 6. MT als Kernfeature

**UX-Pattern:**

- Im Side-by-side-Editor: ein Klick "MT-Vorschlag" zeigt den Vorschlag des konfigurierten Default-Providers, Übersetzer akzeptiert, editiert oder verwirft. Akzeptierte Vorschläge gehen als `status=draft, mt_provider=…, mt_confidence=…` rein.
- **Batch**: "Alle fehlenden EN-Übersetzungen erzeugen" → läuft als Job, schreibt alles als `status=draft` mit MT-Marker. Übersetzer sieht in Inbox alle Drafts, reviewt sie.
- **Glossar-Injection**: bei jedem MT-Call werden relevante Glossar-Einträge automatisch im Prompt (Claude/OpenAI) bzw. via Glossary-ID (DeepL) mitgegeben.

**Sicherheit / Kosten:**

- API-Keys über REDAXO-Config + optional `.env`-Override (`SPROG_MT_DEEPL_KEY` etc.), nie im Klartext in UI sichtbar nach Speicherung.
- Budget-Wächter: Hard-Limit pro Tag/Monat in Zeichen oder Tokens, sichtbar im Dashboard.
- Audit-Log: welcher User hat wann welchen Provider mit wie vielen Zeichen aufgerufen.

**Default-Provider-Empfehlung:** Claude (`claude-sonnet-4-6` für Marketing/Tonalität, `claude-haiku-4-5` für Bulk), DeepL für rechtlich/technisch präzise Übersetzungen. Provider pro Sprachpaar oder pro Tag konfigurierbar.

## 7. Full Content Translation Hub — Quellen

| Quelle | Wie sie erscheint | Release |
|---|---|---|
| Wildcards | Wie heute, aber im neuen Editor | v2.0 |
| Abbreviation / Foreignword | Eigene Reiter, in Coverage integriert | v2.0 |
| Strukturartikel (Name/Catname/Meta) | Pro Artikel ein Side-by-side-Editor, Status pro Sprache | v2.0 |
| Slices (Modul-Inhalte) | Modul deklariert Multi-Lang-Felder (`_<clang>` oder Manifest), dann Auto-Discovery | v2.1 |
| YForm-Datasets | Tabellen mit `clang_id`-Spalte oder `_<clang>`-Spalten werden auto-erkannt | v2.1 |
| Mediapool-Metadaten | Title/Description/Copyright pro `clang_id` | v2.2 |
| Custom (Shop, Events, …) | Drittaddons registrieren via `TranslationSourceInterface` | ab v2.0 |

## 8. Datenmodell — Skizze v2.0

```sql
sprog_unit
  id PK, namespace, key, source_type, source_ref, source_hash, tags JSON, notes,
  created_at, created_by, updated_at, updated_by
  UNIQUE (namespace, key)
  INDEX (source_type, source_ref)

sprog_translation
  id PK, unit_id FK, clang_id, value LONGTEXT, value_hash,
  source_hash_at_translation,       -- für "stale"-Detection
  status ENUM('missing','draft','translated','needs_review','approved','stale'),
  mt_provider, mt_confidence DECIMAL(3,2),
  translator_id, reviewer_id, revision,
  created_at, updated_at
  UNIQUE (unit_id, clang_id)
  INDEX (clang_id, status)

sprog_glossary
  id PK, source_clang, target_clang, source_term, target_term, notes
  UNIQUE (source_clang, target_clang, source_term)

sprog_tm
  id PK, source_clang, target_clang, source_segment, target_segment, source_hash
  INDEX (source_clang, target_clang, source_hash)

sprog_activity
  id PK, unit_id, translation_id, user_id, action, payload JSON, created_at
  INDEX (unit_id, created_at)
```

`sprog_wildcard`, `sprog_abbreviation`, `sprog_foreignword` bleiben v1-Schema unverändert während v2.x. Compat-Layer schreibt parallel, liest wahlweise.

## 9. Pluralisierung & Formatierung

- Wildcards können ICU-MessageFormat-Syntax tragen: `{{ cart.items, plural, one {# Artikel} other {# Artikel} }}`.
- Auf `intl`-Extension aufgesetzt; harter Fail mit klarer Fehlermeldung wenn `intl` fehlt (PHP 8.4 bringt sie üblicherweise mit).
- Datums-, Zahlen-, Währungsformat-Helper pro `clang_id`.

## 10. Frontend & Performance

- **Wildcard-Lookups** über In-Memory-Cache pro Request, vorgespeist aus persistentem Cache (`rex_cache`).
- **Cache-Invalidation** über `EXTENSION_POINT`s — nicht über Vollscans.
- **OUTPUT_FILTER** nur dann aktiv, wenn die Seite tatsächlich Wildcards/Abbreviations/Foreignwords enthalten könnte. Heute laufen drei `preg_match_all` über jeden Response, auch wenn die Seite keinen einzigen Wildcard hat.
- **Abbreviation/Foreignword**: Aho-Corasick / Trie-Matcher statt N × `preg_replace_callback`. Bei vielen Einträgen Faktor 10–100 schneller.
- **JSON/XML-Safe Mode** für API-Responses: optional anderer Tag-Stil oder gezielter Lookup-Modus statt OUTPUT_FILTER.

## 11. Developer-API

- **PHP-API**: `Sprog::trans($key, $clang, $vars)`, `Sprog::translations($key)`, `Sprog::missing($clang)` — sauber namespaced, typisiert. Deprecated Aliase auf alte `Wildcard::*`.
- **REST-API** (über `rex_api_function`): `GET /sprog/translations?clang=2&status=missing`, `PUT /sprog/translations/:id`, `POST /sprog/mt/translate`.
- **Twig-Filter**: `{{ "hero.title"|sprog }}` falls Projekt Twig hat.
- **Webhook**: bei Statuswechsel `→ approved` extern triggerbar (z.B. Build-Pipeline).

## 12. Import/Export

- **XLIFF 2.1** als Standard (Industrieformat — jeder professionelle Übersetzer kann das).
- **JSON** (für Frontend-Entwickler / Headless-Setups).
- **CSV** bleibt aus Kompatibilitätsgründen.
- **TMX** für Translation-Memory-Austausch.
- **Optional**: direkte Anbindung an Crowdin/Phrase/Lokalise via Adapter — als separate Plugin-Addons, nicht im Core.

## 13. Permissions

| Perm | Bedeutung |
|---|---|
| `sprog[view]` | Sieht Coverage + alle Übersetzungen lesend |
| `sprog[edit]` | Darf Übersetzungen editieren (in erlaubten Sprachen) |
| `sprog[review]` | Darf Status `needs_review → approved` setzen |
| `sprog[publish]` | Darf Inhalte zwischen Sprachen kopieren/syncen |
| `sprog[mt]` | Darf MT-Provider triggern (kostet Geld!) |
| `sprog[import]` | Darf XLIFF/CSV importieren |
| `sprog[admin]` | Settings + Provider-Keys |

Pro `clang_id`-Berechtigung weiter über REDAXO-Complex-Perm.

## 14. Reporting

- Coverage pro Sprache (in %, absolut).
- "Stale"-Übersetzungen (Quelle hat sich seit Übersetzung geändert, `source_hash != source_hash_at_translation`).
- Tote Wildcards (existieren in DB, werden nirgendwo verwendet — per Code-Scan).
- Fehlende Wildcards (im Code referenziert, nicht in DB) — über AST/Regex-Scan über `redaxo/data/addons` + `redaxo/src`.
- Aktivitäts-Log: wer hat wann was übersetzt.

## 15. Tooling — verbindlich für v2

- **PHP 8.4**, `declare(strict_types=1)` in jeder Datei.
- **PHPStan Level 8** auf `lib/` (Pages dürfen Level 6).
- **PHP-CS-Fixer** PSR-12 + Symfony Set.
- **PHPUnit**: Unit-Tests gegen Services (kein REDAXO-Bootstrap nötig durch DI), Integration mit echter MySQL gegen Schema.
- **Composer-Autoload-Hack** entfernen — REDAXO 5.21-Composer-Setup ist stabil.

## 16. Stretch / "wenn-Zeit-bleibt"

- **In-Place-Edit auf Frontend**: Bearbeiten-Modus im Frontend, der Wildcards inline editierbar macht (für eingeloggte Redakteure).
- **A/B-Übersetzungen**: zwei Varianten einer Übersetzung mit Performance-Tracking.
- **Voice-Memo-Übersetzungen** für Audio-Inhalte (Podcasts/Videos) — wahrscheinlich zu weit.

## 17. Offene Punkte

- Schema-Migrations-Strategie für sehr grosse Bestandsinstallationen (Chunked? Online ohne Downtime?).
- Genaue Form des Modul-Manifests für die Slice-Translation-Discovery.
- Default-Tonalitätsprofil bei Claude/OpenAI (pro Projekt? pro Tag?).
