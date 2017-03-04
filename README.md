Sprog
================================================================================

**AddOn für Sprachen**

## Platzhalter
 
- einfaches Erstellen von Platzhaltern und deren Ersetzungen
- Eine Sprache kann die Ersetzungen einer anderen Sprache verwenden (Sprachbasis)

### Anwendung

Das **Anlegen** des Platzhalters erfolgt **ohne** das öffnende bzw. schließende **Tag**.

**Beispiel**
    
    platzhalter 

Das **Notieren** des Platzhalters **im Code** (Klassen, Funktionen, Templates, Module, etc.), in **Artikelinhalten** oder **Tabellendaten** usw. erfolgt **mit** öffnenden und schließenden **Tag**.

**Beispiel**

    {{ platzhalter }}
 
 
### Filter verwenden

Filter werden direkt am Platzhalter im Code notiert und haben Einfluss auf deren Übersetzung.

#### Mögliche Filter
- - - - - - - - - - - - - - - - - - - - 
- format (sprintf)
- limit
- lower
- markdown
- raw
- title
- upper
- words

- - - - - - - - - - - - - - - - - - - -
#### format (sprintf) 
- - - - - - - - - - - - - - - - - - - - 
<dl>
    <dt>Platzhalter</dt>
    <dd>kinderspiel</dd>
    <dt>Ersetzung</dt>
    <dd>%s Affen sitzen auf einem %s</dd>
    <dt>Anwendung</dt>
    <dd><code>{{ kinderspiel|format(5, Baum) }}</code></dd>
    <dt>Ausgabe</dt>
    <dd>5 Affen sitzen auf einem Baum</dd>
</dl>

- - - - - - - - - - - - - - - - - - - - 
#### limit
- - - - - - - - - - - - - - - - - - - - 
<dl>
    <dt>Platzhalter</dt>
    <dd>kinderspiel</dd>
    <dt>Ersetzung</dt>
    <dd>5 Affen sitzen auf einem Baum</dd>
    <dt>Anwendung</dt>
    <dd><code>{{ kinderspiel|limit(5,...) }}</code></dd>
    <dt>Ausgabe</dt>
    <dd>5 Aff...</dd>
</dl>

- - - - - - - - - - - - - - - - - - - -
#### Lower
- - - - - - - - - - - - - - - - - - - -
<dl>
    <dt>Platzhalter</dt>
    <dd>kinderspiel</dd>
    <dt>Ersetzung</dt>
    <dd>5 Affen sitzen auf einem Baum</dd>
    <dt>Anwendung</dt>
    <dd><code>{{ kinderspiel|lower }}</code></dd>
    <dt>Ausgabe</dt>
    <dd>5 affen sitzen auf einem baum</dd>
</dl>

- - - - - - - - - - - - - - - - - - - -
#### Markdown
- - - - - - - - - - - - - - - - - - - -
<dl>
    <dt>Platzhalter</dt>
    <dd>kinderspiel</dd>
    <dt>Ersetzung</dt>
    <dd>**5 Affen sitzen auf einem Baum**</dd>
    <dt>Anwendung</dt>
    <dd><code>{{ kinderspiel|markdown }}</code></dd>
    <dt>Ausgabe</dt>
    <dd><p><strong>5 Affen sitzen auf einem Baum</strong></p></dd>
</dl>

- - - - - - - - - - - - - - - - - - - -
#### Raw (kein nl2br)
- - - - - - - - - - - - - - - - - - - -
<dl>
    <dt>Platzhalter</dt>
    <dd>kinderspiel</dd>
    <dt>Ersetzung</dt>
    <dd>5 Affen sitzen auf einem Baum</dd>
    <dt>Anwendung</dt>
    <dd><code>{{ kinderspiel|raw }}</code></dd>
    <dt>Ausgabe</dt>
    <dd>5 Affen sitzen auf einem Baum</dd>
</dl>

- - - - - - - - - - - - - - - - - - - -
#### Title
- - - - - - - - - - - - - - - - - - - -
<dl>
    <dt>Platzhalter</dt>
    <dd>kinderspiel</dd>
    <dt>Ersetzung</dt>
    <dd>5 Affen sitzen auf einem Baum</dd>
    <dt>Anwendung</dt>
    <dd><code>{{ kinderspiel|title }}</code></dd>
    <dt>Ausgabe</dt>
    <dd>5 Affen Sitzen Auf Einem Baum</dd>
</dl>

- - - - - - - - - - - - - - - - - - - -
#### Upper
- - - - - - - - - - - - - - - - - - - -
<dl>
    <dt>Platzhalter</dt>
    <dd>kinderspiel</dd>
    <dt>Ersetzung</dt>
    <dd>5 Affen sitzen auf einem Baum</dd>
    <dt>Anwendung</dt>
    <dd><code>{{ kinderspiel|upper }}</code></dd>
    <dt>Ausgabe</dt>
    <dd>5 AFFEN SITZEN AUF EINEM BAUM</dd>
</dl>

- - - - - - - - - - - - - - - - - - - -
#### Words
- - - - - - - - - - - - - - - - - - - -
<dl>
    <dt>Platzhalter</dt>
    <dd>kinderspiel</dd>
    <dt>Ersetzung</dt>
    <dd>5 Affen sitzen auf einem Baum</dd>
    <dt>Anwendung</dt>
    <dd><code>{{ kinderspiel|words(4) }}</code></dd>
    <dt>Ausgabe</dt>
    <dd>5 Affen sitzen auf</dd>
</dl>


### Helferfunktionen

**Text ersetzen lassen**

    echo sprogdown($text, $clang_id = null)
    
**Übersetzung eines einzelnen Platzhalters**

    echo sprogcard($wildcard, $clang_id = null)

**Tabellenfeld mit dem Suffix der aktuellen Sprache**

    echo sprogfield($field, $separator = '_')

```
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


## Inhalte kopieren/synchronisieren
 
- Inhalte können von einer Sprache zur anderen Sprache kopiert werden
- Metadaten der Artikel/Kategorien können von einer Sprache zur anderen Sprache synchronisiert werden


- - - - - - - - - - - - - - - - - - - -

## Bugtracker

Du hast einen Fehler gefunden oder ein nettes Feature parat? [Lege ein Issue an](https://github.com/tbaddade/redaxo_sprog/issues). Bevor du ein neues Issue erstellts, suche bitte ob bereits eines mit deinem Anliegen existiert und lese die [Issue Guidelines (englisch)](https://github.com/necolas/issue-guidelines) von [Nicolas Gallagher](https://github.com/necolas/).


## Changelog

siehe [CHANGELOG.md](https://github.com/tbaddade/redaxo_sprog/blob/master/CHANGELOG.md)

## Lizenz

siehe [LICENSE.md](https://github.com/tbaddade/redaxo_sprog/blob/master/LICENSE.md)


## Autor

**Thomas Blum**

* https://github.com/tbaddade
