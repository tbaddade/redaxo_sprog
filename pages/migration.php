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
 | JSON-Endpoint: nächsten Chunk verarbeiten
 |---------------------------------------------------------------------------
 | Wird vom Frontend per fetch() so lange aufgerufen, bis done=true. Der
 | Service wählt die Quelle selbst (nächste unfertige). CSRF-Token ist Pflicht.
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

    if ($service->isDone()) {
        rex_response::sendJson(['success' => true, 'progress' => null, 'done' => true]);
        exit;
    }

    try {
        $progress = $service->runNextChunk(50, $user->getId());
        rex_response::sendJson([
            'success' => true,
            'progress' => $progress?->toArray(),
            'done' => $service->isDone(),
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
 | HTML-Render
 |---------------------------------------------------------------------------
 */
if (!$service->isMigrationPending()) {
    ?>
    <article class="sprog-ui sprog-migration">
        <header class="sprog-intro">
            <h1 class="sprog-heading"><?= rex_i18n::msg('sprog_migration_heading') ?></h1>
        </header>
        <p class="sprog-empty">
            <?= rex_i18n::msg('sprog_migration_done') ?>
            <a href="<?= rex_url::backendPage('sprog/inbox') ?>"><?= rex_i18n::msg('sprog_migration_to_inbox') ?></a>
        </p>
    </article>
    <?php
    return;
}

// Pro Quelle mit v1-Daten der aktuelle Stand (oder pending mit live ermitteltem total).
$state = $service->state();
$items = [];
foreach ($service->migrators() as $name => $migrator) {
    if (!$migrator->isAvailable()) {
        continue;
    }
    $progress = $state->for($name);
    if (null !== $progress) {
        $items[$name] = $progress->toArray();
        continue;
    }
    $total = $migrator->totalCount();
    if ($total > 0) {
        $items[$name] = [
            'total_rows' => $total,
            'processed_rows' => 0,
            'last_error' => null,
            'completed_at' => null,
        ];
    }
}

$started = false;
$lastError = '';
foreach ($items as $p) {
    $started = $started || $p['processed_rows'] > 0 || null !== $p['completed_at'];
    if (null !== $p['last_error'] && '' !== $p['last_error']) {
        $lastError = (string) $p['last_error'];
    }
}

?>
<article class="sprog-ui sprog-migration" data-sprog-migration>
    <header class="sprog-intro">
        <h1 class="sprog-heading"><?= rex_i18n::msg('sprog_migration_heading') ?></h1>
        <p class="sprog-lead"><?= rex_i18n::msg('sprog_migration_lead') ?></p>
    </header>

    <ul class="sprog-migration--list" role="list">
        <?php foreach ($items as $name => $p) :
            $isCompleted = null !== $p['completed_at'];
            $badgeKey = $isCompleted
                ? 'sprog_migration_badge_completed'
                : ($p['processed_rows'] > 0 ? 'sprog_migration_badge_paused' : 'sprog_migration_badge_ready');
        ?>
            <li
                class="sprog-panel sprog-migration--item <?= $isCompleted ? 'is-completed' : 'is-idle' ?>"
                data-source="<?= rex_escape($name) ?>"
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
            </li>
        <?php endforeach ?>
    </ul>

    <p class="sprog-migration--error" data-role="error" <?= '' === $lastError ? 'hidden' : '' ?>><?= rex_escape($lastError) ?></p>

    <footer class="sprog-migration--actions">
        <button type="button" class="sprog-btn sprog-btn--primary" data-role="run">
            <?= rex_i18n::msg($started ? 'sprog_migration_button_resume' : 'sprog_migration_button_start') ?>
        </button>
    </footer>
</article>

<script>
window.sprogMigration = {
    csrf: {
        name:  <?= json_encode(rex_csrf_token::PARAM, JSON_THROW_ON_ERROR) ?>,
        value: <?= json_encode($csrf->getValue(), JSON_THROW_ON_ERROR) ?>
    },
    endpoint: <?= json_encode(rex_url::currentBackendPage(['func' => 'chunk'], false), JSON_THROW_ON_ERROR) ?>,
    strings: {
        running:      <?= json_encode(rex_i18n::rawMsg('sprog_migration_button_running'), JSON_THROW_ON_ERROR) ?>,
        resume:       <?= json_encode(rex_i18n::rawMsg('sprog_migration_button_resume'), JSON_THROW_ON_ERROR) ?>,
        badgeRunning: <?= json_encode(rex_i18n::rawMsg('sprog_migration_badge_running'), JSON_THROW_ON_ERROR) ?>,
        badgeError:   <?= json_encode(rex_i18n::rawMsg('sprog_migration_badge_error'), JSON_THROW_ON_ERROR) ?>,
        badgeDone:    <?= json_encode(rex_i18n::rawMsg('sprog_migration_badge_completed'), JSON_THROW_ON_ERROR) ?>,
        serverError:  <?= json_encode(rex_i18n::rawMsg('sprog_migration_ajax_server_error'), JSON_THROW_ON_ERROR) ?>,
        chunkLimit:   <?= json_encode(rex_i18n::rawMsg('sprog_migration_ajax_chunk_limit'), JSON_THROW_ON_ERROR) ?>,
        badResponse:  <?= json_encode(rex_i18n::rawMsg('sprog_migration_ajax_bad_response'), JSON_THROW_ON_ERROR) ?>
    }
};
</script>
