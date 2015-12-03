<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog;

$content = '';

$func = rex_request('func', 'string');

if ($func == 'update') {
    // clang_switch wird in der boot.php neu gesetzt
    echo \rex_view::info($this->i18n('settings_config_saved'));
    \rex_config::set('sprog', 'wildcard_open_tag', rex_request('open_tag', 'string'));
    \rex_config::set('sprog', 'wildcard_close_tag', rex_request('close_tag', 'string'));
}

$content .= '
    <fieldset>
        <input type="hidden" name="func" value="update" />
';

        $formElements = [];
        $n = [];
        $n['label'] = '<label for="wildcard-open-tag">' . $this->i18n('settings_wildcard_open_tag') . '</label>';
        $n['field'] = '<input class="form-control" type="text" id="wildcard-open-tag" name="open_tag" value="' . htmlspecialchars(Wildcard::getOpenTag()) . '" />';
        $formElements[] = $n;

        $n = [];
        $n['label'] = '<label for="wildcard-close-tag">' . $this->i18n('settings_wildcard_close_tag') . '</label>';
        $n['field'] = '<input class="form-control" type="text" id="wildcard-close-tag" name="close_tag" value="' . htmlspecialchars(Wildcard::getCloseTag()) . '" />';
        $formElements[] = $n;

        $clangSwitchSelect = new \rex_select();
        $clangSwitchSelect->setName('clang_switch');
        $clangSwitchSelect->setSize(1);
        $clangSwitchSelect->setAttribute('class', 'form-control');
        $clangSwitchSelect->setAttribute('id', 'wildcard-clang-switch');
        for ($i = 1; $i <= \rex_clang::count(); ++$i) {
            $clangSwitchSelect->addOption($i, $i);
        }
        $clangSwitchSelect->setSelected(\rex_config::get('sprog', 'wildcard_clang_switch', \rex_clang::count()));

        $n = [];
        $n['label'] = '<label for="wildcard-clang-switch">' . $this->i18n('settings_wildcard_clang_switch') . '</label>';
        $n['field'] = $clangSwitchSelect->get();
        $formElements[] = $n;

        $fragment = new \rex_fragment();
        $fragment->setVar('flush', true);
        $fragment->setVar('elements', $formElements, false);
        $content .= $fragment->parse('core/form/form.php');

        $formElements = [];

        $n = [];
        $n['field'] = '<a class="btn btn-abort" href="' . \rex_url::currentBackendPage() . '">' . \rex_i18n::msg('form_abort') . '</a>';
        $formElements[] = $n;

        $n = [];
        $n['field'] = '<button class="btn btn-apply rex-form-aligned" type="submit" name="send" value="1"' . \rex::getAccesskey(\rex_i18n::msg('update'), 'apply') . '>' . \rex_i18n::msg('update') . '</button>';
        $formElements[] = $n;

        $fragment = new \rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $buttons = $fragment->parse('core/form/submit.php');

$content .= '
    </fieldset>';

$fragment = new \rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $this->i18n('settings'), false);
$fragment->setVar('body', $content, false);
$fragment->setVar('buttons', $buttons, false);
$content = $fragment->parse('core/page/section.php');

$content = '
    <form action="' . \rex_url::currentBackendPage() . '" method="post">
        ' . $content . '
    </form>

    ';

echo $content;
