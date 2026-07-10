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

use Sprog\Enum\Status;
use Sprog\Model\Unit;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Sprog\Service\TranslationService;
use Symfony\Component\Serializer\Encoder\CsvEncoder;

$csrf = rex_csrf_token::factory('sprog_artefact_import');
$messages = [];

/*
 |-----------------------------------------------------------------------------
 | Import: CSV im Export-Format → Inbox. Units per (namespace, context, key)
 | matchen; fehlende anlegen. Werte über den normalen updateValue-Pfad setzen
 | (origin=import, Auto-Status wie beim Inbox-Edit). Freigegebene bleiben
 | unangetastet, leere Zellen werden übersprungen.
 |-----------------------------------------------------------------------------
 */
if ('post' === rex_request::requestMethod()) {
    $file = rex_files('import_file');

    if (!$csrf->isValid()) {
        $messages[] = rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif (!is_array($file) || empty($file['tmp_name'])) {
        $messages[] = rex_view::error($this->i18n('import_no_file'));
    } else {
        $records = (new CsvEncoder())->decode(rex_file::get($file['tmp_name']), 'csv', [CsvEncoder::DELIMITER_KEY => ';']);
        if (!is_array($records) || !isset($records[0])) {
            $records = [];
        }

        $clangByCode = [];
        foreach (rex_clang::getAll() as $clang) {
            $clangByCode[strtolower($clang->getCode())] = $clang->getId();
        }
        $reserved = ['namespace', 'context', 'key', 'notes'];

        $unitRepo = new UnitRepository();
        $translationRepo = new TranslationRepository();
        $service = TranslationService::create();
        $userId = null !== rex::getUser() ? (int) rex::getUser()->getId() : null;

        $unitsCreated = 0;
        $valuesSet = 0;
        $skippedApproved = 0;
        $skippedEmpty = 0;
        $errors = 0;
        $ignoredColumns = [];

        foreach ($records as $record) {
            // Header-Keys normalisieren (BOM aus dem Export + Whitespace).
            $row = [];
            foreach ($record as $key => $value) {
                $row[strtolower(trim(str_replace("\u{FEFF}", '', (string) $key)))] = $value;
            }

            $namespace = trim((string) ($row['namespace'] ?? ''));
            $unitKey = trim((string) ($row['key'] ?? ''));
            $context = trim((string) ($row['context'] ?? ''));
            $notes = (string) ($row['notes'] ?? '');

            if ('' === $namespace || '' === $unitKey) {
                continue;
            }

            $unit = $unitRepo->findByKey($namespace, $unitKey, $context);
            if (null === $unit) {
                $unit = $unitRepo->save(new Unit(null, $namespace, $unitKey, $context, notes: '' !== $notes ? $notes : null));
                ++$unitsCreated;
            }

            foreach ($row as $column => $value) {
                if (in_array($column, $reserved, true)) {
                    continue;
                }
                if (!isset($clangByCode[$column])) {
                    $ignoredColumns[$column] = $column;
                    continue;
                }

                $value = (string) $value;
                if ('' === trim($value)) {
                    ++$skippedEmpty;
                    continue;
                }

                $clangId = $clangByCode[$column];
                $current = $translationRepo->findForUnitAndClang((int) $unit->id, $clangId);
                if (null !== $current && Status::Approved === $current->status) {
                    ++$skippedApproved;
                    continue;
                }

                try {
                    $service->updateValue($unit, $clangId, $value, $userId, origin: 'import');
                    ++$valuesSet;
                } catch (Throwable) {
                    ++$errors;
                }
            }
        }

        foreach ($ignoredColumns as $column) {
            $messages[] = rex_view::warning($this->i18n('import_language_ignored', $column));
        }
        $messages[] = rex_view::success($this->i18n('import_result', $unitsCreated, $valuesSet, $skippedApproved, $skippedEmpty));
        if ($errors > 0) {
            $messages[] = rex_view::warning($this->i18n('import_errors', $errors));
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
