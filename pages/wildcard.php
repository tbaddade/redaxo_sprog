<?php

/**
 * This file is part of the Wildcard package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use \Wildcard\Wildcard;

$content = '';
$message = '';

// -------------- Defaults
$wildcard_id       = rex_request('wildcard_id', 'int');
$wildcard_name     = rex_request('wildcard_name', 'string');
$wildcard_replaces = rex_request('wildcard_replaces', 'array');
$func = rex_request('func', 'string');

// -------------- Form Submits
$add_wildcard_save  = rex_post('add_wildcard_save', 'boolean');
$edit_wildcard_save = rex_post('edit_wildcard_save', 'boolean');

$error = '';
$success = '';

// ----- delete wildcard
if ($func == 'delete' && $wildcard_id > 0) {
    $deleteWildcard = rex_sql::factory();
    $deleteWildcard->setQuery('DELETE FROM ' . rex::getTable('wildcard') . ' WHERE id=?', [$wildcard_id]);
    $success = $this->i18n('wildcard_deleted');

    $func = '';
    unset($wildcard_id);
}

// ----- add wildcard
if ($add_wildcard_save || $edit_wildcard_save) {
    if ($wildcard_name == '') {

        $error = $this->i18n('enter_wildcard');
        $func = $add_wildcard_save ? 'add' : 'edit';

    } elseif ($add_wildcard_save) {

        $success = $this->i18n('wildcard_added');

        $addWildcard = rex_sql::factory();

        foreach (rex_clang::getAllIds() as $clang_id) {

            if (isset($wildcard_replaces[$clang_id])) {

                $addWildcard->setTable( rex::getTable('wildcard') );

                if (! isset($id)) {
                    $id = $addWildcard->setNewId('id');
                } else {
                    $addWildcard->setValue('id', $id);
                }

                $addWildcard->setValue('clang_id', $clang_id);
                $addWildcard->setValue('wildcard', $wildcard_name);
                $addWildcard->setValue('replace', $wildcard_replaces[$clang_id]);
                $addWildcard->addGlobalUpdateFields();
                $addWildcard->addGlobalCreateFields();
                $addWildcard->insert();

            }
        }
        $func = '';

    } else {

        $success = $this->i18n('edited');
        
        $editWildcard = rex_sql::factory();

        foreach (rex_clang::getAllIds() as $clang_id) {

            if (isset($wildcard_replaces[$clang_id])) {

                $editWildcard->setTable( rex::getTable('wildcard') );
                $editWildcard->setWhere(['id' => $wildcard_id, 'clang_id' => $clang_id]);
                $editWildcard->setValue('wildcard', $wildcard_name);
                $editWildcard->setValue('replace', $wildcard_replaces[$clang_id]);
                $editWildcard->addGlobalUpdateFields();
                $editWildcard->update();

            }
        }

        $func = '';
        unset($wildcard_id);
    }
}

if ($success != '') {
    $message .= rex_view::success($success);
}

if ($error != '') {
    $message .= rex_view::error($error);
}

$th = '';
$td_add = '';
foreach (rex_clang::getAll() as $clang_id => $clang) {
    $th .= '<th>' . $clang->getName() . '</th>';
    $td_add .= '<td data-title="' . $clang->getName() . '"><textarea class="form-control" name="wildcard_replaces[' . $clang_id . ']" rows="6">' . (isset($wildcard_replaces[$clang_id]) ? htmlspecialchars($wildcard_replaces[$clang_id]) : '') . '</textarea></td>';
}

$content .= '
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th class="rex-table-icon"><a href="' . rex_url::currentBackendPage(['func' => 'add']) . '#wildcard"' . rex::getAccesskey( $this->i18n('add'), 'add') . '><i class="rex-icon rex-icon-add-article"></i></a></th>
                    <th class="rex-table-id">' . $this->i18n('id') . '</th>
                    <th>' . $this->i18n('wildcard') . '</th>
                    ' . $th . '
                    <th class="rex-table-action" colspan="2">' . $this->i18n('function') . '</th>
                </tr>
            </thead>
            <tbody>
    ';

// Add form
if ($func == 'add') {
    $content .= '
                <tr class="mark">
                    <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
                    <td class="rex-table-id" data-title="' . $this->i18n('id') . '">–</td>
                    <td data-title="' . $this->i18n('wildcard') . '"><input class="form-control" type="text" name="wildcard_name" value="' . htmlspecialchars($wildcard_name) . '" /></td>
                    ' . $td_add . '
                    <td class="rex-table-action" colspan="2"><button class="btn btn-save" type="submit" name="add_wildcard_save"' . rex::getAccesskey($this->i18n('add'), 'save') . ' value="1">' . $this->i18n('add') . '</button></td>
                </tr>
            ';
}


$querySelect = [];
$queryJoin = [];
foreach (rex_clang::getAll() as $clang_id => $clang) {
    $as = strtolower($clang->getName());
    $querySelect[] = $as . '.replace AS ' . 'id' . $clang->getId();
    $queryJoin[] = 'LEFT JOIN ' . rex::getTable('wildcard') . ' AS ' . $as . ' ON a.id = ' . $as . '.id AND ' . $as . '.clang_id = ' . $clang->getId();

}

$wildcards = rex_sql::factory();
$entries = $wildcards->setQuery('SELECT DISTINCT a.id, a.wildcard AS wildcard, ' . implode(',', $querySelect) . ' FROM ' . rex::getTable('wildcard') . ' AS a ' . implode(' ', $queryJoin) . ' ORDER BY wildcard')->getArray();




if (count($entries)) {

    foreach ($entries as $entry) {

        $entry_id = $entry['id'];
        $entry_wildcard = $entry['wildcard'];

        unset($entry['id']);
        unset($entry['wildcard']);

        // Edit form
        if ($func == 'edit' && $wildcard_id == $entry_id) {

            $td = '';
            foreach ($entry as $lang_name => $replace) {
                $clang_id = (int)trim($lang_name, 'id');
                $td .= '<td data-title="' . rex_clang::get($clang_id)->getName() . '"><textarea class="form-control" name="wildcard_replaces[' . $clang_id . ']" rows="6">' . htmlspecialchars($replace) . '</textarea></td>';
            }
            $content .= '
                        <tr class="mark">
                            <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
                            <td class="rex-table-id" data-title="' . $this->i18n('id') . '">' . $entry_id . '</td>
                            <td data-title="' . $this->i18n('wildcard') . '"><input class="form-control" type="text" name="wildcard_name" value="' . htmlspecialchars(($edit_wildcard_save ? $wildcard_name : $entry_wildcard)) . '" /></td>
                            ' . $td . '
                            <td class="rex-table-action" colspan="2"><button class="btn btn-save" type="submit" name="edit_wildcard_save"' . rex::getAccesskey( $this->i18n('update'), 'save') . ' value="1">' .  $this->i18n('update') . '</button></td>
                        </tr>';
        } else {

            $td = '';
            foreach ($entry as $lang_name => $replace) {
                $clang_id = (int)trim($lang_name, 'id');
                $td .= '<td data-title="' . rex_clang::get($clang_id)->getName(). '">' . $replace . '</td>';
            }

            $content .= '
                        <tr>
                            <td class="rex-table-icon"><a href="' . rex_url::currentBackendPage(['func' => 'edit', 'wildcard_id' => $entry_id]) . '"><i class="rex-icon rex-icon-refresh"></i></a></td>
                            <td class="rex-table-id" data-title="' . $this->i18n('id') . '">' . $entry_id . '</td>
                            <td data-title="' . $this->i18n('wildcard') . '">' . $entry_wildcard . '</td>
                            ' . $td . '
                            <td class="rex-table-action"><a href="' . rex_url::currentBackendPage(['func' => 'edit', 'wildcard_id' => $entry_id]) . '"><i class="rex-icon rex-icon-edit"></i> ' . $this->i18n('edit') . '</a></td>
                            <td class="rex-table-action"><a href="' . rex_url::currentBackendPage(['func' => 'delete', 'wildcard_id' => $entry_id]) . '" data-confirm="' . $this->i18n('delete') . ' ?"><i class="rex-icon rex-icon-delete"></i> ' . $this->i18n('delete') . '</a></td>
                        </tr>';
        }
    }
}

$content .= '
        </tbody>
    </table>';

echo $message;

$fragment = new rex_fragment();
$fragment->setVar('title',  $this->i18n('caption'), false);
$fragment->setVar('content', $content, false);
$content = $fragment->parse('core/page/section.php');

if ($func == 'add' || $func == 'edit') {
    $content = '
        <form action="' . rex_url::currentBackendPage() . '" method="post">
            <fieldset>
                <input type="hidden" name="wildcard_id" value="' . $wildcard_id . '" />
                ' . $content . '
            </fieldset>
        </form>
        ';
}

echo $content;


$missingWildcards = \Wildcard\Wildcard::getMissingWildcards();

if (count($missingWildcards)) {

    $content = '';
    $content .= '
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th class="rex-table-icon"><a href="' . rex_url::currentBackendPage(['func' => 'add']) . '#wildcard"' . rex::getAccesskey( $this->i18n('add'), 'add') . '><i class="rex-icon rex-icon-add-article"></i></a></th>
                        <th>' . $this->i18n('wildcard') . '</th>
                        <th class="rex-table-action" colspan="2">' . $this->i18n('function') . '</th>
                    </tr>
                </thead>
                <tbody>
        ';
    
    foreach ($missingWildcards as $name => $params) {

        $content .= '
                    <tr>
                        <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
                        <td data-title="' . $this->i18n('wildcard') . '">' . $name . '</td>
                        <td class="rex-table-action"><a href="' . rex_url::currentBackendPage(['func' => 'add', 'wildcard_name' => $params['wildcard']]) . '"><i class="rex-icon rex-icon-edit"></i> ' . $this->i18n('add') . '</a></td>
                        <td class="rex-table-action"><a href="' . $params['url'] . '"><i class="rex-icon rex-icon-article"></i> ' . $this->i18n('go_to_the_article') . '</a></td>
                    </tr>';

    }

    $content .= '
            </tbody>
        </table>';


    $fragment = new rex_fragment();
    $fragment->setVar('title',  $this->i18n('caption_missing', rex_i18n::msg('title_structure')), false);
    $fragment->setVar('content', $content, false);
    $content = $fragment->parse('core/page/section.php');
    
    echo $content;

}
