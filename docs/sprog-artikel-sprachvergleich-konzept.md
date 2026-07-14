# Konzept: Artikel-Sprachvergleich im Sprog-Addon (REDAXO 5)

**Status:** Umsetzungskonzept · **Zielsystem:** REDAXO 5.x (PHP 8.3+) · **Host-Addon:** `sprog`
**Zweck:** Redakteur:innen sollen den Inhalt eines Artikels **zweier Sprachen nebeneinander** sehen und Inhalte gezielt von einer Sprache in die andere übernehmen — ohne den Core zu patchen, nur mit dokumentierten Core-APIs.

> Dieses Dokument ist als Bauanleitung für eine andere Session gedacht. Alle zentralen Core-Fakten sind mit `datei:zeile` belegt, damit nichts neu hergeleitet werden muss.

---

## 1. Scope — was drin ist und was bewusst NICHT

### Enthalten (nachweislich in R5 machbar)
1. **Vergleichsansicht**: zwei Sprachspalten (A = aktuell editierte clang, B = wählbar) nebeneinander, je Slice als Karte mit **gerendertem Modul-Output**, eingebettet in die bestehende Content-Maske.
2. **Batch-Copy einer leeren Zielsprache**: Ist die Zielsprache für den Artikel komplett leer, kann der **gesamte** Artikelinhalt aus der Quellsprache übernommen werden.
3. **Einzel-Slice-Copy**: ein einzelner Slice wird in die andere Sprache kopiert.
4. **Einfügeposition wählbar** beim Einzel-Slice-Copy (innerhalb desselben ctype der Zielsprache).

### Bewusst NICHT enthalten (in R5 nicht sauber möglich)
- **Kein echter Slice-für-Slice-Abgleich / Diff zwischen den Sprachen.**
  **Warum:** In R5 hat ein Slice **keine sprachübergreifende Identität**. Jede Sprache ist eine eigene, unabhängige Slice-Liste; jede Slice-Zeile hat eine eigene Auto-Increment-`id` und eigene `priority` pro `clang_id`/`revision`. Es gibt keinen gemeinsamen Identifier, der „Slice X in DE" mit „Slice X in EN" verknüpft. Damit ist keine verlässliche 1:1-Zuordnung, kein „diese beiden gehören zusammen", kein Feld-Diff und kein „nur Fehlendes ersetzen" möglich.
- **Kein Überschreiben** einzelner Ziel-Slices. Kopieren ist **immer ein Insert** (nie ein Replace). Das umgeht das Identitätsproblem vollständig und ist die tragende Grundregel dieses Konzepts.
- **Kein Meta-/Metainfo-Feld-Abgleich** zwischen Sprachen (war im Mockup enthalten, fällt unter „echter Abgleich" → raus).

> **Grundregel:** *Kopieren erzeugt neue Slices. Bestehende Slices der Zielsprache werden nie verändert oder überschrieben.*

---

## 2. Core-Faktenlage (verifiziert)

Alle Pfade relativ zu `redaxo/src/addons/structure/plugins/content/` sofern nicht anders angegeben. Das Slice-/Content-System lebt komplett im `content`-Plugin des `structure`-Addons.

### 2.1 Datenmodell
| Fakt | Beleg |
|---|---|
| `rex_article_slice` bindet den Slice per **`clang_id`** an eine Sprache | `install.php:6` |
| Spalten: `article_id`, `ctype_id`, `module_id`, `revision`, `priority`, `status` (tinyint, default 1), `value1..20`, `media1..10`, `medialist1..10`, `link1..10`, `linklist1..10` | `install.php:5-71` |
| Slice-Status ist damit **pro (Artikel, clang, revision, Slice)** | `install.php:11`, `isOnline()` `lib/article_slice.php:503` |
| `rex_article`: **eine Zeile je (id, clang_id)** (UNIQUE `find_articles`), Status pro Sprache | `redaxo/src/addons/structure/install.php:19` (`status` `:13`) |
| **Kein** vorgerendertes HTML in der DB — Ausgabe entsteht erst beim Rendern | siehe 2.3 |

### 2.2 Slices lesen
| Zweck | API | Beleg |
|---|---|---|
| Alle Slices eines Artikels je Sprache | `rex_article_slice::getSlicesForArticle($id, $clang, $revision = 0, $ignoreOfflines = false)` | `lib/article_slice.php:203` |
| Slices eines ctype | `getSlicesForArticleOfType($id, $moduleTypeId, $clang, $revision, $ignoreOfflines)` | `:227` |
| Einzelner Slice | `getArticleSliceById($id, $clang, $revision)` | `:128` |
| Erster Slice eines ctype (für „leer?"-Check) | `getFirstSliceForCtype($ctype, $id, $clang, $revision)` → `null` wenn leer | `:180`, so genutzt in `pages/content.php:333-340` |
| Werte auslesen | `getValue($i)`, `getMedia($i)`, `getMediaList($i)`, `getLink($i)`, `getLinkList($i)`, `getModuleId()`, `getCtype()`, `getPriority()`, `isOnline()` | `:390` ff, `:503` |

### 2.3 Slice rendern (für die Vorschau)
- `rex_article_slice::getSlice()` instanziiert `rex_article_content` und rendert den Slice (Modul-Output-PHP wird **ausgeführt**) — `lib/article_slice.php` (~`:276`).
- Frontend-Renderklasse: `rex_article_content` (`lib/article_content.php`); Basis `rex_article_content_base::getSlice($sliceId)` erzwingt `eval=true` (`lib/article_content_base.php:335`).
- Optionaler Backend-Preview-EP: `SLICE_BE_PREVIEW` (falls Backend-Styling gewünscht).
- **Es gibt kein gespeichertes HTML** — gerenderte Ausgabe kostet Modul-Ausführung (siehe Limitierung 8.1).

### 2.4 Kopieren (Batch, ganze Sprache) — existiert bereits vollständig
- `rex_content_service::copyContent($fromId, $toId, $fromClang = 1, $toClang = 1, $revision = null, $overwrite = false): bool` — `lib/content_service.php:229`.
  Liest alle Quell-Slices, kopiert jede Spalte außer `id`, remappt `clang_id`/`article_id`, offsettet `priority`, reorganisiert Prioritäten, löscht Cache, feuert `ART_SLICES_COPY` + `…art_content_updated`.
- API-Wrapper (CSRF-geschützt, Rechte-Check): `rex_api_content_copy` — `lib/api_functions/api_content_copy.php` (ruft `copyContent($id, $id, $a, $b, null, $overwrite)`).
- Vorhandene Backend-UI („Inhalte kopieren"): `pages/content.functions.php:166-245`, sichtbar nur bei `hasPerm('copyContent[]')` **und** mehr als einer erlaubten clang.

### 2.5 Einzel-Slice einfügen mit Position — mit bestehender API abbildbar ⭐
- `rex_content_service::addSlice(int $articleId, int $clangId, int $ctypeId, int $moduleId, array $data = []): string` — `lib/content_service.php:9`.
  **`$data['priority']` wird respektiert** (`:15-22`); danach normalisiert `rex_sql_util::organizePriorities(...)` die Prioritäten innerhalb `(article, clang, ctype, revision)` (`:41`). Cache-Löschung (`:48`) und EPs `SLICE_ADDED` + `…art_content_updated` (`:55-68`) sind bereits enthalten.
  → **Einfügen an gewählter Position = `priority` = Zielposition setzen, alle Wert-Spalten in `$data` mitgeben, `addSlice()` aufrufen. Kein roher SQL nötig.**
- Referenz für Prioritäts-Handling zusätzlich: `moveSlice()` `:83`.

### 2.6 Sprachen & Rechte
- `rex_clang::getAll($ignoreOfflines = false)` (`redaxo/src/core/lib/clang/clang.php:222`), `getName()` `:140`, `getCode()` `:130`, `getCurrentId()` `:94`.
- clang-Rechte je User: `rex::requireUser()->getComplexPerm('clang')` (Zählung/Prüfung wie in `api_content_copy.php`).
- Kopier-Recht: `copyContent[]` (bereits vorhanden).

### 2.7 Einbettung in die Content-Maske (ohne Core-Patch)
Die Content-Seite feuert String-Rückgabe-EPs, deren Rückgabe direkt in die Seite eingefügt wird. Parameter je EP: `article_id`, `clang`, `function`, `slice_id`, `page`, `ctype`.

| EP | Position | Beleg | Verwendung hier |
|---|---|---|---|
| `STRUCTURE_CONTENT_HEADER` | Kopfbereich der Maske | `pages/content.php:82` | „Vergleichen"-Button + Sprach-B-Auswahl |
| `STRUCTURE_CONTENT_AFTER_SLICES` | nach der Slice-Liste | `pages/content.php:425` | Container/Panel der Vergleichsansicht |
| `STRUCTURE_CONTENT_BEFORE_SLICES` | vor der Slice-Liste | `pages/content.php:408` | Alternativ-Platz fürs Panel |
| `STRUCTURE_CONTENT_SIDEBAR` | Seitenleiste | `pages/content.php:440` | optional |

Seiten-Gate im `boot.php`: `rex_be_controller::getCurrentPagePart(1) === 'content'` (vgl. `content/boot.php:20`).

---

## 3. Architektur im Sprog-Addon

> **Hinweis:** Sprog ist in diesem Core-Checkout **nicht** vorhanden (Drittanbieter-Addon). Klassennamen/Autoloading an Sprogs eigene Konvention anpassen (Namespace `FriendsOfRedaxo\Sprog` bzw. Legacy `rex_sprog_*`). Alle Core-Referenzen unten gegen die **installierte** Sprog-/Core-Version gegenprüfen.

```
sprog/
├─ boot.php                         # EP-Listener + Assets registrieren (nur auf content-Page)
├─ lib/
│  └─ api_function/
│     └─ rex_api_sprog_slice_copy.php   # Einzel-Slice-Copy (Klassennamen MUSS rex_api_* sein!)
├─ fragments/sprog/
│  └─ langcompare.php               # rendert das Vergleichs-Panel (2 Spalten)
├─ assets/
│  ├─ langcompare.css
│  └─ langcompare.js                # Toggle, Sprach-B-Wechsel, Copy-Requests
└─ pages/ ...                        # (bestehende Sprog-Seiten, unberührt)
```

- **`rex_api_content_copy`** (Core) wird für Batch **wiederverwendet** — kein Nachbau.
- **`rex_content_service::addSlice()`** (Core) wird für Einzel-Copy **wiederverwendet** — die neue API-Funktion ist nur ein dünner, rechte-/CSRF-geprüfter Wrapper.
- Ausführung passiert im **Backend-Kontext** (`rex::isBackend() === true`), also stehen `rex::getUser()`, complexPerm etc. zur Verfügung.

---

## 4. Komponente A — Vergleichsansicht (eingebettet)

### 4.1 Einstieg
- `boot.php` registriert einen Listener auf `STRUCTURE_CONTENT_HEADER`, der einen **„Vergleichen"-Toggle-Button** und ein **Sprach-B-`<select>`** zurückgibt (befüllt aus den für den User erlaubten clangs außer der aktuellen). `article_id`/`clang` (= Sprache A) kommen aus den EP-Params.
- Ein Listener auf `STRUCTURE_CONTENT_AFTER_SLICES` gibt den **Panel-Container** zurück (initial leer/versteckt).

### 4.2 Panel-Inhalt (Fragment `langcompare.php`)
Erzeugt zwei Spalten für den aktuell offenen `ctype`:

Für jede Spalte (Sprache `$clang`):
1. Slices laden: `rex_article_slice::getSlicesForArticleOfType($articleId, $ctype, $clang, $revision)`.
2. Ist die Liste **leer** → Leerzustand + (nur in Spalte B, wenn Spalte A Inhalt hat) **Batch-Button** „Gesamten Inhalt aus <A> übernehmen" (siehe Komponente B).
3. Sonst je Slice eine Karte:
   - **Gerenderter Output**: `rex_article_content` auf `$clang` (und `$revision`) konfigurieren und den Slice rendern (Modul-Output). Fallback bei Render-Fehler: Slice-Typ/Modulname anzeigen.
   - Status-Indikator (`isOnline()` → online/offline, offline optional ausgegraut).
   - **Copy-Action** „→ nach <andere Sprache>": öffnet die Positionsauswahl (Komponente C).

> **Ausrichtung:** Die Spalten sind **zwei unabhängige, nach `priority` geordnete Listen** — bewusst **keine** Zeilen-Kopplung. Das Panel behauptet keine Zuordnung zwischen linken und rechten Karten.

### 4.3 Nachladen / Aktualisieren
- Sprach-B-Wechsel und Copy-Ergebnisse aktualisieren das Panel per **AJAX-Fragment** (eigener `rex_api_function`, der `langcompare.php` mit `article_id`, `clang_a`, `clang_b`, `ctype` rendert und das HTML zurückgibt), oder per einfachem Reload der Content-Seite mit gesetzten Parametern. AJAX bevorzugt (kein Verlust des Bearbeitungszustands).

---

## 5. Komponente B — Batch-Copy einer leeren Zielsprache

### 5.1 „Leer"-Bedingung
Zielsprache B gilt für den Artikel als leer, wenn **über alle ctypes** kein Slice existiert:
`rex_article_slice::getSlicesForArticle($articleId, $clangB, $revision)` liefert ein leeres Array.
→ Nur dann wird der Batch-Button angeboten (verhindert Dubletten und Überschreiben by design).

### 5.2 Ausführung — bestehende API
- Aufruf von **`rex_api_content_copy`** mit `article_id`, `clang_a` (= Quelle A), `clang_b` (= Ziel B), `overwrite = 0`.
- Da B leer ist, ist `overwrite=0` (Anhängen) sauber und dublettenfrei. `copyContent` kopiert **alle ctypes** des Artikels.
- Rechte-/CSRF-Prüfung übernimmt `rex_api_content_copy` bereits.
- Nach Erfolg: Panel neu laden (5.3).

### 5.3 Nach dem Copy
Cache/EPs sind in `copyContent` enthalten. Panel-Fragment neu rendern → Spalte B zeigt jetzt Inhalt.

---

## 6. Komponente C — Einzel-Slice-Copy mit Positionswahl

### 6.1 Interaktion
1. Nutzer klickt an einer Slice-Karte „→ nach <Zielsprache>".
2. Es erscheint eine **Positionsauswahl**: Liste der Ziel-Slices desselben `ctype` in Sprache B mit Einfügeschlitzen „ganz oben / nach Slice #n / ganz unten" (Zielposition = 1-basierte `priority`).
3. Bestätigen → Request an `rex_api_sprog_slice_copy`.

### 6.2 API-Funktion `rex_api_sprog_slice_copy`
> Klassenname **muss** mit `rex_api_` beginnen (Voraussetzung für `rex_api_function`). CSRF-Token verpflichtend.

Eingabe: `slice_id` (Quelle), `clang_from`, `clang_to`, `target_position` (int), `article_id`.

Ablauf:
1. **Rechte prüfen** (analog `api_content_copy.php`): `copyContent[]` **und** clang-complexPerm für `clang_from`/`clang_to`, Kategorie-/Artikelrecht. CSRF via `rex_api_function`.
2. **Quell-Slice lesen**: Zeile über `rex_sql` (`SELECT * FROM rex_article_slice WHERE id = :id AND clang_id = :clang_from`) oder `rex_article_slice::getArticleSliceById()`.
3. **`$data` bauen** — alle inhaltlichen Spalten übernehmen:
   `value1..20`, `media1..10`, `medialist1..10`, `link1..10`, `linklist1..10`, `status`, `revision`.
   Setzen: `$data['priority'] = target_position`.
   **Nicht** übernehmen: `id`, `pid`, `clang_id`, `article_id`, `ctype_id`, `module_id` (die kommen als eigene Parameter), sowie create/update-Felder (setzt `addSlice` selbst).
4. **Einfügen** (Ziel-Sprache, Position, Cache, EPs — alles in einem Call):
   ```php
   rex_content_service::addSlice(
       $articleId,
       $clangTo,
       $sourceSlice->getCtype(),
       $sourceSlice->getModuleId(),
       $data // enthält value*/media*/link*/status/revision + priority = Zielposition
   );
   ```
   `addSlice` respektiert `priority`, normalisiert danach via `organizePriorities`, löscht Cache und feuert `SLICE_ADDED` + `art_content_updated`.
5. **Antwort**: Erfolg + genügend Daten, damit `langcompare.js` das Panel-Fragment neu lädt.

> **Immer Insert, nie Overwrite** — es wird ausschließlich ein neuer Ziel-Slice erzeugt; kein bestehender Slice der Zielsprache wird angefasst.

### 6.3 ctype-Regel
Der neue Slice landet im **gleichen `ctype`** wie die Quelle. Existiert der ctype in der Ziel-Vorlage nicht, ist der Copy zu unterbinden (Positionsauswahl nur für vorhandene ctypes anbieten).

---

## 7. Rechte & Sicherheit
- **Kopier-Recht:** `copyContent[]` (bestehend) für Batch **und** Einzel-Copy.
- **clang-Recht:** `getComplexPerm('clang')` muss Quell- **und** Zielsprache erlauben.
- **Sichtbarkeit** der Vergleichsfunktion: nur wenn `count()` der erlaubten clangs > 1 (wie die bestehende Kopier-UI, `content.functions.php:167`).
- **CSRF:** alle schreibenden Requests über `rex_api_function` mit Token; für Batch das mitgelieferte `rex_api_content_copy::getHiddenFields()`.
- **Escaping:** gerenderter Modul-Output stammt aus Redaktionsdaten; im Panel bewusst als HTML ausgeben (das ist der Zweck), restliche Metadaten (Slice-Typ, Modulname) mit `rex_escape()`.

---

## 8. Bekannte Grenzen & Prüfpunkte

### 8.1 Gerenderter Fremdsprach-Output (gewählte Vorschau-Variante)
Module, die **global** auf `rex_clang::getCurrentId()` oder den „aktuellen" Artikel zugreifen (statt auf das Content-Objekt), rendern in Spalte B ggf. in der falschen Sprache/Kontext. Mitigation: `rex_article_content` explizit auf `$clangB` setzen; robusten Fallback (Slice-Typ/Modulname) vorsehen. Als Limitierung dokumentieren; kein Blocker für den ersten Ausbau.

### 8.2 Revisionen / `version`-Plugin
Standard-Umsetzung arbeitet auf **Live-Revision** (`revision = 0`). Ist das `version`-Plugin aktiv und wird eine Arbeitskopie editiert, muss `revision` konsistent durchgereicht werden (Quelle lesen **und** Ziel schreiben mit derselben `revision`). Für v1 optional; sauber parametrisieren.

### 8.3 Sprog nicht im Checkout
Alle Sprog-spezifischen Pfade/Klassennamen gegen die **installierte** Sprog-Version verifizieren. Core-Referenzen (Abschnitt 2) gegen die installierte Core-Version prüfen — Zeilennummern können je Version abweichen; die **Methodensignaturen** sind der verlässliche Anker.

### 8.4 Keine Zuordnung, kein Diff
Bewusste Grenze (siehe Scope). Das UI darf keine „gehört zusammen"-Semantik suggerieren. Kein Feld-Diff, kein „nur Fehlendes ersetzen", kein Überschreiben.

---

## 9. Umsetzungsschritte (Checkliste)
1. [ ] `boot.php`: Assets (`langcompare.css/js`) **nur** auf `content`-Page laden (Gate `getCurrentPagePart(1) === 'content'`).
2. [ ] `boot.php`: Listener auf `STRUCTURE_CONTENT_HEADER` → „Vergleichen"-Button + Sprach-B-Select (erlaubte clangs, `count > 1`).
3. [ ] `boot.php`: Listener auf `STRUCTURE_CONTENT_AFTER_SLICES` → Panel-Container.
4. [ ] Fragment `langcompare.php`: zwei Spalten je `ctype`, Slices via `getSlicesForArticleOfType`, gerenderter Output via `rex_article_content`, Status via `isOnline()`.
5. [ ] Leerzustand-Erkennung Spalte B (`getSlicesForArticle` leer) → Batch-Button.
6. [ ] Batch: Anbindung an `rex_api_content_copy` (`overwrite=0`).
7. [ ] `rex_api_sprog_slice_copy`: Rechte/CSRF, Quell-Slice lesen, `$data` mappen, `rex_content_service::addSlice()` mit `priority = Zielposition`.
8. [ ] Positionsauswahl-UI (Einfügeschlitze aus Ziel-Slices des ctype).
9. [ ] `langcompare.js`: Toggle, Sprach-B-Wechsel (AJAX-Fragment-Reload), Copy-Requests (Batch + Einzel), Panel-Refresh nach Erfolg.
10. [ ] AJAX-Fragment-Endpoint (rendert `langcompare.php` für `article_id/clang_a/clang_b/ctype`).
11. [ ] Sichtbarkeits-/Rechte-Gate durchgängig prüfen.
12. [ ] Lang-Keys (Sprog-Konvention) für Buttons/Meldungen.

## 10. Definition of Done
- [ ] In einem mehrsprachigen Artikel öffnet der „Vergleichen"-Button ein Panel mit zwei Sprachen nebeneinander (gerenderter Slice-Output, Status sichtbar).
- [ ] Bei leerer Zielsprache übernimmt der Batch-Button den kompletten Artikelinhalt (dublettenfrei), Panel aktualisiert sich.
- [ ] Ein einzelner Slice lässt sich in die andere Sprache kopieren; die **Einfügeposition ist wählbar** und wird korrekt angewandt.
- [ ] Kein bestehender Ziel-Slice wird je überschrieben.
- [ ] Rechte (`copyContent[]`, clang-Perms) und CSRF greifen; ohne Rechte ist die Funktion unsichtbar.
- [ ] Kein Core-Patch; Umsetzung ausschließlich über EPs + bestehende Core-Services.

---

## Anhang — verwendete Core-Symbole (Schnellreferenz)
- `rex_article_slice::getSlicesForArticle()` / `getSlicesForArticleOfType()` / `getArticleSliceById()` / `getFirstSliceForCtype()` / `getSlice()` / `getValue()`/`getMedia()`/`getLink()` / `isOnline()` — `.../content/lib/article_slice.php`
- `rex_content_service::addSlice()` (⭐ Positions-Insert) / `copyContent()` / `moveSlice()` / `generateArticleContent()` — `.../content/lib/content_service.php`
- `rex_api_content_copy` — `.../content/lib/api_functions/api_content_copy.php`
- Kopier-UI-Referenz — `.../content/pages/content.functions.php:166-245`
- EPs `STRUCTURE_CONTENT_HEADER/BEFORE_SLICES/AFTER_SLICES/SIDEBAR` — `.../content/pages/content.php:82/408/425/440`
- `rex_article` (Zeile je clang, Status je Sprache) — `redaxo/src/addons/structure/install.php`
- `rex_clang::getAll()/getName()/getCode()/getCurrentId()` — `redaxo/src/core/lib/clang/clang.php`
- `rex_sql_util::organizePriorities()` — Prioritäts-Normalisierung (in `addSlice`/`moveSlice` genutzt)
