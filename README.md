Sprog
================================================================================

**Mehrsprachigkeit für REDAXO** — Übersetzungen zentral in einer Inbox verwalten,
per DeepL oder KI vorübersetzen und in einem Review-Workflow freigeben.

## Voraussetzungen

- REDAXO `^5.21`
- PHP `^8.4` (Minimum PHP 8.4)

## Was Sprog kann

- **Platzhalter (Wildcards)** — `{{ … }}` in Templates, Modulen, Artikelinhalten
  oder Tabellendaten, die bei der Ausgabe pro Sprache ersetzt werden.
- **Abkürzungen** — konfigurierte Begriffe werden im Frontend automatisch als
  `<abbr title="…">…</abbr>` ausgezeichnet.
- **Fremdwörter** — fremdsprachige Begriffe werden als `<span lang="…">…</span>`
  markiert (korrekte Sprachausgabe für Screenreader).
- **Inbox** — zentrale Verwaltung aller Übersetzungen: filtern nach Sprache,
  Namespace und Status, inline bearbeiten, maschinell vorübersetzen, Verlauf.
- **Maschinelle Übersetzung (MT)** — DeepL oder KI (über das `ai_platform`-AddOn),
  glossargestützt.
- **Glossar** — verbindliche Begriffs-Vorgaben, die in die MT einfließen.
- **Synchronisierung & Kopieren** — Struktur-Metadaten und Artikelinhalte zwischen
  den Sprachen abgleichen bzw. kopieren.

## Inbox & Übersetzungs-Workflow

Jede übersetzbare Einheit (Platzhalter, Abkürzung, Fremdwort) besitzt pro Sprache
eine Übersetzung mit einem Status. Der Workflow:

`fehlt → Entwurf → zur Prüfung → freigegeben` (dazu „zurückgeben" und der
System-Status „veraltet", wenn sich der Quelltext geändert hat).

- Rollen (je Sprache über die clang-Rechte wirksam): `sprog[translator]` reicht
  Übersetzungen zur Prüfung ein, `sprog[reviewer]` gibt frei bzw. gibt zurück. Wer
  beides darf (oder Admin) kann direkt freigeben.
- **Wichtig:** Im **Frontend erscheinen ausschließlich freigegebene** (`approved`)
  Übersetzungen. Entwürfe und zur Prüfung eingereichte Texte sind nicht öffentlich.

## Maschinelle Übersetzung (MT)

- **DeepL** — API-Key in der Sprog-Konfiguration hinterlegen.
- **KI** — über das optionale `ai_platform`-AddOn (OpenAI, Claude, Gemini, Ollama);
  genutzt wird dessen Standard-Text-Profil.
- Begriffe aus dem **Glossar** werden dem Provider verbindlich mitgegeben.
- MT-Ergebnisse sind immer **Vorschläge** (Entwurf) und durchlaufen den Workflow —
  nichts wird automatisch freigegeben.

## Platzhalter

- einfaches Anlegen von Platzhaltern und deren Ersetzungen in der Inbox
- eine Sprache kann die Ersetzungen einer anderen Sprache verwenden (Sprachbasis)

### Anwendung

Das **Anlegen** des Platzhalters (Inbox → „Neu", Namespace „Platzhalter") erfolgt
**ohne** öffnendes bzw. schließendes **Tag**.

**Beispiel**

    platzhalter

Das **Notieren** des Platzhalters **im Code** (Klassen, Funktionen, Templates,
Module, etc.), in **Artikelinhalten** oder **Tabellendaten** usw. erfolgt **mit**
öffnendem und schließendem **Tag**.

**Beispiel**

    {{ platzhalter }}


### Filter verwenden

Filter werden direkt am Platzhalter im Code notiert und haben Einfluss auf deren Übersetzung.

#### Mögliche Filter
- - - - - - - - - - - - - - - - - - - -

- format <small>(sprintf)</small>
- limit
- lower
- markdown
- raw <small>(kein nl2br)</small>
- title
- upper
- words

| Verwendung | Ersetzung | Ausgabe |
| ---------- | --------- | ------- |
| <code>{{&#160;sprog&#124;format(5,&#160;Baum)&#160;}}</code> | `%s Affen sitzen auf einem %s` | `5 Affen sitzen auf einem Baum` |
| <code>{{&#160;sprog&#124;limit(5,...)&#160;}}</code> | `5 Affen sitzen auf einem Baum` | `5 Aff...` |
| <code>{{&#160;sprog&#124;lower&#160;}}</code> | `5 Affen sitzen auf einem Baum` | `5 affen sitzen auf einem baum` |
| <code>{{&#160;sprog&#124;markdown&#160;}}</code> | `**5 Affen sitzen auf einem Baum**` | `<p><strong>5 Affen sitzen auf einem Baum</strong></p>` |
| <code>{{&#160;sprog&#124;raw&#160;}}</code> | `5 Affen sitzen auf einem Baum` | `5 Affen sitzen auf einem Baum` |
| <code>{{&#160;sprog&#124;title&#160;}}</code> | `5 Affen sitzen auf einem Baum` | `5 Affen Sitzen Auf Einem Baum` |
| <code>{{&#160;sprog&#124;upper&#160;}}</code> | `5 Affen sitzen auf einem Baum` | `5 AFFEN SITZEN AUF EINEM BAUM` |
| <code>{{&#160;sprog&#124;words(4)&#160;}}</code> | `5 Affen sitzen auf einem Baum` | `5 Affen sitzen auf` |


### Helferfunktionen

**Text ersetzen lassen**

```php
echo sprogdown($text, $clang_id = null);
```

**Übersetzung eines einzelnen Platzhalters**

```php
echo sprogcard($wildcard, $clang_id = null);
```

**Tabellenfeld mit dem Suffix der aktuellen Sprache**

```php
echo sprogfield($field, $separator = '_');

// field
// about_1,  about_2
echo sprogfield('about');


// oder in Yorm Dataset Class
public function getAbout()
{
    return trim($this->{sprogfield('about')});
}
```

```php
// normal
foreach ($items as $item) {
    echo $item->getValue('name_' . rex_clang::getCurrentId());
}

// sprogfield
foreach ($items as $item) {
    echo $item->getValue(sprogfield('name'));
}
```

## Optionale Synchronisierung von

- Artikelname mit Kategoriename innerhalb derselben Sprache
- Kategoriename mit Artikelname innerhalb derselben Sprache
- Status (Online/Offline) zwischen den Sprachen
- Template zwischen den Sprachen
- ausgewählte MetaInfo-Felder zwischen den Sprachen

## Sprachauswahl auf yrewrite-Domains beschränken

Sind mehrere Sprachen angelegt, eine Domain (yrewrite) bedient aber nur bestimmte,
kann Sprog die übrigen Sprachen in der Struktur ausblenden: im Kategoriebaum, in
der Bearbeiten-Maske und im Artikel-Sprachvergleich werden nur noch die Sprachen
angeboten, die die yrewrite-Domain der jeweiligen Kategorie bedient. Die aktuell
gewählte Sprache bleibt dabei immer sichtbar.

Aktiviert wird das über **Sprog → Konfiguration → Struktur** (nur sichtbar, wenn
yrewrite installiert ist). Die erlaubten Sprachen leitet Sprog aus der Domain ab;
für Sonderfälle lassen sie sich per Extension Point überschreiben:

```php
rex_extension::register('SPROG_STRUCTURE_CLANGS', function (rex_extension_point $ep) {
    $allowed = $ep->getSubject();     // int[]|null (aus yrewrite berechnet)
    $contextId = $ep->getParam('context_id');
    $clang = $ep->getParam('clang');
    return $allowed;                  // int[] = erlaubte clang-IDs, null = keine Einschränkung
});
```

## Inhalte & Metadaten kopieren

Unter **Datenpflege**:

- **Artikelinhalte kopieren** — die Slices eines Artikels von einer Sprache in
  eine andere übertragen (optional ab einem Startartikel und/oder mit vorherigem
  Leeren der Zielsprache).
- **Metadaten kopieren** — Struktur-/Artikel-Metadaten von einer Sprache in eine
  andere übernehmen.
- **Import / Export** — Platzhalter als CSV importieren bzw. exportieren.

## Migration von Sprog 1.x

Beim Installieren bzw. Update überführt Sprog vorhandene Bestandsdaten (Platzhalter,
Abkürzungen, Fremdwörter) automatisch in das neue v2-Modell — bereits live
geschaltete Inhalte bleiben dabei sichtbar. Der Vorgang ist idempotent und lässt
sich bei Bedarf manuell unter **Datenpflege → Migration** wiederholen.


## Bugtracker

Du hast einen Fehler gefunden oder ein nettes Feature parat? [Lege ein Issue an](https://github.com/tbaddade/redaxo_sprog/issues). Bevor du ein neues Issue erstellts, suche bitte ob bereits eines mit deinem Anliegen existiert und lese die [Issue Guidelines (englisch)](https://github.com/necolas/issue-guidelines) von [Nicolas Gallagher](https://github.com/necolas/).


## Changelog

siehe [CHANGELOG.md](https://github.com/tbaddade/redaxo_sprog/blob/master/CHANGELOG.md)

## Lizenz

siehe [LICENSE](https://github.com/tbaddade/redaxo_sprog/blob/master/LICENSE)


## Autor

**[Thomas Blum](https://github.com/tbaddade)**


## Übersetzungen

- English [@ynamite](https://github.com/ynamite)
- Español [@nandes2062](https://github.com/nandes2062)
- Svensk [@interweave-media](https://github.com/interweave-media)
