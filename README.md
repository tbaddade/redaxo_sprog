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


## Inhalte kopieren/synchronisieren
 
- Inhalte können von einer Sprache zur anderen Sprache kopiert werden
- Metadaten der Artikel/Kategorien können von einer Sprache zur anderen Sprache synchronisiert werden


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
