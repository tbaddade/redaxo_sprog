<?php

declare(strict_types=1);

use Sprog\Enum\Status;
use Sprog\Exception\OptimisticLockException;
use Sprog\Model\Unit;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Sprog\Service\MtService;
use Sprog\Service\TranslationService;
use Sprog\Support\Labels;

$user = rex::getUser();
if (null === $user) {
    throw new rex_exception('Zugriff verweigert.');
}

$unitId = (int) rex_request('unit', 'int', 0);
if ($unitId <= 0) {
    echo rex_view::error(rex_i18n::msg('sprog_editor_unit_id_missing'));

    return;
}

$units = new UnitRepository();
$translations = new TranslationRepository();
$service = TranslationService::create();

$unit = $units->find($unitId);
if (null === $unit) {
    echo rex_view::error(rex_i18n::msg('sprog_editor_unit_not_found'));

    return;
}

$csrf = rex_csrf_token::factory('sprog_editor_' . $unitId);
$flashMessages = [];
$action = rex_request('action', 'string', '');

/*
 |---------------------------------------------------------------------------
 | POST: alle Texte einer Unit speichern
 |---------------------------------------------------------------------------
 */
if ('save' === $action) {
    if (!$csrf->isValid()) {
        $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_editor_csrf_invalid'));
    } else {
        $valuesByClang = (array) rex_request('value', 'array', []);
        $revisionsByClang = (array) rex_request('revision', 'array', []);
        $mtProvidersByClang = (array) rex_request('mt_provider', 'array', []);
        $mtConfidencesByClang = (array) rex_request('mt_confidence', 'array', []);
        $saved = 0;

        // Whitelist gegen die tatsächlich konfigurierten Provider; Werte aus
        // dem Form, die nicht passen, fallen still durch (mt_provider bleibt
        // dann NULL — der TranslationService speichert die Translation ohne
        // MT-Marker).
        $validMtProviders = MtService::create()->configuredProviderNames();

        foreach (rex_clang::getAll() as $clangId => $clang) {
            if (!$user->getComplexPerm('clang')->hasPerm($clangId)) {
                continue;
            }
            if (!array_key_exists($clangId, $valuesByClang)) {
                continue;
            }

            $newValue = is_string($valuesByClang[$clangId]) ? $valuesByClang[$clangId] : '';
            $current = $translations->findForUnitAndClang($unitId, $clangId);

            // Keine echte Änderung → keine DB-Aktion, keine Revision-Inkrement.
            if (null !== $current && $current->value === $newValue) {
                continue;
            }

            // Erwartete Revision aus dem Form: nur senden, wenn die Translation
            // beim Page-Load schon existierte (sonst hat das Form revision=0
            // mitgeschickt — Optimistic-Lock greift dort nicht).
            $expectedRevision = null;
            if (null !== $current && array_key_exists($clangId, $revisionsByClang)) {
                $expectedRevision = (int) $revisionsByClang[$clangId];
            }

            // MT-Marker: nur durchreichen, wenn Provider in der Whitelist
            // steht UND die Confidence (falls vorhanden) im erlaubten Range
            // [0.0, 1.0] liegt. Sonst beide auf NULL.
            $mtProvider = null;
            $mtConfidence = null;
            $providerRaw = isset($mtProvidersByClang[$clangId]) && is_string($mtProvidersByClang[$clangId])
                ? trim($mtProvidersByClang[$clangId])
                : '';
            if ('' !== $providerRaw && in_array($providerRaw, $validMtProviders, true) && 'noop' !== $providerRaw) {
                $mtProvider = $providerRaw;

                if (isset($mtConfidencesByClang[$clangId]) && '' !== $mtConfidencesByClang[$clangId]) {
                    $confRaw = (float) $mtConfidencesByClang[$clangId];
                    if ($confRaw >= 0.0 && $confRaw <= 1.0) {
                        $mtConfidence = $confRaw;
                    }
                }
            }

            try {
                $service->updateValue(
                    $unit,
                    $clangId,
                    $newValue,
                    $user->getId(),
                    expectedRevision: $expectedRevision,
                    mtProvider: $mtProvider,
                    mtConfidence: $mtConfidence,
                );
                ++$saved;
            } catch (OptimisticLockException) {
                $flashMessages[] = rex_view::warning(rex_i18n::msg(
                    'sprog_editor_conflict_save',
                    $clang->getName(),
                ));
            } catch (Throwable $e) {
                $flashMessages[] = rex_view::error(sprintf(
                    '%s: %s',
                    rex_escape($clang->getCode()),
                    rex_escape($e->getMessage()),
                ));
            }
        }

        if ($saved > 0) {
            $flashMessages[] = rex_view::success(rex_i18n::msg(
                1 === $saved ? 'sprog_editor_save_count_one' : 'sprog_editor_save_count_many',
                (string) $saved,
            ));
        }
    }
}

/*
 |---------------------------------------------------------------------------
 | POST: Status-Übergang einer einzelnen Übersetzung
 |---------------------------------------------------------------------------
 */
if ('transition' === $action) {
    if (!$csrf->isValid()) {
        $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_editor_csrf_invalid'));
    } else {
        $translationId = (int) rex_request('translation_id', 'int', 0);
        $targetStatusInput = (string) rex_request('target_status', 'string', '');
        $expectedRevision = (int) rex_request('expected_revision', 'int', 0);

        if ($translationId <= 0 || !in_array($targetStatusInput, Status::values(), true)) {
            $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_editor_status_invalid'));
        } else {
            try {
                $translation = $translations->find($translationId);
                if (null === $translation || $translation->unitId !== $unitId) {
                    // Schutz vor Querverweisen: die Translation muss zu DIESER Unit gehören.
                    $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_editor_translation_not_belonging'));
                } elseif (!$user->getComplexPerm('clang')->hasPerm($translation->clangId)) {
                    $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_editor_clang_no_perm'));
                } else {
                    $service->transition(
                        $translationId,
                        Status::from($targetStatusInput),
                        $user->getId(),
                        expectedRevision: $expectedRevision,
                    );
                    $flashMessages[] = rex_view::success(rex_i18n::msg('sprog_editor_status_updated'));
                }
            } catch (OptimisticLockException) {
                $flashMessages[] = rex_view::warning(rex_i18n::msg('sprog_editor_conflict_transition'));
            } catch (Throwable $e) {
                $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_editor_status_failed', $e->getMessage()));
            }
        }
    }
}

/*
 |---------------------------------------------------------------------------
 | JSON-Endpoint: MT-Vorschlag für eine Sprache anfordern
 |---------------------------------------------------------------------------
 | Liest den Quelltext der Start-Clang, ruft MtService::translate() auf und
 | gibt den Vorschlag als JSON zurück. Der Client (sprog.editor.js) trägt
 | das Ergebnis ins textarea ein; ein Save passiert NICHT — das macht der
 | User mit einem expliziten Klick auf "Speichern".
 */
if ('mt' === $action) {
    rex_response::cleanOutputBuffers();

    if (!$csrf->isValid()) {
        rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
        rex_response::sendJson([
            'success' => false,
            'error' => rex_i18n::rawMsg('sprog_editor_mt_csrf'),
        ]);
        exit;
    }

    $targetClangId = (int) rex_request('clang_id', 'int', 0);
    $providerRequest = trim((string) rex_request('provider', 'string', ''));

    if (!rex_clang::exists($targetClangId)) {
        rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
        rex_response::sendJson([
            'success' => false,
            'error' => rex_i18n::rawMsg('sprog_editor_mt_unknown_clang'),
        ]);
        exit;
    }

    if (!$user->getComplexPerm('clang')->hasPerm($targetClangId)) {
        rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
        rex_response::sendJson([
            'success' => false,
            'error' => rex_i18n::rawMsg('sprog_editor_clang_no_perm'),
        ]);
        exit;
    }

    // Quelltext kommt aus der Start-Clang. Per-Unit override (z.B. ein anderer
    // "Source-Lang"-Setter) ist eine Idee für eine spätere Tranche.
    $sourceClangId = rex_clang::getStartId();
    if ($sourceClangId === $targetClangId) {
        rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
        rex_response::sendJson([
            'success' => false,
            'error' => rex_i18n::rawMsg('sprog_editor_mt_same_lang'),
        ]);
        exit;
    }

    $sourceClang = rex_clang::get($sourceClangId);
    $targetClang = rex_clang::get($targetClangId);
    if (null === $sourceClang || null === $targetClang) {
        rex_response::setStatus(rex_response::HTTP_INTERNAL_ERROR);
        rex_response::sendJson([
            'success' => false,
            'error' => rex_i18n::rawMsg('sprog_editor_mt_unknown_clang'),
        ]);
        exit;
    }

    $sourceTranslation = $translations->findForUnitAndClang($unitId, $sourceClangId);
    if (null === $sourceTranslation || '' === trim($sourceTranslation->value)) {
        rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
        rex_response::sendJson([
            'success' => false,
            'error' => rex_i18n::rawMsg('sprog_editor_mt_no_source', $sourceClang->getName()),
        ]);
        exit;
    }

    try {
        $mt = MtService::create();
        $configured = $mt->configuredProviderNames();

        // Provider-Auswahl: explizit aus Request, sonst erster echter Provider,
        // fallback nicht auf 'noop' (das wäre nutzlos für den User — gibt nur
        // den Quelltext zurück).
        $useProvider = null;
        if ('' !== $providerRequest && in_array($providerRequest, $configured, true) && 'noop' !== $providerRequest) {
            $useProvider = $providerRequest;
        } else {
            foreach ($configured as $name) {
                if ('noop' !== $name) {
                    $useProvider = $name;
                    break;
                }
            }
        }

        if (null === $useProvider) {
            rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
            rex_response::sendJson([
                'success' => false,
                'error' => rex_i18n::rawMsg('sprog_editor_mt_no_provider'),
            ]);
            exit;
        }

        $result = $mt->translate(
            $sourceTranslation->value,
            strtolower($sourceClang->getCode()),
            strtolower($targetClang->getCode()),
            $useProvider,
        );

        rex_response::sendJson([
            'success' => true,
            'text' => $result->text,
            'provider' => $result->provider,
            'confidence' => $result->confidence,
        ]);
    } catch (Throwable $e) {
        rex_response::setStatus(rex_response::HTTP_INTERNAL_ERROR);
        rex_response::sendJson([
            'success' => false,
            'error' => rex_i18n::rawMsg('sprog_editor_mt_failed', $e->getMessage()),
        ]);
    }
    exit;
}

/*
 |---------------------------------------------------------------------------
 | POST: unit_key / notes der Einheit aktualisieren
 |---------------------------------------------------------------------------
 | Eigene Permission `sprog[unit_edit]`. Admin geht immer durch.
 | namespace, source_type, source_ref, tags bleiben unverändert — nur key
 | und notes sind bewusst editierbar (alles andere ist strukturell oder
 * wird durch die Inhalt-Synchronisation gesetzt).
 */
$canEditUnit = $user->isAdmin() || $user->hasPerm('sprog[unit_edit]');

if ('update_unit' === $action) {
    if (!$canEditUnit) {
        $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_editor_unit_edit_no_perm'));
    } elseif (!$csrf->isValid()) {
        $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_editor_csrf_invalid'));
    } else {
        $newKey = trim((string) rex_request('unit_key', 'string', ''));
        $newNotesIn = trim((string) rex_request('notes', 'string', ''));
        $newNotes = '' === $newNotesIn ? null : $newNotesIn;

        $errors = [];
        if ('' === $newKey) {
            $errors[] = rex_i18n::msg('sprog_create_key_empty');
        } elseif (strlen($newKey) > 191) {
            $errors[] = rex_i18n::msg('sprog_create_key_too_long');
        }
        if (null !== $newNotes && strlen($newNotes) > 500) {
            $errors[] = rex_i18n::msg('sprog_create_notes_too_long');
        }

        // UNIQUE-Vorprüfung nur bei tatsächlichem Key-Wechsel — sonst würde
        // unser eigener Eintrag als „Duplikat" gewertet.
        if ([] === $errors && $newKey !== $unit->unitKey) {
            $existing = $units->findByKey($unit->namespace, $newKey);
            if (null !== $existing && $existing->id !== $unit->id) {
                $errors[] = rex_i18n::msg('sprog_editor_unit_edit_duplicate', $newKey, $unit->namespace);
            }
        }

        if ([] === $errors) {
            try {
                $unit = $units->save(new Unit(
                    id: $unit->id,
                    namespace: $unit->namespace,
                    unitKey: $newKey,
                    context: $unit->context,
                    sourceType: $unit->sourceType,
                    sourceRef: $unit->sourceRef,
                    sourceHash: $unit->sourceHash,
                    tags: $unit->tags,
                    notes: $newNotes,
                ));
                $flashMessages[] = rex_view::success(rex_i18n::msg('sprog_editor_unit_updated'));
            } catch (Throwable $e) {
                $flashMessages[] = rex_view::error(rex_escape($e->getMessage()));
            }
        } else {
            foreach ($errors as $err) {
                $flashMessages[] = rex_view::error($err);
            }
        }
    }
}

/*
 |---------------------------------------------------------------------------
 | Render: Unit + alle Translations
 |---------------------------------------------------------------------------
 */
$clangs = rex_clang::getAll();
$currentTranslations = [];
foreach ($translations->findByUnit($unitId) as $t) {
    $currentTranslations[$t->clangId] = $t;
}

// Forward-Workflow für die Status-Buttons: zentral im Status-Enum
// (`Status::userActions()`). Single source of truth, geteilt mit der
// Inbox-Page und dem TranslationService-Validator.

?>
<article class="sprog-editor">
    <header class="sprog-editor--intro">
        <a class="sprog-editor--back" href="<?= rex_escape(rex_url::backendPage('sprog/inbox', [], false)) ?>">
            <?= rex_i18n::msg('sprog_editor_back') ?>
        </a>

        <h1 class="sprog-editor--heading"><?= rex_escape($unit->unitKey) ?></h1>

        <div class="sprog-editor--meta">
            <span class="sprog-editor--ns"><?= Labels::forNamespace($unit->namespace) ?></span>
            <?php if (null !== $unit->sourceType) : ?>
                <span class="sprog-editor--source"><?= rex_i18n::msg('sprog_editor_meta_source', Labels::sourceType($unit->sourceType)) ?></span>
            <?php endif ?>
            <?php if ([] !== $unit->tags) : ?>
                <span class="sprog-editor--tags">
                    <?php foreach ($unit->tags as $tag) : ?>
                        <span class="sprog-editor--tag"><?= rex_escape((string) $tag) ?></span>
                    <?php endforeach ?>
                </span>
            <?php endif ?>
        </div>
    </header>

    <?php foreach ($flashMessages as $msg) {
        echo $msg;
    } ?>

    <?php if ($canEditUnit) : ?>
        <details class="sprog-editor--unit-edit">
            <summary class="sprog-editor--unit-edit-summary">
                <?= rex_i18n::msg('sprog_editor_unit_edit_heading') ?>
            </summary>

            <form method="post" class="sprog-editor--unit-form">
                <?= $csrf->getHiddenField() ?>
                <input type="hidden" name="action" value="update_unit">

                <p class="sprog-editor--unit-edit-lead">
                    <?= rex_i18n::msg('sprog_editor_unit_edit_lead') ?>
                </p>

                <label class="sprog-editor--field">
                    <span class="sprog-editor--label"><?= rex_i18n::msg('sprog_editor_unit_key_label') ?></span>
                    <input
                        type="text"
                        name="unit_key"
                        class="sprog-editor--unit-input"
                        value="<?= rex_escape($unit->unitKey) ?>"
                        required
                        maxlength="191"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                    >
                    <span class="sprog-editor--unit-hint">
                        <?= rex_i18n::rawMsg('sprog_editor_unit_key_hint') ?>
                    </span>
                </label>

                <label class="sprog-editor--field">
                    <span class="sprog-editor--label"><?= rex_i18n::msg('sprog_editor_unit_notes_label') ?></span>
                    <textarea
                        name="notes"
                        class="sprog-editor--unit-notes"
                        rows="2"
                        maxlength="500"
                    ><?= rex_escape($unit->notes ?? '') ?></textarea>
                </label>

                <div class="sprog-editor--unit-actions">
                    <button type="submit" class="sprog-editor--button sprog-editor--button-primary">
                        <?= rex_i18n::msg('sprog_editor_unit_edit_submit') ?>
                    </button>
                </div>
            </form>
        </details>
    <?php endif ?>

    <form method="post" class="sprog-editor--form">
        <?= $csrf->getHiddenField() ?>
        <input type="hidden" name="action" value="save">

        <div class="sprog-editor--grid">
            <?php foreach ($clangs as $clangId => $clang) :
                $hasPerm = $user->getComplexPerm('clang')->hasPerm($clangId);
                $current = $currentTranslations[$clangId] ?? null;
                $value = $current?->value ?? '';
                $status = $current?->status ?? Status::Missing;
                $isStale = null !== $current && null !== $unit->sourceHash
                    && $current->isStaleAgainst($unit->sourceHash);

                $cssClasses = ['sprog-editor--lang'];
                if ($isStale) {
                    $cssClasses[] = 'is-stale';
                }
                if (!$hasPerm) {
                    $cssClasses[] = 'is-locked';
                }
            ?>
                <section class="<?= rex_escape(implode(' ', $cssClasses)) ?>"
                         aria-label="<?= rex_i18n::msg('sprog_editor_section_label', $clang->getName()) ?>">
                    <header class="sprog-editor--lang-head">
                        <h2 class="sprog-editor--lang-title">
                            <span class="sprog-editor--clang-code"><?= rex_escape($clang->getCode()) ?></span>
                            <span class="sprog-editor--clang-name"><?= rex_escape($clang->getName()) ?></span>
                        </h2>
                        <span class="sprog-status sprog-status--<?= rex_escape($status->value) ?>">
                            <?= Labels::status($status) ?>
                        </span>
                    </header>

                    <?php if ($isStale) : ?>
                        <p class="sprog-editor--stale-hint">
                            <?= rex_i18n::msg('sprog_editor_stale_hint') ?>
                        </p>
                    <?php endif ?>

                    <label class="sprog-editor--field">
                        <span class="sprog-editor--label"><?= rex_i18n::msg('sprog_editor_translation_label') ?></span>
                        <textarea
                            id="sprog-textarea-<?= rex_escape((string) $clangId) ?>"
                            name="value[<?= rex_escape((string) $clangId) ?>]"
                            rows="6"
                            class="sprog-editor--textarea"
                            <?= $hasPerm ? '' : 'readonly aria-readonly="true"' ?>
                        ><?= rex_escape($value) ?></textarea>
                    </label>
                    <?php if (null !== $current) : ?>
                        <input
                            type="hidden"
                            name="revision[<?= rex_escape((string) $clangId) ?>]"
                            value="<?= rex_escape((string) $current->revision) ?>"
                        >
                    <?php endif ?>

                    <?php if (null !== $current && null !== $current->mtProvider) : ?>
                        <p class="sprog-editor--mt">
                            <?= rex_i18n::msg('sprog_editor_mt_suggestion', '<strong>' . rex_escape($current->mtProvider) . '</strong>') ?>
                            <?php if (null !== $current->mtConfidence) : ?>
                                · <?= rex_i18n::msg('sprog_editor_mt_confidence', number_format($current->mtConfidence, 2)) ?>
                            <?php endif ?>
                        </p>
                    <?php endif ?>

                    <?php if ($hasPerm && $clangId !== rex_clang::getStartId()) :
                        // Initial-Werte für die MT-Marker: nur befüllt, wenn die
                        // aktuelle Translation tatsächlich MT-induziert ist (z.B.
                        // nach einem früheren MT-Save). JS schreibt sie auch zur
                        // Laufzeit, sobald ein MT-Vorschlag akzeptiert wird.
                        $mtProviderInit = $current?->mtProvider ?? '';
                        $mtConfidenceInit = null !== $current?->mtConfidence
                            ? (string) $current->mtConfidence
                            : '';
                    ?>
                        <div class="sprog-editor--mt-bar"
                             data-mt-active="<?= '' !== $mtProviderInit ? 'true' : 'false' ?>">
                            <button
                                type="button"
                                class="sprog-editor--mt-trigger"
                                data-role="mt-trigger"
                                data-clang-id="<?= rex_escape((string) $clangId) ?>"
                                data-textarea-id="sprog-textarea-<?= rex_escape((string) $clangId) ?>"
                            >
                                <?= rex_i18n::msg('sprog_editor_mt_button') ?>
                            </button>
                            <button
                                type="button"
                                class="sprog-editor--mt-discard"
                                data-role="mt-discard"
                                data-textarea-id="sprog-textarea-<?= rex_escape((string) $clangId) ?>"
                                <?= '' === $mtProviderInit ? 'hidden' : '' ?>
                            >
                                <?= rex_i18n::msg('sprog_editor_mt_discard_button') ?>
                            </button>
                            <span class="sprog-editor--mt-status" data-role="mt-status" role="status" aria-live="polite"></span>

                            <input
                                type="hidden"
                                name="mt_provider[<?= rex_escape((string) $clangId) ?>]"
                                value="<?= rex_escape($mtProviderInit) ?>"
                                data-role="mt-provider"
                            >
                            <input
                                type="hidden"
                                name="mt_confidence[<?= rex_escape((string) $clangId) ?>]"
                                value="<?= rex_escape($mtConfidenceInit) ?>"
                                data-role="mt-confidence"
                            >
                        </div>
                    <?php endif ?>

                    <?php if (null !== $current && $hasPerm) :
                        $transitions = $status->userActions();
                        if ([] !== $transitions) : ?>
                            <div class="sprog-editor--actions" role="group" aria-label="<?= rex_i18n::msg('sprog_editor_actions_label') ?>">
                                <?php foreach ($transitions as $targetStatus) : ?>
                                    <button
                                        type="submit"
                                        form="transition-<?= rex_escape((string) $current->id) ?>-<?= rex_escape($targetStatus->value) ?>"
                                        class="sprog-editor--action sprog-editor--action-<?= rex_escape($targetStatus->value) ?>"
                                    >
                                        → <?= Labels::status($targetStatus) ?>
                                    </button>
                                <?php endforeach ?>
                            </div>
                    <?php endif;
                    endif ?>
                </section>
            <?php endforeach ?>
        </div>

        <div class="sprog-editor--save-bar">
            <button type="submit" class="sprog-editor--button sprog-editor--button-primary">
                <?= rex_i18n::msg('sprog_editor_save_button') ?>
            </button>
        </div>
    </form>

    <?php
    /*
     * Hidden forms für die einzelnen Status-Übergänge — liegen ausserhalb der
     * Save-Form, weil <form> nicht nestbar ist. Die Action-Buttons oben werden
     * per `form="..."`-Attribut mit ihrer jeweiligen Form verknüpft.
     */
    foreach ($currentTranslations as $clangId => $current) :
        if (!$user->getComplexPerm('clang')->hasPerm($clangId)) {
            continue;
        }
        foreach ($current->status->userActions() as $targetStatus) :
    ?>
        <form
            id="transition-<?= rex_escape((string) $current->id) ?>-<?= rex_escape($targetStatus->value) ?>"
            method="post"
            class="sprog-editor--hidden-form"
        >
            <?= $csrf->getHiddenField() ?>
            <input type="hidden" name="action" value="transition">
            <input type="hidden" name="translation_id" value="<?= rex_escape((string) $current->id) ?>">
            <input type="hidden" name="target_status" value="<?= rex_escape($targetStatus->value) ?>">
            <input type="hidden" name="expected_revision" value="<?= rex_escape((string) $current->revision) ?>">
        </form>
    <?php endforeach;
    endforeach ?>
</article>

<script>
window.sprogEditor = {
    csrf: {
        name:  <?= json_encode(rex_csrf_token::PARAM, JSON_THROW_ON_ERROR) ?>,
        value: <?= json_encode($csrf->getValue(), JSON_THROW_ON_ERROR) ?>
    },
    endpoint: <?= json_encode(rex_url::currentBackendPage(['unit' => $unitId, 'func' => 'mt'], false), JSON_THROW_ON_ERROR) ?>,
    strings: {
        loading:        <?= json_encode(rex_i18n::rawMsg('sprog_editor_mt_button_loading'), JSON_THROW_ON_ERROR) ?>,
        button:         <?= json_encode(rex_i18n::rawMsg('sprog_editor_mt_button'), JSON_THROW_ON_ERROR) ?>,
        applyConfirm:   <?= json_encode(rex_i18n::rawMsg('sprog_editor_mt_apply_confirm'), JSON_THROW_ON_ERROR) ?>,
        discardConfirm: <?= json_encode(rex_i18n::rawMsg('sprog_editor_mt_discard_confirm'), JSON_THROW_ON_ERROR) ?>
    }
};
</script>
