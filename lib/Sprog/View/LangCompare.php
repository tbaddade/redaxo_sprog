<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sprog\View;

use rex;
use rex_addon;
use rex_article_content_editor;
use rex_clang;
use rex_csrf_token;
use rex_extension_point;
use rex_fragment;
use rex_i18n;
use rex_response;
use rex_sql;
use rex_url;
use Sprog\Mt\AiPlatformProvider;
use Sprog\Service\MtService;
use Throwable;

use function count;
use function is_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Rendert das Artikel-Sprachvergleich-Panel: zwei Sprachspalten nebeneinander,
 * jede über die Core-Klasse rex_article_content_editor — also exakt die native
 * Content-Maske (inkl. „Block hinzufügen", Slice-Menü, Modul-Vorschau) je Sprache.
 *
 * Jede Spalte wird von einem temporären rex_clang-Wechsel umschlossen, damit
 * global-clang-abhängige Module in der richtigen Sprache rendern.
 */
final class LangCompare
{
    /**
     * Steuer-Dropdown (STRUCTURE_CONTENT_HEADER): „Vergleichen mit …" — Auswahl
     * der Vergleichssprache (Auswahl = Vergleich aktiv, „aus" = deaktiviert) plus
     * Footer-Umschalter Inhalt/Metadaten. Ein Bootstrap-Dropdown (nicht `<select>`,
     * damit ein Footer möglich ist); wird per JS neben den nativen Sprachschalter
     * `.rex-language` gehängt. Enthält außerdem den `window.sprogLangCompare`-
     * Bootstrap für assets/js/sprog.langcompare.js.
     *
     * Rendert nichts, wenn dem Benutzer außer der aktuellen keine weitere Sprache
     * zur Verfügung steht (dann ist kein Vergleich möglich).
     */
    public static function renderBar(int $articleId, int $clangA, int $ctype, int $revision): string
    {
        $others = self::selectableOthers($clangA);
        if (0 === count($others)) {
            return '';
        }

        $langItems = '<li><a href="#" class="sprog-lc-lang" data-lang="0">'
            . rex_escape(rex_i18n::msg('sprog_langcompare_off')) . '</a></li>';
        foreach ($others as $id => $name) {
            $langItems .= '<li><a href="#" class="sprog-lc-lang" data-lang="' . $id . '">'
                . rex_escape($name) . '</a></li>';
        }

        // Konfigurierte MT-Provider mit Inbox-Beschriftung (ein Button je Provider,
        // gleiche Labels wie die Inbox: „DeepL", „KI (…)").
        $mtProviders = [];
        foreach (MtService::create()->providers() as $mtName => $mtProvider) {
            if ('noop' === $mtName || !$mtProvider->isConfigured()) {
                continue;
            }
            $labelKey = 'sprog_inbox_mt_provider_' . $mtName;
            $label = rex_i18n::hasMsg($labelKey) ? rex_i18n::rawMsg($labelKey) : $mtName;
            if ($mtProvider instanceof AiPlatformProvider) {
                $profile = $mtProvider->profileProviderName();
                if (null !== $profile && '' !== $profile) {
                    $label .= ' (' . $profile . ')';
                }
            }
            $mtProviders[] = ['name' => $mtName, 'label' => $label];
        }

        $csrf = rex_csrf_token::factory('sprog_langcompare');
        $config = [
            'endpoint' => rex_url::backendPage('sprog.langcompare'),
            'csrf' => ['name' => rex_csrf_token::PARAM, 'value' => $csrf->getValue()],
            'article' => $articleId,
            'clangA' => $clangA,
            'ctype' => $ctype,
            'revision' => $revision,
            'mtProviders' => $mtProviders,
            'strings' => [
                'loading' => rex_i18n::rawMsg('sprog_langcompare_loading'),
                'serverError' => rex_i18n::rawMsg('sprog_langcompare_server_error'),
                'copyTo' => rex_i18n::rawMsg('sprog_langcompare_copy_to'),
                'confirmDelete' => rex_i18n::rawMsg('sprog_langcompare_confirm_delete'),
                'switchOff' => rex_i18n::rawMsg('sprog_langcompare_switch_off'),
                'switchOn' => rex_i18n::rawMsg('sprog_langcompare_switch_on'),
                'mtAll' => rex_i18n::rawMsg('sprog_langcompare_mt_all'),
                'mtTitle' => rex_i18n::rawMsg('sprog_langcompare_mt_title'),
                'batch' => rex_i18n::rawMsg('sprog_langcompare_batch'),
                'batchConfirm' => rex_i18n::rawMsg('sprog_langcompare_batch_confirm'),
            ],
        ];

        return '<div class="dropdown sprog-langcompare-switch" id="sprog-langcompare-switch">'
            . '<button class="btn btn-default dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'
            . '<span class="sprog-langcompare-switch--label">' . rex_escape(rex_i18n::msg('sprog_langcompare_switch_off')) . '</span> '
            . '<span class="caret"></span>'
            . '</button>'
            . '<ul class="dropdown-menu dropdown-menu-right" role="menu">'
            . '<li class="dropdown-header">' . rex_escape(rex_i18n::msg('sprog_langcompare_switch_header')) . '</li>'
            . $langItems
            . '<li class="divider" role="separator"></li>'
            . '<li class="dropdown-header">' . rex_escape(rex_i18n::msg('sprog_langcompare_mode_header')) . '</li>'
            . '<li class="sprog-langcompare-switch--modes">'
            . '<a href="#" class="sprog-lc-mode" data-mode="content">' . rex_escape(rex_i18n::msg('sprog_langcompare_mode_content')) . '</a>'
            . '<a href="#" class="sprog-lc-mode" data-mode="metadata">' . rex_escape(rex_i18n::msg('sprog_langcompare_mode_metadata')) . '</a>'
            . '</li>'
            . '</ul>'
            . '</div>'
            . '<script nonce="' . rex_response::getNonce() . '">'
            . 'window.sprogLangCompare = ' . json_encode($config, JSON_THROW_ON_ERROR) . ';'
            . 'if (window.sprogLangCompareBoot) { window.sprogLangCompareBoot(); }'
            . '</script>';
    }

    /**
     * Leerer Panel-Host (STRUCTURE_CONTENT_AFTER_SLICES). Wird per JS mit dem
     * Ergebnis von {@see renderPanel()} befüllt und ein-/ausgeblendet.
     */
    public static function renderContainer(): string
    {
        return '<div class="sprog-ui sprog-langcompare" id="sprog-langcompare-panel" aria-live="polite" hidden></div>';
    }

    /**
     * Andere Sprachen als $clangA, auf die der Benutzer clang-Recht hat.
     *
     * @return array<int, string> clang_id => Name
     */
    private static function selectableOthers(int $clangA): array
    {
        $user = rex::getUser();
        if (null === $user) {
            return [];
        }

        $clangPerm = $user->getComplexPerm('clang');
        $others = [];
        foreach (rex_clang::getAll() as $clang) {
            $id = $clang->getId();
            if ($id !== $clangA && $clangPerm->hasPerm($id)) {
                $others[$id] = $clang->getName();
            }
        }

        return $others;
    }

    /**
     * Metadaten-Vergleich (read-only): zwei Spalten mit der KOMPLETTEN nativen
     * Sidebar je Sprache (Metadaten/metainfo + yrewrite URL + SEO). Read-only vs.
     * editierbar steuert das JS (deaktivieren bzw. eine Spalte aktivieren) — es
     * darf immer nur EINE Spalte editierbar sein (gleiche Widget-IDs METAINFO_*).
     */
    public static function renderMetaPanel(int $articleId, int $clangA, int $clangB, int $ctype): string
    {
        return '<div class="sprog-langcompare--grid">'
            . self::renderMetaColumn($articleId, $clangA, $ctype, false)
            . self::renderMetaColumn($articleId, $clangB, $ctype, false)
            . '</div>';
    }

    /**
     * Eine einzelne Metadaten-Spalte (Bearbeiten-Modus) für eine Sprache.
     */
    public static function renderMetaEdit(int $articleId, int $clang, int $ctype): string
    {
        return self::renderMetaColumn($articleId, $clang, $ctype, true);
    }

    /**
     * Eine Metadaten-Spalte: Kopf (Sprache + Bearbeiten/Abbrechen) plus die volle
     * native Sidebar. `$editable` schaltet nur den Kopf-Button um; das eigentliche
     * Aktiv-/Read-only-Schalten macht das JS.
     */
    private static function renderMetaColumn(int $articleId, int $clang, int $ctype, bool $editable): string
    {
        $clangObj = rex_clang::get($clang);
        $langName = null !== $clangObj ? $clangObj->getName() : (string) $clang;

        $action = $editable
            ? '<a href="#" class="sprog-btn sprog-btn--sm sprog-lc-meta-cancel">' . rex_escape(rex_i18n::msg('sprog_langcompare_position_cancel')) . '</a>'
            : '<a href="#" class="sprog-btn sprog-btn--sm sprog-lc-meta-edit" data-clang="' . $clang . '">' . rex_escape(rex_i18n::msg('sprog_langcompare_meta_edit')) . '</a>';

        $head = '<div class="sprog-langcompare--col-head">'
            . '<span class="sprog-langcompare--col-name">' . rex_escape($langName) . '</span>'
            . $action
            . '</div>';

        try {
            $body = self::renderSidebar($articleId, $clang, $ctype);
        } catch (Throwable $e) {
            $body = '<div class="sprog-note sprog-note--warning">'
                . rex_escape(rex_i18n::msg('sprog_langcompare_render_error', $e->getMessage())) . '</div>';
        }

        return '<div class="sprog-langcompare--col sprog-langcompare--metacol" data-clang="' . $clang . '">' . $head . $body . '</div>';
    }

    /**
     * Die komplette native Content-Sidebar einer Sprache serverseitig
     * zusammenbauen: metainfo-Panel + yrewrite URL/SEO. Die Handler registrieren
     * sich nur auf der Content-Seite, daher binden wir die Panels-Seiten direkt
     * ein (mit gespiegelten Guards) statt den EP zu feuern. Bewusst KEIN
     * Vollseiten-Fetch → keine Slice-Auswertung, kein markitup-Risiko.
     */
    private static function renderSidebar(int $articleId, int $clang, int $ctype): string
    {
        $params = ['article_id' => $articleId, 'clang' => $clang, 'ctype' => $ctype];

        // 1. metainfo (nutzt $ep, liefert fertige Section).
        $out = self::renderMetaForm($articleId, $clang, $ctype);

        // 2 + 3. yrewrite URL + SEO (nutzen $params via includeFile), Guards wie boot.php.
        $yrewrite = rex_addon::get('yrewrite');
        $user = rex::getUser();
        if ($yrewrite->isAvailable() && null !== $user) {
            if (!$yrewrite->getConfig('yrewrite_hide_url_block') && $user->hasPerm('yrewrite[url]')) {
                $out .= self::sidebarSection((string) $yrewrite->includeFile('pages/content.yrewrite_url.php', ['params' => $params]), rex_i18n::msg('yrewrite_rewriter'));
            }
            if (!$yrewrite->getConfig('yrewrite_hide_seo_block') && $user->hasPerm('yrewrite[seo]')) {
                $out .= self::sidebarSection((string) $yrewrite->includeFile('pages/content.yrewrite_seo.php', ['params' => $params]), rex_i18n::msg('yrewrite_rewriter_seo'));
            }
        }

        return $out;
    }

    /**
     * Ein Sidebar-Panel in das Core-Section-Fragment wickeln (wie der native
     * yrewrite-Handler).
     */
    private static function sidebarSection(string $body, string $title): string
    {
        $fragment = new rex_fragment();
        $fragment->setVar('title', '<i class="rex-icon rex-icon-info"></i> ' . $title, false);
        $fragment->setVar('body', $body, false);
        $fragment->setVar('collapse', true);
        $fragment->setVar('collapsed', false);

        return $fragment->parse('core/page/section.php');
    }

    /**
     * Natives metainfo-Sidebar-Formular einer Sprache erzeugen — durch Einbinden
     * von metainfo/pages/content.metainfo.php mit einem passend gefüllten
     * Extension-Point (article_id/clang/ctype), genau wie es der native
     * STRUCTURE_CONTENT_SIDEBAR-Hook tut.
     */
    private static function renderMetaForm(int $articleId, int $clang, int $ctype): string
    {
        $ep = new rex_extension_point('STRUCTURE_CONTENT_SIDEBAR', '', [
            'article_id' => $articleId,
            'clang' => $clang,
            'ctype' => $ctype,
        ]);
        $path = rex_addon::get('metainfo')->getPath('pages/content.metainfo.php');

        // @phpstan-ignore closure.unusedUse ($ep wird von der inkludierten content.metainfo.php im Scope erwartet)
        $render = static function () use ($ep, $path): string {
            $out = include $path;

            return (string) $out;
        };

        return $render();
    }

    /**
     * Panel-Inhalt: zwei native Sprachspalten nebeneinander (clang A + clang B)
     * für den aktiven ctype.
     */
    public static function renderPanel(int $articleId, int $clangA, int $clangB, int $ctype, int $revision = 0): string
    {
        return '<div class="sprog-langcompare--grid">'
            . self::renderColumn($articleId, $clangA, $ctype, $revision)
            . self::renderColumn($articleId, $clangB, $ctype, $revision)
            . '</div>';
    }

    /**
     * Eine Sprachspalte als native Slice-Liste. `$function`/`$sliceId` steuern den
     * Editor-Modus: '' = Liste, 'edit' + slice_id = Bearbeiten-Formular inline,
     * 'add' + slice_id (+ request module_id) = Hinzufügen-Formular inline.
     *
     * Der rex_clang-Wechsel stellt sicher, dass global-clang-abhängige Module in
     * dieser Sprache rendern. getArticle() evaluiert Modul-Output; ein defektes
     * Modul (z. B. Klasse eines deaktivierten AddOns) würde sonst die ganze Spalte
     * reißen — daher Throwable-Fallback pro Spalte.
     */
    public static function renderColumn(int $articleId, int $clang, int $ctype, int $revision, string $function = '', int $sliceId = 0): string
    {
        $clangObj = rex_clang::get($clang);
        $name = null !== $clangObj ? $clangObj->getName() : (string) $clang;

        $head = '<div class="sprog-langcompare--col-head">'
            . '<span class="sprog-langcompare--col-name">' . rex_escape($name) . '</span>'
            . '</div>';

        $origClang = rex_clang::getCurrentId();
        rex_clang::setCurrentId($clang);
        try {
            $body = self::renderEditor($articleId, $clang, $ctype, $revision, $function, $sliceId);
        } catch (Throwable $e) {
            $body = '<div class="sprog-note sprog-note--warning">'
                . rex_escape(rex_i18n::msg('sprog_langcompare_render_error', $e->getMessage()))
                . '</div>';
        } finally {
            rex_clang::setCurrentId($origClang);
        }

        return '<div class="sprog-langcompare--col" data-clang="' . $clang . '">' . $head . $body . '</div>';
    }

    /**
     * Native Editor-Ausgabe eines Artikels/ctypes für eine Sprache, verpackt in
     * das Core-Fragment slice_list.php (→ <ul class="rex-slices">). Setup 1:1
     * gespiegelt von structure/content: pages/content.edit.php. Das Modul beim
     * Hinzufügen liest der Editor selbst aus rex_request('module_id').
     */
    private static function renderEditor(int $articleId, int $clang, int $ctype, int $revision, string $function, int $sliceId): string
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT template.attributes AS template_attributes
             FROM ' . rex::getTable('article') . ' AS article
             LEFT JOIN ' . rex::getTable('template') . ' AS template ON template.id = article.template_id
             WHERE article.id = ? AND article.clang_id = ?',
            [$articleId, $clang],
        );
        $templateAttributes = $sql->getArrayValue('template_attributes');
        if (!is_array($templateAttributes)) {
            $templateAttributes = [];
        }

        $editor = new rex_article_content_editor();
        $editor->getContentAsQuery();
        $editor->info = '';
        $editor->warning = '';
        $editor->template_attributes = $templateAttributes;
        $editor->setArticleId($articleId);
        $editor->setSliceId($sliceId);
        $editor->setMode('edit');
        $editor->setClang($clang);
        $editor->setEval(true);
        $editor->setSliceRevision($revision);
        $editor->setFunction('add' === $function ? 'add' : 'edit');
        $content = $editor->getArticle($ctype);

        $fragment = new rex_fragment();
        $fragment->setVar('content', $content, false);

        return $fragment->parse('slice_list.php');
    }
}
