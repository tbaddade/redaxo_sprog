<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$addon = rex_addon::get('sprog');

$csrfToken = rex_csrf_token::factory('sprog-abbreviation');

$id = rex_request('id', 'int');
$func = rex_request('func', 'string');
$clangId = (int)str_replace('clang', '', rex_be_controller::getCurrentPagePart(3));

$abbreviation = rex_post('abbreviation', 'string');
$text = rex_post('text', 'string');
$save = rex_post('abbreviation_save', 'boolean');

$error = '';
$success = '';

if (('delete' === $func || $save) && !$csrfToken->isValid()) {
    $error = $addon->i18n('csrf_token_invalid');
} elseif ('delete' === $func && $id > 0) {
    $sql = rex_sql::factory();
    $sql->setTable(rex::getTable('sprog_abbreviation'));
    $sql->setWhere('id = :id', ['id' => $id]);

    try {
        $sql->delete();
        $success = $addon->i18n('abbreviation_deleted');
    } catch (rex_sql_exception $e) {
        $error = $addon->i18n('abbreviation_error_delete');
    }

    $func = '';
    unset($id);
} elseif ($save && ('' === $abbreviation || '' === $text)) {
    $error = $addon->i18n('abbreviation_error_is_empty');
} elseif ($save) {
    $sql = rex_sql::factory();
    $sql->setTable(rex::getTable('sprog_abbreviation'));
    $sql->setValue('clang_id', $clangId);
    $sql->setValue('abbreviation', $abbreviation);
    $sql->setValue('text', $text);
    $sql->setValue('status', '1');
    $sql->addGlobalUpdateFields();

    try {
        if ($id > 0) {
            $sql->setWhere('id = :id', ['id' => $id]);
            $sql->update();
            $success = $addon->i18n('abbreviation_edited');
        } else {
            $sql->addGlobalCreateFields();
            $sql->insert();
            $success = $addon->i18n('abbreviation_added');
        }
        $func = '';
    } catch (rex_sql_exception $e) {
        $error = $addon->i18n('abbreviation_error_exists');
    }
}

if ('' !== $success) {
    echo rex_view::success($success);
}

if ('' !== $error) {
    echo rex_view::error($error);
}

$sql = rex_sql::factory();
$sql->setQuery('SELECT DISTINCT `id`, `abbreviation`, `text`
                FROM '.rex::getTable('sprog_abbreviation').' 
                WHERE `clang_id` = :clangId
                ORDER BY `abbreviation`', ['clangId' => $clangId]);
$items = $sql->getArray();

$tableRows = [];

if ('add' === $func) {
    $tableRows[] = '
        <tr class="mark" id="abbreviation-add">
            <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
            <td><input class="form-control" type="text" name="abbreviation" value="'.rex_escape($abbreviation).'" /></td>
            <td><input class="form-control" type="text" name="text" value="'.rex_escape($text).'" /></td>
            <td class="rex-table-action" colspan="2">
                <button class="btn btn-save" type="submit" name="abbreviation_save" value="1">'.$addon->i18n('add').'</button>
            </td>
        </tr>
    ';
}

foreach ($items as $item) {
    if ('edit' === $func && $id === (int)$item['id']) {
        $tableRows[] =
            '<tr class="mark" id="abbreviation-'.$item['id'].'">
                <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
                <td><input class="form-control" type="text" name="abbreviation" value="'.rex_escape($item['abbreviation']).'" /></td>
                <td><input class="form-control" type="text" name="text" value="'.rex_escape($item['text']).'" /></td>
                <td class="rex-table-action" colspan="2">
                    <button class="btn btn-save" type="submit" name="abbreviation_save" value="1">'.$addon->i18n('update').'</button>
                </td>
            </tr>';
    } else {
        $tableRows[] =
            '<tr class="mark" id="abbreviation-'.$item['id'].'">
                <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
                <td>'.$item['abbreviation'].'</td>
                <td>'.$item['text'].'</td>
                <td class="rex-table-action">
                    <a href="'.rex_url::currentBackendPage(['func' => 'edit', 'id' => $item['id']]).'#abbreviation-'.$item['id'].'">
                        <i class="rex-icon rex-icon-edit"></i> '.$addon->i18n('function_edit').'
                    </a>
                </td>
                <td class="rex-table-action">
                    <a href="'.rex_url::currentBackendPage(['func' => 'delete', 'id' => $item['id']] + $csrfToken->getUrlParams()).'" data-confirm="'.$addon->i18n('delete').' ?">
                        <i class="rex-icon rex-icon-delete"></i> '.$addon->i18n('delete').'
                    </a>
                </td>
            </tr>';

    }
}

$content = '
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th class="rex-table-icon"><a href="'.rex_url::currentBackendPage(['func' => 'add']).'#abbreviation-add"><i class="rex-icon rex-icon-add-article"></i></a></th>
                    <th>'.$addon->i18n('abbreviation').'</th>
                    <th>'.$addon->i18n('abbreviation_long_form').'</th>
                    <th class="rex-table-action" colspan="2">'.$addon->i18n('function').'</th>
                </tr>
            </thead>
            <tbody>
                '.implode('', $tableRows).'
            </tbody>
        </table>';

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('abbreviation_caption'), false);
$fragment->setVar('content', $content, false);
$content = $fragment->parse('core/page/section.php');

if ('add' === $func || 'edit' === $func) {
    $content = '
        <form action="'.rex_url::currentBackendPage().'" method="post">
            <fieldset>
                <input type="hidden" name="id" value="'.rex_escape($id).'" />
                <input type="hidden" name="func" value="'.rex_escape($func).'" />
                '.$csrfToken->getHiddenField().'
                '.$content.'
            </fieldset>
        </form>
        ';
}

echo $content;
