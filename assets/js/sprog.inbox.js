/*
 * sprog Inbox – Inline-Editor mit Auto-Save auf Blur.
 *
 * Strategie:
 *   - Pro Textarea wird beim Blur geprüft, ob der Wert sich gegen `data-original`
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
    // Page-globaler CSRF-Token für Save/Update/Transition. Liegt am Root-Element,
    // damit nicht jede Unit-Karte ihn dupliziert.
    const csrfName           = root.getAttribute('data-csrf-name');
    const csrfValue          = root.getAttribute('data-csrf-value');
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
     * Schreibt das Status-Label + den class-Modifier in den Row-Header,
     * nachdem der Server uns den neuen Status mitgeteilt hat.
     */
    function updateRowStatus(row, statusValue, statusLabel) {
        if (!row) {
            return;
        }
        row.setAttribute('data-status', statusValue);
        const badge = row.querySelector('[data-role="row-status"]');
        if (badge) {
            badge.className = 'sprog-status sprog-status--' + statusValue;
            if (statusLabel) {
                badge.textContent = statusLabel;
            }
        }
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
     * Schaltet die Status-Übergangsbuttons einer Row auf Basis der vom
     * Server gelieferten Liste erlaubter Targets (Strings). Buttons, deren
     * `data-target-status` in der Liste steht, werden enabled — alle anderen
     * disabled. So bleibt der ganze Workflow sichtbar, ohne DOM-Flicker.
     */
    function updateTransitionButtons(row, allowed) {
        const list = Array.isArray(allowed) ? allowed : [];
        row.querySelectorAll('[data-role="transition"]').forEach((b) => {
            b.disabled = !list.includes(b.getAttribute('data-target-status'));
        });
    }

    async function saveRow(textarea) {
        const row = textarea.closest('.sprog-inbox--row');
        const unit = textarea.closest('[data-unit-id]');
        if (!row || !unit) {
            return;
        }

        const newValue = textarea.value;
        const original = textarea.getAttribute('data-original') || '';
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
            // und bitten ihn zum Reload. data-original NICHT überschreiben, damit
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

            // Erfolg: original-Wert + revision aktualisieren, sonst löst der
            // nächste Blur wieder einen Save aus, und die Optimistic-Lock-Revision
            // ist nicht mehr gültig.
            textarea.setAttribute('data-original', newValue);
            row.setAttribute('data-revision', String(data.revision));
            updateRowStatus(row, data.status, data.statusLabel);
            // Status-Übergangsbuttons direkt am neuen Status ausrichten —
            // bei missing → draft erscheinen die zwei Forward-Buttons,
            // ohne dass der User die Seite neu laden müsste.
            updateTransitionButtons(row, data.availableTransitions);
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
         * Custom-Toggle fürs Status-Dropdown: weil das <summary> per
         * `display: contents` aus dem Layout fällt, reichen manche Browser
         * den Click nicht zuverlässig an den nativen Toggle weiter. Wir
         * machen das Toggle hier explizit — Klicks innerhalb des Popups
         * lassen wir durch (sonst würde jedes Checkbox-Klick das Popup
         * schließen).
         */
        filterForm.querySelectorAll('.sprog-inbox--toolbar-cell--dropdown').forEach((details) => {
            details.addEventListener('click', (event) => {
                if (event.target.closest('.sprog-inbox--toolbar-popup')) {
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
        if (!target.classList.contains('sprog-inbox--textarea')) {
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
     * Zeigt oder versteckt die "context / "-Anzeige vor dem Key im Summary,
     * je nachdem, ob ein context-Wert gesetzt ist.
     */
    function updateContextDisplay(unitEl, contextValue) {
        const keyLine = unitEl.querySelector('.sprog-inbox--key-line');
        if (!keyLine) return;

        const ctxSpan = keyLine.querySelector('[data-role="context-text"]');
        const ctxSep  = keyLine.querySelector('.sprog-inbox--context-sep');
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
                sep.setAttribute('aria-hidden', 'true');
                sep.textContent = '/';
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
        const rows = unitEl.querySelector('.sprog-inbox--rows');
        if (!rows) return;

        let block = rows.querySelector('.sprog-inbox--notes');
        const hasNotes = notesValue && notesValue.length > 0;

        if (hasNotes) {
            if (!block) {
                block = document.createElement('p');
                block.className = 'sprog-inbox--notes';
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
            const unitEl = editTrigger.closest('[data-unit-id]');
            if (openUnitModal) openUnitModal(unitEl);
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

        const row  = btn.closest('.sprog-inbox--row');
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
        // mehrfach durchgehen. Bei Erfolg ÜBERSCHREIBT updateTransitionButtons()
        // den disabled-Zustand entsprechend der neuen erlaubten Übergänge —
        // daher den Reset im finally nur bei Misserfolg ausführen.
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
            updateRowStatus(row, data.status, data.statusLabel);
            // Buttons bleiben sichtbar — wir schalten nur disabled/enabled
            // basierend auf den vom Server gelieferten erlaubten Übergängen.
            updateTransitionButtons(row, data.availableTransitions);
            success = true;
            setFeedback(row, strings.transitionSaved || 'Status aktualisiert', 'ok');
            toast(strings.transitionSaved || 'Status aktualisiert', 'ok');
        } catch (err) {
            setFeedback(row, (strings.errorPrefix || 'Fehler') + ': ' + err.message, 'error');
            toast((strings.errorPrefix || 'Fehler') + ': ' + err.message, 'error');
        } finally {
            // Nur bei Fehlern den geklickten Button wieder freigeben — bei
            // Erfolg hat updateTransitionButtons() bereits den korrekten
            // disabled-State pro Button gesetzt.
            if (!success) {
                btn.disabled = false;
            }
        }
    });
})();
