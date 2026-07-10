# Glossar — Konzept & Nutzung

> Teil von Sprog v2 (siehe [`CONCEPT-V2.md`](CONCEPT-V2.md)). Dieses Dokument
> erklärt das Glossar konkret: wofür es da ist, wann man es nutzt — und wann
> ausdrücklich nicht.

## Kernidee

Ein Glossar ist eine **Terminologie-Liste**:

> Kommt ein bestimmter Begriff in der **Basissprache** vor, wird er **immer gleich**
> in die Zielsprache übersetzt.

Die Quellsprache ist immer die **Basissprache** (konfigurierbar, Standard =
REDAXO-Start-Sprache — siehe `Sprog\Support\BaseLang`); es gibt daher **keine
Quellsprachen-Auswahl**. Als Ziel wählt man **eine bestimmte Sprache** oder **„Alle
Sprachen"** — letzteres deckt mit *einem* Eintrag jede Sprache ab (ideal für Marken-/
Produktnamen).

Das Glossar übersetzt **nicht selbst**. Es ist eine Regelmenge, die Konsistenz
bei einzelnen Fachbegriffen erzwingt — gegenüber einer Maschinenübersetzung und
(perspektivisch) als Hinweis für Übersetzer im Editor.

## Beispiel (Basissprache Deutsch)

| Quell-Term (DE) | Ziel-Term        | Zielsprache | Notiz                              |
|-----------------|------------------|-------------|------------------------------------|
| Mitarbeitende   | team members     | EN          | nicht „employees“ — Tonalität      |
| Beitrag         | post             | EN          | im Blog-Kontext, nicht „contribution“ |
| Geschäftsführung| Management Board | EN          | Eigenbezeichnung, fix              |
| Sprog           | Sprog            | **Alle**    | Produktname → „Nicht übersetzen"   |

Ohne Glossar übersetzt eine Maschine „Mitarbeitende“ mal als „employees“, mal als
„staff“, mal als „workforce“ — je nach Satz. Das Glossar zwingt überall dieselbe
Entscheidung durch. Ein Begriff mit Zielsprache **„Alle"** (wie „Sprog") gilt mit
einem einzigen Eintrag für jede Sprache; die Abkürzung **„Nicht übersetzen"** setzt
Ziel-Term = Quell-Term und Ziel = „Alle".

## Wofür es technisch gedacht ist

Zwei Konsumenten (vgl. `Sprog\Service\GlossaryService`):

1. **Maschinenübersetzung (Hauptzweck).** Bei jedem MT-Call werden die passenden
   Glossar-Einträge mitgegeben — beim KI-Provider als Anweisung im System-Prompt.
   `GlossaryService::mapForPair(basis, ziel)` liefert die `Source → Target`-Map:
   ziel-spezifische Einträge **plus** „Alle Sprachen"-Einträge (`target_clang_id = 0`),
   wobei ein ziel-spezifischer Eintrag den „Alle"-Eintrag überschreibt.
2. **Editor-Hinweis (Konsistenz).** Während ein Übersetzer manuell arbeitet, soll
   ihm angezeigt werden, dass für einen Begriff eine Glossar-Vorgabe existiert.

> **Status:** Pflege-Schicht (Schema → Repository → Service → Backend-Page) inkl.
> **Anlegen, Bearbeiten und Löschen** vollständig. Die **MT-Anbindung ist verdrahtet**
> (2026-07): der Inbox-/Batch-MT-Endpoint reicht `mapForPair()` an den Provider durch,
> der KI-Provider (`ai_platform`) verwertet die Begriffe im System-Prompt — siehe
> `docs/machine-translation.md`. Offen bleiben der Editor-Hinweis (Konsistenz-Anzeige
> während manueller Arbeit) und die native DeepL-`glossary_ids`-Anbindung.

## Abgrenzung: Glossar vs. Wildcard vs. Translation Memory

Sprog hat drei ähnlich klingende Konzepte mit völlig verschiedenen Aufgaben. Hier
liegt die größte Verwechslungsgefahr:

|                       | **Glossar**                          | **Wildcard**                                | **Translation Memory** (`sprog_tm`, geplant) |
|-----------------------|--------------------------------------|---------------------------------------------|-----------------------------------------------|
| Was ist es?           | Begriffs-Regel                       | Platzhalter mit fester Übersetzung          | Archiv schon übersetzter Sätze                |
| Einheit               | einzelnes Wort / kurze Phrase        | ganzer benannter Schlüssel                  | ganzes Segment / Satz                         |
| Beispiel              | „Mitarbeitende → team members“       | `{{ footer_copyright }}` → „© 2026 …“       | „Willkommen auf unserer Seite“ → „Welcome …“  |
| Wo greift es?         | *innerhalb* beliebiger Texte, als Vorgabe für die Übersetzung | wird im Output 1:1 ersetzt | als Vorschlag, wenn ein Satz schon übersetzt wurde |
| Im Frontend sichtbar? | nein                                 | ja                                          | nein                                          |

Merksätze:

- **Wildcard** = „dieser eine Platzhalter zeigt diesen festen Text.“
- **Glossar** = „dieses Wort heißt in jeder Übersetzung so.“
- **TM** = „diesen Satz hatten wir schon mal, nimm das von damals.“

## Wann benutzen

- Eigennamen, Marken, Produktnamen (oft: *nicht* übersetzen lassen).
- Fachjargon, der **eine** feste Übersetzung haben muss (juristisch/technisch).
- Tonalitäts-Entscheidungen, die eine KI sonst falsch rät.
- Abkürzungen/Einheiten, die konsistent bleiben sollen.

## Wann **nicht** benutzen

- Ganze Sätze oder Marketing-Texte → das ist normale Übersetzung (Editor) bzw. TM.
- Feste UI-Bausteine, die im Frontend erscheinen → das ist eine **Wildcard**.
- Begriffe, die je nach Kontext unterschiedlich übersetzt werden → ein Glossar
  erzwingt *immer dieselbe* Übersetzung; bei mehrdeutigen Begriffen schadet das
  mehr, als es nützt.

## Datenmodell

Tabelle `sprog_glossary` (angelegt in `Sprog\Schema\V2Schema::ensureGlossaryTable`):

| Spalte             | Zweck                                                            |
|--------------------|------------------------------------------------------------------|
| `id`               | PK                                                               |
| `source_clang_id`  | Quellsprache = **Basissprache** (keine UI-Auswahl mehr)          |
| `target_clang_id`  | Zielsprache; **`0` = „Alle Sprachen"** (Sentinel)                |
| `source_term`      | Begriff in der Basissprache (VARCHAR 191)                        |
| `target_term`      | feste Übersetzung (VARCHAR 191); bei „Nicht übersetzen" = source_term |
| `notes`            | optionale Notiz (max. 500 Zeichen)                               |

UNIQUE-Index `glossary_lookup` über `(source_clang_id, target_clang_id, source_term)`.
`target_clang_id = 0` bedeutet „gilt für alle Zielsprachen"; in `mapForPair()` hat ein
spezifischer Eintrag (konkrete Zielsprache) Vorrang vor dem „Alle"-Eintrag.

## Beteiligte Klassen

- `Sprog\Model\GlossaryEntry` — unveränderliches Wertobjekt einer Zeile.
- `Sprog\Repository\GlossaryRepository` — SQL-pure Persistenz (`find`, `findByPair`,
  `findAll`, `mapForPair`, `save`, `delete`).
- `Sprog\Service\GlossaryService` — Validierung + Geschäftslogik (`find`, `listAll`,
  `mapForPair`, `add`, `update`, `remove`); Ziel `0` („Alle") ist zugelassen.
- `Sprog\Support\BaseLang` — liefert die Basissprache (Quellsprache); konfigurierbar
  über `base_clang_id`, Fallback Start-Clang.
- `pages/glossary.php` — Backend-Pflege (Admin-only, CSRF): Anlegen, **Bearbeiten**,
  Löschen; Ziel „Alle Sprachen" + „Nicht übersetzen"-Abkürzung; keine Quellsprachen-Auswahl.

## Offene Punkte

1. **Editor-Hinweis bauen.** Eine Konsistenz-Anzeige während der manuellen Arbeit
   im Inbox-Akkordeon gibt es noch nicht.
2. **DeepL-Native-Glossary.** DeepL ignoriert die Glossar-Map aktuell; nur der
   KI-Provider nutzt sie. Die native `glossary_ids`-Anbindung ist offen
   (s. `docs/machine-translation.md`).

**Erledigt (2026-07):** MT-Injection verdrahtet (Inbox + Batch); **Bearbeiten in der
UI**; Ziel **„Alle Sprachen"** + „Nicht übersetzen"; Quellsprache fest an die
**Basissprache** gebunden (keine Quell-Auswahl mehr).
