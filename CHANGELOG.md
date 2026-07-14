
Sprog - Changelog
================================================================================

## Version 2.0.0 (in Entwicklung)

### Neu

- Automatische Datenmigration v1 → v2 bei Installation/Update: deployte
  Instanzen migrieren ihre Bestandsdaten (Platzhalter, Abkürzungen,
  Fremdwörter) selbst in das neue `sprog_unit`/`sprog_translation`-Modell.
  Idempotent und flag-gesteuert (läuft nur einmal); bei Bedarf manuell über
  „Datenpflege → Migration" wiederholbar.
- Artikel-Sprachvergleich (experimentell) in der Content-Maske: Seite-an-Seite-
  Vergleich des Artikelinhalts zweier Sprachen inkl. Inline-Bearbeitung,
  Metadaten-Vergleich und MT-Übersetzung je Feld.

### Breaking Changes

- `Sprog\Filter` (Abstract-Klasse) hat jetzt typisierte Signaturen:
  `name(): string` und `fire(string $value, string $arguments): string`.
  Drittaddon-Filter, die von `Sprog\Filter` erben, müssen ihre Methoden
  entsprechend typen, sonst greift PHPs LSP-Check und es gibt einen
  Fatal Error.
- Die v1-Backend-Seiten für Platzhalter und Abkürzungen wurden entfernt; die
  Pflege läuft jetzt über die Inbox. Die Rechte `sprog[wildcard]` und
  `sprog[abbreviation]` entfallen.
- **Abkürzungs-Status entfällt:** Der v1-Aktiv/Inaktiv-Status von Abkürzungen
  (Spalte `status`) wird bei der Migration nach v2 nicht übernommen. Zuvor
  **deaktivierte** Abkürzungen werden danach wieder im Frontend ausgegeben.
  Betroffene Einträge nach der Migration bei Bedarf in der Inbox prüfen.

## Version 1.3.0 - 19.11.2021

### Neu

- [#30](https://github.com/tbaddade/redaxo_sprog/commit/9c3c64573dcc789b578f8a3ff5efe9e803e008e3) Import / Export (@lexplatt)
- [#38](https://github.com/tbaddade/redaxo_sprog/commit/972277fd02459621966564114b3817e0e92fe97c) Bei eingeblendeter Unternavigation kann im Formular zwischen den Sprachen gewechselt werden
- [#66](https://github.com/tbaddade/redaxo_sprog/pull/66) Kopieren in dieselbe Sprache verhindern (@TobiasKrais)
- [#67](https://github.com/tbaddade/redaxo_sprog/pull/67) Hilfeseite (@marcohanke)
- [#73](https://github.com/tbaddade/redaxo_sprog/pull/73) Funktionen wegen statischer Code-Analyse in eigene Datei verschoben (@dergel)
- Sucheingabe bleibt erhalten
- Layoutanpassungen

### Bugfix

- [#71](https://github.com/tbaddade/redaxo_sprog/commit/3fcba57eed87740cf850b100f6d2be458cc69e18) Sucheingabe wurde nicht an Paginierung übergeben


## Version 1.2.0 – 26.02.2019

### Neu

- [#52](https://github.com/tbaddade/redaxo_sprog/pull/52) Spanische Übersetzung (@nandes2062)
- [#58](https://github.com/tbaddade/redaxo_sprog/pull/58) "Kategorienamen synchronsieren" verständlicher formuliert (@Pixeldaniel)

### Bugfix

- [#50](https://github.com/tbaddade/redaxo_sprog/commit/0d74d9a7efd976844f6ec15d462baccc7e2c2401) Synchronisieren gelöschter Metainfos (@IngoWinter)
- [#56](https://github.com/tbaddade/redaxo_sprog/commit/1e92221f0177b97863b1472fddb1a49bdd4d114f) Countable Warning (@IngoWinter)


## Version 1.1.0 - 30.01.2017

### Security
- CSRF Protection eingebaut

### Information
- REDAXO 5.5 ist Vorraussetzung
