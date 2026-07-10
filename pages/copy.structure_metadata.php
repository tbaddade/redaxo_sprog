<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Sprog\Copy\StructureMetadata;

$csrf = rex_csrf_token::factory('sprog_copy_metadata');
$func = rex_request('func', 'string', '');

/*
 |-----------------------------------------------------------------------------
 | JSON-Endpoints für den fetch-Chunk-Runner (assets/js/sprog.copy.js)
 |-----------------------------------------------------------------------------
 | prepare: ermittelt die Artikel-Liste; chunk: kopiert die gewählten Felder
 | der IDs von clangFrom → clangTo (überschreibend, kein „vorher löschen").
 */
if ('prepare' === $func || 'chunk' === $func) {
    rex_response::cleanOutputBuffers();

    if (!$csrf->isValid()) {
        rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
        rex_response::sendJson(['success' => false, 'error' => rex_i18n::msg('csrf_token_invalid')]);
        exit;
    }

    $clangFrom = rex_request('clangFrom', 'int', 0);
    $clangTo = rex_request('clangTo', 'int', 0);
    $fields = rex_request('fields', 'array', []);

    try {
        if (0 === $clangTo || $clangFrom === $clangTo) {
            throw new rex_exception($this->i18n('sprog_copy_error_clang'));
        }
        if (0 === count($fields)) {
            throw new rex_exception($this->i18n('sprog_copy_error_no_fields'));
        }

        if ('prepare' === $func) {
            $items = StructureMetadata::getArticleIds();
            rex_response::sendJson(['success' => true, 'total' => count($items), 'items' => array_values($items)]);
            exit;
        }

        // chunk
        $startClang = rex_clang::getStartId();
        $ids = array_values(array_filter(array_map('intval', explode(',', rex_request('ids', 'string', '')))));
        $items = array_map(static fn (int $id): array => [$id, $startClang], $ids);
        StructureMetadata::fire($items, [
            'clangFrom' => $clangFrom,
            'clangTo' => $clangTo,
            'fields' => implode(',', array_map('strval', $fields)),
        ]);
        rex_response::sendJson(['success' => true, 'processed' => count($items)]);
        exit;
    } catch (Throwable $e) {
        rex_response::setStatus(rex_response::HTTP_INTERNAL_ERROR);
        rex_response::sendJson(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/*
 |-----------------------------------------------------------------------------
 | HTML
 |-----------------------------------------------------------------------------
 */
$clangOptions = [];
foreach (rex_clang::getAll() as $clang) {
    $clangOptions[$clang->getId()] = $clang->getName();
}

$fromSelect = new rex_select();
$fromSelect->setId('sprog-copy-meta-clang-from');
$fromSelect->setName('clangFrom');
$fromSelect->setAttribute('class', 'sprog-control');
$fromSelect->addArrayOptions($clangOptions);
$fromSelect->setSelected(rex_clang::getStartId());

$toSelect = new rex_select();
$toSelect->setId('sprog-copy-meta-clang-to');
$toSelect->setName('clangTo');
$toSelect->setAttribute('class', 'sprog-control');
$toSelect->addOption('–', 0);
$toSelect->addArrayOptions($clangOptions);

// Feld-Gruppen: Struktur (feste Spalten) + Kategorie-/Artikel-MetaInfo.
$metaQuery = 'SELECT `title`, `name` FROM ' . rex::getTable('metainfo_field') . ' WHERE `name` LIKE :name AND `type_id` != :type_id ORDER BY name';
$catOptions = rex_sql::factory()->getArray($metaQuery, ['name' => 'cat_%', 'type_id' => '12']);
$artOptions = rex_sql::factory()->getArray($metaQuery, ['name' => 'art_%', 'type_id' => '12']);

$renderCheck = static function (string $value, string $label): string {
    return '<label class="sprog-settings--check">'
        . '<input type="checkbox" name="fields[]" value="' . rex_escape($value) . '">'
        . '<span>' . rex_escape($label) . '</span>'
        . '</label>';
};

$structureChecks = '';
foreach (['catname', 'catpriority', 'name', 'priority', 'status', 'template_id'] as $col) {
    $structureChecks .= $renderCheck($col, $col . '  ·  ' . $this->i18n('copy_' . $col));
}

$catChecks = '';
foreach ($catOptions as $o) {
    $catChecks .= $renderCheck($o['name'], $o['name'] . '  ·  ' . rex_i18n::translate($o['title']));
}

$artChecks = '';
foreach ($artOptions as $o) {
    $artChecks .= $renderCheck($o['name'], $o['name'] . '  ·  ' . rex_i18n::translate($o['title']));
}

$chunkSize = (int) $this->getConfig('chunk_size_articles') ?: 4;
?>
<article class="sprog-ui sprog-copy" data-sprog-copy>
    <header class="sprog-intro">
        <h1 class="sprog-heading"><?= rex_escape($this->i18n('copy_structure_metadata')) ?></h1>
        <p class="sprog-lead"><?= rex_escape($this->i18n('sprog_copy_metadata_lead')) ?></p>
    </header>

    <section class="sprog-panel sprog-copy--panel">
        <form class="sprog-copy--form" data-role="form" onsubmit="return false">
            <div class="sprog-copy--grid">
                <label class="sprog-field">
                    <span class="sprog-field--label"><?= rex_escape($this->i18n('copy_clang_from')) ?></span>
                    <?= $fromSelect->get() ?>
                </label>
                <label class="sprog-field">
                    <span class="sprog-field--label"><?= rex_escape($this->i18n('copy_clang_to')) ?></span>
                    <?= $toSelect->get() ?>
                </label>
            </div>

            <div class="sprog-field">
                <span class="sprog-field--label"><?= rex_escape($this->i18n('copy_structure_metadata_fields')) ?></span>
                <div class="sprog-copy--fields">
                    <div class="sprog-settings--subhead"><span class="sprog-settings--subhead-title"><?= rex_escape($this->i18n('copy_structure_metadata_structure')) ?></span></div>
                    <div class="sprog-settings--checks"><?= $structureChecks ?></div>
                    <?php if ('' !== $catChecks) : ?>
                        <div class="sprog-settings--subhead"><span class="sprog-settings--subhead-title"><?= rex_escape($this->i18n('copy_structure_metadata_categories')) ?></span></div>
                        <div class="sprog-settings--checks"><?= $catChecks ?></div>
                    <?php endif ?>
                    <?php if ('' !== $artChecks) : ?>
                        <div class="sprog-settings--subhead"><span class="sprog-settings--subhead-title"><?= rex_escape($this->i18n('copy_structure_metadata_articles')) ?></span></div>
                        <div class="sprog-settings--checks"><?= $artChecks ?></div>
                    <?php endif ?>
                </div>
            </div>

            <div class="sprog-copy--actions">
                <button type="button" class="sprog-btn sprog-btn--primary" data-role="run">
                    <?= rex_escape($this->i18n('sprog_copy_button_start')) ?>
                </button>
                <span class="sprog-copy--status" data-role="status" role="status" aria-live="polite"></span>
            </div>

            <div class="sprog-copy--progress" data-role="progress" hidden>
                <progress class="sprog-copy--bar" data-role="bar" max="1" value="0"></progress>
                <output class="sprog-copy--counter" data-role="counter">0 / 0</output>
            </div>

            <p class="sprog-note sprog-note--warning sprog-copy--error" data-role="error" hidden></p>
        </form>
    </section>
</article>

<script nonce="<?= rex_response::getNonce() ?>">
window.sprogCopy = {
    csrf:      { name: <?= json_encode(rex_csrf_token::PARAM, JSON_THROW_ON_ERROR) ?>, value: <?= json_encode($csrf->getValue(), JSON_THROW_ON_ERROR) ?> },
    endpoint:  <?= json_encode(rex_url::currentBackendPage(), JSON_THROW_ON_ERROR) ?>,
    chunkSize: <?= json_encode($chunkSize, JSON_THROW_ON_ERROR) ?>,
    strings: {
        running:     <?= json_encode(rex_i18n::rawMsg('sprog_copy_running'), JSON_THROW_ON_ERROR) ?>,
        done:        <?= json_encode(rex_i18n::rawMsg('sprog_copy_done'), JSON_THROW_ON_ERROR) ?>,
        failed:      <?= json_encode(rex_i18n::rawMsg('sprog_copy_failed'), JSON_THROW_ON_ERROR) ?>,
        serverError: <?= json_encode(rex_i18n::rawMsg('sprog_copy_ajax_server_error'), JSON_THROW_ON_ERROR) ?>,
        badResponse: <?= json_encode(rex_i18n::rawMsg('sprog_copy_ajax_bad_response'), JSON_THROW_ON_ERROR) ?>
    }
};
</script>
