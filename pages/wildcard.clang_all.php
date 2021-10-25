<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 * @author (c) Alex Platter <a.platter@kreatif.it>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Sprog\Wildcard;

$csrfToken = rex_csrf_token::factory('sprog-clang-all');

$content = '';
$message = '';

// -------------- Defaults
$wildcard_id = rex_request('wildcard_id', 'int');
$wildcard_name = rex_request('wildcard_name', 'string');
$wildcard_replaces = rex_request('wildcard_replaces', 'array');
$func = rex_request('func', 'string');
$search_term = rex_request('search-term', 'string', '');

// -------------- Form Submits
$add_wildcard_save = rex_post('add_wildcard_save', 'boolean');
$edit_wildcard_save = rex_post('edit_wildcard_save', 'boolean');

$error = '';
$success = '';

$clangAll = \rex_clang::getAll();
$clangBase = $this->getConfig('clang_base');
// Alle Sprachen die eine andere Basis haben, nicht anzeigen lassen
foreach ($clangAll as $clang) {
    if (isset($clangBase[$clang->getId()]) && $clangBase[$clang->getId()] != $clang->getId()) {
        unset($clangAll[$clang->getId()]);
    }
}

// ----- delete wildcard
if ($func == 'delete' && !$csrfToken->isValid()) {
    $error = rex_i18n::msg('csrf_token_invalid');
} elseif ($func == 'delete' && $wildcard_id > 0 && rex::getUser()->getComplexPerm('clang')->hasAll()) {
    $deleteWildcard = rex_sql::factory();
    $deleteWildcard->setQuery('DELETE FROM '.rex::getTable('sprog_wildcard').' WHERE id=?', [$wildcard_id]);
    $success = $this->i18n('wildcard_deleted');

    $func = '';
    unset($wildcard_id);
}

// ----- add wildcard
if (($add_wildcard_save || $edit_wildcard_save) && !$csrfToken->isValid()) {
    $error = rex_i18n::msg('csrf_token_invalid');
} elseif ($add_wildcard_save || $edit_wildcard_save) {
    if (($wildcard_name == '' && $add_wildcard_save) || (rex::getUser()->getComplexPerm('clang')->hasAll() && $wildcard_name == '' && $edit_wildcard_save)) {
        $error = $this->i18n('wildcard_enter_wildcard');
        $func = $add_wildcard_save ? 'add' : 'edit';
    } elseif ($add_wildcard_save) {
        $success = $this->i18n('wildcard_added');

        $addWildcard = rex_sql::factory();

        foreach (rex_clang::getAllIds() as $clang_id) {
            if (isset($wildcard_replaces[$clang_id])) {
                $addWildcard->setTable(rex::getTable('sprog_wildcard'));

                if (!isset($id)) {
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
        $success = $this->i18n('wildcard_edited');

        $editWildcard = rex_sql::factory();

        foreach (rex_clang::getAllIds() as $clang_id) {
            if (isset($wildcard_replaces[$clang_id])) {
                $editWildcard->setTable(rex::getTable('sprog_wildcard'));
                $editWildcard->setWhere('id = :id AND clang_id = :clang_id', ['id' => $wildcard_id, 'clang_id' => $clang_id]);
                if (rex::getUser()->getComplexPerm('clang')->hasAll()) {
                    $editWildcard->setValue('wildcard', $wildcard_name);
                }
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
foreach ($clangAll as $clang_id => $clang) {
    if (rex::getUser()->getComplexPerm('clang')->hasPerm($clang->getId())) {
        $th .= '<th>'.$clang->getName().'</th>';
        $td_add .= '<td data-title="'.$clang->getName().'"><textarea class="form-control" name="wildcard_replaces['.$clang->getId().']" rows="6">'.(isset($wildcard_replaces[$clang->getId()]) ? htmlspecialchars($wildcard_replaces[$clang->getId()]) : '').'</textarea></td>';
    }
}

$add_icon = rex::getUser()->getComplexPerm('clang')->hasAll() ? '<a href="'.rex_url::currentBackendPage(['func' => 'add']).'#wildcard"'.rex::getAccesskey($this->i18n('add'), 'add').'><i class="rex-icon rex-icon-add-article"></i></a>' : '';

$content .= '
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th class="rex-table-icon">'.$add_icon.'</th>
                    <th class="rex-table-id">'.$this->i18n('id').'</th>
                    <th class="rex-table-minwidth-6">'.$this->i18n('wildcard').'</th>
                    '.$th.'
                    <th class="rex-table-action" colspan="2">'.$this->i18n('function').'</th>
                </tr>
            </thead>
            <tbody>
    ';

// Add form
if ($func == 'add') {
    $content .= '
                <tr class="mark">
                    <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
                    <td class="rex-table-id" data-title="'.$this->i18n('id').'">–</td>
                    <td data-title="'.$this->i18n('wildcard').'"><input class="form-control" type="text" name="wildcard_name" value="'.htmlspecialchars($wildcard_name).'" /></td>
                    '.$td_add.'
                    <td class="rex-table-action" colspan="2"><button class="btn btn-save" type="submit" name="add_wildcard_save"'.rex::getAccesskey($this->i18n('add'), 'save').' value="1">'.$this->i18n('add').'</button></td>
                </tr>
            ';
}

$querySelect = [];
$queryJoin = [];
foreach ($clangAll as $clang_id => $clang) {
    if (rex::getUser()->getComplexPerm('clang')->hasPerm($clang->getId())) {
        $as = 'clang'.$clang->getId();
        $querySelect[] = $as.'.replace AS '.'id'.$clang->getId();
        $queryJoin[] = 'LEFT JOIN '.rex::getTable('sprog_wildcard').' AS '.$as.' ON a.id = '.$as.'.id AND '.$as.'.clang_id = '.$clang->getId();
    }
}
$querySelectAsString = count($querySelect) ? ', '.implode(',', $querySelect) : '';
$wildcards = rex_sql::factory();
$search = '';
if (strlen($search_term)) {
    $search = 'AND (a.`wildcard` LIKE "%'.$search_term.'%" OR a.`replace` LIKE "%'.$search_term.'%")';
}
$entries = $wildcards->setQuery('SELECT DISTINCT a.id, a.wildcard AS wildcard'.$querySelectAsString.' FROM '.rex::getTable('sprog_wildcard').' AS a '.implode(' ', $queryJoin).' WHERE 1 '.$search.' ORDER BY wildcard')->getArray();
//$entries = $wildcards->setQuery('SELECT DISTINCT a.id, a.wildcard AS wildcard' . $querySelectAsString . ' FROM ' . rex::getTable('sprog_wildcard') . ' AS a ' . implode(' ', $queryJoin) . ' ORDER BY wildcard')->getArray();

if (count($entries)) {
    foreach ($entries as $entry) {
        $entry_id = $entry['id'];
        $entry_wildcard = $entry['wildcard'];

        unset($entry['id']);
        unset($entry['wildcard']);

        // Edit form
        if ($func == 'edit' && $wildcard_id == $entry_id) {
            $colSpan = count($entry);
            $td = '';
            if (rex::getUser()->getComplexPerm('clang')->hasAll()) {
                $td .= '<td colspan="'.$colSpan.'" data-title="'.$this->i18n('wildcard').'"><div class="input-group"><span class="input-group-addon">'.Wildcard::getOpenTag().'</span><input class="form-control" type="text" name="wildcard_name" value="'.htmlspecialchars(($edit_wildcard_save ? $wildcard_name : $entry_wildcard)).'" /><span class="input-group-addon">'.Wildcard::getCloseTag().'</span></div></td>';
            } else {
                $td .= '<td colspan="'.$colSpan.'" data-title="'.$this->i18n('wildcard').'">'.$entry_wildcard.'</td>';
            }
            $edit_rows = '';
            foreach ($entry as $lang_name => $replace) {
                $clang_id = (int) trim($lang_name, 'id');
                //$td .= '<td data-title="' . rex_clang::get($clang_id)->getName() . '"><textarea class="form-control" name="wildcard_replaces[' . $clang_id . ']" rows="6">' . htmlspecialchars($replace) . '</textarea></td>';
                $edit_rows .= '
                        <tr style="background-color: rgba(224, 245, 238, 0.4);">
                            <td class="rex-table-icon"></td>
                            <td class="rex-table-id"></td>
                            <th>'.rex_clang::get($clang_id)->getName().'</th>
                            <td colspan="'.$colSpan.'" data-title="'.rex_clang::get($clang_id)->getName().'"><textarea class="form-control" name="wildcard_replaces['.$clang_id.']" rows="8">'.htmlspecialchars($replace).'</textarea></td>
                            <td class="rex-table-action" colspan="2"></td>
                        </tr>';
            }
            $content .= '
                        <tr class="mark" id="wildcard-'.$entry_id.'">
                            <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
                            <td class="rex-table-id" data-title="'.$this->i18n('id').'">'.$entry_id.'</td>
                            <th>'.$this->i18n('wildcard').'</th>
                            '.$td.'
                            <td class="rex-table-action" colspan="2"><button class="btn btn-save" type="submit" name="edit_wildcard_save"'.rex::getAccesskey($this->i18n('update'), 'save').' value="1">'.$this->i18n('update').'</button></td>
                        </tr>'.$edit_rows;
        } else {
            $td = '';
            foreach ($entry as $lang_name => $replace) {
                $clang_id = (int) trim($lang_name, 'id');
                $td .= '<td data-title="'.rex_clang::get($clang_id)->getName().'">'.htmlspecialchars($replace).'</td>';
            }

            $class = (rex_request('wildcard_id', 'int') == $entry_id) ? ' class="mark"' : '';
            $content .= '
                        <tr'.$class.' id="wildcard-'.$entry_id.'">
                            <td class="rex-table-icon"><a href="'.rex_url::currentBackendPage(['func' => 'edit', 'wildcard_id' => $entry_id]).'#wildcard-'.$entry_id.'"><i class="rex-icon rex-icon-refresh"></i></a></td>
                            <td class="rex-table-id" data-title="'.$this->i18n('id').'">'.$entry_id.'</td>
                            <td data-title="'.$this->i18n('wildcard').'">'.$entry_wildcard.'</td>
                            '.$td.'
                            <td class="rex-table-action"><a href="'.rex_url::currentBackendPage(['func' => 'edit', 'wildcard_id' => $entry_id, 'search-term' => $search_term]).'#wildcard-'.$entry_id.'"><i class="rex-icon rex-icon-edit"></i> '.$this->i18n('function_edit').'</a></td>
                            <td class="rex-table-action">'.(rex::getUser()->getComplexPerm('clang')->hasAll() ? '<a href="'.rex_url::currentBackendPage(['func' => 'delete', 'wildcard_id' => $entry_id, 'search-term' => $search_term] + $csrfToken->getUrlParams()).'" data-confirm="'.$this->i18n('delete').' ?"><i class="rex-icon rex-icon-delete"></i> '.$this->i18n('delete').'</a>' : '').'</td>
                        </tr>';
        }
    }
}

$content .= '
        </tbody>
    </table>';

echo $message;

$searchControl = '<div class="form-inline"><div class="input-group input-group-xs"><div class="input-group-btn"><a href="'.rex_url::currentBackendPage().'" class="btn btn-default btn-xs"><i class="rex-icon rex-icon-clear"></i></a></div><input class="form-control" style="height: 24px; padding-top: 3px; padding-bottom: 3px; font-size: 12px; line-height: 1;" type="text" name="search-term" value="'.htmlspecialchars($search_term).'" /><div class="input-group-btn"><button type="submit" class="btn btn-primary btn-xs">'.$this->i18n('search').'</button></div></div></div>';
$searchControl = ($func == '') ? '<form action="'.\rex_url::currentBackendPage().'" method="post">'.$searchControl.'</form>' : $searchControl;

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('wildcard_caption'), false);
$fragment->setVar('content', $content, false);
$fragment->setVar('options', $searchControl, false);
$content = $fragment->parse('core/page/section.php');

if ($func == 'add' || $func == 'edit') {
    $content = '
        <form action="'.rex_url::currentBackendPage(['search-term' => $search_term]).'" method="post">
            <fieldset>
                <input type="hidden" name="wildcard_id" value="'.$wildcard_id.'" />
                '.$csrfToken->getHiddenField().'
                '.$content.'
            </fieldset>
        </form>
        ';
}

echo $content;

if (rex::getUser()->getComplexPerm('clang')->hasAll()) {
    echo Wildcard::getMissingWildcardsAsTable();
}
