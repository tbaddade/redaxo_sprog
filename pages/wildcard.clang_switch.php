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
$pid = rex_request('pid', 'int');
$func = rex_request('func', 'string');
$clang_id = (int)str_replace('clang', '', rex_be_controller::getCurrentPagePart(3));


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

if ($func == '') {
    $title = rex_i18n::msg('wildcard_caption');

    $list = rex_list::factory('SELECT `pid`, `id`, `wildcard`, `replace` FROM ' . rex::getTable('wildcard') . ' WHERE `clang_id`="' . $clang_id . '" ORDER BY wildcard');
    $list->addTableAttribute('class', 'table-striped');

    $tdIcon = '<i class="rex-icon rex-icon-refresh"></i>';
    $thIcon = '<a href="' . $list->getUrl(['func' => 'add']) . '#wildcard"' . rex::getAccesskey($this->i18n('add'), 'add') . '><i class="rex-icon rex-icon-add-article"></i></a>';
    $list->addColumn($thIcon, $tdIcon, 0, ['<th class="rex-table-icon">###VALUE###</th>', '<td class="rex-table-icon">###VALUE###</td>']);
    $list->setColumnParams($thIcon, ['func' => 'edit', 'pid' => '###pid###']);

    $list->removeColumn('pid');

    $list->setColumnLabel('id', rex_i18n::msg('id'));
    $list->setColumnLayout('id', ['<th class="rex-table-id">###VALUE###</th>', '<td class="rex-table-id">###VALUE###</td>']);

    $list->setColumnLabel('wildcard', rex_i18n::msg('wildcard'));
    $list->setColumnLabel('replace', $this->i18n('replace'));

    $list->addColumn('edit', '<i class="rex-icon rex-icon-edit"></i> ' . rex_i18n::msg('edit'));
    $list->setColumnLabel('edit', $this->i18n('function'));
    $list->setColumnLayout('edit', ['<th class="rex-table-action" colspan="2">###VALUE###</th>', '<td class="rex-table-action">###VALUE###</td>']);
    $list->setColumnParams('edit', ['func' => 'edit', 'pid' => '###pid###']);

    $list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> ' . rex_i18n::msg('delete'));
    $list->setColumnLabel('delete', $this->i18n('function'));
    $list->setColumnLayout('delete', ['', '<td class="rex-table-action">###VALUE###</td>']);
    $list->setColumnParams('delete', ['func' => 'delete', 'pid' => '###pid###']);
    $list->addLinkAttribute('delete', 'data-confirm', rex_i18n::msg('delete') . ' ?');

    $content .= $list->get();

    $fragment = new rex_fragment();
    $fragment->setVar('title', $title);
    $fragment->setVar('content', $content, false);
    $content = $fragment->parse('core/page/section.php');
} else {
    $title = $func == 'edit' ? $this->i18n('edit') : $this->i18n('add');

    $form = rex_form::factory(rex::getTable('wildcard'), '', 'pid = ' . $pid);
    $form->addParam('pid', $pid);
    $form->setApplyUrl(rex_url::currentBackendPage());
    $form->setLanguageSupport('id', 'clang_id');
    $form->setEditMode($func == 'edit');

    $field = $form->addTextField('wildcard', rex_request('wildcard_name', 'string'));
    $field->setLabel(rex_i18n::msg('wildcard'));
    $field->getValidator()->add('notEmpty', $this->i18n('error_no_wildcard'));

    $field = $form->addTextAreaField('replace');
    $field->setLabel(rex_i18n::msg('wildcard_replace'));

    $content .= $form->get();

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', $title);
    $fragment->setVar('body', $content, false);
    $content = $fragment->parse('core/page/section.php');
}

echo $message;
echo $content;

$missingWildcards = \Wildcard\Wildcard::getMissingWildcards();

if (count($missingWildcards)) {
    $content = '';
    $content .= '
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th class="rex-table-icon"></th>
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
                        <td class="rex-table-action"><a href="' . rex_url::currentBackendPage(['func' => 'add', 'wildcard_name' => $params['wildcard']]) . '"><i class="rex-icon rex-icon-edit"></i> ' . $this->i18n('function_add') . '</a></td>
                        <td class="rex-table-action"><a href="' . $params['url'] . '"><i class="rex-icon rex-icon-article"></i> ' . $this->i18n('go_to_the_article') . '</a></td>
                    </tr>';
    }

    $content .= '
            </tbody>
        </table>';

    $fragment = new rex_fragment();
    $fragment->setVar('title', $this->i18n('caption_missing', rex_i18n::msg('title_structure')), false);
    $fragment->setVar('content', $content, false);
    $content = $fragment->parse('core/page/section.php');

    echo $content;
}
