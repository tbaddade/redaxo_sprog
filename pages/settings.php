<?php

/**
 * This file is part of the Wildcard package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Wildcard;

use Wildcard\Wildcard;

$content = '';

$func = rex_request('func', 'string');
$openTag = rex_request('open_tag', 'string');
$closeTag = rex_request('close_tag', 'string');

if ($func == 'update') {
    echo \rex_view::info($this->i18n('config_saved'));
    \rex_config::set('wildcard', 'open_tag', $openTag);
    \rex_config::set('wildcard', 'close_tag', $closeTag);
}

$content .= '
    <fieldset>
        <input type="hidden" name="func" value="update" />
';

        $formElements = [];
        $n = [];
        $n['label'] = '<label for="wildcard-open-tag">' . $this->i18n('open_tag') . '</label>';
        $n['field'] = '<input class="form-control" type="text" id="wildcard-open-tag" name="open_tag" value="' . htmlspecialchars(Wildcard::getOpenTag()) . '" />';
        $formElements[] = $n;

        $n = [];
        $n['label'] = '<label for="wildcard-close-tag">' . $this->i18n('close_tag') . '</label>';
        $n['field'] = '<input class="form-control" type="text" id="wildcard-close-tag" name="close_tag" value="' . htmlspecialchars(Wildcard::getCloseTag()) . '" />';
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
$fragment->setVar('title', $this->i18n('config'), false);
$fragment->setVar('body', $content, false);
$fragment->setVar('buttons', $buttons, false);
$content = $fragment->parse('core/page/section.php');

$content = '
    <form action="' . \rex_url::currentBackendPage() . '" method="post">
        ' . $content . '
    </form>

    ';

echo $content;
