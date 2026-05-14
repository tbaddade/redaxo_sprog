/*
 * sprog-editor — MT-Trigger + Discard im Translation-Editor.
 *
 * Pro Sprachblock zwei Buttons:
 *   - [data-role="mt-trigger"]  fordert einen MT-Vorschlag an und schreibt
 *                               ihn ins textarea. Hidden-Fields mt_provider
 *                               + mt_confidence werden mitgesetzt, sodass das
 *                               Save-Form den Provider markiert.
 *   - [data-role="mt-discard"]  setzt textarea + hidden-fields zurück.
 *
 * Sicherheit:
 *   - CSRF-Token aus window.sprogEditor.csrf
 *   - credentials: same-origin
 *   - Server-Errors landen als textContent (kein HTML-Inject)
 *   - Lokalisierte UI-Strings aus window.sprogEditor.strings
 */
(() => {
    'use strict';

    const cfg = window.sprogEditor;
    if (!cfg || !cfg.strings) {
        return;
    }

    const strings = cfg.strings;

    document.querySelectorAll('[data-role="mt-trigger"]').forEach(initTrigger);
    document.querySelectorAll('[data-role="mt-discard"]').forEach(initDiscard);

    function initTrigger(btn) {
        const clangId    = btn.getAttribute('data-clang-id');
        const textareaId = btn.getAttribute('data-textarea-id');
        const bar        = btn.closest('.sprog-editor--mt-bar');
        const statusEl   = bar?.querySelector('[data-role="mt-status"]') || null;
        const providerEl = bar?.querySelector('[data-role="mt-provider"]') || null;
        const confEl     = bar?.querySelector('[data-role="mt-confidence"]') || null;
        const discardEl  = bar?.querySelector('[data-role="mt-discard"]') || null;

        if (!clangId || !textareaId) {
            return;
        }

        btn.addEventListener('click', async () => {
            const textarea = document.getElementById(textareaId);
            if (!(textarea instanceof HTMLTextAreaElement)) {
                return;
            }

            btn.disabled = true;
            const originalLabel = btn.textContent;
            btn.textContent = strings.loading;
            setStatus(statusEl, '', null);

            try {
                const result = await requestMt(clangId);

                if (!result || result.success !== true) {
                    throw new Error(result && result.error ? result.error : 'unknown error');
                }

                const suggested = typeof result.text === 'string' ? result.text : '';
                if (textarea.value.trim() !== '' && suggested !== textarea.value) {
                    if (!window.confirm(strings.applyConfirm)) {
                        setStatus(statusEl, '', null);
                        return;
                    }
                }

                textarea.value = suggested;
                // input-Event auslösen, damit andere Listener (Dirty-State,
                // Zeichenzähler etc., kommen später) mitbekommen, dass der
                // Wert programmatisch verändert wurde.
                textarea.dispatchEvent(new Event('input', { bubbles: true }));

                // Hidden-Fields setzen, damit das Save-Form den MT-Marker
                // weitergibt. Provider-String und Confidence stammen direkt
                // aus der Server-Antwort.
                const providerName = typeof result.provider === 'string' ? result.provider : '';
                if (providerEl instanceof HTMLInputElement) {
                    providerEl.value = providerName;
                }
                if (confEl instanceof HTMLInputElement) {
                    confEl.value = typeof result.confidence === 'number' && Number.isFinite(result.confidence)
                        ? String(result.confidence)
                        : '';
                }
                if (bar) {
                    bar.setAttribute('data-mt-active', 'true');
                }
                if (discardEl instanceof HTMLElement) {
                    discardEl.hidden = false;
                }

                setStatus(statusEl, providerLabel(providerName, result.confidence), 'ok');
            } catch (err) {
                const msg = err && err.message ? err.message : String(err);
                setStatus(statusEl, msg, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = originalLabel || strings.button;
            }
        });
    }

    function initDiscard(btn) {
        const textareaId = btn.getAttribute('data-textarea-id');
        const bar        = btn.closest('.sprog-editor--mt-bar');
        const statusEl   = bar?.querySelector('[data-role="mt-status"]') || null;
        const providerEl = bar?.querySelector('[data-role="mt-provider"]') || null;
        const confEl     = bar?.querySelector('[data-role="mt-confidence"]') || null;

        if (!textareaId) {
            return;
        }

        btn.addEventListener('click', () => {
            const textarea = document.getElementById(textareaId);
            if (!(textarea instanceof HTMLTextAreaElement)) {
                return;
            }
            if (!window.confirm(strings.discardConfirm)) {
                return;
            }

            textarea.value = '';
            textarea.dispatchEvent(new Event('input', { bubbles: true }));

            if (providerEl instanceof HTMLInputElement) {
                providerEl.value = '';
            }
            if (confEl instanceof HTMLInputElement) {
                confEl.value = '';
            }
            if (bar) {
                bar.setAttribute('data-mt-active', 'false');
            }
            btn.hidden = true;
            setStatus(statusEl, '', null);
        });
    }

    async function requestMt(clangId) {
        const body = new FormData();
        body.append('action', 'mt');
        body.append('clang_id', clangId);
        body.append(cfg.csrf.name, cfg.csrf.value);

        const response = await fetch(cfg.endpoint, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (_) {
            throw new Error('HTTP ' + response.status);
        }

        if (!response.ok && payload && payload.error) {
            throw new Error(payload.error);
        }

        return payload;
    }

    function providerLabel(provider, confidence) {
        if (typeof provider !== 'string' || provider === '') {
            return '';
        }
        if (typeof confidence === 'number' && Number.isFinite(confidence)) {
            return provider + ' (' + confidence.toFixed(2) + ')';
        }
        return provider;
    }

    function setStatus(el, text, kind) {
        if (!el) {
            return;
        }
        el.classList.remove('is-ok', 'is-error');
        if (text === '') {
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
})();
