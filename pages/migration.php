<?php

declare(strict_types=1);

use Sprog\Service\MigrationService;
use Sprog\Support\Labels;

$user = rex::getUser();
if (null === $user || !$user->isAdmin()) {
    throw new rex_exception('Zugriff verweigert.');
}

$csrf = rex_csrf_token::factory('sprog_migration');
$service = MigrationService::create();
$func = rex_request('func', 'string', '');

/*
 |---------------------------------------------------------------------------
 | JSON-Endpoint: ein Chunk verarbeiten
 |---------------------------------------------------------------------------
 | Wird vom Frontend per fetch() aufgerufen. CSRF-Token ist Pflicht;
 | jeder andere Pfad ist eine Fehlfunktion.
 */
if ('chunk' === $func) {
    rex_response::cleanOutputBuffers();

    if (!$csrf->isValid()) {
        rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
        rex_response::sendJson([
            'success' => false,
            'error' => rex_i18n::rawMsg('sprog_migration_ajax_csrf'),
        ]);
        exit;
    }

    $source = (string) rex_request('source', 'string', '');
    $chunkSize = (int) rex_request('chunk_size', 'int', 50);

    try {
        $progress = $service->runChunk($source, $chunkSize, $user->getId());
        rex_response::sendJson([
            'success' => true,
            'progress' => $progress->toArray(),
            'completed' => $progress->isCompleted(),
        ]);
    } catch (Throwable $e) {
        rex_response::setStatus(rex_response::HTTP_INTERNAL_ERROR);
        rex_response::sendJson([
            'success' => false,
            // Nur die Message, kein Stack-Trace — der wandert ins Activity-Log.
            'error' => $e->getMessage(),
        ]);
    }
    exit;
}

/*
 |---------------------------------------------------------------------------
 | POST: Status zurücksetzen (initialisiert alle Migratoren neu)
 |---------------------------------------------------------------------------
 */
$flashMessage = '';
if ('reset' === $func) {
    if (!$csrf->isValid()) {
        $flashMessage = rex_view::error(rex_i18n::msg('sprog_migration_csrf_invalid'));
    } else {
        try {
            $service->reset($user->getId());
            $flashMessage = rex_view::success(rex_i18n::msg('sprog_migration_reset_success'));
        } catch (Throwable $e) {
            $flashMessage = rex_view::error(rex_i18n::msg('sprog_migration_reset_error', $e->getMessage()));
        }
    }
}

/*
 |---------------------------------------------------------------------------
 | HTML-Render
 |---------------------------------------------------------------------------
 */
$state = $service->state();
$migrators = $service->migrators();
$availableNames = [];
foreach ($migrators as $name => $migrator) {
    if ($migrator->isAvailable()) {
        $availableNames[] = $name;
    }
}

// Initial-State für die JS-Schicht: pro verfügbarer Quelle das aktuelle
// Progress-Snapshot, oder eine pending-Fassung mit live ermitteltem total.
$jsState = [];
foreach ($availableNames as $name) {
    $progress = $state->for($name);
    if (null === $progress) {
        $jsState[$name] = [
            'source' => $name,
            'total_rows' => $migrators[$name]->totalCount(),
            'processed_rows' => 0,
            'last_processed_id' => null,
            'last_error' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    } else {
        $jsState[$name] = $progress->toArray();
    }
}

echo $flashMessage;

?>
<article class="sprog-migration" data-sprog-migration>
    <header class="sprog-migration--intro">
        <h1 class="sprog-migration--heading"><?= rex_i18n::msg('sprog_migration_heading') ?></h1>
        <p class="sprog-migration--lead"><?= rex_i18n::rawMsg('sprog_migration_lead') ?></p>
    </header>

    <?php if ([] === $availableNames) : ?>
        <p class="sprog-migration--empty"><?= rex_i18n::msg('sprog_migration_empty') ?></p>
    <?php else : ?>
        <ul class="sprog-migration--list" role="list">
            <?php foreach ($availableNames as $name) :
                $p = $jsState[$name];
                $isCompleted = null !== $p['completed_at'];
                $hasError = null !== $p['last_error'] && '' !== $p['last_error'];
                $stateClass = $isCompleted
                    ? 'is-completed'
                    : ($hasError ? 'has-error' : 'is-idle');

                $badgeKey = $isCompleted
                    ? 'sprog_migration_badge_completed'
                    : ($hasError ? 'sprog_migration_badge_error' : 'sprog_migration_badge_ready');

                $buttonKey = $isCompleted
                    ? 'sprog_migration_button_done'
                    : ($hasError ? 'sprog_migration_button_retry' : 'sprog_migration_button_start');
            ?>
                <li
                    class="sprog-migration--item <?= rex_escape($stateClass) ?>"
                    data-source="<?= rex_escape($name) ?>"
                    data-total="<?= rex_escape((string) $p['total_rows']) ?>"
                    data-processed="<?= rex_escape((string) $p['processed_rows']) ?>"
                    data-completed="<?= rex_escape((string) ($p['completed_at'] ?? '')) ?>"
                    data-error="<?= rex_escape((string) ($p['last_error'] ?? '')) ?>"
                >
                    <header class="sprog-migration--item-head">
                        <h2 class="sprog-migration--item-title"><?= Labels::forNamespace($name) ?></h2>
                        <span class="sprog-migration--badge" data-role="status">
                            <?= rex_i18n::msg($badgeKey) ?>
                        </span>
                    </header>

                    <div class="sprog-migration--progress">
                        <progress
                            class="sprog-migration--bar"
                            max="<?= rex_escape((string) max(1, $p['total_rows'])) ?>"
                            value="<?= rex_escape((string) $p['processed_rows']) ?>"
                            data-role="bar"
                        ></progress>
                        <output class="sprog-migration--counter" data-role="counter">
                            <?= rex_escape((string) $p['processed_rows']) ?>
                            <span aria-hidden="true">/</span>
                            <?= rex_escape((string) $p['total_rows']) ?>
                        </output>
                    </div>

                    <p
                        class="sprog-migration--error"
                        data-role="error"
                        <?= $hasError ? '' : 'hidden' ?>
                    ><?= rex_escape((string) ($p['last_error'] ?? '')) ?></p>

                    <footer class="sprog-migration--actions">
                        <button
                            type="button"
                            class="sprog-migration--button sprog-migration--button-primary"
                            data-role="run"
                            <?= $isCompleted ? 'disabled' : '' ?>
                        >
                            <?= rex_i18n::msg($buttonKey) ?>
                        </button>
                    </footer>
                </li>
            <?php endforeach ?>
        </ul>

        <noscript>
            <p class="rex-alert rex-alert-warning"><?= rex_i18n::msg('sprog_noscript_confirm_warning') ?></p>
        </noscript>
        <form method="post" class="sprog-migration--reset" data-confirm="<?= rex_i18n::msg('sprog_migration_reset_confirm') ?>">
            <?= $csrf->getHiddenField() ?>
            <input type="hidden" name="func" value="reset">
            <button
                type="submit"
                class="sprog-migration--button sprog-migration--button-ghost"
            >
                <?= rex_i18n::msg('sprog_migration_reset_button') ?>
            </button>
            <span class="sprog-migration--hint"><?= rex_i18n::msg('sprog_migration_reset_hint') ?></span>
        </form>
    <?php endif ?>
</article>

<script>
window.sprogMigration = {
    csrf: {
        name:  <?= json_encode(rex_csrf_token::PARAM, JSON_THROW_ON_ERROR) ?>,
        value: <?= json_encode($csrf->getValue(), JSON_THROW_ON_ERROR) ?>
    },
    endpoint: <?= json_encode(rex_url::currentBackendPage(['func' => 'chunk'], false), JSON_THROW_ON_ERROR) ?>,
    defaultChunkSize: 50,
    initial: <?= json_encode($jsState, JSON_THROW_ON_ERROR) ?>,
    strings: {
        running:       <?= json_encode(rex_i18n::rawMsg('sprog_migration_button_running'), JSON_THROW_ON_ERROR) ?>,
        done:          <?= json_encode(rex_i18n::rawMsg('sprog_migration_button_done'), JSON_THROW_ON_ERROR) ?>,
        retry:         <?= json_encode(rex_i18n::rawMsg('sprog_migration_button_retry'), JSON_THROW_ON_ERROR) ?>,
        badgeRunning:  <?= json_encode(rex_i18n::rawMsg('sprog_migration_badge_running'), JSON_THROW_ON_ERROR) ?>,
        badgeError:    <?= json_encode(rex_i18n::rawMsg('sprog_migration_badge_error'), JSON_THROW_ON_ERROR) ?>,
        badgeDone:     <?= json_encode(rex_i18n::rawMsg('sprog_migration_badge_completed'), JSON_THROW_ON_ERROR) ?>,
        serverError:   <?= json_encode(rex_i18n::rawMsg('sprog_migration_ajax_server_error'), JSON_THROW_ON_ERROR) ?>,
        chunkLimit:    <?= json_encode(rex_i18n::rawMsg('sprog_migration_ajax_chunk_limit'), JSON_THROW_ON_ERROR) ?>,
        badResponse:   <?= json_encode(rex_i18n::rawMsg('sprog_migration_ajax_bad_response'), JSON_THROW_ON_ERROR) ?>
    }
};
</script>
