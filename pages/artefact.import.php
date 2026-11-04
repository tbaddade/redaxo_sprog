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

use Sprog\Service\CsvImportService;

$csrf = rex_csrf_token::factory('sprog_artefact_import');
$messages = [];

/*
 |-----------------------------------------------------------------------------
 | Import: CSV im Export-Format → Inbox. Die Logik liegt im CsvImportService
 | (auch per Konsole `sprog:import` und aus anderen AddOns nutzbar).
 |-----------------------------------------------------------------------------
 */
if ('post' === rex_request::requestMethod()) {
    $file = rex_files('import_file');

    if (!$csrf->isValid()) {
        $messages[] = rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif (!is_array($file) || empty($file['tmp_name'])) {
        $messages[] = rex_view::error($this->i18n('import_no_file'));
    } else {
        $userId = null !== rex::getUser() ? (int) rex::getUser()->getId() : null;

        try {
            $result = CsvImportService::create()->importFile((string) $file['tmp_name'], userId: $userId);

            foreach ($result->ignoredColumns as $column) {
                $messages[] = rex_view::warning($this->i18n('import_language_ignored', $column));
            }
            $messages[] = rex_view::success($this->i18n('import_result', $result->unitsCreated, $result->valuesSet, $result->skippedUnchanged, $result->skippedApproved, $result->skippedEmpty));
            if ([] !== $result->errors) {
                $items = '';
                foreach ($result->errors as $error) {
                    $items .= '<li>' . rex_escape(rex_i18n::rawMsg('sprog_import_error_row', $error['row'], $error['message'])) . '</li>';
                }
                $messages[] = rex_view::warning($this->i18n('import_errors', count($result->errors)) . '<ul>' . $items . '</ul>');
            }
        } catch (Throwable $e) {
            $messages[] = rex_view::error(rex_escape($e->getMessage()));
        }
    }
}

echo implode('', $messages);
?>
<article class="sprog-ui sprog-copy">
    <header class="sprog-intro">
        <h1 class="sprog-heading"><?= rex_escape($this->i18n('import_heading')) ?></h1>
        <p class="sprog-lead"><?= rex_escape($this->i18n('import_lead')) ?></p>
    </header>

    <section class="sprog-panel sprog-copy--panel">
        <form class="sprog-copy--form" method="post" action="<?= rex_url::currentBackendPage() ?>" enctype="multipart/form-data" data-pjax="false">
            <?= $csrf->getHiddenField() ?>
            <label class="sprog-field">
                <span class="sprog-field--label"><?= rex_escape($this->i18n('import_file')) ?></span>
                <input type="file" name="import_file" accept=".csv,text/csv" class="sprog-control">
            </label>
            <p class="sprog-hint"><?= rex_escape($this->i18n('import_format_hint')) ?></p>
            <div class="sprog-copy--actions">
                <button type="submit" class="sprog-btn sprog-btn--primary"><?= rex_escape($this->i18n('import_button')) ?></button>
            </div>
        </form>
    </section>
</article>
