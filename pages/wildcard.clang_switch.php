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

$csrfToken = rex_csrf_token::factory('sprog-clang-switch');

$content = '';
$message = '';

// -------------- Defaults
$pid = rex_request('pid', 'int');
$wildcard_id = rex_request('wildcard_id', 'int');
$func = rex_request('func', 'string');
$clang_id = (int) str_replace('clang', '', rex_be_controller::getCurrentPagePart(3));

$error = '';
$success = '';

if (!function_exists('sprogStyleTd')) {
    function sprogStyleTd($params, $value = '', $classes = [])
    {
        $pid = rex_request('pid', 'int');
        $list = $params['list'];
        $class = '';

        $style = $pid == $list->getValue('pid') ? ' style="background-color: #e0f5ee"' : '';
        $value = $value != '' ? $value : $params['value'];
        $value = $params['field'] == 'replace' ? htmlspecialchars($value) : $value;
        if (isset($params['params']['classes'])) {
            $classes = array_merge($classes, $params['params']['classes']);
        }
        if (count($classes)) {
            $class = ' class="'.implode(' ', $classes).'"';
        }
        return '<td'.$class.$style.'>'.$value.'</td>';
    }
}
if (!function_exists('sprogStyleTdPid')) {
    function sprogStyleTdPid($params)
    {
        return sprogStyleTd($params, $params['list']->getColumnLink('edit', '<i class="rex-icon rex-icon-refresh"></i>'));
    }
}
if (!function_exists('sprogStyleTdEdit')) {
    function sprogStyleTdEdit($params)
    {
        return sprogStyleTd($params, $params['list']->getColumnLink('edit', '<i class="rex-icon rex-icon-edit"></i> '.\rex_i18n::msg('edit')), ['rex-table-action']);
    }
}
if (!function_exists('sprogStyleTdDelete')) {
    function sprogStyleTdDelete($params)
    {
        return sprogStyleTd($params, $params['list']->getColumnLink('delete', '<i class="rex-icon rex-icon-delete"></i> '.\rex_i18n::msg('delete')), ['rex-table-action']);
    }
}

// Wenn der Platzhalter vom Admin geändert wird, muss dieser in den anderen Sprachen synchronisiert werden
if (rex::getUser()->getComplexPerm('clang')->hasAll() && count(rex_clang::getAll()) >= 2) {
    \rex_extension::register('REX_FORM_SAVED', function (rex_extension_point $ep) use ($pid, $clang_id) {
        $form = $ep->getParam('form');
        if ($form->isEditMode()) {
            $items = rex_sql::factory()->getArray('SELECT `id`, `wildcard` FROM '.$form->getTablename().' WHERE `pid` = :pid LIMIT 2', ['pid' => $pid]);
            if (count($items) == 1) {
                $savedId = $items[0]['id'];
                $savedWildcard = $items[0]['wildcard'];
                $sql = rex_sql::factory();
                $sql->setTable($form->getTablename());
                $sql->setWhere('pid != :pid AND id = :id', ['pid' => $pid, 'id' => $savedId]);
                $sql->setValue('wildcard', $savedWildcard);
                $sql->update();
            }
        }
    });
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

$search_term = rex_request('search-term', 'string', '');
if ($func == '') {
    $title = $this->i18n('wildcard_caption');

    $sqlWhere = '';
    if (strlen($search_term)) {
        $sqlWhere = ' AND (`wildcard` LIKE "%'.$search_term.'%" OR `replace` LIKE "%'.$search_term.'%")';
    }

    $list = rex_list::factory('SELECT `pid`, `id`, `wildcard`, `replace` FROM '.rex::getTable('sprog_wildcard').' WHERE `clang_id`="'.$clang_id.'"'.$sqlWhere.' ORDER BY `wildcard`');
    $list->addParam('search-term', $search_term);
    $list->addTableAttribute('class', 'table-striped table-hover');

    $tdIcon = '<i class="rex-icon rex-icon-refresh"></i>';
    $thIcon = rex::getUser()->getComplexPerm('clang')->hasAll() ? '<a href="'.$list->getUrl(['func' => 'add']).'#wildcard"'.rex::getAccesskey($this->i18n('add'), 'add').'><i class="rex-icon rex-icon-add-article"></i></a>' : '';

    $list->addColumn($thIcon, $tdIcon, 0, ['<th class="rex-table-icon">###VALUE###</th>', '###VALUE###']);
    $list->setColumnParams($thIcon, ['func' => 'edit', 'pid' => '###pid###']);
    $list->setColumnFormat($thIcon, 'custom', 'sprogStyleTdPid');

    $list->removeColumn('pid');

    $list->setColumnLabel('id', $this->i18n('id'));
    $list->setColumnLayout('id', ['<th class="rex-table-id">###VALUE###</th>', '###VALUE###']);
    $list->setColumnFormat('id', 'custom', 'sprogStyleTd', ['classes' => ['rex-table-id']]);

    $list->setColumnLabel('wildcard', $this->i18n('wildcard'));
    $list->setColumnLayout('wildcard', ['<th>###VALUE###</th>', '###VALUE###']);
    $list->setColumnFormat('wildcard', 'custom', 'sprogStyleTd');

    $list->setColumnLabel('replace', $this->i18n('wildcard_replace'));
    $list->setColumnLayout('replace', ['<th>###VALUE###</th>', '###VALUE###']);
    $list->setColumnFormat('replace', 'custom', 'sprogStyleTd');

    $list->addColumn('edit', '<i class="rex-icon rex-icon-edit"></i> '.$this->i18n('edit'));
    $list->setColumnLabel('edit', $this->i18n('function'));
    $list->setColumnLayout('edit', ['<th class="rex-table-action" colspan="2">###VALUE###</th>', '###VALUE###']);
    $list->setColumnParams('edit', ['func' => 'edit', 'pid' => '###pid###']);
    $list->setColumnFormat('edit', 'custom', 'sprogStyleTdEdit');

    if (rex::getUser()->getComplexPerm('clang')->hasAll()) {
        $list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> '.$this->i18n('delete'));
        $list->setColumnLabel('delete', $this->i18n('function'));
        $list->setColumnLayout('delete', ['', '###VALUE###']);
        $list->setColumnParams('delete', ['func' => 'delete', 'wildcard_id' => '###id###'] + $csrfToken->getUrlParams());
        $list->addLinkAttribute('delete', 'data-confirm', $this->i18n('delete').' ?');
        $list->setColumnFormat('delete', 'custom', 'sprogStyleTdDelete');
    } else {
        $list->addColumn('delete', '');
        $list->setColumnLayout('delete', ['', '<td class="rex-table-action"></td>']);
        $list->setColumnFormat('delete', 'custom', 'sprogStyleTd');
    }

    $content .= $list->get();

    $searchControl = '<form action="'.\rex_url::currentBackendPage().'" method="post" class="form-inline"><div class="input-group input-group-xs"><div class="input-group-btn"><a href="'.rex_url::currentBackendPage().'" class="btn btn-default btn-xs"><i class="rex-icon rex-icon-clear"></i></a></div><input class="form-control" style="height: 24px; padding-top: 3px; padding-bottom: 3px; font-size: 12px; line-height: 1;" type="text" name="search-term" value="'.htmlspecialchars($search_term).'" /><div class="input-group-btn"><button type="submit" class="btn btn-primary btn-xs">'.$this->i18n('search').'</button></div></div></form>';

    $fragment = new rex_fragment();
    $fragment->setVar('title', $title);
    $fragment->setVar('content', $content, false);
    $fragment->setVar('options', $searchControl, false);
    $content = $fragment->parse('core/page/section.php');
} else {
    $title = $func == 'edit' ? $this->i18n('edit') : $this->i18n('add');

    \rex_extension::register('REX_FORM_CONTROL_FIELDS', '\Sprog\Extension::wildcardFormControlElement');

    $form = rex_form::factory(rex::getTable('sprog_wildcard'), '', 'pid = '.$pid);
    $form->setApplyUrl(rex_url::currentBackendPage(['search-term' => $search_term], false));
    $form->addParam('pid', $pid);
    $form->addParam('search-term', $search_term);
    $form->setLanguageSupport('id', 'clang_id');
    $form->setEditMode($func == 'edit');

    if (rex::getUser()->getComplexPerm('clang')->hasAll()) {
        $field = $form->addTextField('wildcard', rex_request('wildcard_name', 'string', null));
        $field->setNotice($this->i18n('wildcard_without_tag'));
    } else {
        $field = $form->addReadOnlyField('wildcard', rex_request('wildcard_name', 'string', null));
    }
    $field->setLabel($this->i18n('wildcard'));
    $field->getValidator()->add('notEmpty', $this->i18n('wildcard_error_no_wildcard'));

    $field = $form->addTextAreaField('replace');
    $field->setLabel($this->i18n('wildcard_replace'));

    $content .= $form->get();

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', $title);
    $fragment->setVar('body', $content, false);
    $content = $fragment->parse('core/page/section.php');
}

if ($success != '') {
    $message .= rex_view::success($success);
}

if ($error != '') {
    $message .= rex_view::error($error);
}

echo $message;
echo $content;

if (rex::getUser()->getComplexPerm('clang')->hasAll()) {
    echo Wildcard::getMissingWildcardsAsTable();
}
