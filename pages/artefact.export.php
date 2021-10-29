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

use Sprog\Export\CsvExport;

$addon = rex_addon::get('sprog');

$csrfToken = \rex_csrf_token::factory('sprog-settings');

$func = rex_request('func', 'string');

if ($func == 'export' && !$csrfToken->isValid()) {
    echo \rex_view::error(\rex_i18n::msg('csrf_token_invalid'));
} elseif ($func == 'export') {
    rex_response::cleanOutputBuffers();
    $sql = rex_sql::factory();
    $items = $sql->getArray('SELECT `id`, `clang_id`, `wildcard`, `replace` FROM '.rex::getTable('sprog_wildcard').' ORDER BY `wildcard`');

    $rows = [];
    $data = [];
    $clang_ids = [];
    foreach ($items as $index => $item) {
        $data[$item['wildcard']][$item['clang_id']] = str_replace("\r", '', $item['replace']);
        $clang_ids[$item['clang_id']] = '';
    }

    ksort($data);

    $header = ['wildcard'];
    foreach ($clang_ids as $clang_id => $value) {
        $header[] = ($clang = rex_clang::get($clang_id)) ? $clang->getCode() : $clang_id;
    }

    $csv = new CsvExport();
    $csv->addHeaders($header);

    foreach ($data as $wildcard => $clangs) {
        $record = [$wildcard];
        foreach ($clangs as $clang_id => $replace) {
            $record[] = $replace;
        }
        $csv->addItem($record);
    }

    $csv->sendFile('sprog-' . date('Ymd-His') . '.csv');
}

$formElements = [];
$n = [];
$n['field'] = '<button class="btn btn-save" type="submit" name="send" value="1">'.rex_i18n::msg('sprog_export').'</button>';
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$buttons = $fragment->parse('core/form/submit.php');

$panelBody = '
    <fieldset>
        <input type="hidden" name="func" value="export" />
        '.$csrfToken->getHiddenField().'
        <h3>'.rex_i18n::msg('sprog_export_heading').'</h3>
        <p>'.rex_i18n::msg('sprog_export_description').'</p>
    </fieldset>';

$fragment = new \rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('sprog_export_title'), false);
$fragment->setVar('body', $panelBody, false);
$fragment->setVar('buttons', $buttons, false);
$section = $fragment->parse('core/page/section.php');

echo '
    <form action="'.\rex_url::currentBackendPage().'" method="post">
        '.$section.'
    </form>
';
