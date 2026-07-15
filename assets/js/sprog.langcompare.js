/**
 * sprog-langcompare — Artikel-Sprachvergleich in der Struktur-Content-Maske.
 *
 * Zwei native Sprachspalten nebeneinander (gerendert über rex_article_content_editor,
 * s. lib/Sprog/View/LangCompare.php). Die native Original-Slice-Liste wird bei
 * aktivem Vergleich ausgeblendet (Body-Klasse sprog-lc-on).
 *
 * Aktionen bleiben inline: native Slice-Links (Status/Verschieben/Löschen) werden
 * abgefangen und über den Endpoint (func=slice_status|slice_move|slice_delete)
 * ausgeführt; „→ kopieren" pro Slice über func=slice_copy. Nach jeder Aktion wird
 * das Panel neu geladen (entspricht einem nativen Reload). editieren/hinzufügen
 * bleiben vorerst native Links (Schritt 3).
 *
 * Konfiguration/Bootstrap: window.sprogLangCompare (pro Content-Render neu gesetzt).
 * Listener werden per Delegation einmalig gebunden (PJAX-fest);
 * window.sprogLangCompareBoot() stellt nach jedem Render den Zustand wieder her.
 */
(function () {
    'use strict';

    function cfg() {
        return window.sprogLangCompare || null;
    }

    function store(key, value) {
        try {
            window.localStorage.setItem('sprog.langcompare.' + key, value);
        } catch (e) {
            /* localStorage nicht verfügbar */
        }
    }

    function restore(key) {
        try {
            return window.localStorage.getItem('sprog.langcompare.' + key);
        } catch (e) {
            return null;
        }
    }

    function el(id) {
        return document.getElementById(id);
    }

    function panelHost() {
        return el('sprog-langcompare-panel');
    }

    // Zustand liegt in localStorage: langb (0 = aus) und mode (content|metadata).
    // Der gemerkte Wert wird gegen die Menüeinträge validiert: das Dropdown listet
    // nur „andere" Sprachen (ohne die aktuelle Backend-Sprache). Ist die gemerkte
    // Sprache = aktuelle Backend-Sprache (z. B. nach Sprachwechsel), gibt es keinen
    // Eintrag → ungültig → 0 (aus). Der localStorage-Wert bleibt unangetastet, damit
    // ein Zurückwechseln den Vergleich wiederherstellt.
    function currentLangB() {
        var raw = parseInt(restore('langb'), 10) || 0;
        if (raw <= 0) {
            return 0;
        }
        var sw = el('sprog-langcompare-switch');
        if (sw && sw.querySelector('.sprog-lc-lang[data-lang="' + raw + '"]')) {
            return raw;
        }
        return 0;
    }

    function currentMode() {
        return 'metadata' === restore('mode') ? 'metadata' : 'content';
    }

    // Body-Klasse blendet die native Original-Slice-Liste aus (CSS in sprog.v2.css).
    function setCompareMode(on) {
        document.body.classList.toggle('sprog-lc-on', on);
    }

    // Metadaten-Sidebar (.col-lg-4) aus- und Inhaltsspalte (.col-lg-8) breit
    // schalten, solange der Vergleich aktiv ist — mehr Platz für die Spalten.
    function setSidebarHidden(on) {
        var sb = document.getElementById('rex-js-main-sidebar');
        var sbCol = sb && sb.closest ? sb.closest('[class*="col-"]') : null;
        var host = panelHost();
        var contentCol = host && host.closest ? host.closest('[class*="col-"]') : null;
        if (sbCol) {
            sbCol.classList.toggle('sprog-lc-col-hidden', on);
        }
        if (contentCol) {
            contentCol.classList.toggle('sprog-lc-col-wide', on);
        }
    }

    // Sperrt alle Aktionen (beide Spalten), solange eine Spalte im Bearbeiten-/
    // Hinzufügen-Modus ist — verhindert zwei gleichzeitig offene Formulare und
    // schützt das offene. Bewusst NICHT an ein `<form>` gekoppelt: die native
    // Slice-Liste enthält je nach REDAXO-Version schon im Ruhezustand Formulare
    // (z.B. die „Block hinzufügen"-Modulauswahl oder Status-Toggles), was den
    // Vergleich sonst dauerhaft sperren würde (alle Buttons disabled).
    function updateLockState() {
        var host = panelHost();
        if (host) {
            host.classList.toggle('sprog-lc-locked', !!host.querySelector('.sprog-lc-col--editing'));
        }
    }

    function deactivate() {
        setCompareMode(false);
        setSidebarHidden(false);
        document.body.classList.remove('sprog-lc-mode-content', 'sprog-lc-mode-metadata');
        var host = panelHost();
        if (host) {
            host.hidden = true;
            host.innerHTML = '';
            host.classList.remove('sprog-lc-locked');
        }
    }

    // Native REDAXO-Widgets/Verhalten im frisch eingespielten Panel initialisieren
    // (Bootstrap-Dropdowns, später Medienpool/Link/Editor im Edit-Formular).
    function rexReady(container) {
        if (window.jQuery) {
            try {
                window.jQuery(document).trigger('rex:ready', [window.jQuery(container)]);
            } catch (e) {
                /* rex:ready optional */
            }
        }
    }

    // „→ kopieren"-Button in jede native Slice-Optionsleiste einhängen. Ziel ist
    // jeweils die Gegenspalte.
    function injectCopyButtons() {
        var host = panelHost();
        if (!host) {
            return;
        }
        var cols = host.querySelectorAll('.sprog-langcompare--col');
        if (cols.length < 2) {
            return;
        }
        var meta = Array.prototype.map.call(cols, function (c) {
            var nameEl = c.querySelector('.sprog-langcompare--col-name');
            return { clang: c.getAttribute('data-clang'), name: nameEl ? nameEl.textContent : '' };
        });

        Array.prototype.forEach.call(cols, function (col, i) {
            var other = meta[i === 0 ? 1 : 0];
            col.querySelectorAll('.rex-slice-output').forEach(function (li) {
                var sliceId = (li.id || '').replace('slice', '');
                if (!sliceId) {
                    return;
                }
                var options = li.querySelector('.rex-panel-options');
                if (!options || options.querySelector('.sprog-lc-copy')) {
                    return;
                }
                var group = document.createElement('div');
                group.className = 'btn-group btn-group-xs sprog-lc-copy-group';
                var a = document.createElement('a');
                a.href = '#';
                a.className = 'btn btn-default sprog-lc-copy';
                a.setAttribute('data-slice', sliceId);
                a.setAttribute('data-from', meta[i].clang);
                a.setAttribute('data-to', other.clang);
                a.title = cfg().strings.copyTo.replace('{0}', other.name);
                a.textContent = '→ ' + other.name;
                group.appendChild(a);
                options.appendChild(group);
            });
        });
    }

    // „Alles aus <Sprache> kopieren" in eine LEERE Spalte einhängen, wenn die
    // Gegenspalte Inhalt hat. Kopiert die ganze (leere) Zielsprache aus der Quelle
    // (func=batch_copy). Richtung wird aus „welche Spalte ist leer" abgeleitet —
    // der Endpoint verlangt eine leere Zielsprache (verhindert Dubletten).
    function injectBatchButton() {
        var host = panelHost();
        var c = cfg();
        if (!host || !c) {
            return;
        }
        var cols = host.querySelectorAll('.sprog-langcompare--col');
        if (cols.length < 2) {
            return;
        }
        var info = Array.prototype.map.call(cols, function (col) {
            var nameEl = col.querySelector('.sprog-langcompare--col-name');
            return {
                col: col,
                clang: col.getAttribute('data-clang'),
                name: nameEl ? nameEl.textContent.trim() : '',
                count: col.querySelectorAll('.rex-slice-output').length,
            };
        });
        info.forEach(function (target, i) {
            var source = info[i === 0 ? 1 : 0];
            // nur wenn Ziel leer UND Quelle Inhalt hat; nicht doppelt injizieren
            if (0 !== target.count || 0 === source.count || target.col.querySelector('.sprog-lc-batch')) {
                return;
            }
            var wrap = document.createElement('div');
            wrap.className = 'sprog-langcompare--batch';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'sprog-btn sprog-btn--primary sprog-btn--sm sprog-lc-batch';
            btn.textContent = c.strings.batch.replace('{0}', source.name);
            btn.setAttribute('data-from', source.clang);
            btn.setAttribute('data-to', target.clang);
            wrap.appendChild(btn);
            var head = target.col.querySelector('.sprog-langcompare--col-head');
            if (head) {
                head.insertAdjacentElement('afterend', wrap);
            } else {
                target.col.insertBefore(wrap, target.col.firstChild);
            }
        });
    }

    async function loadPanel(langB) {
        var c = cfg();
        var host = panelHost();
        if (!c || !host || !langB) {
            return;
        }

        host.hidden = false;
        host.innerHTML = '<p class="sprog-langcompare--loading">' + c.strings.loading + '</p>';

        var url = c.endpoint
            + '&func=panel'
            + '&article_id=' + encodeURIComponent(c.article)
            + '&clang_a=' + encodeURIComponent(c.clangA)
            + '&clang_b=' + encodeURIComponent(langB)
            + '&ctype=' + encodeURIComponent(c.ctype)
            + '&revision=' + encodeURIComponent(c.revision);

        try {
            var response = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            host.innerHTML = await response.text();
            injectCopyButtons();
            injectBatchButton();
            rexReady(host);
            updateLockState();
        } catch (e) {
            host.innerHTML = '<p class="sprog-note sprog-note--warning">' + c.strings.serverError + '</p>';
        }
    }

    function colClang(node) {
        var col = node && node.closest ? node.closest('.sprog-langcompare--col') : null;
        return col ? col.getAttribute('data-clang') : 0;
    }

    // Eine einzelne Spalte neu rendern (Liste ↔ Bearbeiten-/Hinzufügen-Formular),
    // ohne die Gegenspalte anzufassen. Widgets im Formular per rex:ready initialisieren.
    async function loadColumn(clang, fn, sliceId, moduleId) {
        var c = cfg();
        var host = panelHost();
        if (!c || !host) {
            return;
        }
        var col = host.querySelector('.sprog-langcompare--col[data-clang="' + clang + '"]');
        if (!col) {
            return;
        }

        var url = c.endpoint
            + '&func=column'
            + '&article_id=' + encodeURIComponent(c.article)
            + '&clang=' + encodeURIComponent(clang)
            + '&ctype=' + encodeURIComponent(c.ctype)
            + '&revision=' + encodeURIComponent(c.revision)
            + '&function=' + encodeURIComponent(fn || '')
            + '&slice_id=' + encodeURIComponent(sliceId || 0)
            + '&module_id=' + encodeURIComponent(moduleId || 0);

        try {
            var html = await (await fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })).text();
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var newCol = tmp.querySelector('.sprog-langcompare--col') || tmp.firstElementChild;
            if (!newCol) {
                return;
            }
            // Bearbeiten/Hinzufügen → Spalte als „editierend" markieren, damit
            // updateLockState() den Vergleich sperrt (unabhängig davon, ob die
            // Slice-Liste ohnehin schon Formulare enthält).
            if ('edit' === fn || 'add' === fn) {
                newCol.classList.add('sprog-lc-col--editing');
            }
            col.replaceWith(newCol);
            injectCopyButtons();
            rexReady(newCol);
            updateLockState();
            if ('edit' === fn || 'add' === fn) {
                injectMtButtons(newCol);
            }
        } catch (e) {
            window.alert(c.strings.serverError);
        }
    }

    // Native Erfolgsmeldung nach dem Speichern am (neu geladenen) Slice zeigen —
    // die Editor-Ausgabe selbst trägt sie nicht, da wir frisch rendern. sliceId=0
    // (z. B. Hinzufügen) → oben in der Liste. textContent → kein XSS.
    function showSaveNote(clang, sliceId, msg) {
        if (!msg) {
            return;
        }
        var host = panelHost();
        var col = host && host.querySelector('.sprog-langcompare--col[data-clang="' + clang + '"]');
        if (!col) {
            return;
        }
        var note = document.createElement('div');
        note.className = 'alert alert-success sprog-lc-savenote';
        note.textContent = msg;

        var slice = sliceId ? col.querySelector('#slice' + sliceId) : null;
        if (slice) {
            slice.insertBefore(note, slice.firstChild);
        } else {
            var ul = col.querySelector('.rex-slices');
            if (ul) {
                ul.insertBefore(note, ul.firstChild);
            } else {
                col.appendChild(note);
            }
        }
    }

    async function post(func, params) {
        var c = cfg();
        var body = new FormData();
        body.set(c.csrf.name, c.csrf.value);
        body.set('func', func);
        Object.keys(params).forEach(function (key) {
            body.set(key, params[key]);
        });

        var response = await fetch(c.endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        var data;
        try {
            data = await response.json();
        } catch (e) {
            throw new Error(c.strings.serverError);
        }
        if (!data || !data.success) {
            throw new Error((data && data.error) || c.strings.serverError);
        }
        return data;
    }

    // Slice-Aktion (Status/Verschieben/Löschen) aus einem nativen Link ableiten
    // und über den Endpoint ausführen, dann Panel neu laden.
    async function sliceAction(func, link, confirmFirst) {
        var col = link.closest('.sprog-langcompare--col');
        var clang = col ? col.getAttribute('data-clang') : 0;
        var url = new URL(link.href, window.location.href);

        var params = {
            article_id: cfg().article,
            slice_id: url.searchParams.get('slice_id') || '',
            clang: clang,
        };
        if ('slice_move' === func) {
            params.direction = url.searchParams.get('direction') || '';
        }

        if (confirmFirst && !window.confirm(link.getAttribute('data-confirm') || cfg().strings.confirmDelete)) {
            return;
        }

        try {
            await post(func, params);
            await loadPanel(currentLangB());
        } catch (e) {
            window.alert(e.message);
        }
    }

    async function doCopy(btn) {
        try {
            await post('slice_copy', {
                article_id: cfg().article,
                slice_id: btn.getAttribute('data-slice'),
                clang_from: btn.getAttribute('data-from'),
                clang_to: btn.getAttribute('data-to'),
                target_position: 0, // anhängen
                revision: cfg().revision,
            });
            await loadPanel(currentLangB());
        } catch (e) {
            window.alert(e.message);
        }
    }

    // Ganze (leere) Zielsprache aus der Quelle befüllen (func=batch_copy).
    async function doBatch(btn) {
        if (!window.confirm(cfg().strings.batchConfirm)) {
            return;
        }
        btn.disabled = true;
        try {
            await post('batch_copy', {
                article_id: cfg().article,
                clang_a: btn.getAttribute('data-from'),
                clang_b: btn.getAttribute('data-to'),
                revision: cfg().revision,
            });
            await loadPanel(currentLangB());
        } catch (e) {
            btn.disabled = false;
            window.alert(e.message);
        }
    }

    // Formular-Controls einer Spalte (de)aktivieren; im Vergleich sind beide
    // Spalten read-only, damit keine Widgets initialisiert werden (Kollision).
    function disableColumnControls(col, on) {
        col.classList.toggle('sprog-lc-readonly', on);
        col.querySelectorAll('input, select, textarea, button').forEach(function (f) {
            f.disabled = on;
        });
    }

    // IDs (id/for) einer Spalte entfernen — verhindert, dass die Widgets der
    // aktiven Spalte per getElementById die gleich benannten Felder der anderen
    // Spalte treffen (METAINFO_*, yform).
    function stripIds(col) {
        col.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
        col.querySelectorAll('[for]').forEach(function (el) { el.removeAttribute('for'); });
    }

    // Metadaten-Vergleich: volle native Sidebar beider Sprachen (read-only).
    async function loadMetaPanel(langB) {
        var host = panelHost();
        var c = cfg();
        if (!host || !c || !langB) {
            return;
        }
        host.hidden = false;
        host.classList.remove('sprog-lc-locked');
        host.innerHTML = '<p class="sprog-langcompare--loading">' + c.strings.loading + '</p>';

        var url = c.endpoint
            + '&func=meta'
            + '&article_id=' + encodeURIComponent(c.article)
            + '&clang_a=' + encodeURIComponent(c.clangA)
            + '&clang_b=' + encodeURIComponent(langB)
            + '&ctype=' + encodeURIComponent(c.ctype);

        try {
            host.innerHTML = await (await fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })).text();
            // Beide Spalten read-only + NICHT rex:ready → keine Medien-/Link-
            // Widget-Init (Kollision). ABER die selectpicker (Datum/Selects) sind
            // per CSS bis zur JS-Init display:none — daher gezielt nur diese
            // initialisieren, damit Datums-/Auswahlfelder sichtbar/vergleichbar sind.
            host.querySelectorAll('.sprog-langcompare--metacol').forEach(function (col) {
                disableColumnControls(col, true);
            });
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.selectpicker) {
                window.jQuery(host).find('.selectpicker').selectpicker();
            }
        } catch (e) {
            host.innerHTML = '<p class="sprog-note sprog-note--warning">' + c.strings.serverError + '</p>';
        }
    }

    // Eine Metadaten-Spalte in den Bearbeiten-Modus laden (volle native Sidebar,
    // aktiv + rex:ready). Die Gegenspalte bleibt read-only, ihre IDs werden
    // gestrippt → nur die aktive Spalte hat lebende Widget-IDs.
    async function loadMetaColumn(clang) {
        var c = cfg();
        var host = panelHost();
        if (!c || !host) {
            return;
        }
        var col = host.querySelector('.sprog-langcompare--metacol[data-clang="' + clang + '"]');
        if (!col) {
            return;
        }
        var url = c.endpoint
            + '&func=meta_edit'
            + '&article_id=' + encodeURIComponent(c.article)
            + '&clang=' + encodeURIComponent(clang)
            + '&ctype=' + encodeURIComponent(c.ctype);
        try {
            var html = await (await fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })).text();
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var newCol = tmp.querySelector('.sprog-langcompare--metacol') || tmp.firstElementChild;
            if (!newCol) {
                return;
            }
            // Gegenspalte(n) read-only + IDs strippen, DANN aktive Spalte einsetzen.
            host.querySelectorAll('.sprog-langcompare--metacol').forEach(function (other) {
                if (other !== col) {
                    disableColumnControls(other, true);
                    stripIds(other);
                }
            });
            col.replaceWith(newCol);
            host.classList.add('sprog-lc-locked');
            rexReady(newCol);
            injectMtButtons(newCol);
        } catch (e) {
            window.alert(c.strings.serverError);
        }
    }

    // Erfolgsmeldung nach dem Metadaten-Speichern oben in der gespeicherten Spalte
    // zeigen: direkt unter den Spaltenkopf, also ÜBER den Metadaten-Feldern (nicht
    // unter der ganzen Spalte). Die Metadaten-Spalte trägt .sprog-langcompare--metacol.
    function showMetaNote(clang, msg) {
        if (!msg) {
            return;
        }
        var host = panelHost();
        var col = host && host.querySelector('.sprog-langcompare--metacol[data-clang="' + clang + '"]');
        if (!col) {
            return;
        }
        var note = document.createElement('div');
        note.className = 'alert alert-success sprog-lc-savenote';
        note.textContent = msg;
        var head = col.querySelector('.sprog-langcompare--col-head');
        if (head) {
            head.insertAdjacentElement('afterend', note);
        } else {
            col.insertBefore(note, col.firstChild);
        }
    }

    // Sidebar-Formular (metainfo ODER yrewrite-SEO) per fetch an content/edit
    // speichern; der Auslöser (savemeta bzw. yform-Button) kommt aus dem Submitter.
    // Danach den read-only Vergleich neu laden.
    async function handleMetaSave(form, submitter) {
        var c = cfg();
        var col = form.closest('.sprog-langcompare--metacol');
        var clang = col ? col.getAttribute('data-clang') : currentLangB();
        try {
            var fd = new FormData(form);
            if (submitter && submitter.name) {
                fd.set(submitter.name, submitter.value || '1');
            }
            fd.set('clang', clang);
            fd.set('article_id', c.article);
            if (!fd.has('ctype')) {
                fd.set('ctype', c.ctype);
            }

            var action = c.endpoint.split('?')[0] + '?page=content/edit';
            var res = await fetch(action, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            var text = await res.text();
            var doc = new DOMParser().parseFromString(text, 'text/html');

            if (doc.querySelector('.alert-success')) {
                var msg = doc.querySelector('.alert-success').textContent.replace(/^\s*×?\s*/, '').trim();
                await loadMetaPanel(currentLangB());
                showMetaNote(clang, msg);
            } else {
                var warn = doc.querySelector('.alert-danger, .alert-warning');
                window.alert(warn ? warn.textContent.trim() : c.strings.serverError);
            }
        } catch (e) {
            window.alert(c.strings.serverError);
        }
    }

    // Das Steuer-Dropdown neben den nativen Sprachschalter .rex-language hängen.
    // Vor .rex-language in den DOM → bei float:right steht es rechts daneben
    // (Reihenfolge: „Sprache …" links, „Vergleich …" rechts). Fällt zurück auf
    // die EP-Position, falls .rex-language fehlt.
    function relocateSwitch() {
        // REDAXO rendert die Sprachauswahl bei >= 4 clangs als Dropdown
        // (.rex-language), bei 2-3 clangs als Button-Gruppe (.rex-nav-language).
        // Beide werden float:right ausgegeben; wir setzen den Switch direkt davor,
        // damit er (ebenfalls float:right) rechts daneben in derselben Zeile sitzt.
        var lang = document.querySelector('.rex-language, .rex-nav-language');
        // Der Switch wird beim ersten Aufbau AUS dem PJAX-Container (#rex-js-page-
        // main-content) neben die Sprachauswahl (in #rex-js-page-main) verschoben.
        // Nach einer PJAX-Navigation liefert der Server einen frischen Switch INNEN
        // → kurzzeitig zwei mit gleicher ID. Duplikate entfernen: den bereits neben
        // der Sprachauswahl platzierten behalten, sonst den ersten.
        var switches = document.querySelectorAll('#sprog-langcompare-switch');
        if (switches.length > 1) {
            var keep = null;
            var i;
            for (i = 0; i < switches.length; i++) {
                if (lang && switches[i].nextElementSibling === lang) {
                    keep = switches[i];
                    break;
                }
            }
            if (!keep) {
                keep = switches[0];
            }
            for (i = 0; i < switches.length; i++) {
                if (switches[i] !== keep && switches[i].parentNode) {
                    switches[i].parentNode.removeChild(switches[i]);
                }
            }
        }
        var sw = el('sprog-langcompare-switch');
        if (sw && lang && sw.nextElementSibling !== lang) {
            lang.parentNode.insertBefore(sw, lang);
        }
    }

    // Nach einer PJAX-Navigation läuft das genonc'te Inline-<script>, das
    // window.sprogLangCompare (article/clang/ctype) neu setzt, NICHT erneut → die
    // Config ist veraltet. Die veränderlichen Werte aus der aktuellen URL nachziehen,
    // damit das Panel den richtigen Artikel/ctype lädt. csrf/endpoint/strings/
    // mtProviders bleiben pro Session stabil und werden bewusst nicht angefasst.
    function refreshConfigFromUrl() {
        var c = cfg();
        if (!c) {
            return;
        }
        try {
            var p = new URLSearchParams(window.location.search);
            var article = parseInt(p.get('article_id'), 10);
            var clang = parseInt(p.get('clang'), 10);
            var ctype = parseInt(p.get('ctype'), 10);
            if (article) {
                c.article = article;
            }
            if (clang) {
                c.clangA = clang;
            }
            if (ctype) {
                c.ctype = ctype;
            }
        } catch (e) {
            /* URLSearchParams nicht verfügbar → Config unverändert */
        }
    }

    // Label + aktive Markierungen (Sprache/Modus) des Dropdowns aktualisieren.
    function updateSwitchUI() {
        var sw = el('sprog-langcompare-switch');
        if (!sw || !cfg()) {
            return;
        }
        var langB = currentLangB();
        var mode = currentMode();

        var label = sw.querySelector('.sprog-langcompare-switch--label');
        if (label) {
            if (langB > 0) {
                var item = sw.querySelector('.sprog-lc-lang[data-lang="' + langB + '"]');
                var name = item ? item.textContent : String(langB);
                // „Vergleich: {0}" mit dem Sprachnamen in <b> (wie der native
                // Sprachschalter „Sprache <b>…</b>"). DOM-Knoten → XSS-sicher.
                var parts = cfg().strings.switchOn.split('{0}');
                label.textContent = '';
                label.appendChild(document.createTextNode(parts[0]));
                var b = document.createElement('b');
                b.textContent = name;
                label.appendChild(b);
                if (parts.length > 1) {
                    label.appendChild(document.createTextNode(parts.slice(1).join('{0}')));
                }
            } else {
                label.textContent = cfg().strings.switchOff;
            }
        }
        // aktive Sprache wie der native .rex-language über <li class="active">
        // markieren (Bootstrap-Blau) — kein eigenes Icon.
        sw.querySelectorAll('.sprog-lc-lang').forEach(function (a) {
            var li = a.closest('li');
            if (li) {
                li.classList.toggle('active', (parseInt(a.getAttribute('data-lang'), 10) || 0) === langB);
            }
        });
        sw.querySelectorAll('.sprog-lc-mode').forEach(function (a) {
            a.classList.toggle('is-active', a.getAttribute('data-mode') === mode);
        });
        sw.classList.toggle('is-on', langB > 0);
    }

    // Zentrale Anwendung des Zustands (Sprache + Modus) — Erstladen, PJAX,
    // Auswahländerung. langB=0 → aus.
    async function apply() {
        updateSwitchUI();
        var langB = currentLangB();
        var mode = currentMode();

        if (langB <= 0) {
            deactivate();
            return;
        }

        setCompareMode(true);
        setSidebarHidden(true);
        document.body.classList.toggle('sprog-lc-mode-content', 'content' === mode);
        document.body.classList.toggle('sprog-lc-mode-metadata', 'metadata' === mode);

        if ('metadata' === mode) {
            await loadMetaPanel(langB);
        } else {
            await loadPanel(langB);
        }
    }

    // Sprachname für die MT-Beschriftung. Zuerst aus dem Spaltenkopf (enthält den
    // Namen beider Spalten inkl. der aktuellen Backend-Sprache), sonst aus den
    // Dropdown-Einträgen (nur „andere" Sprachen), zuletzt Fallback auf die ID.
    function langNameFor(clang) {
        var host = panelHost();
        var colName = host ? host.querySelector('.sprog-langcompare--col[data-clang="' + clang + '"] .sprog-langcompare--col-name') : null;
        if (colName) {
            return colName.textContent.trim();
        }
        var sw = el('sprog-langcompare-switch');
        var item = sw ? sw.querySelector('.sprog-lc-lang[data-lang="' + clang + '"]') : null;
        return item ? item.textContent.trim() : String(clang);
    }

    // MT-Buttons ins aktive Bearbeiten-Formular einer Spalte einhängen — Optik +
    // Beschriftung wie in der Inbox: ein Button je konfiguriertem Provider
    // (Label = Provider-Name, sprog-btn sprog-btn--sm), plus oben „Alle Felder
    // übersetzen" je Provider. Nur wenn ein Provider konfiguriert ist.
    function injectMtButtons(editedCol) {
        var c = cfg();
        if (!c || !c.mtProviders || !c.mtProviders.length || !editedCol) {
            return;
        }
        var host = panelHost();
        var targetClang = editedCol.getAttribute('data-clang');
        var sourceClang = null;
        if (host) {
            host.querySelectorAll('.sprog-langcompare--col').forEach(function (col) {
                if (col !== editedCol) {
                    sourceClang = col.getAttribute('data-clang');
                }
            });
        }
        if (!sourceClang || sourceClang === targetClang) {
            return;
        }
        var srcName = langNameFor(sourceClang);
        var multi = c.mtProviders.length > 1;

        var count = 0;
        editedCol.querySelectorAll('textarea:not([readonly]):not([disabled]), input[type="text"]:not([readonly]):not([disabled])').forEach(function (f) {
            if (!f.name || (f.nextElementSibling && f.nextElementSibling.classList && f.nextElementSibling.classList.contains('sprog-lc-mt-wrap'))) {
                return;
            }
            var wrap = document.createElement('span');
            wrap.className = 'sprog-lc-mt-wrap';
            c.mtProviders.forEach(function (p) {
                // <button type="button"> wie in der Inbox (kein a:hover-Underline)
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'sprog-btn sprog-btn--sm sprog-lc-mt';
                btn.textContent = p.label;
                btn.title = c.strings.mtTitle.replace('{0}', srcName);
                btn.setAttribute('data-provider', p.name);
                btn.setAttribute('data-src', sourceClang);
                btn.setAttribute('data-tgt', targetClang);
                wrap.appendChild(btn);
            });
            f.insertAdjacentElement('afterend', wrap);
            count++;
        });

        if (count > 0) {
            // „Alle übersetzen" direkt in den Kopf (er ist in beiden Spalten
            // konstant 46px hoch → keine Feld-Verschiebung). Vor „Abbrechen".
            var head = editedCol.querySelector('.sprog-langcompare--col-head');
            var anchor = head ? head.querySelector('.sprog-lc-meta-cancel') : null;
            c.mtProviders.forEach(function (p) {
                var all = document.createElement('button');
                all.type = 'button';
                all.className = 'sprog-btn sprog-btn--sm sprog-lc-mt-all';
                all.textContent = multi ? c.strings.mtAll + ' · ' + p.label : c.strings.mtAll;
                all.title = c.strings.mtTitle.replace('{0}', srcName);
                all.setAttribute('data-provider', p.name);
                if (!head) {
                    editedCol.insertBefore(all, editedCol.firstChild);
                } else if (anchor) {
                    head.insertBefore(all, anchor);
                } else {
                    head.appendChild(all);
                }
            });
        }
    }

    // Ein Feld per MT übersetzen (Feldinhalt aus der Gegensprache → aktuelle).
    async function doMt(btn) {
        var wrap = btn.closest('.sprog-lc-mt-wrap');
        var field = wrap ? wrap.previousElementSibling : btn.previousElementSibling;
        if (!field || !('value' in field) || '' === field.value.trim()) {
            return;
        }
        btn.classList.add('sprog-lc-mt--busy');
        try {
            var data = await post('mt', {
                text: field.value,
                source_clang: btn.getAttribute('data-src'),
                target_clang: btn.getAttribute('data-tgt'),
                provider: btn.getAttribute('data-provider') || '',
            });
            field.value = data.text;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (e) {
            window.alert(e.message);
        } finally {
            btn.classList.remove('sprog-lc-mt--busy');
        }
    }

    // Alle Textfelder der Spalte mit demselben Provider nacheinander übersetzen.
    async function doMtAll(btn) {
        var col = btn.closest('.sprog-langcompare--col');
        if (!col) {
            return;
        }
        var provider = btn.getAttribute('data-provider');
        var buttons = Array.prototype.filter.call(
            col.querySelectorAll('.sprog-lc-mt'),
            function (b) { return b.getAttribute('data-provider') === provider; },
        );
        btn.classList.add('sprog-lc-mt--busy');
        try {
            for (var i = 0; i < buttons.length; i++) {
                await doMt(buttons[i]);
            }
        } finally {
            btn.classList.remove('sprog-lc-mt--busy');
        }
    }

    // Zustand nach jedem Content-Render wiederherstellen (Erstladen + PJAX).
    function boot() {
        if (!cfg() || !el('sprog-langcompare-switch')) {
            // Keine Content-Maske (z.B. via PJAX weg­navigiert) → evtl. gesetzte
            // Vergleichs-Klassen von Body/Layout entfernen, damit sie nicht auf
            // Fremdseiten haften (sonst bliebe die native Slice-Liste ausgeblendet).
            deactivate();
            return;
        }
        refreshConfigFromUrl();
        relocateSwitch();
        apply();
    }

    window.sprogLangCompareBoot = boot;

    if (!window.__sprogLangCompareBound) {
        window.__sprogLangCompareBound = true;

        // Nach einer nativen PJAX-Navigation (ctype-Wechsel, „Editiermodus" und
        // andere Content-Links AUSSERHALB des Panels) läuft der genonc'te Inline-
        // Bootstrap NICHT erneut. Ohne Re-Init bliebe der Vergleich inaktiv, während
        // body.sprog-lc-on die native Slice-Liste weiter ausblendet → leere Maske.
        // Deshalb hier neu booten. Bewusst pjax:end statt rex:ready: unser rexReady()
        // feuert selbst rex:ready (würde eine Endlosschleife boot→loadPanel→rexReady
        // →boot auslösen); pjax:end feuert ausschließlich bei echter PJAX-Navigation.
        if (window.jQuery) {
            window.jQuery(document).on('pjax:end', function () {
                boot();
            });
        }

        document.addEventListener('click', function (e) {
            if (!e.target.closest) {
                return;
            }

            // Steuer-Dropdown (liegt außerhalb des Panels, neben .rex-language):
            // Sprache wählen (0 = aus) bzw. Modus umschalten.
            var langItem = e.target.closest('.sprog-lc-lang');
            if (langItem) {
                e.preventDefault();
                store('langb', langItem.getAttribute('data-lang'));
                apply();
                return;
            }
            var modeItem = e.target.closest('.sprog-lc-mode');
            if (modeItem) {
                e.preventDefault();
                store('mode', modeItem.getAttribute('data-mode'));
                apply();
                return;
            }

            var host = panelHost();
            if (!host) {
                return;
            }

            // Ein echtes offenes Bearbeiten-/Hinzufügen-Formular sperrt alle
            // Aktionen außerhalb davon (Backup zur CSS-Sperre, verhindert ein
            // zweites offenes Formular). Erkennung über die editierende Spalte
            // (.sprog-lc-col--editing) — bewusst NICHT über host.querySelector('form'):
            // die native Slice-Liste enthält je nach REDAXO-Version schon im
            // Ruhezustand ein <form> (z.B. die „Block hinzufügen"-Modulauswahl),
            // wodurch Kopieren und native Slice-Aktionen sonst dauerhaft ins Leere
            // klicken.
            var editingCol = host.querySelector('.sprog-lc-col--editing');
            var openForm = editingCol ? editingCol.querySelector('form') : null;

            // MT-Buttons (vor der Sperr-Logik, damit „Alle übersetzen" im Kopf greift)
            var mtAll = e.target.closest('.sprog-lc-mt-all');
            if (mtAll && host.contains(mtAll)) {
                e.preventDefault();
                doMtAll(mtAll);
                return;
            }
            var mtBtn = e.target.closest('.sprog-lc-mt');
            if (mtBtn && host.contains(mtBtn)) {
                e.preventDefault();
                doMt(mtBtn);
                return;
            }

            // „→ kopieren" (eigener Button)
            var copyBtn = e.target.closest('.sprog-lc-copy');
            if (copyBtn && host.contains(copyBtn)) {
                e.preventDefault();
                if (openForm && !openForm.contains(copyBtn)) {
                    return;
                }
                doCopy(copyBtn);
                return;
            }

            // „Alles aus <Sprache> kopieren" (leere Zielsprache befüllen)
            var batchBtn = e.target.closest('.sprog-lc-batch');
            if (batchBtn && host.contains(batchBtn)) {
                e.preventDefault();
                if (openForm && !openForm.contains(batchBtn)) {
                    return;
                }
                doBatch(batchBtn);
                return;
            }

            // native Slice-Aktionslinks abfangen
            var link = e.target.closest('a');
            if (!link || !host.contains(link)) {
                return;
            }

            // Metadaten-Modus: Bearbeiten / Abbrechen (vor der Sperr-Prüfung,
            // damit „Abbrechen" auch bei offenem Formular funktioniert).
            if (link.classList.contains('sprog-lc-meta-cancel')) {
                e.preventDefault();
                loadMetaPanel(currentLangB());
                return;
            }
            if (link.classList.contains('sprog-lc-meta-edit')) {
                e.preventDefault();
                if (host.classList.contains('sprog-lc-locked')) {
                    return; // es wird bereits eine Spalte bearbeitet → gesperrt
                }
                loadMetaColumn(link.getAttribute('data-clang'));
                return;
            }

            // Content-Modus: Einzelformular-Sperre — Links außerhalb des offenen
            // Formulars sind gesperrt (im Metadaten-Modus greift die eigene Sperre).
            if (!document.body.classList.contains('sprog-lc-mode-metadata') && openForm && !openForm.contains(link)) {
                e.preventDefault();
                return;
            }
            var href = link.getAttribute('href') || '';
            if (/rex-api-call=content_slice_status/.test(href)) {
                e.preventDefault();
                sliceAction('slice_status', link, false);
            } else if (/rex-api-call=content_move_slice/.test(href)) {
                e.preventDefault();
                sliceAction('slice_move', link, false);
            } else if (/[?&]function=delete/.test(href)) {
                e.preventDefault();
                sliceAction('slice_delete', link, true);
            } else if (/[?&]function=edit(?:[&#]|$)/.test(href)) {
                e.preventDefault();
                var ue = new URL(link.href, window.location.href);
                loadColumn(colClang(link), 'edit', ue.searchParams.get('slice_id') || 0, 0);
            } else if (/[?&]function=add(?:[&#]|$)/.test(href)) {
                e.preventDefault();
                var ua = new URL(link.href, window.location.href);
                loadColumn(colClang(link), 'add', ua.searchParams.get('slice_id') || 0, ua.searchParams.get('module_id') || 0);
            } else if (href && '#' !== href && !link.getAttribute('onclick')) {
                // jeder sonstige echte Navigations-Link im Panel (v. a. „Abbrechen",
                // btn-abort) → in der Vergleichsansicht bleiben, Spalte als Liste
                // neu laden. Popup-Buttons (href="#", onclick) sind ausgenommen.
                e.preventDefault();
                loadColumn(colClang(link), '', 0, 0);
            }
        });

        // Inline-Speichern: das native Modul-Formular per fetch an content/edit
        // schicken (echter Core-Save inkl. Editor-Sync + PRE/POST-Actions + EPs),
        // danach die betroffene Spalte neu laden. editor-eigene submit-Listener
        // (Redactor etc.) laufen als form-Listener vor diesem document-Listener,
        // sind also synchronisiert, wenn wir die FormData lesen.
        document.addEventListener('submit', async function (e) {
            var host = panelHost();
            var form = e.target;
            if (!host || !form || !host.contains(form)) {
                return;
            }
            e.preventDefault();

            // Im Metadaten-Modus alle Sidebar-Formulare (metainfo + yrewrite-SEO)
            // über handleMetaSave behandeln.
            if (document.body.classList.contains('sprog-lc-mode-metadata')) {
                await handleMetaSave(form, e.submitter);
                return;
            }

            var c = cfg();
            // Kontext des nativen Formulars steht teils in der action-URL
            // (edit: function + slice_id), teils in Hidden-Feldern (add: function,
            // module_id) — daher beide Quellen berücksichtigen.
            var actionUrl = new URL(form.getAttribute('action') || '', window.location.href);
            var hidden = function (name) {
                var f = form.querySelector('[name="' + name + '"]');
                return f ? f.value : null;
            };
            var clang = actionUrl.searchParams.get('clang') || colClang(form);
            var fn = hidden('function') || actionUrl.searchParams.get('function') || 'edit';
            var sliceId = actionUrl.searchParams.get('slice_id') || hidden('slice_id') || 0;
            var articleId = actionUrl.searchParams.get('article_id') || c.article;
            var ctype = actionUrl.searchParams.get('ctype') || c.ctype;
            var moduleId = hidden('module_id') || actionUrl.searchParams.get('module_id') || 0;
            var keepEditing = e.submitter && /update/i.test(e.submitter.name || '');

            try {
                var fd = new FormData(form);
                if (e.submitter && e.submitter.name) {
                    fd.set(e.submitter.name, e.submitter.value || '1');
                }
                // Kontext + Save erzwingen. clang = Zielspalte.
                fd.set('save', '1');
                fd.set('function', fn);
                fd.set('clang', clang);
                fd.set('ctype', ctype);
                fd.set('article_id', articleId);
                if (sliceId) { fd.set('slice_id', sliceId); }
                if (moduleId) { fd.set('module_id', moduleId); }

                // An content/edit posten (kein clang im GET → POST-clang gewinnt).
                var action = c.endpoint.split('?')[0] + '?page=content/edit';
                var res = await fetch(action, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                var text = await res.text();
                var doc = new DOMParser().parseFromString(text, 'text/html');

                if (doc.querySelector('.alert-success')) {
                    // native Erfolgsmeldung übernehmen (führendes „×" der Close-Box strippen)
                    var savedMsg = doc.querySelector('.alert-success').textContent.replace(/^\s*×?\s*/, '').trim();
                    if (keepEditing && 'edit' === fn && sliceId) {
                        await loadColumn(clang, 'edit', sliceId, 0); // „übernehmen": weiter bearbeiten
                        showSaveNote(clang, sliceId, savedMsg);
                    } else {
                        await loadColumn(clang, '', 0, 0);           // gespeichert → Liste
                        showSaveNote(clang, 'edit' === fn ? sliceId : 0, savedMsg);
                    }
                } else {
                    var warn = doc.querySelector('.alert-danger, .alert-warning');
                    window.alert(warn ? warn.textContent.trim() : c.strings.serverError);
                    await loadColumn(clang, fn, sliceId, moduleId);  // Formular erneut zeigen
                }
            } catch (err) {
                window.alert(c.strings.serverError);
            }
        });
    }

    // Erstladen: Inline-Bootstrap läuft vor diesem deferred Bundle → boot() hier
    // noch einmal selbst anstoßen.
    boot();
})();
