<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Alex Platter <a.platter@kreatif.it>
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Sprog\Import;

$addon = rex_addon::get('sprog');

$csrfToken = \rex_csrf_token::factory('sprog-settings');

$func = rex_request('func', 'string');
$missing_language = rex_request('missing_language', 'string', '');
$delimiter = rex_request('delimiter', 'string', ';');

$delimiterOptions = [
    ';' => rex_i18n::msg('sprog_import_delimiter_semicolon').' (;)',
    ',' => rex_i18n::msg('sprog_import_delimiter_comma').' (,)',
    'tab' => rex_i18n::msg('sprog_import_delimiter_tab').'',
];

if (!isset($delimiterOptions[$delimiter])) {
    $delimiter = ';';
}

$messages = [];

if ($func == 'import-csv' && !$csrfToken->isValid()) {
    echo \rex_view::error(\rex_i18n::msg('csrf_token_invalid'));
} elseif ($func == 'import-csv') {
    $file = rex_files('upload_file');

    $importer = new Import($delimiter, $missing_language);
    $result = $importer->importCsv($file['tmp_name'], true);

    if ($result) {
        $messages[] = rex_view::success($addon->i18n('import_success'));
    } else {
        $messages[] = rex_view::error($addon->i18n('import_failed'));
    }
}

if (count($messages)) {
    echo implode('', $messages);
}

$panelElements = '';
$formElements = [];

$n = [];
$n['label'] = '<label>'.rex_i18n::msg('sprog_import_missing_language_ignore') . '</label>';
$n['field'] = '<input type="radio" name="missing_language" value=""' . (($missing_language == '') ? 'checked' : '') . ' />';
$formElements[] = $n;

$n = [];
$n['label'] = '<label>'.rex_i18n::msg('sprog_import_missing_language_add').'</label>';
$n['field'] = '<input type="radio" name="missing_language" value="add"' . (($missing_language == 'add') ? 'checked' : '') . ' />';
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$radios = $fragment->parse('core/form/radio.php');

$formElements = [];
$n = [];
$n['label'] = '<label>'.rex_i18n::msg('sprog_import_missing_language_label') . '</label>';
$n['field'] = $radios;
$formElements[] = $n;

$a = new rex_select();
$a->setName('delimiter');
$a->setId('delimiter');
$a->addOptions($delimiterOptions);
$a->setSelected($delimiter);

$n = [];
$n['label'] = '<label>' . rex_i18n::msg('sprog_import_delimiter') . '</label>';
$n['field'] = '<div class="rex-style">' . $a->get() . '</div>';
$formElements[] = $n;

$n = [];
$n['label'] = '<label>' . rex_i18n::msg('sprog_import_file') . '</label>';
$n['field'] = '<input class="form-control text-right" type="file" name="upload_file" />';
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$panelElements .= $fragment->parse('core/form/form.php');

$formElements = [];
$n = [];
$n['field'] = '<button class="btn btn-apply rex-form-aligned" type="submit" name="send" value="1">'.$addon->i18n('import').'</button>';
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$buttons = $fragment->parse('core/form/submit.php');

$panelBody = '
    <fieldset>
        <input type="hidden" name="func" value="import-csv" />
        '.$csrfToken->getHiddenField().'
        <h3>'.$addon->i18n('import_heading').'</h3>
        <p>'.$addon->i18n('import_description').'</p>
        '.$panelElements.'
    </fieldset>';

$fragment = new \rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('import_title'), false);
$fragment->setVar('body', $panelBody, false);
$fragment->setVar('buttons', $buttons, false);
$section = $fragment->parse('core/page/section.php');

echo '
    <form action="'.\rex_url::currentBackendPage().'" method="post" data-pjax="false" enctype="multipart/form-data">
        '.$section.'
    </form>
';
