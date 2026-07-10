/**
 * sprog-copy — fetch-basierter Chunk-Runner für „Datenpflege → Inhalte/
 * Metadaten kopieren". Ersetzt den früheren Handlebars/jQuery-Popup-Flow.
 *
 * Ablauf: „prepare" ermittelt die Artikel-Liste (und löscht bei „vorher
 * löschen" das Ziel), danach werden die IDs in Chunks an „chunk" geschickt;
 * der Fortschrittsbalken zählt mit. Konfiguration über window.sprogCopy.
 */
(function () {
    'use strict';

    var cfg = window.sprogCopy;
    if (!cfg) {
        return;
    }

    var root = document.querySelector('[data-sprog-copy]');
    if (!root) {
        return;
    }

    var form = root.querySelector('[data-role="form"]');
    var runBtn = root.querySelector('[data-role="run"]');
    var progress = root.querySelector('[data-role="progress"]');
    var bar = root.querySelector('[data-role="bar"]');
    var counter = root.querySelector('[data-role="counter"]');
    var statusEl = root.querySelector('[data-role="status"]');
    var errorEl = root.querySelector('[data-role="error"]');

    function showError(message) {
        errorEl.textContent = message || cfg.strings.serverError;
        errorEl.hidden = false;
    }

    async function send(func, extra) {
        var body = new FormData(form);
        body.set(cfg.csrf.name, cfg.csrf.value);
        body.set('func', func);
        if (extra) {
            Object.keys(extra).forEach(function (key) {
                body.set(key, extra[key]);
            });
        }

        var response = await fetch(cfg.endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        var data;
        try {
            data = await response.json();
        } catch (e) {
            throw new Error(cfg.strings.badResponse);
        }
        if (!data || !data.success) {
            throw new Error((data && data.error) || cfg.strings.serverError);
        }
        return data;
    }

    runBtn.addEventListener('click', async function () {
        errorEl.hidden = true;
        runBtn.disabled = true;
        progress.hidden = false;
        bar.value = 0;
        statusEl.textContent = cfg.strings.running;

        try {
            var prepared = await send('prepare', null);
            var items = prepared.items || [];
            var total = items.length;

            bar.max = Math.max(1, total);
            counter.textContent = '0 / ' + total;

            var size = Math.max(1, cfg.chunkSize);
            var done = 0;
            for (var i = 0; i < items.length; i += size) {
                var chunk = items.slice(i, i + size);
                await send('chunk', { ids: chunk.join(',') });
                done += chunk.length;
                bar.value = done;
                counter.textContent = done + ' / ' + total;
            }

            statusEl.textContent = cfg.strings.done.replace('{0}', String(total));
        } catch (e) {
            showError(e.message);
            statusEl.textContent = cfg.strings.failed;
        } finally {
            runBtn.disabled = false;
        }
    });
})();
