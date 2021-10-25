<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$csrfToken = \rex_csrf_token::factory('sprog-copy-metadata');

$sections = '';

$func = rex_request('func', 'string');
$clangFrom = rex_request('sprog_copy_clang_from', 'int', 0);
$clangTo = rex_request('sprog_copy_clang_to', 'int', 0);
$fields = rex_request('sprog_copy_fields', 'array', []);

if ($func == 'copy') {
    if ($clangFrom == $clangTo) {
    }
    //echo \rex_view::success($this->i18n('settings_config_saved'));
}

if ($func == '') {
    $panelElements = '';

    $clangAll = \rex_clang::getAll();
    $clangOptions = [];
    foreach ($clangAll as $clang) {
        $clangOptions[$clang->getId()] = $clang->getName();
    }

    $formElements = [];

    $select = new \rex_select();
    $select->setId('sprog-copy-clang-from');
    $select->setName('sprog_copy_clang_from');
    $select->setSelected($clangFrom);
    $select->setAttribute('data-sprog-param', 'clangFrom');
    $select->addArrayOptions($clangOptions);
    $n = [];
    $n['header'] = '<div class="row"><div class="col-md-6">';
    $n['footer'] = '</div>';
    $n['label'] = '<label for="sprog-copy-clang-from">'.$this->i18n('copy_clang_from').'</label>';
    $n['field'] = $select->get();
    $formElements[] = $n;

    $select = new \rex_select();
    $select->setId('sprog-copy-clang-to');
    $select->setName('sprog_copy_clang_to');
    $select->setSelected($clangTo);
    $select->setAttribute('data-sprog-param', 'clangTo');
    $select->addArrayOptions($clangOptions);
    $n = [];
    $n['header'] = '<div class="col-md-6">';
    $n['footer'] = '</div></div>';
    $n['label'] = '<label for="sprog-copy-clang-to">'.$this->i18n('copy_clang_to').'</label>';
    $n['field'] = $select->get();
    $formElements[] = $n;

    $query = 'SELECT `title`, `name` FROM '.\rex::getTable('metainfo_field').' WHERE `name` LIKE :name AND `type_id` != :type_id ORDER BY name';
    $catOptions = \rex_sql::factory()->getArray($query, ['name' => 'cat_%', 'type_id' => '12']);
    $artOptions = \rex_sql::factory()->getArray($query, ['name' => 'art_%', 'type_id' => '12']);

    $fieldsSelect = new \rex_select();
    $fieldsSelect->setId('sprog-copy-fields');
    $fieldsSelect->setName('sprog_copy_fields');
    $fieldsSelect->setAttribute('data-sprog-param', 'fields');
    $fieldsSelect->setAttribute('class', 'form-control');
    $fieldsSelect->setMultiple();
    $fieldsSelect->setSize(15);
    $fieldsSelect->setSelected($fields);

    $fieldsSelect->addOptgroup($this->i18n('copy_structure_metadata_structure'));
    foreach (['catname', 'catpriority', 'name', 'priority', 'status', 'template_id'] as $option) {
        $fieldsSelect->addOption($option.'   |   '.$this->i18n('copy_'.$option), $option);
    }
    if (count($catOptions)) {
        $fieldsSelect->addOptgroup($this->i18n('copy_structure_metadata_categories'));
        foreach ($catOptions as $option) {
            $fieldsSelect->addOption($option['name'].'   |   '.\rex_i18n::translate($option['title']).'', $option['name']);
        }
    }
    if (count($artOptions)) {
        $fieldsSelect->addOptgroup($this->i18n('copy_structure_metadata_articles'));
        foreach ($artOptions as $option) {
            $fieldsSelect->addOption($option['name'].'   |   '.\rex_i18n::translate($option['title']).'', $option['name']);
        }
    }
    $n = [];
    $n['label'] = '<label for="sprog-copy-fields">'.$this->i18n('copy_structure_metadata_fields').'</label>';
    $n['field'] = $fieldsSelect->get();
    $formElements[] = $n;

    $fragment = new \rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $panelElements .= $fragment->parse('core/form/form.php');

    $formElements = [];
    $n = [];
    $n['field'] = '<a class="btn btn-apply sprog-copy-button-start" href="'.rex_url::backendPage('sprog.copy.structure_metadata_popup', $csrfToken->getUrlParams()).'">'.$this->i18n('sprog_copy_button_start').'</a>';
    $formElements[] = $n;

    $fragment = new \rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $buttons = $fragment->parse('core/form/form.php');

    $panelBody = '
        <fieldset>
            <input type="hidden" name="func" value="update" />
            '.$panelElements.'
        </fieldset>';

    $fragment = new \rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', $this->i18n('copy_structure_metadata'), false);
    $fragment->setVar('body', $panelBody, false);
    $fragment->setVar('buttons', $buttons, false);
    $section = $fragment->parse('core/page/section.php');

    echo '
        <form action="'.\rex_url::currentBackendPage().'" method="post">
            '.$section.'
        </form>
    ';
}

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
        $n['header'] = '<div class="col-md-5">';
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
