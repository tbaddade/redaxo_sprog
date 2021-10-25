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

$csrfToken = \rex_csrf_token::factory('sprog-settings');

$sections = '';

$func = rex_request('func', 'string');

if ($func == 'update' && !$csrfToken->isValid()) {
    echo \rex_view::error(\rex_i18n::msg('csrf_token_invalid'));
} elseif ($func == 'update') {
    // clang_switch und clang_base wird in der boot.php neu gesetzt

    $this->setConfig(rex_post('settings', [
        ['wildcard_open_tag', 'string'],
        ['wildcard_close_tag', 'string'],
        ['sync_structure_category_name_to_article_name', 'bool'],
        ['sync_structure_article_name_to_category_name', 'bool'],
        ['sync_structure_status', 'bool'],
        ['sync_structure_template', 'bool'],
        ['sync_metainfo_cat', 'array'],
        ['sync_metainfo_art', 'array'],
        //['sync_metainfo_med', 'array'],
    ]));

    echo \rex_view::success($this->i18n('settings_config_saved'));
}

// - - - - - - - - - - - - - - - - - - - - - - Wildcard
$panelElements = '';
$formElements = [];
$n = [];
$n['header'] = '<div class="row"><div class="col-lg-8">';
$n['footer'] = '</div></div>';
$n['label'] = '<label for="wildcard-open-tag">'.$this->i18n('settings_wildcard_open_close_tag').'</label>';
$n['field'] = '
    <div class="input-group">
        <input class="form-control text-right" type="text" id="wildcard-open-tag" name="settings[wildcard_open_tag]" value="'.htmlspecialchars(Wildcard::getOpenTag()).'" placeholder="'.$this->i18n('settings_wildcard_open_tag').'" />
        <span class="input-group-addon">'.strtolower($this->i18n('wildcard')).'</span>
        <input class="form-control" type="text" id="wildcard-close-tag" name="settings[wildcard_close_tag]" value="'.htmlspecialchars(Wildcard::getCloseTag()).'" placeholder="'.$this->i18n('settings_wildcard_close_tag').'" />
    </div>';
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$panelElements .= $fragment->parse('core/form/form.php');

$formElements = [];
$n = [];
$n['label'] = '<label for="wildcard-clang-switch">'.$this->i18n('settings_wildcard_clang_switch').'</label>';
$n['field'] = '<input type="checkbox" id="wildcard-clang-switch" name="clang_switch"'.(Wildcard::isClangSwitchMode() ? ' checked="checked"' : '').' value="1" />';
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$panelElements .= $fragment->parse('core/form/checkbox.php');

$panelBody = '
    <fieldset>
        <input type="hidden" name="func" value="update" />
        '.$csrfToken->getHiddenField().'
        '.$panelElements.'
    </fieldset>';

$fragment = new \rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $this->i18n('wildcard'), false);
$fragment->setVar('body', $panelBody, false);
$sections .= $fragment->parse('core/page/section.php');

// - - - - - - - - - - - - - - - - - - - - - -
$clangAll = \rex_clang::getAll();
if (count($clangAll) >= 2) {
    $clangOptions = [];
    foreach ($clangAll as $clang) {
        $clangOptions[$clang->getId()] = $clang->getName();
    }
    $panelElements = '';
    $formElements = [];
    $clangBase = $this->getConfig('clang_base');
    foreach ($clangAll as $clang) {
        $select = new \rex_select();
        $select->setName('clang_base['.$clang->getId().']');
        if (isset($clangBase[$clang->getId()])) {
            $select->setSelected($clangBase[$clang->getId()]);
        } else {
            $select->setSelected($clang->getId());
        }
        $select->addArrayOptions($clangOptions);

        $n = [];
        $n['header'] = '<div class="col-md-6">';
        $n['footer'] = '</div>';
        $n['label'] = '<label>'.$clang->getName().'</label>';
        $n['field'] = $select->get();
        $formElements[] = $n;
    }

    $fragment = new \rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $panelElements .= $fragment->parse('core/form/form.php');

    $panelBody = '
        <fieldset>
            <div class="row">'.$panelElements.'</div>
        </fieldset>';

    $fragment = new \rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', $this->i18n('settings_clang_base'), false);
    $fragment->setVar('body', $panelBody, false);
    $sections .= $fragment->parse('core/page/section.php');
}

// - - - - - - - - - - - - - - - - - - - - - - Synchronization
$panelElements = '';
$panelElements .= '<fieldset><legend>'.$this->i18n('settings_sync_structure').'</legend>';

$formElements = [];
$n = [];
$n['label'] = '<label for="sync-structure-category-name-to-article-name">'.$this->i18n('settings_sync_structure_category_name_to_article_name').'</label>';
$n['field'] = '<input type="checkbox" id="sync-structure-category-name-to-article-name" name="settings[sync_structure_category_name_to_article_name]"'.($this->getConfig('sync_structure_category_name_to_article_name') ? ' checked="checked"' : '').' value="1" />';
$formElements[] = $n;

$n = [];
$n['label'] = '<label for="sync-structure-article-name-to-category-name">'.$this->i18n('settings_sync_structure_article_name_to_category_name').'</label>';
$n['field'] = '<input type="checkbox" id="sync-structure-article-name-to-category-name" name="settings[sync_structure_article_name_to_category_name]"'.($this->getConfig('sync_structure_article_name_to_category_name') ? ' checked="checked"' : '').' value="1" />';
$formElements[] = $n;

$n = [];
$n['label'] = '<label for="sync-structure-status">'.$this->i18n('settings_sync_structure_status').'</label>';
$n['field'] = '<input type="checkbox" id="sync-structure-status" name="settings[sync_structure_status]"'.($this->getConfig('sync_structure_status') ? ' checked="checked"' : '').' value="1" />';
$formElements[] = $n;

$n = [];
$n['label'] = '<label for="sync-structure-template">'.$this->i18n('settings_sync_structure_template').'</label>';
$n['field'] = '<input type="checkbox" id="sync-structure-template" name="settings[sync_structure_template]"'.($this->getConfig('sync_structure_template') ? ' checked="checked"' : '').' value="1" />';
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$panelElements .= $fragment->parse('core/form/checkbox.php');

$panelElements .= '</fieldset>';
$panelElements .= '<fieldset><legend>'.$this->i18n('settings_sync_metainfo').'</legend><p>'.$this->i18n('settings_sync_metainfo_note').'</p>';

// type_id => 12 => exlude legends
$query = 'SELECT title, name FROM '.\rex::getTable('metainfo_field').' WHERE name LIKE :name AND type_id != :type_id ORDER BY name';
$catOptions = \rex_sql::factory()->getArray($query, ['name' => 'cat_%', 'type_id' => '12']);
$artOptions = \rex_sql::factory()->getArray($query, ['name' => 'art_%', 'type_id' => '12']);
// $medOptions = \rex_sql::factory()->getArray($query, ['name' => 'med_%', 'type_id' => '12']);

$sizeSelectMax = 10;
$sizeSelectPlus = 2;
$sizeSelect = $sizeSelectPlus + count($catOptions);
$sizeSelect = $sizeSelect > $sizeSelectMax ? $sizeSelectMax : $sizeSelect;
$catSelect = new \rex_select();
$catSelect->setId('sync-metainfo-cat');
$catSelect->setName('settings[sync_metainfo_cat][]');
$catSelect->setMultiple();
$catSelect->setAttribute('class', 'form-control');
$catSelect->setAttribute('placeholder', 'Platzhalter');
$catSelect->setSelected($this->getConfig('sync_metainfo_cat'));
$catSelect->setSize($sizeSelect);
if (count($catOptions)) {
    foreach ($catOptions as $option) {
        $catSelect->addOption($option['name'].'   |   '.\rex_i18n::translate($option['title']).'', $option['name']);
    }
} else {
    $catSelect->addOption($this->i18n('settings_sync_metainfo_not_found'), '', 0, 0, ['disabled' => 'disabled']);
}

$sizeSelect = $sizeSelectPlus + count($artOptions);
$sizeSelect = $sizeSelect > $sizeSelectMax ? $sizeSelectMax : $sizeSelect;
$artSelect = new \rex_select();
$artSelect->setId('sync-metainfo-art');
$artSelect->setName('settings[sync_metainfo_art][]');
$artSelect->setMultiple();
$artSelect->setAttribute('class', 'form-control');
$artSelect->setSelected($this->getConfig('sync_metainfo_art'));
$artSelect->setSize($sizeSelect);
if (count($artOptions)) {
    foreach ($artOptions as $option) {
        $artSelect->addOption($option['name'].'   |   '.\rex_i18n::translate($option['title']).'', $option['name']);
    }
} else {
    $artSelect->addOption($this->i18n('settings_sync_metainfo_not_found'), '', 0, 0, ['disabled' => 'disabled']);
}

/*


$sizeSelect = $sizeSelectPlus + count($medOptions);



$sizeSelect = $sizeSelect > $sizeSelectMax ? $sizeSelectMax : $sizeSelect;
$medSelect = new \rex_select();
$medSelect->setId('sync-metainfo-med');
$medSelect->setName('settings[sync_metainfo_med][]');
$medSelect->setMultiple();
$medSelect->setSelected($this->getConfig('sync_metainfo_med'));
$medSelect->setSize($sizeSelect);
if (count($medOptions)) {
    foreach ($medOptions as $option) {
        $medSelect->addOption($option['name'] . '   |   ' . \rex_i18n::translate($option['title']) . '', $option['name']);
    }
} else {
    $medSelect->addOption($this->i18n('settings_sync_metainfo_not_found'), '', 0, 0, ['disabled' => 'disabled']);
}
*/

$grid = [];
$formElements = [];
$n = [];
$n['label'] = '<label for="sync-metainfo-art">'.$this->i18n('settings_sync_metainfo_art').'</label>';
$n['field'] = $artSelect->get();
$n['note'] = \rex_i18n::msg('ctrl');
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$grid[] = $fragment->parse('core/form/form.php');

$formElements = [];
$n = [];
$n['header'] = '';
$n['label'] = '<label for="sync-metainfo-cat">'.$this->i18n('settings_sync_metainfo_cat').'</label>';
$n['field'] = $catSelect->get();
$n['note'] = \rex_i18n::msg('ctrl');
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$grid[] = $fragment->parse('core/form/form.php');
/*
$formElements = [];
$n = [];
$n['before'] = '<div class="rex-select-style">';
$n['after'] = '</div>';
$n['label'] = '<label for="sync-metainfo-med">' . $this->i18n('settings_sync_metainfo_med') . '</label>';
$n['field'] = $medSelect->get();
$n['note'] = \rex_i18n::msg('ctrl');
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$grid[] = $fragment->parse('core/form/form.php');
*/

$fragment = new \rex_fragment();
$fragment->setVar('content', $grid, false);
$panelElements .= $fragment->parse('core/page/grid.php');

$panelElements .= '</fieldset>';

$fragment = new \rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $this->i18n('settings_synchronization'), false);
$fragment->setVar('body', $panelElements, false);
$sections .= $fragment->parse('core/page/section.php');

$formElements = [];
$n = [];
$n['field'] = '<a class="btn btn-abort" href="'.\rex_url::currentBackendPage().'">'.\rex_i18n::msg('form_abort').'</a>';
$formElements[] = $n;

$n = [];
$n['field'] = '<button class="btn btn-apply rex-form-aligned" type="submit" name="send" value="1"'.\rex::getAccesskey(\rex_i18n::msg('update'), 'apply').'>'.\rex_i18n::msg('update').'</button>';
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$buttons = $fragment->parse('core/form/submit.php');

$fragment = new \rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('buttons', $buttons, false);
$sections .= $fragment->parse('core/page/section.php');

echo '
    <form action="'.\rex_url::currentBackendPage().'" method="post">
        '.$sections.'
    </form>
';
