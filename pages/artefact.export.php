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
use Sprog\Support\Labels;

$csrf = rex_csrf_token::factory('sprog_artefact_export');

/*
 |-----------------------------------------------------------------------------
 | Export: alle Inbox-Einheiten als CSV (eine Zeile pro Unit, je Sprache eine
 | Spalte). Format: namespace; context; key; notes; <sprachcode> …
 |-----------------------------------------------------------------------------
 */
if ('export' === rex_request('func', 'string', '')) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        rex_response::cleanOutputBuffers();

        $namespace = rex_request('namespace', 'string', '');
        $sql = rex_sql::factory();

        $where = '';
        $params = [];
        if ('' !== $namespace) {
            $where = ' WHERE `namespace` = :ns';
            $params['ns'] = $namespace;
        }
        $units = $sql->getArray(
            'SELECT `id`, `namespace`, `context`, `unit_key`, `notes` FROM ' . rex::getTable('sprog_unit') . $where . ' ORDER BY `namespace`, `context`, `unit_key`',
            $params,
        );

        $translations = [];
        foreach ($sql->getArray('SELECT `unit_id`, `clang_id`, `value` FROM ' . rex::getTable('sprog_translation')) as $row) {
            $translations[(int) $row['unit_id']][(int) $row['clang_id']] = (string) $row['value'];
        }

        $clangs = rex_clang::getAll();

        $csv = new CsvExport();
        $header = ['namespace', 'context', 'key', 'notes'];
        foreach ($clangs as $clang) {
            $header[] = $clang->getCode();
        }
        $csv->addHeaders($header);

        foreach ($units as $unit) {
            $record = [
                (string) $unit['namespace'],
                (string) $unit['context'],
                (string) $unit['unit_key'],
                (string) ($unit['notes'] ?? ''),
            ];
            foreach ($clangs as $clang) {
                $record[] = str_replace("\r", '', $translations[(int) $unit['id']][$clang->getId()] ?? '');
            }
            $csv->addItem($record);
        }

        $csv->sendFile('sprog-inbox-' . date('Ymd-His') . '.csv');
    }
}

$namespaces = rex_sql::factory()->getArray('SELECT DISTINCT `namespace` FROM ' . rex::getTable('sprog_unit') . ' ORDER BY `namespace`');

$nsSelect = new rex_select();
$nsSelect->setId('sprog-export-namespace');
$nsSelect->setName('namespace');
$nsSelect->setAttribute('class', 'sprog-control');
$nsSelect->addOption($this->i18n('export_all'), '');
foreach ($namespaces as $ns) {
    $nsSelect->addOption(Labels::forNamespace((string) $ns['namespace']), (string) $ns['namespace']);
}
?>
<article class="sprog-ui sprog-copy">
    <header class="sprog-intro">
        <h1 class="sprog-heading"><?= rex_escape($this->i18n('export_heading')) ?></h1>
        <p class="sprog-lead"><?= rex_escape($this->i18n('export_lead')) ?></p>
    </header>

    <section class="sprog-panel sprog-copy--panel">
        <form class="sprog-copy--form" method="post" action="<?= rex_url::currentBackendPage() ?>">
            <?= $csrf->getHiddenField() ?>
            <input type="hidden" name="func" value="export">
            <label class="sprog-field">
                <span class="sprog-field--label"><?= rex_escape($this->i18n('export_namespace')) ?></span>
                <?= $nsSelect->get() ?>
            </label>
            <div class="sprog-copy--actions">
                <button type="submit" class="sprog-btn sprog-btn--primary"><?= rex_escape($this->i18n('export_button')) ?></button>
            </div>
        </form>
    </section>
</article>
