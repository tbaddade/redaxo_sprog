<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 * @author (c) Robert Rupf <robert.rupf@maumha.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$addon = rex_addon::get('sprog');

$csrfToken = rex_csrf_token::factory('sprog-foreignword');

$id = rex_request('id', 'int');
$func = rex_request('func', 'string');
$clangId = (int)str_replace('clang', '', rex_be_controller::getCurrentPagePart(3));

$foreignword = rex_post('foreignword', 'string');
$lang = rex_post('lang', 'string');
$save = rex_post('foreignword_save', 'boolean');

$error = '';
$success = '';

if (('delete' === $func || 'status' === $func || $save) && !$csrfToken->isValid()) {
    $error = $addon->i18n('csrf_token_invalid');
} elseif ('delete' === $func && $id > 0) {
    $sql = rex_sql::factory();
    $sql->setTable(rex::getTable('sprog_foreignword'));
    $sql->setWhere('id = :id', ['id' => $id]);

    try {
        $sql->delete();
        $success = $addon->i18n('foreignword_deleted');
    } catch (rex_sql_exception $e) {
        $error = $addon->i18n('foreignword_error_delete');
    }

    $func = '';
    unset($id);
} elseif ('status' === $func && $id > 0) {
    $status = (rex_request('status', 'int') + 1) % 2;
    $sql = rex_sql::factory();
    $sql->setTable(rex::getTable('sprog_foreignword'));
    $sql->setWhere(['id' => $id]);
    $sql->setValue('status', $status);
    $sql->addGlobalUpdateFields();
    try {
        $sql->update();
        $success = $addon->i18n('foreignword_status_updated');
    } catch (rex_sql_exception $e) {
        $error = $addon->i18n('foreignword_error_status_updated');
    }

} elseif ($save && ('' === $foreignword || '' === $lang)) {
    $error = $addon->i18n('foreignword_error_is_empty');
} elseif ($save) {
    $sql = rex_sql::factory();
    $sql->setTable(rex::getTable('sprog_foreignword'));
    $sql->setValue('clang_id', $clangId);
    $sql->setValue('foreignword', $foreignword);
    $sql->setValue('lang', $lang);
    $sql->addGlobalUpdateFields();

    try {
        if ($id > 0) {
            $sql->setWhere('id = :id', ['id' => $id]);
            $sql->update();
            $success = $addon->i18n('foreignword_edited');
        } else {
            $sql->setValue('status', 1);
            $sql->addGlobalCreateFields();
            $sql->insert();
            $success = $addon->i18n('foreignword_added');
        }
        $func = '';
    } catch (rex_sql_exception $e) {
        $error = $addon->i18n('foreignword_error_exists');
    }
}

if ('' !== $success) {
    echo rex_view::success($success);
}

if ('' !== $error) {
    echo rex_view::error($error);
}

$sql = rex_sql::factory();
$sql->setQuery('SELECT DISTINCT `id`, `foreignword`, `lang`, `status`
                FROM '.rex::getTable('sprog_foreignword').'
                WHERE `clang_id` = :clangId
                ORDER BY `foreignword`', ['clangId' => $clangId]);
$items = $sql->getArray();

$langSelect = new \rex_select();
$langSelect->setName('lang');
$langSelect->setAttribute('class', 'form-control');
$langSelect->addOptions(\Sprog\Foreignword::getLanguages(), true);

$tableRows = [];

if ('add' === $func) {
    $tableRows[] = '
        <tr class="mark" id="foreignword-add">
            <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
            <td><input class="form-control" type="text" name="foreignword" value="'.rex_escape($foreignword).'" /></td>
            <td>' . $langSelect->get() . '</td>
            <td class="rex-table-action"></td>
            <td class="rex-table-action" colspan="2">
                <button class="btn btn-save" type="submit" name="foreignword_save" value="1">'.$addon->i18n('function_add').'</button>
            </td>
        </tr>
    ';
}

foreach ($items as $item) {
    if ('edit' === $func && $id === (int)$item['id']) {
        $langSelect->setSelected($item['lang']);
        $tableRows[] =
            '<tr class="mark" id="foreignword-'.$item['id'].'">
                <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
                <td><input class="form-control" type="text" name="foreignword" value="'.rex_escape($item['foreignword']).'" /></td>
                <td>' . $langSelect->get() . '</td>
                <td class="rex-table-action"></td>
                <td class="rex-table-action" colspan="2">
                    <button class="btn btn-save" type="submit" name="foreignword_save" value="1">'.$addon->i18n('update').'</button>
                </td>
            </tr>';
    } else {
        $tableRows[] =
            '<tr id="foreignword-'.$item['id'].'">
                <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
                <td>'.$item['foreignword'].'</td>
                <td>'.$item['lang'].'</td>
                <td class="rex-table-action">
                    <a href="'.rex_url::currentBackendPage(['func' => 'status', 'id' => $item['id'], 'status' => $item['status']] + $csrfToken->getUrlParams()).'#foreignword-'.$item['id'].'">
                        '.(1 == $item['status'] ? '<span class="rex-online"><i class="rex-icon rex-icon-active-true"></i> '.$addon->i18n('activated').'</span>' : '<span class="rex-offline"><i class="rex-icon rex-icon-active-false"></i> '.$addon->i18n('deactivated').'</span>').'
                    </a>
                </td>
                <td class="rex-table-action">
                    <a href="'.rex_url::currentBackendPage(['func' => 'edit', 'id' => $item['id']]).'#foreignword-'.$item['id'].'">
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
                    <th class="rex-table-icon"><a href="'.rex_url::currentBackendPage(['func' => 'add']).'#foreignword-add"><i class="rex-icon rex-icon-add-article"></i></a></th>
                    <th>'.$addon->i18n('foreignword').'</th>
                    <th>'.$addon->i18n('foreignword_lang').'</th>
                    <th>'.$addon->i18n('foreignword_status').'</th>
                    <th class="rex-table-action" colspan="2">'.$addon->i18n('function').'</th>
                </tr>
            </thead>
            <tbody>
                '.implode('', $tableRows).'
            </tbody>
        </table>';

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('foreignword_caption'), false);
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
