<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$csrfToken = \rex_csrf_token::factory('sprog-copy-content');

$sections = '';

$func = rex_request('func', 'string');
$clangFrom = rex_request('sprog_copy_structure_content_clang_from', 'int', 0);
$clangTo = rex_request('sprog_copy_structure_content_clang_to', 'int', 0);
$deleteBefore = rex_request('sprog_copy_structure_content_delete_before', 'bool', 1);

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
    $select->setId('sprog-copy-structure-content-clang-from');
    $select->setName('sprog_copy_structure_content_clang_from');
    $select->setSelected($clangFrom);
    $select->setAttribute('data-sprog-param', 'clangFrom');
    $select->addArrayOptions($clangOptions);
    $n = [];
    $n['header'] = '<div class="row"><div class="col-md-6">';
    $n['footer'] = '</div>';
    $n['label'] = '<label for="sprog-copy-structure-content-clang-from">'.$this->i18n('copy_clang_from').'</label>';
    $n['field'] = $select->get();
    $formElements[] = $n;

    $select = new \rex_select();
    $select->setId('sprog-copy-structure-content-clang-to');
    $select->setName('sprog_copy_structure_content_clang_to');
    $select->setSelected($clangTo);
    $select->setAttribute('data-sprog-param', 'clangTo');
    $select->addArrayOptions($clangOptions);
    $n = [];
    $n['header'] = '<div class="col-md-6">';
    $n['footer'] = '</div></div>';
    $n['label'] = '<label for="sprog-copy-structure-content-clang-to">'.$this->i18n('copy_clang_to').'</label>';
    $n['field'] = $select->get();
    $formElements[] = $n;

    $fragment = new \rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $panelElements .= $fragment->parse('core/form/form.php');

    $formElements = [];
    $n = [];
    $n['label'] = '<label for="sprog-copy-structure-content-delete-before">'.$this->i18n('copy_delete_before').'</label>';
    $n['field'] = '<input id="sprog-copy-structure-content-delete-before" data-sprog-param="deleteBefore" name="sprog_copy_structure_content_delete_before" type="checkbox"'.($deleteBefore ? ' checked="checked"' : '').' value="1" />';
    $formElements[] = $n;

    $fragment = new \rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $panelElements .= $fragment->parse('core/form/checkbox.php');

    $formElements = [];
    $n = [];
    $n['field'] = '<a class="btn btn-apply sprog-copy-button-start" href="'.rex_url::backendPage('sprog.copy.structure_content_popup', $csrfToken->getUrlParams()).'">'.$this->i18n('sprog_copy_button_start').'</a>';
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
    $fragment->setVar('title', $this->i18n('copy_structure_content'), false);
    $fragment->setVar('body', $panelBody, false);
    $fragment->setVar('buttons', $buttons, false);
    $section = $fragment->parse('core/page/section.php');

    echo '
        <form action="'.\rex_url::currentBackendPage().'" method="post">
            '.$section.'
        </form>
    ';
?>
<script>
    function lang_changer() {
        var from = document.querySelector('#sprog-copy-structure-content-clang-from');
        var to = document.querySelector('#sprog-copy-structure-content-clang-to');
        var submitButton = document.querySelector('.sprog-copy-button-start');

        if (from.value === to.value) {
            to.value = '';
        }
        if ('' === to.value) {
            submitButton.disabled = true;
        } else {
            submitButton.disabled = false;
        }

        for (var i = 0; i < to.children.length; i++) {
            if (to[i].value === from.value) {
                to[i].disabled = true;
            } else {
                to[i].disabled = false;
            }
        }
    }

    // Hide on document load
    $(document).ready(function () {
        lang_changer();
    });
    // Hide option selection change
    $("#sprog-copy-structure-content-clang-from").on('change', function (e) {
        lang_changer();
    });
    $("#sprog-copy-structure-content-clang-to").on('change', function (e) {
        lang_changer();
    });
</script>
<?php
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
