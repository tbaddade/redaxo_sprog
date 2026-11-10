/*
 * sprog-migration — Frontend für die einmalige v1 → v2 Migration.
 *
 * Ein Button startet einen fetch()-Loop: Jeder Aufruf verarbeitet einen Chunk
 * der nächsten unfertigen Quelle (Auswahl trifft der Server). Der Loop läuft,
 * bis der Server done=true liefert — dann lädt die Seite neu und zeigt
 * „abgeschlossen". Bei einem Fehler stoppt der Loop, der Button setzt fort.
 *
 * Sicherheit: CSRF-Token aus window.sprogMigration.csrf bei jedem POST;
 * HTTP 4xx/5xx oder fehlendes success-Flag beenden den Loop sofort.
 *
 * Lokalisierung: alle User-Strings aus window.sprogMigration.strings
 * (PHP: rex_i18n::rawMsg). Kein String hardcoded.
 */
(() => {
    'use strict';

    const cfg = window.sprogMigration;
    const root = document.querySelector('[data-sprog-migration]');
    if (!cfg || !cfg.strings || !root) {
        return;
    }

    const strings = cfg.strings;
    const runBtn = root.querySelector('[data-role="run"]');
    const errEl = root.querySelector('[data-role="error"]');
    if (!runBtn) {
        return;
    }

    runBtn.addEventListener('click', async () => {
        runBtn.disabled = true;
        runBtn.textContent = strings.running;
        if (errEl) {
            errEl.hidden = true;
        }

        try {
            await runUntilDone();
        } catch (err) {
            showError(err && err.message ? err.message : String(err));
            root.querySelectorAll('.sprog-migration--item.is-running').forEach((li) => {
                setState(li, 'error');
                const status = li.querySelector('[data-role="status"]');
                if (status) {
                    status.textContent = strings.badgeError;
                }
            });
            runBtn.textContent = strings.resume;
            runBtn.disabled = false;
            return;
        }

        window.location.reload();
    });

    async function runUntilDone() {
        // Schutz gegen Endlosschleife bei fehlerhaft pendelndem Backend.
        const maxChunks = 10000;

        for (let chunks = 0; chunks < maxChunks; chunks++) {
            const result = await runChunk();

            if (!result || result.success !== true) {
                throw new Error(result && result.error ? result.error : strings.serverError);
            }

            applyProgress(result.progress);

            if (result.done === true) {
                return;
            }
        }

        throw new Error(strings.chunkLimit);
    }

    async function runChunk() {
        const body = new FormData();
        body.append('func', 'chunk');
        body.append(cfg.csrf.name, cfg.csrf.value);

        const response = await fetch(cfg.endpoint, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });

        // Auch bei HTTP 500 liefert der Server JSON mit success=false, sofern
        // er noch antwortet. Daher zuerst response.json() versuchen.
        let payload = null;
        try {
            payload = await response.json();
        } catch (_) {
            throw new Error(formatString(strings.badResponse, String(response.status)));
        }

        if (!response.ok && payload && payload.error) {
            throw new Error(payload.error);
        }

        return payload;
    }

    function applyProgress(progress) {
        if (!progress || !progress.source) {
            return;
        }

        const li = root.querySelector('[data-source="' + CSS.escape(progress.source) + '"]');
        if (!li) {
            return;
        }

        const bar     = li.querySelector('[data-role="bar"]');
        const counter = li.querySelector('[data-role="counter"]');
        const status  = li.querySelector('[data-role="status"]');

        const processed = Number(progress.processed_rows ?? 0);
        const total     = Number(progress.total_rows ?? 0);

        if (bar) {
            bar.max = Math.max(1, total);
            bar.value = processed;
        }
        if (counter) {
            counter.replaceChildren(
                document.createTextNode(String(processed) + ' '),
                Object.assign(document.createElement('span'), { ariaHidden: 'true', textContent: '/' }),
                document.createTextNode(' ' + String(total)),
            );
        }

        const completed = Boolean(progress.completed_at);
        setState(li, completed ? 'completed' : 'running');
        if (status) {
            status.textContent = completed ? strings.badgeDone : strings.badgeRunning;
        }
    }

    function showError(message) {
        if (!errEl) {
            return;
        }
        errEl.textContent = message;
        errEl.hidden = false;
    }

    function setState(li, state) {
        li.classList.remove('is-idle', 'is-running', 'is-completed', 'has-error');
        const next = ({
            running:   'is-running',
            completed: 'is-completed',
            error:     'has-error',
        })[state] || 'is-idle';
        li.classList.add(next);
    }

    /**
     * Mini-Helper für rex_i18n-Stil-Platzhalter ({0}, {1}, …). Der Aufrufer
     * setzt das Ergebnis via textContent, also keine HTML-Interpretation.
     */
    function formatString(template, ...args) {
        if (typeof template !== 'string') {
            return '';
        }
        return template.replace(/\{(\d+)\}/g, (m, idx) => {
            const i = Number(idx);
            return args[i] !== undefined ? String(args[i]) : m;
        });
    }
})();
