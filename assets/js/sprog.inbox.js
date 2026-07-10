/*
 * sprog Inbox – Inline-Editor mit Auto-Save auf Blur.
 *
 * Strategie:
 *   - Pro Textarea wird beim Blur geprüft, ob der Wert sich gegen `data-last-saved`
 *     verändert hat. Falls ja, geht ein fetch-POST an den JSON-Endpoint
 *     (data-endpoint am Wrapper) raus.
 *   - CSRF-Token + per-Unit-Daten liegen am <details data-unit-id ...>.
 *   - Optimistic-Lock: revision wandert mit; bei 409 zeigt der Toast einen
 *     Reload-Hint, der Wert bleibt im Feld (kein silent-overwrite).
 *   - Read-only Textareas (kein clang-Recht) sind komplett ausgeschlossen.
 */

(() => {
    'use strict';

    const root = document.querySelector('[data-sprog-inbox]');
    if (!root) {
        return;
    }
    const endpoint           = root.getAttribute('data-endpoint');
    const endpointUpdateUnit = root.getAttribute('data-endpoint-update-unit');
    const endpointTransition = root.getAttribute('data-endpoint-transition');
    const endpointMt         = root.getAttribute('data-endpoint-mt');
    const endpointHistory    = root.getAttribute('data-endpoint-history');
    const endpointRestore    = root.getAttribute('data-endpoint-restore');
    const endpointBatchPrepare   = root.getAttribute('data-endpoint-batch-prepare');
    const endpointBatchTranslate = root.getAttribute('data-endpoint-batch-translate');
    // Page-globaler CSRF-Token für Save/Update/Transition. Liegt am Root-Element,
    // damit nicht jede Unit-Karte ihn dupliziert.
    const csrfName           = root.getAttribute('data-csrf-name');
    const csrfValue          = root.getAttribute('data-csrf-value');
    // Aktuell angezeigte Sprache (Filter). Wird gebraucht, um beim Basissprache-
    // Save den Summary-Dot der Zeile zu aktualisieren, falls die angezeigte
    // Sprache selbst gerade veraltet ist.
    const displayClang       = root.getAttribute('data-display-clang');
    // Wildcard-Tags für den Copy-Button: der Platzhalter wird beim Klick aus
    // data-unit-context + data-unit-key + diesen Tags gebaut (immer aktuell,
    // auch nach Inline-Edit, weil die data-Attribute am <details> mitgepflegt
    // werden).
    const wildcardOpen       = root.getAttribute('data-wildcard-open') || '{{ ';
    const wildcardClose      = root.getAttribute('data-wildcard-close') || ' }}';
    if (!endpoint) {
        return;
    }
    const strings = (window.sprogInbox && window.sprogInbox.strings) || {};
    const toastEl = root.querySelector('[data-role="toast"]');

    /**
     * Toast-Anzeige mit Auto-Dismiss. `kind` ist 'ok' / 'warn' / 'error',
     * das beeinflusst die CSS-Klasse — Farbgebung kommt aus dem Stylesheet.
     */
    let toastTimer = null;
    function toast(message, kind) {
        if (!toastEl) {
            return;
        }
        toastEl.textContent = message;
        toastEl.dataset.kind = kind || 'ok';
        toastEl.hidden = false;
        if (toastTimer) {
            window.clearTimeout(toastTimer);
        }
        toastTimer = window.setTimeout(() => {
            toastEl.hidden = true;
        }, kind === 'error' || kind === 'warn' ? 6000 : 2500);
    }

    /**
     * Kopiert Text in die Zwischenablage. navigator.clipboard braucht einen
     * Secure Context (https oder localhost); im Dev-Backend über http greift
     * der execCommand-Fallback.
     */
    function copyToClipboard(text, btn) {
        const done = () => {
            toast((strings.copyDone || 'Kopiert') + ': ' + text, 'ok');
            if (btn) {
                btn.classList.add('is-copied');
                window.setTimeout(() => btn.classList.remove('is-copied'), 1200);
            }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, () => fallbackCopy(text, done));
        } else {
            fallbackCopy(text, done);
        }
    }

    function fallbackCopy(text, done) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            /* Zwischenablage nicht verfügbar — still ignorieren. */
        }
        document.body.removeChild(ta);
    }

    /**
     * Setzt den nicht-sichtbaren Zeilen-Status: data-status (für CSS/Selektoren)
     * + Stale-Deko (is-stale-Klasse + Hinweis). Der sichtbare Status-Chip kommt
     * aus dem server-gerenderten HTML (applyRowActions), nicht von hier — so
     * folgt der Hinweis sofort dem Status (z.B. stale → freigegeben), egal aus
     * welchem Pfad (Save/Transition/Geschwister/Restore).
     */
    function setRowStatus(row, statusValue) {
        if (!row) {
            return;
        }
        row.setAttribute('data-status', statusValue);
        applyStaleDecoration(row, statusValue === 'stale');
    }

    function setFeedback(row, message, kind) {
        const target = row.querySelector('[data-role="feedback"]');
        if (!target) {
            return;
        }
        target.textContent = message || '';
        target.dataset.kind = kind || '';
    }

    /**
     * Tauscht die komplette Aktionszeile (Status-Chip + Workflow-Buttons) gegen
     * das vom Server gerenderte HTML. Single source of truth: exakt dieselbe
     * Render-Logik wie beim Seiten-Reload, keine Rekonstruktion von Button-
     * Zuständen im Client. Die Klick-Bindung läuft über Delegation an root, ein
     * innerHTML-Tausch bricht sie also nicht.
     */
    function applyRowActions(row, html) {
        if (!row || typeof html !== 'string') {
            return;
        }
        const actions = row.querySelector('.sprog-inbox--row-actions');
        if (actions) {
            actions.innerHTML = html;
        }
    }

    /**
     * Blendet den Stale-Hinweis in einer offenen Zeile ein/aus und toggelt die
     * is-stale-Klasse — spiegelt exakt das serverseitige Render (Reload-gleich).
     */
    function applyStaleDecoration(row, isStale) {
        row.classList.toggle('is-stale', isStale);
        const body = row.querySelector('.sprog-inbox--row-body');
        if (!body) {
            return;
        }
        let hint = body.querySelector('.sprog-inbox--stale-hint');
        if (isStale) {
            if (!hint) {
                hint = document.createElement('p');
                hint.className = 'sprog-hint sprog-hint--warning sprog-inbox--stale-hint';
                hint.textContent = strings.staleHint || '';
                body.insertBefore(hint, body.firstChild);
            }
        } else if (hint) {
            hint.remove();
        }
    }

    /**
     * Rechnet die Coverage-Zahl (N/M) einer Unit aus den Summary-Badges neu —
     * N = Sprachen mit Status approved, M = alle Sprachen.
     */
    function updateCoverage(unit) {
        const badges = unit.querySelectorAll('[data-role="lang-badge"]');
        if (!badges.length) {
            return;
        }
        let done = 0;
        badges.forEach((b) => {
            if (b.getAttribute('data-status') === 'approved') {
                done += 1;
            }
        });
        const cov = unit.querySelector('[data-role="coverage"]');
        if (cov) {
            cov.textContent = done + '/' + badges.length;
        }
    }

    /**
     * Setzt das Summary-Badge einer Sprache (Farbe/Titel/data-status) und — falls
     * es die aktuell angezeigte Sprache ist — den Summary-Dot. Gemeinsam genutzt
     * von Save, Transition und Geschwister-Update, damit die Coverage-Zeile
     * überall demselben Stand folgt.
     */
    function syncBadge(unit, clangId, status, statusLabel) {
        const id = String(clangId);
        const badge = unit.querySelector('[data-role="lang-badge"][data-clang-id="' + id + '"]');
        if (badge) {
            badge.className = 'sprog-status sprog-status--' + status + ' sprog-inbox--lang-badge';
            badge.setAttribute('data-status', status);
            const name = badge.getAttribute('data-clang-name');
            if (name && statusLabel) {
                badge.title = name + ' · ' + statusLabel;
            }
        }
        if (id === displayClang) {
            const dot = unit.querySelector('[data-role="translation-dot"]');
            if (dot) {
                dot.style.background = 'var(--sprog-status-' + status + ')';
            }
        }
    }

    /**
     * Nach dem Speichern der Basissprache: den vom Server gelieferten neuen
     * Stand der abhängigen Sprachen (ggf. jetzt „veraltet") ohne Reload
     * spiegeln — Summary-Badge, Summary-Dot der Anzeige-Sprache, offene
     * Bearbeitungs-Zeile (Chip, Revision, Buttons, Stale-Deko) und Coverage.
     */
    function applySiblingUpdates(unit, siblings) {
        siblings.forEach((sib) => {
            const clangId = String(sib.clangId);
            syncBadge(unit, clangId, sib.status, sib.statusLabel);

            const row = unit.querySelector('[data-role="row"][data-clang-id="' + clangId + '"]');
            if (row) {
                row.setAttribute('data-revision', String(sib.revision));
                setRowStatus(row, sib.status);
                applyRowActions(row, sib.rowActionsHtml);
            }
        });
        updateCoverage(unit);
    }

    async function saveRow(textarea) {
        const row = textarea.closest('[data-role="row"]');
        const unit = textarea.closest('[data-unit-id]');
        if (!row || !unit) {
            return;
        }

        const newValue = textarea.value;
        const original = textarea.getAttribute('data-last-saved') || '';
        if (newValue === original) {
            return;
        }

        const unitId   = unit.getAttribute('data-unit-id');
        const clangId  = row.getAttribute('data-clang-id');
        const revision = row.getAttribute('data-revision') || '0';

        const body = new URLSearchParams();
        body.set('unit_id', unitId);
        body.set('clang_id', clangId);
        body.set('value', newValue);
        body.set('revision', revision);
        // MT-Marker mitschicken, falls die Zeile per MT-Vorschlag befüllt wurde.
        // Der Server prüft den Provider gegen die Whitelist; ein fehlender Wert
        // bedeutet „keine MT-Markierung".
        const mtBar = row.querySelector('[data-role="mt-bar"]');
        if (mtBar) {
            const mtProviderInput = mtBar.querySelector('[data-role="mt-provider"]');
            const mtConfidenceInput = mtBar.querySelector('[data-role="mt-confidence"]');
            if (mtProviderInput && mtProviderInput.value) {
                body.set('mt_provider', mtProviderInput.value);
                if (mtConfidenceInput && mtConfidenceInput.value) {
                    body.set('mt_confidence', mtConfidenceInput.value);
                }
            }
        }
        if (csrfName && csrfValue) {
            body.set(csrfName, csrfValue);
        }

        textarea.disabled = true;
        setFeedback(row, strings.saving || 'Saving…', 'pending');

        try {
            const res = await fetch(endpoint, {
                method:  'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString(),
                credentials: 'same-origin',
            });

            // 409 = Optimistic-Lock-Konflikt — der Wert wurde anderswo geändert.
            // Wir behalten den User-Eingabewert (sonst gehen Tipparbeit verloren)
            // und bitten ihn zum Reload. data-last-saved NICHT überschreiben, damit
            // ein Nachklick auf "Speichern" denselben Konflikt wieder zeigt.
            if (res.status === 409) {
                const data = await res.json().catch(() => ({}));
                setFeedback(row, data.error || strings.conflict || 'Konflikt', 'error');
                toast((strings.conflict || 'Konflikt') + ' · ' + (strings.reloadHint || ''), 'warn');
                return;
            }

            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                const msg = (strings.errorPrefix || 'Fehler') + ': ' + (data.error || res.status);
                setFeedback(row, msg, 'error');
                toast(msg, 'error');
                return;
            }

            const data = await res.json();
            if (!data || data.ok !== true) {
                setFeedback(row, data && data.error ? data.error : 'Fehler', 'error');
                return;
            }

            // Erfolg: gespeicherten Wert + revision aktualisieren, sonst löst der
            // nächste Blur wieder einen Save aus, und die Optimistic-Lock-Revision
            // ist nicht mehr gültig.
            textarea.setAttribute('data-last-saved', newValue);
            // Feld ist jetzt „clean" — der Zurücksetzen-Button verschwindet.
            const savedResetBtn = row.querySelector('[data-role="reset"]');
            if (savedResetBtn) {
                savedResetBtn.hidden = true;
            }
            row.setAttribute('data-revision', String(data.revision));
            setRowStatus(row, data.status);
            // Server-gerenderte Aktionszeile (Chip + Buttons) 1:1 einsetzen —
            // z.B. missing → draft blendet die Forward-Buttons ein, ohne Reload.
            applyRowActions(row, data.rowActionsHtml);
            // Badge + Coverage der bearbeiteten Sprache selbst mitziehen — sonst
            // zeigt die Coverage-Zeile nach einem Status-Sprung (z.B. veraltet →
            // Entwurf beim Nachbearbeiten) noch den alten Stand.
            syncBadge(unit, row.getAttribute('data-clang-id'), data.status, data.statusLabel);
            updateCoverage(unit);
            // Basissprache gespeichert → abhängige Übersetzungen können jetzt
            // „veraltet" sein. Der Server liefert deren neuen Stand mit; sofort
            // in Badges/Coverage/offene Zeilen spiegeln (kein Reload nötig).
            if (Array.isArray(data.siblings) && data.siblings.length) {
                applySiblingUpdates(unit, data.siblings);
            }
            setFeedback(row, strings.saved || 'Gespeichert', 'ok');
            toast(strings.saved || 'Gespeichert', 'ok');
        } catch (err) {
            setFeedback(row, (strings.errorPrefix || 'Fehler') + ': ' + err.message, 'error');
            toast((strings.errorPrefix || 'Fehler') + ': ' + err.message, 'error');
        } finally {
            textarea.disabled = false;
        }
    }

    /**
     * Auto-Grow für Inline-Textareas.
     *
     * Textareas starten visuell einzeilig (rows="1") und wachsen ab dem zweiten
     * Zeilenumbruch bis maxRows. Über die max-Höhe schalten wir overflow-y auf
     * `auto`, damit ab dann ein Scrollbalken erscheint statt unbegrenzt zu
     * wachsen. Funktioniert Layout-unabhängig, weil wir scrollHeight messen
     * und nicht aus dem Content rechnen.
     */
    function autogrow(ta) {
        const maxRows = parseInt(ta.dataset.maxRows || '8', 10);
        const cs = window.getComputedStyle(ta);
        const lineH = parseFloat(cs.lineHeight) || 22;
        const padY  = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom);
        const borderY = parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth);
        const maxH = lineH * maxRows + padY + borderY;

        ta.style.height = 'auto';
        const next = Math.min(ta.scrollHeight, maxH);
        ta.style.height = next + 'px';
        ta.style.overflowY = ta.scrollHeight > maxH ? 'auto' : 'hidden';
    }

    // Initial-Run für alle Textareas in bereits offenen Akkordeons.
    // data-auto-grow ist das Opt-in — andere Textareas im Article bleiben
    // unbehelligt.
    root.querySelectorAll('details[open] textarea[data-auto-grow]').forEach((ta) => {
        autogrow(ta);
    });

    // Beim Aufklappen eines Unit-Akkordeons: Textareas sind erst dann gerendert
    // und haben scrollHeight > 0. Daher Resize hier nachholen. Das 'toggle'-
    // Event bubbled nicht, also Listener auf das jeweilige <details> heften.
    root.querySelectorAll('details[data-unit-id]').forEach((d) => {
        d.addEventListener('toggle', () => {
            if (d.open) {
                d.querySelectorAll('textarea[data-auto-grow]').forEach(autogrow);
            }
        });
    });

    // Live-Wachsen beim Tippen. Auf 'input' (nicht 'keyup'), damit auch
    // Paste/Cut/IME-Input das Resize triggern.
    root.addEventListener('input', (event) => {
        const t = event.target;
        if (t instanceof HTMLTextAreaElement && t.hasAttribute('data-auto-grow')) {
            autogrow(t);
        }
    });

    /**
     * Filter-Form-Submit-Handler.
     *
     * REDAXO/PJAX hat seinen eigenen submit-Listener, der bei nativem Submit
     * "&undefined=" an die URL hängt. Wir intercepten den Submit, bauen die
     * Query-URL selbst aus FormData (mit Skip von leeren/undefined Keys) und
     * navigieren explizit. stopImmediatePropagation verhindert, dass der
     * PJAX-Handler hinter uns nochmal greift.
     */
    const filterForm = document.querySelector('[data-sprog-inbox-filter]');
    if (filterForm) {
        /*
         * Auto-Submit auf change für die nativen <select>-Filter (Sprache,
         * Quelle). Status-Checkboxen sind Multi-Select — sofortiger Submit
         * pro Klick wäre dort störend, daher submitten sie nur explizit über
         * den "Filtern"-Button im Popup-Footer.
         */
        filterForm.querySelectorAll('select').forEach((el) => {
            el.addEventListener('change', () => {
                filterForm.requestSubmit();
            });
        });

        /*
         * Single-Select-Radios in den <details>-Popups (Sprache, Bereich) mit
         * data-autosubmit submitten sofort bei Auswahl. Sortier- und
         * Status-Optionen tragen das Attribut NICHT — sie werden über ihren
         * Anwenden-Button gemeinsam übernommen.
         */
        filterForm.querySelectorAll('input[data-autosubmit]').forEach((el) => {
            el.addEventListener('change', () => {
                filterForm.requestSubmit();
            });
        });

        /*
         * Custom-Toggle fürs Status-Dropdown: weil das <summary> per
         * `display: contents` aus dem Layout fällt, reichen manche Browser
         * den Click nicht zuverlässig an den nativen Toggle weiter. Wir
         * machen das Toggle hier explizit — Klicks innerhalb des Popups
         * lassen wir durch (sonst würde jedes Checkbox-Klick das Popup
         * schließen).
         */
        filterForm.querySelectorAll('[data-role="filter-dropdown"]').forEach((details) => {
            details.addEventListener('click', (event) => {
                if (event.target.closest('[data-role="filter-popup"]')) {
                    return;
                }
                event.preventDefault();
                details.open = !details.open;
            });

            // Tastatur-Toggle: <details> hat tabindex=0, das <summary> ist
            // aus dem Tab-Flow genommen (display: contents macht den nativen
            // summary-Toggle unzuverlässig). Enter/Space am details löst hier
            // den Toggle aus, sodass Tastatur-Nutzer das Popup öffnen können.
            details.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                // Wenn der Fokus auf einem Kind im Popup ist (Checkbox, Button),
                // den Default durchlassen.
                if (event.target !== details) {
                    return;
                }
                event.preventDefault();
                details.open = !details.open;
            });

            // Multi-Select (Status): Auswahl sammeln und erst beim SCHLIESSEN des
            // Dropdowns filtern (kein Anwenden-Button mehr). Single-Select-Optionen
            // submitten dagegen sofort über data-autosubmit.
            if (details.querySelector('input[type="checkbox"]')) {
                let dirty = false;
                details.addEventListener('change', (event) => {
                    if (event.target.matches('input[type="checkbox"]')) {
                        dirty = true;
                    }
                });
                details.addEventListener('toggle', () => {
                    if (!details.open && dirty) {
                        dirty = false;
                        filterForm.requestSubmit();
                    }
                });
            }
        });

        // Klick außerhalb schließt offene Filter-Dropdowns — beim Status-Filter
        // löst das Schließen dann den Submit aus (toggle-Handler oben).
        document.addEventListener('click', (event) => {
            filterForm.querySelectorAll('[data-role="filter-dropdown"][open]').forEach((details) => {
                if (!details.contains(event.target)) {
                    details.open = false;
                }
            });
        });

        // Escape schließt das offene Dropdown ebenfalls.
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }
            filterForm.querySelectorAll('[data-role="filter-dropdown"][open]').forEach((details) => {
                details.open = false;
            });
        });

        filterForm.addEventListener('submit', (event) => {
            event.preventDefault();
            event.stopImmediatePropagation();

            const fd = new FormData(filterForm);
            const params = new URLSearchParams();
            fd.forEach((value, key) => {
                if (typeof key !== 'string' || key === '' || key === 'undefined') {
                    return;
                }
                params.append(key, typeof value === 'string' ? value : String(value));
            });

            const action = filterForm.getAttribute('action') || 'index.php';
            window.location.href = action + '?' + params.toString();
        });
    }

    /**
     * Blur-Handler — delegiert: ein einzelner Listener am Wrapper, das spart
     * Speicher und funktioniert auch für dynamisch nachgeladene Akkordeons.
     * Wir hören auf 'focusout', nicht 'blur', weil 'blur' nicht bubbled.
     */
    root.addEventListener('focusout', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLTextAreaElement)) {
            return;
        }
        if (target.readOnly || target.disabled) {
            return;
        }
        if (target.getAttribute('data-role') !== 'value') {
            return;
        }
        saveRow(target);
    });

    /**
     * Unit-Edit als Modal-Dialog.
     *
     * Klick auf den Stift-Button öffnet ein einziges <dialog>-Element, das pro
     * Klick mit den aktuellen Werten der jeweiligen Unit gefüllt wird (gelesen
     * aus den data-Attributes am <details>-Akkordeon). Submit schickt fetch-
     * POST an den update_unit-Endpoint; bei Erfolg werden Key, Context-Anzeige
     * und Notes-Block im Akkordeon ohne Reload aktualisiert und das Modal
     * geschlossen. Bei Validierungsfehler (z.B. Duplicate-Key) bleibt das
     * Modal offen mit Inline-Error.
     */
    const unitModal = document.querySelector('[data-role="unit-modal"]');
    let modalActiveUnit = null;
    let modalMode = 'edit'; // 'edit' | 'create'
    let openUnitModal = null;
    let openCreateModal = null;

    if (unitModal) {
        const modalForm    = unitModal.querySelector('[data-role="unit-modal-form"]');
        const modalKey     = unitModal.querySelector('[data-role="modal-unit-key"]');
        const modalCtx     = unitModal.querySelector('[data-role="modal-context"]');
        const modalNotes   = unitModal.querySelector('[data-role="modal-notes"]');
        const modalNs      = unitModal.querySelector('[data-role="modal-namespace"]');
        const modalNsSel   = unitModal.querySelector('[data-role="modal-namespace-select"]');
        const modalTitle   = unitModal.querySelector('[data-role="modal-title"]');
        const modalErr     = unitModal.querySelector('[data-role="modal-error"]');
        const modalCancel  = unitModal.querySelector('[data-role="unit-modal-cancel"]');
        const modalClose   = unitModal.querySelector('[data-role="unit-modal-close"]');

        const endpointCreateUnit = unitModal.getAttribute('data-endpoint-create');
        const createCsrfName     = unitModal.getAttribute('data-create-csrf-name');
        const createCsrfValue    = unitModal.getAttribute('data-create-csrf-value');
        const originalTitleHtml  = modalTitle ? modalTitle.textContent : '';

        const closeUnitModal = () => {
            if (unitModal.open) {
                unitModal.close();
            }
            modalActiveUnit = null;
        };

        /** Edit-Mode: Provider read-only, Felder aus existierender Unit. */
        openUnitModal = (unitEl) => {
            if (!unitEl) return;
            modalMode = 'edit';
            modalActiveUnit = unitEl;
            if (modalTitle) modalTitle.textContent = strings.modalTitleEdit || originalTitleHtml;
            modalKey.value      = unitEl.getAttribute('data-unit-key') || '';
            modalCtx.value      = unitEl.getAttribute('data-unit-context') || '';
            modalNotes.value    = unitEl.getAttribute('data-unit-notes') || '';
            modalNs.textContent = unitEl.getAttribute('data-unit-namespace-label')
                || unitEl.getAttribute('data-unit-namespace') || '—';
            modalNs.hidden    = false;
            modalNsSel.hidden = true;
            modalErr.hidden   = true;
            modalErr.textContent = '';
            unitModal.showModal();
            modalKey.focus();
            modalKey.select();
        };

        /** Create-Mode: Provider als Select, alle Felder leer. */
        openCreateModal = () => {
            modalMode = 'create';
            modalActiveUnit = null;
            if (modalTitle) modalTitle.textContent = strings.modalTitleCreate || originalTitleHtml;
            modalKey.value   = '';
            modalCtx.value   = '';
            modalNotes.value = '';
            modalNs.hidden    = true;
            modalNsSel.hidden = false;
            modalNsSel.value  = 'wildcard'; // Default: häufigster Provider
            modalErr.hidden   = true;
            modalErr.textContent = '';
            unitModal.showModal();
            modalKey.focus();
        };

        modalCancel?.addEventListener('click', closeUnitModal);
        modalClose?.addEventListener('click', closeUnitModal);

        unitModal.addEventListener('click', (event) => {
            if (event.target === unitModal) closeUnitModal();
        });

        unitModal.addEventListener('close', () => { modalActiveUnit = null; });

        modalForm?.addEventListener('submit', async (event) => {
            event.preventDefault();

            modalErr.hidden = true;
            modalErr.textContent = '';

            const body = new URLSearchParams();
            body.set('unit_key', modalKey.value.trim());
            body.set('context', modalCtx.value.trim());
            body.set('notes', modalNotes.value);

            let endpoint;
            if ('create' === modalMode) {
                if (!endpointCreateUnit) return;
                body.set('namespace', modalNsSel.value);
                if (createCsrfName && createCsrfValue) {
                    body.set(createCsrfName, createCsrfValue);
                }
                endpoint = endpointCreateUnit;
            } else {
                if (!modalActiveUnit || !endpointUpdateUnit) return;
                body.set('unit_id', modalActiveUnit.getAttribute('data-unit-id'));
                if (csrfName && csrfValue) body.set(csrfName, csrfValue);
                endpoint = endpointUpdateUnit;
            }

            try {
                const res = await fetch(endpoint, {
                    method:  'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    body.toString(),
                    credentials: 'same-origin',
                });
                const data = await res.json().catch(() => ({}));

                if (!res.ok || !data || data.ok !== true) {
                    modalErr.textContent = (data && data.error) || strings.errorPrefix || 'Fehler';
                    modalErr.hidden = false;
                    return;
                }

                if ('create' === modalMode) {
                    // Reload mit `?open_unit=<neue_id>` damit die frische Card im
                    // Akkordeon direkt aufgeklappt erscheint.
                    const url = new URL(window.location.href);
                    url.searchParams.set('open_unit', String(data.unit_id));
                    window.location.href = url.toString();
                    return;
                }

                // Edit-Mode: DOM-Update statt Reload.
                const unitEl = modalActiveUnit;
                const newKey = data.unit_key || modalKey.value.trim();
                const keySpan = unitEl.querySelector('[data-role="key-text"]');
                if (keySpan) keySpan.textContent = newKey;
                updateContextDisplay(unitEl, data.context || '');
                updateNotesBlock(unitEl, data.notes || '');
                unitEl.setAttribute('data-unit-key', newKey);
                unitEl.setAttribute('data-unit-context', data.context || '');
                unitEl.setAttribute('data-unit-notes', data.notes || '');
                toast(strings.keyEditSaved || 'Gespeichert', 'ok');
                closeUnitModal();
            } catch (err) {
                modalErr.textContent = (strings.errorPrefix || 'Fehler') + ': ' + err.message;
                modalErr.hidden = false;
            }
        });
    }

    /**
     * Zeigt oder versteckt die "context."-Anzeige vor dem Key im Summary,
     * je nachdem, ob ein context-Wert gesetzt ist.
     */
    function updateContextDisplay(unitEl, contextValue) {
        const keyLine = unitEl.querySelector('[data-role="key-line"]');
        if (!keyLine) return;

        const ctxSpan = keyLine.querySelector('[data-role="context-text"]');
        const ctxSep  = keyLine.querySelector('[data-role="context-sep"]');
        const hasCtx  = contextValue && contextValue.length > 0;

        if (hasCtx) {
            if (ctxSpan) {
                ctxSpan.textContent = contextValue;
            } else {
                // dynamisch einfügen vor dem Key-Span
                const keySpan = keyLine.querySelector('[data-role="key-text"]');
                const span = document.createElement('span');
                span.className = 'sprog-inbox--context';
                span.setAttribute('data-role', 'context-text');
                span.textContent = contextValue;
                const sep = document.createElement('span');
                sep.className = 'sprog-inbox--context-sep';
                sep.setAttribute('data-role', 'context-sep');
                sep.setAttribute('aria-hidden', 'true');
                sep.textContent = '.';
                keyLine.insertBefore(span, keySpan);
                keyLine.insertBefore(sep, keySpan);
            }
        } else {
            if (ctxSpan) ctxSpan.remove();
            if (ctxSep) ctxSep.remove();
        }
    }

    /**
     * Aktualisiert (oder entfernt) den Notiz-Block im Akkordeon-Body, ohne
     * dass der User die Seite neu laden müsste.
     */
    function updateNotesBlock(unitEl, notesValue) {
        const rows = unitEl.querySelector('[data-role="rows"]');
        if (!rows) return;

        let block = rows.querySelector('[data-role="notes"]');
        const hasNotes = notesValue && notesValue.length > 0;

        if (hasNotes) {
            if (!block) {
                block = document.createElement('p');
                block.className = 'sprog-inbox--notes';
                block.setAttribute('data-role', 'notes');
                const label = document.createElement('span');
                label.className = 'sprog-inbox--notes-label';
                label.textContent = strings.notesLabel || 'Notiz:';
                block.appendChild(label);
                block.appendChild(document.createTextNode(' '));
                rows.prepend(block);
            }
            // alten Text (außer Label) entfernen, dann neuen anhängen
            while (block.childNodes.length > 2) {
                block.removeChild(block.lastChild);
            }
            block.appendChild(document.createTextNode(notesValue));
        } else if (block) {
            block.remove();
        }
    }

    root.addEventListener('click', (event) => {
        // Stift im Akkordeon → Edit-Mode
        const editTrigger = event.target.closest('[data-role="unit-edit-trigger"]');
        if (editTrigger) {
            event.preventDefault();
            event.stopPropagation();
            // Button liegt jetzt außerhalb des <details> (Action-Overlay im
            // <li>) — Unit über das gemeinsame Item finden, nicht via closest.
            const unitEl = editTrigger.closest('.sprog-inbox--unit-item')?.querySelector('[data-unit-id]');
            if (openUnitModal) openUnitModal(unitEl);
            return;
        }

        // Copy-Button am Schlüssel → vollständigen Platzhalter kopieren
        const copyTrigger = event.target.closest('[data-role="unit-copy-placeholder"]');
        if (copyTrigger) {
            event.preventDefault();
            event.stopPropagation();
            const unitEl = copyTrigger.closest('.sprog-inbox--unit-item')?.querySelector('[data-unit-id]');
            if (unitEl) {
                const key = unitEl.getAttribute('data-unit-key') || '';
                const ctx = unitEl.getAttribute('data-unit-context') || '';
                const fullKey = ctx ? ctx + '.' + key : key;
                copyToClipboard(wildcardOpen + fullKey + wildcardClose, copyTrigger);
            }
            return;
        }

        // "+ Neue Einheit" → Create-Mode (statt zur Create-Page zu navigieren).
        // href bleibt als no-JS-Fallback erhalten, JS hijackt den Click.
        const createTrigger = event.target.closest('[data-role="unit-create-trigger"]');
        if (createTrigger) {
            if (openCreateModal) {
                event.preventDefault();
                openCreateModal();
            }
        }
    });

    /**
     * Status-Transition Buttons unter jeder Sprach-Textarea.
     */
    root.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-role="transition"]');
        if (!btn) {
            return;
        }
        event.preventDefault();

        const row  = btn.closest('[data-role="row"]');
        const unit = btn.closest('[data-unit-id]');
        if (!row || !unit) {
            return;
        }

        const unitId        = unit.getAttribute('data-unit-id');
        const translationId = btn.getAttribute('data-translation-id');
        const target        = btn.getAttribute('data-target-status');
        const revision      = row.getAttribute('data-revision') || '0';

        const body = new URLSearchParams();
        body.set('unit_id', unitId);
        body.set('translation_id', translationId);
        body.set('target_status', target);
        body.set('revision', revision);
        if (csrfName && csrfValue) {
            body.set(csrfName, csrfValue);
        }

        // Den geklickten Button kurz disablen, damit Doppel-Klicks nicht
        // mehrfach durchgehen. Bei Erfolg ersetzt applyRowActions() die gesamte
        // Aktionszeile durch frisches Server-HTML (neue, nicht-disablete Buttons)
        // — daher den Reset im finally nur bei Misserfolg ausführen.
        btn.disabled = true;
        setFeedback(row, strings.saving || 'Saving…', 'pending');
        let success = false;

        try {
            const res = await fetch(endpointTransition, {
                method:  'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString(),
                credentials: 'same-origin',
            });

            if (res.status === 409) {
                const data = await res.json().catch(() => ({}));
                setFeedback(row, data.error || strings.conflict || 'Konflikt', 'error');
                toast((strings.conflict || 'Konflikt') + ' · ' + (strings.reloadHint || ''), 'warn');
                return;
            }
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                const msg = (strings.errorPrefix || 'Fehler') + ': ' + (data.error || res.status);
                setFeedback(row, msg, 'error');
                toast(msg, 'error');
                return;
            }

            const data = await res.json();
            if (!data || data.ok !== true) {
                setFeedback(row, data && data.error ? data.error : 'Fehler', 'error');
                return;
            }

            row.setAttribute('data-revision', String(data.revision));
            setRowStatus(row, data.status);
            // Server-gerenderte Aktionszeile 1:1 einsetzen (identisch zum Reload).
            applyRowActions(row, data.rowActionsHtml);
            // Badge + Coverage dieser Sprache am neuen Status ausrichten (z.B.
            // Entwurf → freigegeben hebt den Coverage-Zähler).
            syncBadge(unit, row.getAttribute('data-clang-id'), data.status, data.statusLabel);
            updateCoverage(unit);
            success = true;
            setFeedback(row, strings.transitionSaved || 'Status aktualisiert', 'ok');
            toast(strings.transitionSaved || 'Status aktualisiert', 'ok');
        } catch (err) {
            setFeedback(row, (strings.errorPrefix || 'Fehler') + ': ' + err.message, 'error');
            toast((strings.errorPrefix || 'Fehler') + ': ' + err.message, 'error');
        } finally {
            // Nur bei Fehlern den geklickten Button wieder freigeben — bei
            // Erfolg wurde die ganze Aktionszeile durch frisches HTML ersetzt,
            // der alte Button ist dann ohnehin weg.
            if (!success) {
                btn.disabled = false;
            }
        }
    });

    /**
     * MT-Vorschlag im Akkordeon.
     *
     * Trigger fordert einen Vorschlag beim mt-Endpoint an und schreibt ihn in
     * die Textarea der Zeile. Gespeichert wird NICHT hier: nach dem Einsetzen
     * bekommt die Textarea den Fokus, der bestehende Blur-Auto-Save (focusout)
     * persistiert den Text samt MT-Marker (mt_provider/mt_confidence aus den
     * hidden Feldern der mt-bar).
     */
    function setMtStatus(el, text, kind) {
        if (!el) {
            return;
        }
        el.classList.remove('is-ok', 'is-error');
        if (!text) {
            el.hidden = true;
            el.textContent = '';
            return;
        }
        el.hidden = false;
        el.textContent = text;
        if (kind === 'ok') {
            el.classList.add('is-ok');
        } else if (kind === 'error') {
            el.classList.add('is-error');
        }
    }

    function mtProviderLabel(provider, confidence) {
        if (typeof provider !== 'string' || provider === '') {
            return '';
        }
        return (typeof confidence === 'number' && Number.isFinite(confidence))
            ? provider + ' (' + confidence.toFixed(2) + ')'
            : provider;
    }

    async function requestMt(unitId, clangId, provider) {
        const body = new URLSearchParams();
        body.set('unit_id', unitId);
        body.set('clang_id', clangId);
        if (provider) {
            body.set('provider', provider);
        }
        if (csrfName && csrfValue) {
            body.set(csrfName, csrfValue);
        }
        const res = await fetch(endpointMt, {
            method:  'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    body.toString(),
            credentials: 'same-origin',
        });
        let payload = null;
        try {
            payload = await res.json();
        } catch (_) {
            throw new Error('HTTP ' + res.status);
        }
        if (!res.ok || !payload || payload.ok !== true) {
            throw new Error((payload && payload.error) || ('HTTP ' + res.status));
        }
        return payload;
    }

    if (endpointMt) {
        root.addEventListener('click', async (event) => {
            const trigger = event.target.closest('[data-role="mt-trigger"]');
            if (trigger) {
                event.preventDefault();
                const row  = trigger.closest('[data-role="row"]');
                const unit = trigger.closest('[data-unit-id]');
                const bar  = trigger.closest('[data-role="mt-bar"]');
                if (!row || !unit || !bar) {
                    return;
                }
                const textarea   = row.querySelector('[data-role="value"]');
                const statusEl   = bar.querySelector('[data-role="mt-status"]');
                const providerEl = bar.querySelector('[data-role="mt-provider"]');
                const confEl     = bar.querySelector('[data-role="mt-confidence"]');
                if (!(textarea instanceof HTMLTextAreaElement)) {
                    return;
                }

                const clangId  = row.getAttribute('data-clang-id');
                const unitId   = unit.getAttribute('data-unit-id');
                const provider = trigger.getAttribute('data-provider') || '';

                // Alle Provider-Buttons der Leiste sperren, nicht nur den
                // geklickten — sonst könnte ein zweiter Klick parallel ins selbe
                // Feld schreiben (LLM-Antworten dauern spürbar).
                const triggers = bar.querySelectorAll('[data-role="mt-trigger"]');
                triggers.forEach((t) => { t.disabled = true; });
                const originalLabel = trigger.textContent;
                trigger.textContent = strings.mtLoading || '…';
                setMtStatus(statusEl, '', null);

                try {
                    const result = await requestMt(unitId, clangId, provider);
                    const suggested = typeof result.text === 'string' ? result.text : '';
                    // Bestätigen, bevor ein vorhandener Text überschrieben wird.
                    if (textarea.value.trim() !== '' && suggested !== textarea.value) {
                        if (!window.confirm(strings.mtApplyConfirm || 'OK?')) {
                            setMtStatus(statusEl, '', null);
                            return;
                        }
                    }
                    textarea.value = suggested;
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));

                    const providerName = typeof result.provider === 'string' ? result.provider : '';
                    if (providerEl) {
                        providerEl.value = providerName;
                    }
                    if (confEl) {
                        confEl.value = (typeof result.confidence === 'number' && Number.isFinite(result.confidence))
                            ? String(result.confidence)
                            : '';
                    }
                    bar.setAttribute('data-mt-active', providerName ? 'true' : 'false');
                    // Nach dem Einsetzen ist das Feld „dirty" — der input-Event oben
                    // blendet den „Zurücksetzen"-Button ein (ersetzt das frühere
                    // „MT verwerfen").
                    setMtStatus(statusEl, mtProviderLabel(providerName, result.confidence), 'ok');
                    // Fokus zurück ins Feld: der Blur-Auto-Save greift, sobald der
                    // User es verlässt — konsistent mit dem restlichen Akkordeon.
                    textarea.focus();
                } catch (err) {
                    setMtStatus(statusEl, err && err.message ? err.message : String(err), 'error');
                } finally {
                    triggers.forEach((t) => { t.disabled = false; });
                    trigger.textContent = originalLabel || 'MT';
                }
                return;
            }
        });
    }

    /*
     |-------------------------------------------------------------------------
     | Zurücksetzen / Verlauf / Wiederherstellen
     |-------------------------------------------------------------------------
     | Unabhängig von der MT-Konfiguration (auch ohne Provider verfügbar).
     |
     | mousedown/preventDefault auf den Bedienelementen verhindert, dass der
     | Klick der Textarea den Fokus nimmt — sonst würde der focusout-Auto-Save
     | die noch ungewollte Änderung vorher festschreiben (das Blur-Save-Rennen).
    */
    root.addEventListener('mousedown', (event) => {
        if (event.target.closest('[data-role="reset"], [data-role="history-toggle"], [data-role="history-restore"]')) {
            event.preventDefault();
        }
    });

    // „Zurücksetzen"-Button je nach „dirty"-Zustand (Wert != gespeichert) zeigen.
    root.addEventListener('input', (event) => {
        const ta = event.target;
        if (!(ta instanceof HTMLTextAreaElement) || ta.getAttribute('data-role') !== 'value') {
            return;
        }
        syncResetVisibility(ta);
    });

    function syncResetVisibility(textarea) {
        const row = textarea.closest('[data-role="row"]');
        if (!row) {
            return;
        }
        const resetBtn = row.querySelector('[data-role="reset"]');
        if (resetBtn) {
            resetBtn.hidden = textarea.value === (textarea.getAttribute('data-last-saved') || '');
        }
    }

    root.addEventListener('click', async (event) => {
        // Zurücksetzen: die aktuelle, ungespeicherte Änderung verwerfen (Feld
        // zurück auf den zuletzt gespeicherten Wert; kein Server-Call nötig).
        const reset = event.target.closest('[data-role="reset"]');
        if (reset) {
            event.preventDefault();
            const row = reset.closest('[data-role="row"]');
            const textarea = row && row.querySelector('[data-role="value"]');
            if (!(textarea instanceof HTMLTextAreaElement)) {
                return;
            }
            textarea.value = textarea.getAttribute('data-last-saved') || '';
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            // MT-Marker mitnehmen — der zurückgesetzte Wert ist wieder der
            // gespeicherte Stand, nicht ein MT-Vorschlag.
            const bar = row.querySelector('[data-role="mt-bar"]');
            if (bar) {
                const p = bar.querySelector('[data-role="mt-provider"]');
                const c = bar.querySelector('[data-role="mt-confidence"]');
                if (p) { p.value = ''; }
                if (c) { c.value = ''; }
                bar.setAttribute('data-mt-active', 'false');
                setMtStatus(bar.querySelector('[data-role="mt-status"]'), '', null);
            }
            reset.hidden = true;
            return;
        }

        // Verlauf auf-/zuklappen; beim Öffnen lazy laden.
        const toggle = event.target.closest('[data-role="history-toggle"]');
        if (toggle) {
            event.preventDefault();
            const row = toggle.closest('[data-role="row"]');
            const panel = row && row.querySelector('[data-role="history-panel"]');
            if (!panel) {
                return;
            }
            const open = panel.hidden;
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                await loadHistory(row, panel);
            }
            return;
        }

        // Eine frühere Version wiederherstellen.
        const restoreBtn = event.target.closest('[data-role="history-restore"]');
        if (restoreBtn) {
            event.preventDefault();
            const row = restoreBtn.closest('[data-role="row"]');
            const unit = restoreBtn.closest('[data-unit-id]');
            if (row && unit) {
                await restoreVersion(row, unit, restoreBtn.getAttribute('data-history-id'));
            }
        }
    });

    /**
     * Lädt die Versionsliste einer Übersetzung und rendert sie ins Panel.
     */
    async function loadHistory(row, panel) {
        if (!endpointHistory) {
            return;
        }
        const unit = row.closest('[data-unit-id]');
        panel.innerHTML = '<p class="sprog-inbox--history-empty">' + escHtml(strings.historyLoading || '…') + '</p>';
        try {
            const body = new URLSearchParams();
            body.set('unit_id', unit ? unit.getAttribute('data-unit-id') : '');
            body.set('clang_id', row.getAttribute('data-clang-id'));
            if (csrfName && csrfValue) {
                body.set(csrfName, csrfValue);
            }
            const res = await fetch(endpointHistory, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin',
            });
            const payload = await res.json();
            if (!res.ok || !payload || payload.ok !== true) {
                throw new Error((payload && payload.error) || ('HTTP ' + res.status));
            }
            renderHistory(panel, Array.isArray(payload.versions) ? payload.versions : []);
        } catch (err) {
            panel.innerHTML = '<p class="sprog-inbox--history-error">' + escHtml(err && err.message ? err.message : String(err)) + '</p>';
        }
    }

    function renderHistory(panel, versions) {
        if (versions.length === 0) {
            panel.innerHTML = '<p class="sprog-inbox--history-empty">' + escHtml(strings.historyEmpty || 'Keine früheren Versionen.') + '</p>';
            return;
        }
        panel.innerHTML = versions.map((v) => {
            const who = v.user ? ' · ' + escHtml(v.user) : '';
            // Kopfzeile an der Timeline-Linie: Zeit/Autor · Herkunft.
            const head = '<div class="sprog-inbox--history-head">'
                + '<span class="sprog-inbox--history-when">' + escHtml(formatWhen(v.created_at)) + who + '</span>'
                + '<span class="sprog-inbox--history-origin">' + escHtml(strings['historyOrigin_' + v.origin] || v.origin) + '</span>'
                + '</div>';
            // Vollständiger Text; pre-wrap im CSS erhält Zeilenumbrüche.
            const text = (v.value === undefined || v.value === null || v.value === '')
                ? '<em class="sprog-inbox--history-blank">' + escHtml(strings.historyEmptyValue || '(leer)') + '</em>'
                : escHtml(v.value);
            const action = v.isCurrent
                ? '<span class="sprog-inbox--history-current">' + escHtml(strings.historyCurrent || 'aktuell') + '</span>'
                : '<button type="button" class="sprog-btn sprog-btn--sm sprog-inbox--history-restore" data-role="history-restore" data-history-id="' + escHtml(String(v.id)) + '">' + escHtml(strings.historyRestore || 'Wiederherstellen') + '</button>';
            // Umrandeter Kasten: Text + Aktion (Wiederherstellen / „aktuell").
            const box = '<div class="sprog-inbox--history-box">'
                + '<div class="sprog-inbox--history-value">' + text + '</div>'
                + '<div class="sprog-inbox--history-box-footer">' + action + '</div>'
                + '</div>';
            return '<div class="sprog-inbox--history-item">' + head + box + '</div>';
        }).join('');
    }

    /**
     * Stellt die Version mit history_id serverseitig wieder her (reused den
     * normalen Save-Pfad inkl. Optimistic-Lock) und aktualisiert die Zeile.
     */
    async function restoreVersion(row, unit, historyId) {
        if (!endpointRestore || !historyId) {
            return;
        }
        if (!window.confirm(strings.historyRestoreConfirm || 'OK?')) {
            return;
        }
        const textarea = row.querySelector('[data-role="value"]');
        setFeedback(row, strings.saving || 'Saving…', 'pending');
        try {
            const body = new URLSearchParams();
            body.set('unit_id', unit.getAttribute('data-unit-id'));
            body.set('clang_id', row.getAttribute('data-clang-id'));
            body.set('history_id', historyId);
            body.set('revision', row.getAttribute('data-revision') || '0');
            if (csrfName && csrfValue) {
                body.set(csrfName, csrfValue);
            }
            const res = await fetch(endpointRestore, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin',
            });
            if (res.status === 409) {
                const d = await res.json().catch(() => ({}));
                setFeedback(row, d.error || strings.conflict || 'Konflikt', 'error');
                toast((strings.conflict || 'Konflikt') + ' · ' + (strings.reloadHint || ''), 'warn');
                return;
            }
            const payload = await res.json();
            if (!res.ok || !payload || payload.ok !== true) {
                throw new Error((payload && payload.error) || ('HTTP ' + res.status));
            }
            if (textarea instanceof HTMLTextAreaElement) {
                textarea.value = typeof payload.value === 'string' ? payload.value : textarea.value;
                textarea.setAttribute('data-last-saved', textarea.value);
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }
            row.setAttribute('data-revision', String(payload.revision));
            setRowStatus(row, payload.status);
            applyRowActions(row, payload.rowActionsHtml);
            // Badge + Coverage der Sprache am neuen Status ausrichten.
            const restoreUnit = row.closest('[data-unit-id]');
            if (restoreUnit) {
                syncBadge(restoreUnit, row.getAttribute('data-clang-id'), payload.status, payload.statusLabel);
                updateCoverage(restoreUnit);
            }
            const resetBtn = row.querySelector('[data-role="reset"]');
            if (resetBtn) { resetBtn.hidden = true; }
            setFeedback(row, strings.restored || 'Version wiederhergestellt', 'ok');
            toast(strings.restored || 'Version wiederhergestellt', 'ok');
            // Restore ist selbst eine neue Version — offenes Panel neu laden.
            const panel = row.querySelector('[data-role="history-panel"]');
            if (panel && !panel.hidden) {
                await loadHistory(row, panel);
            }
        } catch (err) {
            const msg = err && err.message ? err.message : String(err);
            setFeedback(row, (strings.errorPrefix || 'Fehler') + ': ' + msg, 'error');
            toast((strings.errorPrefix || 'Fehler') + ': ' + msg, 'error');
        }
    }

    function formatWhen(iso) {
        if (!iso) {
            return '';
        }
        const d = new Date(iso);
        if (isNaN(d.getTime())) {
            return iso;
        }
        try {
            return d.toLocaleString();
        } catch (e) {
            return iso;
        }
    }

    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    /*
     |-------------------------------------------------------------------------
     | Stapelverarbeitung: fehlende Übersetzungen einer Sprache per MT
     |-------------------------------------------------------------------------
     | batch_prepare liefert die Arbeitsliste (unit_ids); der Client schickt sie
     | in kleinen Chunks an batch_translate (jeder Request mit eigenem Zeitlimit)
     | und zeigt Fortschritt. Deterministisch, weil der Client die feste Liste
     | hält — ein Einzel-Fehler bricht den Lauf nicht ab.
    */
    const batchModal = root.querySelector('[data-role="batch-modal"]');
    if (batchModal && endpointBatchPrepare && endpointBatchTranslate) {
        const BATCH_CHUNK = 5;
        const langSel    = batchModal.querySelector('[data-role="batch-language"]');
        const provSel    = batchModal.querySelector('[data-role="batch-provider"]');
        const countEl    = batchModal.querySelector('[data-role="batch-count"]');
        const progressEl = batchModal.querySelector('[data-role="batch-progress"]');
        const barEl      = batchModal.querySelector('[data-role="batch-bar"]');
        const progTextEl = batchModal.querySelector('[data-role="batch-progress-text"]');
        const summaryEl  = batchModal.querySelector('[data-role="batch-summary"]');
        const errorEl    = batchModal.querySelector('[data-role="batch-error"]');
        const startBtn   = batchModal.querySelector('[data-role="batch-start"]');
        const reloadBtn  = batchModal.querySelector('[data-role="batch-reload"]');
        const resultsEl  = batchModal.querySelector('[data-role="batch-results"]');

        let pendingIds = [];
        let running = false;

        const fmt = (tpl, ...args) =>
            String(tpl || '').replace(/\{(\d+)\}/g, (m, i) => (args[i] !== undefined ? String(args[i]) : m));

        function batchReset() {
            pendingIds = [];
            summaryEl.hidden = true; summaryEl.textContent = '';
            errorEl.hidden = true; errorEl.textContent = '';
            progressEl.hidden = true;
            barEl.style.width = '0';
            reloadBtn.hidden = true;
            startBtn.hidden = false;
            startBtn.disabled = true;
            countEl.textContent = '';
            resultsEl.hidden = true;
            resultsEl.innerHTML = '';
        }

        async function batchPrepare() {
            const clangId = langSel.value;
            if (!clangId) { return; }
            errorEl.hidden = true;
            startBtn.disabled = true;
            countEl.textContent = fmt(strings.batchCountMany, '…');
            try {
                const body = new URLSearchParams();
                body.set('clang_id', clangId);
                if (csrfName && csrfValue) { body.set(csrfName, csrfValue); }
                const res = await fetch(endpointBatchPrepare, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                    credentials: 'same-origin',
                });
                const payload = await res.json();
                if (!res.ok || !payload || payload.ok !== true) {
                    throw new Error((payload && payload.error) || ('HTTP ' + res.status));
                }
                pendingIds = Array.isArray(payload.unitIds) ? payload.unitIds : [];
                const total = typeof payload.total === 'number' ? payload.total : pendingIds.length;
                const withoutSource = typeof payload.withoutSource === 'number' ? payload.withoutSource : 0;
                let countText;
                if (total > 0) {
                    countText = fmt(total === 1 ? strings.batchCountOne : strings.batchCountMany, total);
                    if (withoutSource > 0) {
                        countText += ' · ' + fmt(strings.batchWithoutSource || '', withoutSource);
                    }
                } else if (withoutSource > 0) {
                    // Es fehlen zwar Übersetzungen, aber ohne Quelltext in der
                    // Basissprache lässt sich nichts übersetzen.
                    countText = fmt(withoutSource === 1 ? strings.batchOnlyWithoutSourceOne : strings.batchOnlyWithoutSourceMany, withoutSource);
                } else {
                    countText = strings.batchNone || '';
                }
                countEl.textContent = countText;
                startBtn.disabled = total === 0;
            } catch (err) {
                errorEl.hidden = false;
                errorEl.textContent = (strings.errorPrefix || 'Fehler') + ': ' + (err && err.message ? err.message : String(err));
                startBtn.disabled = true;
            }
        }

        async function batchRun() {
            if (running || pendingIds.length === 0) { return; }
            running = true;
            langSel.disabled = true;
            provSel.disabled = true;
            startBtn.disabled = true;
            summaryEl.hidden = true;
            errorEl.hidden = true;
            progressEl.hidden = false;

            const clangId = langSel.value;
            const provider = provSel.value;
            const total = pendingIds.length;
            let done = 0, ok = 0, skipped = 0, failed = 0;
            const items = []; // übersetzte + fehlgeschlagene Einträge (mit Key) fürs Feedback

            const updateProgress = () => {
                barEl.style.width = (total > 0 ? Math.round(done / total * 100) : 100) + '%';
                progTextEl.textContent = fmt(strings.batchRunning || '{0} / {1}', done, total);
            };
            updateProgress();

            try {
                for (let i = 0; i < pendingIds.length; i += BATCH_CHUNK) {
                    const chunk = pendingIds.slice(i, i + BATCH_CHUNK);
                    const body = new URLSearchParams();
                    body.set('clang_id', clangId);
                    body.set('provider', provider);
                    body.set('unit_ids', chunk.join(','));
                    if (csrfName && csrfValue) { body.set(csrfName, csrfValue); }
                    const res = await fetch(endpointBatchTranslate, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString(),
                        credentials: 'same-origin',
                    });
                    const payload = await res.json();
                    if (!res.ok || !payload || payload.ok !== true) {
                        throw new Error((payload && payload.error) || ('HTTP ' + res.status));
                    }
                    (payload.results || []).forEach((r) => {
                        if (r.status === 'ok') { ok++; items.push(r); }
                        else if (r.status === 'skipped') { skipped++; }
                        else { failed++; items.push(r); }
                    });
                    done += chunk.length;
                    updateProgress();
                }
                summaryEl.hidden = false;
                summaryEl.textContent = fmt(strings.batchSummary || '', ok, skipped, failed);
                // Konkretes Feedback: welche Platzhalter wurden übersetzt (bzw.
                // sind fehlgeschlagen) — Liste mit dickem linkem Rahmen.
                if (items.length > 0) {
                    resultsEl.innerHTML = items.map((r) => {
                        const cls = r.status === 'ok' ? 'is-ok' : 'is-failed';
                        const err = (r.status === 'failed' && r.error)
                            ? ' <span class="sprog-inbox--batch-result-error">' + escHtml(r.error) + '</span>'
                            : '';
                        return '<li class="sprog-inbox--batch-result ' + cls + '">' + escHtml(r.key || ('#' + r.unitId)) + err + '</li>';
                    }).join('');
                    resultsEl.hidden = false;
                }
                reloadBtn.hidden = false;
                startBtn.hidden = true;
            } catch (err) {
                errorEl.hidden = false;
                errorEl.textContent = (strings.errorPrefix || 'Fehler') + ': ' + (err && err.message ? err.message : String(err));
            } finally {
                running = false;
                langSel.disabled = false;
                provSel.disabled = false;
            }
        }

        // Escape während des Laufs blocken (sonst schließt der Dialog, während die
        // Chunk-Schleife noch läuft).
        batchModal.addEventListener('cancel', (event) => {
            if (running) { event.preventDefault(); }
        });

        root.addEventListener('click', (event) => {
            if (event.target.closest('[data-role="batch-trigger"]')) {
                event.preventDefault();
                batchReset();
                batchModal.showModal();
                batchPrepare();
                return;
            }
            if (event.target.closest('[data-role="batch-close"]')) {
                event.preventDefault();
                if (!running) { batchModal.close(); }
                return;
            }
            if (event.target.closest('[data-role="batch-start"]')) {
                event.preventDefault();
                batchRun();
                return;
            }
            if (event.target.closest('[data-role="batch-reload"]')) {
                event.preventDefault();
                // Gezielt auf die eben übersetzte Sprache + Status „Prüfung nötig"
                // wechseln, damit die frischen Ergebnisse direkt sichtbar sind
                // (statt eines reinen reload, der die missing-Ansicht behält).
                const url = new URL(window.location.href);
                url.search = '';
                url.searchParams.set('page', 'sprog/inbox');
                url.searchParams.set('clang_id', langSel.value);
                url.searchParams.append('status[]', 'needs_review');
                window.location.href = url.toString();
            }
        });

        langSel.addEventListener('change', () => {
            batchReset();
            batchPrepare();
        });
    }
})();
