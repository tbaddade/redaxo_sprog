# Machine Translation — Provider & Prompt

## Kernidee

MT liefert **Vorschläge**, keine automatischen Freigaben. Ein Klick „MT-Vorschlag"
im Inbox-Akkordeon füllt das Zielfeld; gespeichert wird über den normalen
Blur-Auto-Save mit MT-Marker (`mt_provider` / `mt_confidence`). Die inhaltliche
Verantwortung bleibt beim Review-Workflow: das Ergebnis steht auf „Prüfung nötig",
ein Reviewer gibt frei. Damit ist auch der Nichtdeterminismus von LLMs abgesichert
— eine Fehlübersetzung geht nie ungeprüft live.

## Provider-Architektur

`Sprog\Mt\ProviderInterface` — ein Provider deklariert `name()`, `isConfigured()`,
`supports()` und `translate($text, $source, $target, $glossary, $context)`.
Registriert werden sie in `MtService::create()`:

| Provider | Klasse | Voraussetzung |
|---|---|---|
| `noop` | `NoopProvider` | immer da (gibt den Quelltext zurück; Test/Fallback) |
| `deepl` | `DeepLProvider` | DeepL-Key in sprog-Config (`mt_deepl_key`) |
| `ai_platform` | `AiPlatformProvider` | AddOn `ai_platform` verfügbar **und** Default-Text-Profil gesetzt |

**Quelle** ist immer die **Basissprache** — `Sprog\Support\BaseLang::clangId()`,
konfigurierbar über `base_clang_id` (Einstellungen → Basissprache), Fallback auf die
REDAXO-Start-Clang. Inbox und Batch übergeben sie als `$source`; die Basissprache ist
nie ein MT-Ziel.

`MtService::translate()` validiert Sprachcodes tolerant (auch Locales wie `de_at`).
WELCHE Richtung tatsächlich möglich ist, entscheidet der jeweilige Provider über
`supports()`: DeepL whitelistet 2-Zeichen-Codes, der KI-Provider akzeptiert alles.
Sind mehrere echte Provider konfiguriert, bekommt jeder in der MT-Leiste einen
eigenen Button (»DeepL«, »KI (Ollama)«) — der Klick schickt den gewünschten Provider
mit (`data-provider` → Request-Parameter `provider`). Ohne expliziten Provider fällt
der `MtController` auf den erstregistrierten zurück (Reihenfolge: DeepL vor ai_platform).
Die Button-Beschriftung kommt aus dem Lang-Key `sprog_inbox_mt_provider_<name>`
(Fallback: der technische Provider-Name).

## KI-Provider (ai_platform)

**Weiche Kopplung:** kein `requires` in `package.yml`. Der `AiPlatformProvider`
meldet sich nur als konfiguriert, wenn das AddOn verfügbar ist und ein Text-Profil
als Standard gesetzt wurde. Übersetzt über
`FriendsOfRedaxo\AiPlatform\Service::generateText($text, $systemPrompt)` mit dem
ai_platform-**Default-Text-Profil** (Provider + Modell + Parameter werden dort
konfiguriert — bewusst kein eigenes sprog-Setting).

### System-Prompt

sprog baut den System-Prompt selbst (`AiPlatformProvider::buildSystemPrompt()`)
und gibt ihn bei jedem Aufruf mit. **Der `system_prompt` des ai_platform-Profils
wird dadurch überschrieben** und kann leer bleiben — sprog steuert den
Übersetzungs-Prompt zentral, damit Platzhalter-Schutz, Glossar und Kontext
garantiert greifen. Aufbau:

```
Du bist ein professioneller Fachübersetzer. Übersetze den Text von "de" nach "en".
Gib ausschließlich die Übersetzung zurück – ohne Anführungszeichen, ohne Vor- oder Nachbemerkungen.
Lasse Platzhalter der Form {{ … }}, HTML-Tags und Variablen unverändert. Erhalte Zeilenumbrüche und Formatierung.
Verwende für folgende Begriffe verbindlich die vorgegebene Übersetzung: Warenkorb → cart; Kasse → checkout.   ← nur bei Glossar-Treffern
Kontext des Textes (nur zur Orientierung, nicht mitübersetzen): Bereich: page.about. Hinweis: Button-Label.   ← nur wenn vorhanden
```

- **Glossar** dynamisch pro Sprachrichtung über `GlossaryService::mapForPair(quelle, ziel)`
  — nur Begriffe, die für dieses Sprachpaar hinterlegt sind, landen im Prompt.
- **Kontext** aus Bereich (`context`) + Notiz (`notes`) der Einheit
  (`MtController::buildContext()`).

### Modellwahl: Reasoning meiden

Fürs Übersetzen bringt Reasoning nichts außer Latenz. Modelle mit „Thinking"-Modus
(**qwen3**, **QwQ**, **deepseek-r1**) stellen der eigentlichen Antwort einen
`<think>…</think>`-Block voran und generieren dafür hunderte Extra-Tokens — auf
lokaler Hardware kostet das schnell mehr als die Standard-30-Sekunden von PHP.
**Für interaktives MT daher ein Non-Reasoning-Modell wählen.** Gut geeignet
(mehrsprachig, ohne Thinking, lokal über Ollama):

| Modell | Ollama-Tag | Sprachabdeckung |
|---|---|---|
| **Gemma 3** (Google) | `gemma3:12b`, `gemma3:4b` | sehr breit (inkl. Dänisch) — Standard-Empfehlung |
| Qwen **2.5** (nicht 3!) | `qwen2.5:14b`, `qwen2.5:7b` | ~29 Sprachen, breit europäisch |
| Aya Expanse | `aya-expanse:8b` | übersetzungs-spezialisiert (Dänisch offiziell nicht dabei) |

**Timeout:** `MtController::MT_EXECUTION_TIME_LIMIT` (120 s) hebt das PHP-Zeitlimit
für den MT-Request an — sonst bricht ein langsamer LLM-Call mit einem nicht
abfangbaren Fatal ab (der Client sieht nur „HTTP 500"). Das ist ein Sicherheitsnetz;
ein Reasoning-Modell kann es trotzdem reißen — dann ist ein Non-Reasoning-Modell die
Lösung, nicht ein höheres Limit.

**Think-Strip als Fallback:** `AiPlatformProvider::clean()` entfernt einen führenden
`<think>`-Block, falls doch ein Reasoning-Modell im Einsatz ist — modell-agnostisch
(bei Non-Reasoning-Modellen greift die Regex schlicht nicht). Zusätzlich wird ein den
gesamten Text umschließendes Anführungszeichen-Paar entfernt.

## Kostenlos testen (Ollama, lokal)

Kein API-Key, keine Kosten, offline:

1. `brew install ollama`, Dienst starten (`ollama serve`).
2. Modell laden — ein Non-Reasoning-Modell (s. o.), z. B. `ollama pull gemma3:12b`
   (schneller: `gemma3:4b`). **Kein** qwen3/deepseek-r1 fürs interaktive MT.
3. **AI Platform → Profile:** Typ `Text`, Provider `Ollama (Lokal)`, Basis-URL
   `http://localhost:11434`, Modell z. B. `gemma3:12b`, API-Key leer → „Verbindung testen".
4. **AI Platform → Einstellungen:** dieses Profil als **Standard-Text-Profil**.
5. REDAXO-Cache leeren (neue lib-Klasse wird sonst nicht gefunden).

Das **Modell** wechselst du jederzeit im Profil (reine Profil-Sache, kein Prompt) —
sprog baut den System-Prompt selbst und nutzt immer das im Standard-Profil
hinterlegte Modell.

Danach erscheint `ai_platform` als Provider und die MT-Leiste im Akkordeon nutzt
ihn. Alternative: Google Gemini (Gratis-Kontingent, Provider `Google`).

## Beteiligte Klassen

- `lib/Sprog/Mt/ProviderInterface.php`, `lib/Sprog/Mt/TranslationResult.php`
- `lib/Sprog/Mt/NoopProvider.php`, `DeepLProvider.php`, `AiPlatformProvider.php`
- `lib/Sprog/Service/MtService.php` — Registry + Sprachcode-Validierung.
- `lib/Sprog/Controller/Inbox/MtController.php` — `?func=mt`-Endpoint; reichert Glossar + Kontext an.

## Offene Punkte

1. **Provider-Wahl** — *erledigt (2026-07):* jeder konfigurierte Provider hat in
   der MT-Leiste einen eigenen Button; der Klick wählt ihn direkt (kein globales
   Setting). Offen bleibt nur eine Automatik *pro Sprachpaar* (z. B. DeepL für
   europäische Sprachen, KI für den Rest).
2. **Batch-Vorübersetzung** — alle „Fehlt"-Einträge einer Sprache chunked als
   „Prüfung nötig"-Entwürfe erzeugen (wie die v1→v2-Migration).
3. **DeepL-Native-Glossary** — DeepL ignoriert die Glossar-Map aktuell; die native
   `glossary_ids`-Anbindung ist offen (nur der KI-Provider nutzt das Glossar).
4. **MCP-Tools** (`AI_PLATFORM_MCP_TOOLS`) — sprog-Werkzeuge (z. B. `sprog_translate`)
   für externe Assistenten bereitstellen.
