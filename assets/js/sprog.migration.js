/*
 * sprog-migration — Frontend für die chunked Migration v1 → v2.
 *
 * Modernes Vanilla-JS, kein jQuery. Pro Migration-Item wird der Start-Button
 * gebunden; ein Klick triggert einen fetch()-Loop, der so lange Chunks anfordert,
 * bis der Server completed=true liefert oder ein Fehler kommt.
 *
 * Sicherheit:
 *   - CSRF-Token wird aus dem PHP-bootstrap-Block (window.sprogMigration.csrf)
 *     bei jedem POST mitgeschickt.
 *   - Bei HTTP 4xx/5xx oder fehlendem success-Flag wird der Loop sofort gestoppt
 *     und der Fehler angezeigt — keine endlose Schleife.
 *   - Reset-Form bekommt einen confirm()-Guard via data-confirm-Attribut.
 *
 * Lokalisierung:
 *   - Alle User-Strings stammen aus window.sprogMigration.strings, dort vom
 *     PHP-Bootstrap aus rex_i18n::rawMsg() befüllt. Kein String hardcoded.
 */
(() => {
    'use strict';

    const cfg = window.sprogMigration;
    if (!cfg || !cfg.strings) {
        return;
    }

    const strings = cfg.strings;

    const root = document.querySelector('[data-sprog-migration]');
    if (!root) {
        return;
    }

    root.querySelectorAll('[data-source]').forEach(initItem);

    document.querySelectorAll('[data-confirm]').forEach((el) => {
        el.addEventListener('submit', (event) => {
            const message = el.getAttribute('data-confirm');
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    function initItem(li) {
        const source = li.dataset.source;
        const runBtn = li.querySelector('[data-role="run"]');
        if (!runBtn) {
            return;
        }

        runBtn.addEventListener('click', async () => {
            setState(li, 'running');
            runBtn.disabled = true;
            runBtn.textContent = strings.running;

            try {
                await runUntilDone(source, li);
                runBtn.textContent = strings.done;
            } catch (err) {
                showError(li, err && err.message ? err.message : String(err));
                setState(li, 'error');
                runBtn.textContent = strings.retry;
                runBtn.disabled = false;
                return;
            }
        });
    }

    async function runUntilDone(source, li) {
        // Schutz gegen Endlosschleife bei fehlerhaft pendelndem Backend:
        // pro Item maximal ein paar tausend Chunks, dann hart abbrechen.
        const maxChunks = 10000;
        let chunks = 0;

        while (chunks < maxChunks) {
            chunks++;
            const result = await runChunk(source);

            if (!result || result.success !== true) {
                throw new Error(result && result.error ? result.error : strings.serverError);
            }

            applyProgress(li, result.progress);

            if (result.completed === true) {
                setState(li, 'completed');
                return;
            }
        }

        throw new Error(strings.chunkLimit);
    }

    async function runChunk(source) {
        const body = new FormData();
        body.append('func', 'chunk');
        body.append('source', source);
        body.append('chunk_size', String(cfg.defaultChunkSize));
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

    function applyProgress(li, progress) {
        if (!progress) {
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
            counter.innerHTML = '';
            counter.append(
                document.createTextNode(String(processed) + ' '),
                Object.assign(document.createElement('span'), { ariaHidden: 'true', textContent: '/' }),
                document.createTextNode(' ' + String(total)),
            );
        }

        li.dataset.total = String(total);
        li.dataset.processed = String(processed);
        li.dataset.completed = progress.completed_at ?? '';
        li.dataset.error = progress.last_error ?? '';

        if (status) {
            if (progress.completed_at) {
                status.textContent = strings.badgeDone;
            } else if (progress.last_error) {
                status.textContent = strings.badgeError;
            } else {
                status.textContent = strings.badgeRunning;
            }
        }

        const errEl = li.querySelector('[data-role="error"]');
        if (errEl) {
            if (progress.last_error) {
                errEl.textContent = progress.last_error;
                errEl.hidden = false;
            } else {
                errEl.hidden = true;
            }
        }
    }

    function showError(li, message) {
        let errEl = li.querySelector('[data-role="error"]');
        if (!errEl) {
            errEl = document.createElement('p');
            errEl.className = 'sprog-migration--error';
            errEl.dataset.role = 'error';
            li.append(errEl);
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
     * Mini-Helper für rex_i18n-Stil-Platzhalter ({0}, {1}, …).
     * String-Werte sind in PHP rex_escape-frei (rawMsg), die Werte hier sollten
     * ebenfalls reine Strings sein — der Aufrufer setzt sie via textContent,
     * also keine HTML-Interpretation.
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
