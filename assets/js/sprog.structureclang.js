/**
 * sprog-structureclang — blendet in der Struktur (Kategoriebaum + Content-Maske)
 * die Sprach-Buttons aus, die die yrewrite-Domain der aktuellen Kategorie nicht
 * bedient.
 *
 * Die erlaubten Sprach-IDs liefert der Server als verstecktes data-Element
 * (#sprog-structure-clangs, s. Sprog\Support\StructureClangGuard). Fehlt das
 * Element, gibt es keine Einschränkung → alle Sprachen werden (wieder) gezeigt.
 *
 * Betroffen sind ausschließlich die nativen Umschalter .rex-language (Dropdown,
 * >= 4 Sprachen) und .rex-nav-language (Buttons, 2-3 Sprachen); deren Sprach-
 * Links tragen ?clang=N. Der Sprachvergleich-Switch (href="#") ist nicht betroffen.
 *
 * apply() wird bei rex:ready ausgeführt — das feuert beim Erstladen und nach jeder
 * PJAX-Navigation (Kategorie-/Artikelwechsel). apply() setzt zuerst zurück und
 * blendet dann neu aus, sodass ein Wechsel in eine unbeschränkte Kategorie die
 * zuvor versteckten Sprachen wieder sichtbar macht.
 */
(function () {
    'use strict';

    function allowedFromDom() {
        var data = document.getElementById('sprog-structure-clangs');
        if (!data) {
            return { allowed: null, current: 0 };
        }
        var raw = (data.getAttribute('data-clangs') || '').trim();
        var allowed = null;
        if ('' !== raw) {
            allowed = raw.split(',').map(function (s) {
                return parseInt(s, 10);
            }).filter(function (n) {
                return !!n;
            });
        }
        return { allowed: allowed, current: parseInt(data.getAttribute('data-current'), 10) || 0 };
    }

    function clangOf(link) {
        try {
            return parseInt(new URL(link.href, window.location.href).searchParams.get('clang'), 10) || 0;
        } catch (e) {
            return 0;
        }
    }

    function apply() {
        var state = allowedFromDom();
        var switches = document.querySelectorAll('.rex-language, .rex-nav-language');
        Array.prototype.forEach.call(switches, function (sw) {
            var links = sw.querySelectorAll('a[href*="clang="]');
            Array.prototype.forEach.call(links, function (link) {
                // Dropdown: <li> ausblenden, Button-Gruppe: den <a> selbst.
                var item = link.closest('li') || link;
                // Zuerst eine frühere Ausblendung aufheben (Reset bei Wechsel in
                // eine unbeschränkte Kategorie).
                if (item.__sprogClangHidden) {
                    item.style.display = '';
                    item.__sprogClangHidden = false;
                }
                if (!state.allowed) {
                    return;
                }
                var clang = clangOf(link);
                // aktive Sprache immer sichtbar lassen (kein Aussperren)
                if (!clang || clang === state.current) {
                    return;
                }
                if (-1 === state.allowed.indexOf(clang)) {
                    item.style.display = 'none';
                    item.__sprogClangHidden = true;
                }
            });
        });
    }

    // rex:ready feuert beim Erstladen und nach jeder PJAX-Navigation. apply()
    // triggert selbst kein rex:ready → keine Schleife.
    if (window.jQuery) {
        window.jQuery(document).on('rex:ready', apply);
    }
    apply();
})();
