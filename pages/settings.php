<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog;

use rex;
use rex_addon;
use rex_clang;
use rex_config;
use rex_csrf_token;
use rex_i18n;
use rex_request;
use rex_select;
use rex_sql;
use rex_url;
use rex_view;
use Sprog\Service\MtService;

use function count;
use function in_array;
use function is_array;
use function strlen;

/*
 |-----------------------------------------------------------------------------
 | Konfiguration — eine Seite, mehrere Sektionen (Sprachen, Wildcards,
 | Synchronisierung, Workflow, Machine Translation). Jede Sektion ist ein
 | eigenes Formular mit eigenem Speichern-Button; das versteckte Feld
 | `settings_section` routet die POST-Verarbeitung.
 |
 | Sonderfall `clang_base` (Sprachbasis): wird NICHT hier gespeichert, sondern
 | in Sprog\Boot\PageTreeBuilder::handleSettingsUpdate() — vor dem Render, damit
 | die geänderte Sprachbasis im selben Request schon die Navigation beeinflusst.
 | Gegated auf settings_section=languages, damit andere Sektionen den Wert nicht
 | mit einem leeren Array überschreiben.
 |-----------------------------------------------------------------------------
 */

$csrf = rex_csrf_token::factory('sprog_settings');
$csrfMt = rex_csrf_token::factory('sprog_settings_mt');

$messages = [];
$section = rex_request('settings_section', 'string', '');

if ('post' === rex_request::requestMethod()) {
    if ('mt' === $section) {
        if (!$csrfMt->isValid()) {
            $messages[] = rex_view::error($this->i18n('settings_mt_csrf_invalid'));
        } else {
            $deeplKeyInput = trim((string) rex_request('deepl_key', 'string', ''));
            $deeplKeyRemove = 1 === rex_request('deepl_key_remove', 'int', 0);
            $changed = false;
            $failed = false;

            if ($deeplKeyRemove) {
                rex_config::remove('sprog', 'mt_deepl_key');
                $changed = true;
            } elseif ('' !== $deeplKeyInput) {
                // DeepL-Keys sind UUID + optionales ":fx"-Suffix, also weit unter
                // 200 Zeichen. Defensiver Längen-Check gegen Müll-Eingaben.
                if (strlen($deeplKeyInput) > 200) {
                    $messages[] = rex_view::error($this->i18n('settings_mt_key_too_long'));
                    $failed = true;
                } else {
                    rex_config::set('sprog', 'mt_deepl_key', $deeplKeyInput);
                    $changed = true;
                }
            }

            if ($changed && !$failed) {
                $messages[] = rex_view::success($this->i18n('settings_mt_saved'));
            }
        }
    } elseif (in_array($section, ['languages', 'wildcards', 'sync', 'workflow', 'structure'], true)) {
        if (!$csrf->isValid()) {
            $messages[] = rex_view::error(rex_i18n::msg('csrf_token_invalid'));
        } elseif ('sync' === $section) {
            $syncConfig = rex_post('settings', [
                ['sync_structure_status', 'bool'],
                ['sync_structure_template', 'bool'],
                ['sync_metainfo_cat', 'array'],
                ['sync_metainfo_art', 'array'],
            ]);

            // Die Richtung der Namens-Synchronisation ist eine sich gegenseitig
            // ausschließende Auswahl (Radio: keine / Kategorie→Artikel /
            // Artikel→Kategorie). Sie wird in die zwei bestehenden Bool-Keys
            // übersetzt, die Sprog\Extension bei Artikel-/Kategorie-Updates liest.
            $nameDirection = rex_post('sync_structure_name_direction', 'string', 'none');
            $syncConfig['sync_structure_category_name_to_article_name'] = 'cat_to_art' === $nameDirection;
            $syncConfig['sync_structure_article_name_to_category_name'] = 'art_to_cat' === $nameDirection;

            $this->setConfig($syncConfig);
            $messages[] = rex_view::success($this->i18n('settings_config_saved'));
        } else {
            // clang_base (Sprachen-Sektion) wurde bereits in PageTreeBuilder
            // persistiert; hier nur die restlichen Keys der Sektion.
            $configKeys = [
                'languages' => [
                    ['base_clang_id', 'int'],
                ],
                'wildcards' => [
                    ['wildcard_open_tag', 'string'],
                    ['wildcard_close_tag', 'string'],
                ],
                'workflow' => [
                    ['workflow_dev_self_approve', 'bool'],
                ],
                'structure' => [
                    ['structure_clang_filter', 'bool'],
                ],
            ];

            $this->setConfig(rex_post('settings', $configKeys[$section]));
            $messages[] = rex_view::success($this->i18n('settings_config_saved'));
        }
    }
}

/*
 |-----------------------------------------------------------------------------
 | Render-Helfer
 |-----------------------------------------------------------------------------
 */

$actionUrl = rex_url::currentBackendPage();
$saveLabel = $this->i18n('settings_save');

$renderSection = static function (string $title, string $headExtra, string $hintHtml, string $bodyHtml, string $hiddenFields, string $formAttr, bool $wide = false) use ($actionUrl, $saveLabel): string {
    // Breite Panels (Sprachen, Synchronisierung, MT) spannen im 2-Spalten-Raster
    // beide Spalten; die kleinen (Wildcards, Workflow) belegen je eine Spalte.
    $panelClass = 'sprog-panel sprog-settings--panel' . ($wide ? ' sprog-settings--panel--wide' : '');
    return '<section class="' . $panelClass . '">'
        . '<div class="sprog-settings--head">'
        . '<h2 class="sprog-panel--heading">' . $title . '</h2>'
        . $headExtra
        . '</div>'
        . ('' !== $hintHtml ? '<p class="sprog-panel--hint">' . $hintHtml . '</p>' : '')
        . '<form method="post" action="' . $actionUrl . '" class="sprog-settings--form"' . ('' !== $formAttr ? ' ' . $formAttr : '') . '>'
        . $hiddenFields
        . '<div class="sprog-settings--body">' . $bodyHtml . '</div>'
        . '<div class="sprog-settings--actions"><button type="submit" class="sprog-btn sprog-btn--primary">' . $saveLabel . '</button></div>'
        . '</form>'
        . '</section>';
};

$renderRadio = static function (string $name, string $value, string $label, bool $checked): string {
    return '<label class="sprog-settings--check">'
        . '<input type="radio" name="' . rex_escape($name) . '" value="' . rex_escape($value) . '"' . ($checked ? ' checked' : '') . '>'
        . '<span>' . $label . '</span>'
        . '</label>';
};

// Toggle-Switch für Bool-Configs (visuell als Schalter statt Checkbox). Speichert
// wie ein Checkbox-Feld über settings[<key>] (value=1, fehlend = false).
$renderSwitch = static function (string $key, string $label, bool $checked): string {
    return '<label class="sprog-switch">'
        . '<input type="checkbox" class="sprog-switch--input" name="settings[' . rex_escape($key) . ']" value="1"' . ($checked ? ' checked' : '') . '>'
        . '<span class="sprog-switch--track" aria-hidden="true"></span>'
        . '<span class="sprog-switch--text">' . $label . '</span>'
        . '</label>';
};

$hidden = static function (string $sectionName, string $csrfField): string {
    return '<input type="hidden" name="settings_section" value="' . rex_escape($sectionName) . '">' . $csrfField;
};

$field = static function (string $id, string $label, string $control, string $hint = ''): string {
    return '<div class="sprog-field">'
        . '<label class="sprog-field--label" for="' . rex_escape($id) . '">' . $label . '</label>'
        . $control
        . ('' !== $hint ? '<p class="sprog-hint">' . $hint . '</p>' : '')
        . '</div>';
};

$sections = '';

/*
 |-----------------------------------------------------------------------------
 | Sektion: Sprachen (Basissprache + Sprachbasis)
 |-----------------------------------------------------------------------------
 */
$baseSelect = new rex_select();
$baseSelect->setId('sprog-base-clang');
$baseSelect->setName('settings[base_clang_id]');
$baseSelect->setAttribute('class', 'sprog-control');
$startClang = rex_clang::get(rex_clang::getStartId());
$baseSelect->addOption(
    $this->i18n('settings_base_clang_auto') . (null !== $startClang ? ' (' . $startClang->getName() . ')' : ''),
    0,
);
foreach (rex_clang::getAll() as $clang) {
    $baseSelect->addOption($clang->getName(), $clang->getId());
}
$baseSelect->setSelected((int) $this->getConfig('base_clang_id'));

$languagesBody = $field(
    'sprog-base-clang',
    $this->i18n('settings_base_clang'),
    $baseSelect->get(),
    $this->i18n('settings_base_clang_note'),
);

$clangAll = rex_clang::getAll();
if (count($clangAll) >= 2) {
    $clangOptions = [];
    foreach ($clangAll as $clang) {
        $clangOptions[$clang->getId()] = $clang->getName();
    }
    $clangBase = $this->getConfig('clang_base');

    $baseGrid = '';
    foreach ($clangAll as $clang) {
        $select = new rex_select();
        $select->setId('sprog-clang-base-' . $clang->getId());
        $select->setName('clang_base[' . $clang->getId() . ']');
        $select->setAttribute('class', 'sprog-control');
        if (is_array($clangBase) && isset($clangBase[$clang->getId()])) {
            $select->setSelected($clangBase[$clang->getId()]);
        } else {
            $select->setSelected($clang->getId());
        }
        $select->addArrayOptions($clangOptions);

        $baseGrid .= $field('sprog-clang-base-' . $clang->getId(), rex_escape($clang->getName()), $select->get());
    }

    $languagesBody .= '<div class="sprog-settings--subhead">'
        . '<span class="sprog-settings--subhead-title">' . $this->i18n('settings_clang_base') . '</span>'
        . '<p class="sprog-hint">' . $this->i18n('settings_clang_base_note') . '</p>'
        . '</div>'
        . '<div class="sprog-settings--grid">' . $baseGrid . '</div>';
}

$sections .= $renderSection(
    $this->i18n('settings_languages'),
    '',
    $this->i18n('settings_languages_hint'),
    $languagesBody,
    $hidden('languages', $csrf->getHiddenField()),
    '',
    true,
);

/*
 |-----------------------------------------------------------------------------
 | Sektion: Wildcards (öffnendes / schließendes Tag)
 |-----------------------------------------------------------------------------
 */
$wildcardBody = '<div class="sprog-settings--grid">'
    . $field(
        'sprog-wildcard-open-tag',
        $this->i18n('settings_wildcard_open_tag'),
        '<input type="text" id="sprog-wildcard-open-tag" name="settings[wildcard_open_tag]" value="' . rex_escape(Wildcard::getOpenTag()) . '" class="sprog-control sprog-control--mono">',
    )
    . $field(
        'sprog-wildcard-close-tag',
        $this->i18n('settings_wildcard_close_tag'),
        '<input type="text" id="sprog-wildcard-close-tag" name="settings[wildcard_close_tag]" value="' . rex_escape(Wildcard::getCloseTag()) . '" class="sprog-control sprog-control--mono">',
    )
    . '</div>';

$sections .= $renderSection(
    $this->i18n('wildcard'),
    '',
    $this->i18n('settings_wildcard_note'),
    $wildcardBody,
    $hidden('wildcards', $csrf->getHiddenField()),
    '',
);

/*
 |-----------------------------------------------------------------------------
 | Sektion: Workflow (kleines Panel — teilt sich die Rasterzeile mit Wildcards)
 |-----------------------------------------------------------------------------
 */
$workflowBody = '<div class="sprog-settings--checks">'
    . $renderSwitch('workflow_dev_self_approve', $this->i18n('settings_workflow_dev_self_approve'), (bool) $this->getConfig('workflow_dev_self_approve'))
    . '</div>';

$sections .= $renderSection(
    $this->i18n('settings_workflow'),
    '',
    '',
    $workflowBody,
    $hidden('workflow', $csrf->getHiddenField()),
    '',
);

/*
 |-----------------------------------------------------------------------------
 | Sektion: Struktur (Sprachauswahl auf yrewrite-Domains beschränken)
 |
 | Nur sinnvoll mit yrewrite; sonst ist das Feature ein No-Op → Sektion aus.
 | Serverseitige Auswertung s. Sprog\Support\StructureClangGuard.
 |-----------------------------------------------------------------------------
 */
if (rex_addon::get('yrewrite')->isAvailable()) {
    $structureBody = '<div class="sprog-settings--checks">'
        . $renderSwitch('structure_clang_filter', $this->i18n('settings_structure_clang_filter'), (bool) $this->getConfig('structure_clang_filter'))
        . '<p class="sprog-hint">' . $this->i18n('settings_structure_clang_filter_note') . '</p>'
        . '</div>';

    $sections .= $renderSection(
        $this->i18n('settings_structure'),
        '',
        '',
        $structureBody,
        $hidden('structure', $csrf->getHiddenField()),
        '',
    );
}

/*
 |-----------------------------------------------------------------------------
 | Sektion: Synchronisierung (sprachübergreifend + innerhalb einer Sprache)
 |-----------------------------------------------------------------------------
 */
// „Zwischen den Sprachen": hält Werte über alle rex_clang synchron.
$crossLangChecks = '<div class="sprog-settings--checks">'
    . $renderSwitch('sync_structure_status', $this->i18n('settings_sync_structure_status'), (bool) $this->getConfig('sync_structure_status'))
    . $renderSwitch('sync_structure_template', $this->i18n('settings_sync_structure_template'), (bool) $this->getConfig('sync_structure_template'))
    . '</div>';

// „Innerhalb einer Sprache": Kategorie- ↔ Startartikelname. Die Richtung ist
// exklusiv (Radio); aktueller Wert aus den zwei Bool-Keys abgeleitet.
$catToArt = (bool) $this->getConfig('sync_structure_category_name_to_article_name');
$artToCat = (bool) $this->getConfig('sync_structure_article_name_to_category_name');
$nameDirection = $catToArt ? 'cat_to_art' : ($artToCat ? 'art_to_cat' : 'none');

$nameRadios = '<div class="sprog-settings--checks" role="radiogroup" aria-labelledby="sprog-sync-within-title">'
    . $renderRadio('sync_structure_name_direction', 'none', $this->i18n('settings_sync_structure_name_none'), 'none' === $nameDirection)
    . $renderRadio('sync_structure_name_direction', 'cat_to_art', $this->i18n('settings_sync_structure_category_name_to_article_name'), 'cat_to_art' === $nameDirection)
    . $renderRadio('sync_structure_name_direction', 'art_to_cat', $this->i18n('settings_sync_structure_article_name_to_category_name'), 'art_to_cat' === $nameDirection)
    . '</div>';

// type_id 12 = Legende → aus der Auswahl ausschließen.
$query = 'SELECT title, name FROM ' . rex::getTable('metainfo_field') . ' WHERE name LIKE :name AND type_id != :type_id ORDER BY name';
$catOptions = rex_sql::factory()->getArray($query, ['name' => 'cat_%', 'type_id' => '12']);
$artOptions = rex_sql::factory()->getArray($query, ['name' => 'art_%', 'type_id' => '12']);

// Mehrfachauswahl als Checkbox-Liste statt <select multiple> — kein
// versehentliches Zurücksetzen der Auswahl, tastatur-/screenreader-tauglich.
// name[]-Struktur + array-Speicherung bleiben identisch zum früheren Select.
$buildMetaChecks = function (array $options, string $name, array $selected, string $labelId): string {
    if (0 === count($options)) {
        return '<p class="sprog-hint">' . $this->i18n('settings_sync_metainfo_not_found') . '</p>';
    }

    $items = '';
    foreach ($options as $option) {
        $checked = in_array($option['name'], $selected, true);
        $items .= '<label class="sprog-settings--meta-check">'
            . '<input type="checkbox" name="' . rex_escape($name) . '" value="' . rex_escape($option['name']) . '"' . ($checked ? ' checked' : '') . '>'
            . '<span class="sprog-settings--meta-name">' . rex_escape($option['name']) . '</span>'
            . '<span class="sprog-settings--meta-sep" aria-hidden="true">·</span>'
            . '<span class="sprog-settings--meta-title">' . rex_i18n::translate($option['title']) . '</span>'
            . '</label>';
    }

    return '<div class="sprog-settings--checklist" role="group" aria-labelledby="' . rex_escape($labelId) . '">' . $items . '</div>';
};

$artSelected = (array) ($this->getConfig('sync_metainfo_art') ?? []);
$catSelected = (array) ($this->getConfig('sync_metainfo_cat') ?? []);

$metaGroup = '<div class="sprog-settings--metagroup">'
    . '<span class="sprog-settings--subhead-title">' . $this->i18n('settings_sync_metainfo') . '</span>'
    . '<div class="sprog-settings--grid">'
    . '<div class="sprog-field">'
    . '<span class="sprog-field--label" id="sprog-sync-metainfo-art-label">' . $this->i18n('settings_sync_metainfo_art') . '</span>'
    . $buildMetaChecks($artOptions, 'settings[sync_metainfo_art][]', $artSelected, 'sprog-sync-metainfo-art-label')
    . '</div>'
    . '<div class="sprog-field">'
    . '<span class="sprog-field--label" id="sprog-sync-metainfo-cat-label">' . $this->i18n('settings_sync_metainfo_cat') . '</span>'
    . $buildMetaChecks($catOptions, 'settings[sync_metainfo_cat][]', $catSelected, 'sprog-sync-metainfo-cat-label')
    . '</div>'
    . '</div>'
    . '</div>';

$syncBody = '<div class="sprog-settings--subhead">'
    . '<span class="sprog-settings--subhead-title" id="sprog-sync-cross-title">' . $this->i18n('settings_sync_cross_lang') . '</span>'
    . '<p class="sprog-hint">' . $this->i18n('settings_sync_cross_lang_note') . '</p>'
    . '</div>'
    . $crossLangChecks
    . $metaGroup
    . '<div class="sprog-settings--subhead">'
    . '<span class="sprog-settings--subhead-title" id="sprog-sync-within-title">' . $this->i18n('settings_sync_within_lang') . '</span>'
    . '<p class="sprog-hint">' . $this->i18n('settings_sync_within_lang_note') . '</p>'
    . '</div>'
    . $nameRadios;

$sections .= $renderSection(
    $this->i18n('settings_synchronization'),
    '',
    '',
    $syncBody,
    $hidden('sync', $csrf->getHiddenField()),
    '',
    true,
);

/*
 |-----------------------------------------------------------------------------
 | Sektion: Machine Translation (DeepL-API-Key)
 |
 | Password-Feld-Pattern: value bleibt beim Render immer leer (Key wird nie
 | zurückgespielt). Leeres Feld ohne Remove-Haken = keine Änderung; gefülltes
 | Feld = überschreiben; Remove-Haken = löschen.
 |-----------------------------------------------------------------------------
 */
$mtService = MtService::create();
$deeplConfigured = in_array('deepl', $mtService->configuredProviderNames(), true);

$mtStatusModif = $deeplConfigured ? 'approved' : 'missing';
$mtStatusKey = $deeplConfigured ? 'settings_mt_status_configured' : 'settings_mt_status_not_configured';
$mtHeadExtra = '<span class="sprog-status sprog-status--' . $mtStatusModif . '">' . $this->i18n($mtStatusKey) . '</span>';

$mtPlaceholder = $deeplConfigured
    ? $this->i18n('settings_mt_key_placeholder_set')
    : $this->i18n('settings_mt_key_placeholder_unset');

$mtBody = '<div class="sprog-field">'
    . '<label class="sprog-field--label" for="sprog-mt-deepl-key">' . $this->i18n('settings_mt_deepl_key_label') . '</label>'
    . '<input type="password" id="sprog-mt-deepl-key" name="deepl_key" value="" placeholder="' . $mtPlaceholder . '" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" maxlength="200" class="sprog-control sprog-control--mono">'
    . '<p class="sprog-hint">' . rex_i18n::rawMsg('sprog_settings_mt_deepl_key_hint') . '</p>'
    . '</div>';

if ($deeplConfigured) {
    $mtBody .= '<label class="sprog-settings--check">'
        . '<input type="checkbox" name="deepl_key_remove" value="1">'
        . '<span>' . $this->i18n('settings_mt_key_remove') . '</span>'
        . '</label>';
}

$sections .= $renderSection(
    $this->i18n('settings_mt_heading'),
    $mtHeadExtra,
    $this->i18n('settings_mt_lead'),
    $mtBody,
    $hidden('mt', $csrfMt->getHiddenField()),
    'autocomplete="off"',
    true,
);

/*
 |-----------------------------------------------------------------------------
 | Ausgabe
 |-----------------------------------------------------------------------------
 */
echo '<article class="sprog-ui sprog-settings">';
echo '<header class="sprog-intro">'
    . '<h1 class="sprog-heading">' . $this->i18n('settings') . '</h1>'
    . '<p class="sprog-lead">' . $this->i18n('settings_lead') . '</p>'
    . '</header>';

foreach ($messages as $msg) {
    echo $msg;
}

echo '<div class="sprog-settings--panels">' . $sections . '</div>';
echo '</article>';
