<?php

declare(strict_types=1);

use Sprog\Service\GlossaryService;
use Sprog\Support\BaseLang;
use Sprog\Support\ClangBase;

$user = rex::getUser();
if (null === $user || !$user->isAdmin()) {
    throw new rex_exception('Zugriff verweigert.');
}

// Quelle ist immer die Basissprache (konfigurierbar, Fallback Start-Clang) —
// im Glossar gibt es daher keine Quellsprachen-Auswahl mehr.
$baseClangId = BaseLang::clangId();
$baseClang = rex_clang::get($baseClangId);

$csrf = rex_csrf_token::factory('sprog_glossary');
$service = GlossaryService::create();
$flashMessages = [];
$action = rex_request('action', 'string', '');

// Nur eigenständig übersetzbare Sprachen als Ziel — via Sprachbasis
// (clang_base) abgeleitete Sprachen erben ihre Begriffe ohnehin aus der Basis
// und stehen daher nicht als Glossar-Ziel zur Verfügung.
$clangs = ClangBase::translatableClangs();
$clangPerm = $user->getComplexPerm('clang');

// Sprachen mit Lese-Berechtigung — Perm-Gate für Liste und Auswahlfelder.
$allowedClangIds = [];
foreach ($clangs as $cid => $_clang) {
    if ($clangPerm->hasPerm($cid)) {
        $allowedClangIds[] = $cid;
    }
}

/*
 |---------------------------------------------------------------------------
 | Filter (GET, shareable). 0 = „alle".
 |---------------------------------------------------------------------------
 */
$filterTarget = (int) rex_request('target_clang', 'int', 0);
$search = trim((string) rex_request('search', 'string', ''));
$isFiltered = $filterTarget > 0 || '' !== $search;

/*
 |---------------------------------------------------------------------------
 | POST: Eintrag anlegen — Sprachen kommen aus dem Formular (add_*), bewusst
 | getrennt von den Filter-Feldern, damit ein Anlegen den Filter nicht kapert.
 |---------------------------------------------------------------------------
 */
if ('add' === $action || 'update' === $action) {
    if (!$csrf->isValid()) {
        $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_glossary_add_csrf'));
    } else {
        $sourceTerm = trim((string) rex_request('source_term', 'string', ''));
        $targetTerm = trim((string) rex_request('target_term', 'string', ''));
        $notesIn = trim((string) rex_request('notes', 'string', ''));
        $notes = '' === $notesIn ? null : $notesIn;

        // „Nicht übersetzen": Begriff bleibt unverändert (Ziel-Term = Quell-Term)
        // und gilt für alle Sprachen (Ziel = 0). Typischer Marken-/Produktname.
        $keepVerbatim = (bool) rex_request('keep_verbatim', 'bool', false);
        if ($keepVerbatim) {
            $targetTerm = $sourceTerm;
        }

        try {
            if ('update' === $action) {
                $updateId = (int) rex_request('id', 'int', 0);
                // Ziel darf beim Bearbeiten geändert werden — auch auf 0 (Alle).
                $updateTarget = $keepVerbatim ? 0 : (int) rex_request('add_target_clang', 'int', 0);
                $service->update($updateId, $updateTarget, $sourceTerm, $targetTerm, $notes);
                $flashMessages[] = rex_view::success(rex_i18n::msg('sprog_glossary_update_success', $sourceTerm, $targetTerm));
            } else {
                // Quelle = Basissprache; Ziel = gewählte Sprache ODER 0 = „Alle
                // Sprachen". „Nicht übersetzen" erzwingt „Alle".
                $addTarget = $keepVerbatim ? 0 : (int) rex_request('add_target_clang', 'int', 0);
                $service->add($baseClangId, $addTarget, $sourceTerm, $targetTerm, $notes);
                $flashMessages[] = rex_view::success(rex_i18n::msg('sprog_glossary_add_success', $sourceTerm, $targetTerm));
            }
        } catch (InvalidArgumentException $e) {
            $flashMessages[] = rex_view::error(rex_escape($e->getMessage()));
        } catch (rex_sql_exception $e) {
            // 1062 = ER_DUP_ENTRY (UNIQUE-Verstoss). Error-Code statt Message-
            // Match, weil lokalisierte MySQL/MariaDB-Texte den stripos brechen.
            if (1062 === $e->getErrorCode()) {
                $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_glossary_add_duplicate', $sourceTerm));
            } else {
                $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_glossary_add_error', $e->getMessage()));
            }
        }
    }
}

/*
 |---------------------------------------------------------------------------
 | POST: Eintrag löschen
 |---------------------------------------------------------------------------
 */
if ('delete' === $action) {
    if (!$csrf->isValid()) {
        $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_glossary_add_csrf'));
    } else {
        $deleteId = (int) rex_request('id', 'int', 0);
        if ($deleteId > 0) {
            try {
                $service->remove($deleteId);
                $flashMessages[] = rex_view::success(rex_i18n::msg('sprog_glossary_delete_success'));
            } catch (Throwable $e) {
                $flashMessages[] = rex_view::error(rex_escape($e->getMessage()));
            }
        }
    }
}

/*
 |---------------------------------------------------------------------------
 | Render
 |---------------------------------------------------------------------------
 */
// Quelle immer Basissprache → kein Quell-Filter mehr (null).
$entries = $service->listAll($allowedClangIds, null, $filterTarget, $search);
$count = count($entries);

// Edit-Modus: Eintrag vorab laden, um das Formular zu füllen.
$editEntry = null;
if ('edit' === $action) {
    $editId = (int) rex_request('id', 'int', 0);
    $editEntry = $editId > 0 ? $service->find($editId) : null;
}
$isEdit = null !== $editEntry;

// Standard-Zielsprache fürs Anlegen: aktiver Filter, sonst erste erlaubte
// Sprache außer der Basissprache.
$addTargetDefault = $filterTarget > 0 ? $filterTarget : 0;
if (0 === $addTargetDefault) {
    foreach ($allowedClangIds as $cid) {
        if ($cid !== $baseClangId) {
            $addTargetDefault = $cid;
            break;
        }
    }
}

// Formular-Werte (Anlegen vs. Bearbeiten).
$formSourceTerm = $isEdit ? $editEntry->sourceTerm : '';
$formTargetTerm = $isEdit ? $editEntry->targetTerm : '';
$formNotes = $isEdit ? ($editEntry->notes ?? '') : '';
$formTargetClang = $isEdit ? $editEntry->targetClangId : $addTargetDefault;
$formKeepVerbatim = $isEdit && 0 === $editEntry->targetClangId && $editEntry->targetTerm === $editEntry->sourceTerm;

// Filter-Form-ID: die Sprach-Selects sitzen optisch im Listen-Header, gehören
// aber per form-Attribut zum GET-Filterformular in der Such-Zeile.
$filterFormId = 'sprog-glossary-filter';

// SVG-Icons (Form identisch zur Inbox).
$chevronSvg = '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M12.78 6.22a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L3.22 7.28a.75.75 0 1 1 1.06-1.06L8 9.94l3.72-3.72a.75.75 0 0 1 1.06 0Z"/></svg>';
$searchSvg = '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M11.5 7a4.499 4.499 0 1 1-8.998 0A4.499 4.499 0 0 1 11.5 7Zm-.82 4.74a6 6 0 1 1 1.06-1.06l3.04 3.04a.75.75 0 1 1-1.06 1.06l-3.04-3.04Z"/></svg>';
$trashSvg = '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M11 1.75V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75ZM4.496 6.675l.66 6.6a.25.25 0 0 0 .249.225h5.19a.25.25 0 0 0 .249-.225l.66-6.6a.75.75 0 0 1 1.492.149l-.66 6.6A1.748 1.748 0 0 1 10.595 15h-5.19a1.748 1.748 0 0 1-1.741-1.575l-.66-6.6a.75.75 0 1 1 1.492-.149ZM6.5 1.75V3h3V1.75a.25.25 0 0 0-.25-.25h-2.5a.25.25 0 0 0-.25.25Z"/></svg>';
$pencilSvg = '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.21.21-.47.364-.756.445l-3.251.93a.75.75 0 0 1-.927-.928l.929-3.25c.081-.286.235-.547.445-.758l8.61-8.61Zm.176 4.823L9.75 4.81l-6.286 6.287a.253.253 0 0 0-.064.108l-.558 1.953 1.953-.558a.253.253 0 0 0 .108-.064Zm1.238-3.763a.25.25 0 0 0-.354 0L10.811 3.75l1.439 1.44 1.263-1.263a.25.25 0 0 0 0-.354Z"/></svg>';

?>
<article class="sprog-ui sprog-glossary">
    <header class="sprog-intro">
        <h1 class="sprog-heading"><?= rex_i18n::msg('sprog_glossary_heading') ?></h1>
        <p class="sprog-lead"><?= rex_i18n::msg('sprog_glossary_lead') ?></p>
    </header>

    <details class="sprog-legend">
        <summary class="sprog-legend--summary">
            <span class="sprog-legend--icon" aria-hidden="true">i</span>
            <?= rex_i18n::msg('sprog_glossary_help_summary') ?>
            <span class="sprog-chevron"><?= $chevronSvg ?></span>
        </summary>
        <div class="sprog-legend--body">
            <section class="sprog-legend--section sprog-legend--section--full">
                <p class="sprog-legend--text"><?= rex_i18n::msg('sprog_glossary_help_idea') ?></p>
            </section>

            <section class="sprog-legend--section">
                <h3 class="sprog-legend--heading"><?= rex_i18n::msg('sprog_glossary_help_examples_label') ?></h3>
                <ul class="sprog-legend--list">
                    <li><?= rex_i18n::msg('sprog_glossary_help_example_1') ?></li>
                    <li><?= rex_i18n::msg('sprog_glossary_help_example_2') ?></li>
                    <li><?= rex_i18n::msg('sprog_glossary_help_example_3') ?></li>
                </ul>
            </section>

            <section class="sprog-legend--section">
                <h3 class="sprog-legend--heading"><?= rex_i18n::msg('sprog_glossary_help_when_label') ?></h3>
                <ul class="sprog-legend--list">
                    <li><?= rex_i18n::msg('sprog_glossary_help_when_1') ?></li>
                    <li><?= rex_i18n::msg('sprog_glossary_help_when_2') ?></li>
                </ul>
            </section>

            <section class="sprog-legend--section">
                <h3 class="sprog-legend--heading"><?= rex_i18n::msg('sprog_glossary_help_notwhen_label') ?></h3>
                <ul class="sprog-legend--list">
                    <li><?= rex_i18n::msg('sprog_glossary_help_notwhen_1') ?></li>
                    <li><?= rex_i18n::msg('sprog_glossary_help_notwhen_2') ?></li>
                    <li><?= rex_i18n::msg('sprog_glossary_help_notwhen_3') ?></li>
                </ul>
            </section>

            <section class="sprog-legend--section sprog-legend--section--full">
                <p class="sprog-legend--note"><?= rex_i18n::msg('sprog_glossary_help_status') ?></p>
            </section>
        </div>
    </details>

    <?php foreach ($flashMessages as $msg) {
        echo $msg;
    } ?>

    <div class="sprog-toolbar">
        <!-- Such-Zeile: Suchfeld füllt links, Aktionen + Anlegen-Button rechts. -->
        <div class="sprog-toolbar--row">
            <form method="get" id="<?= $filterFormId ?>" class="sprog-toolbar--search">
                <input type="hidden" name="page" value="sprog/glossary">

                <label class="sprog-search">
                    <span class="sprog-search--icon" aria-hidden="true"><?= $searchSvg ?></span>
                    <input
                        type="search"
                        name="search"
                        value="<?= rex_escape($search) ?>"
                        placeholder="<?= rex_escape(rex_i18n::msg('sprog_glossary_filter_search_placeholder')) ?>"
                        class="sprog-search--input"
                    >
                </label>

                <button type="submit" class="sprog-btn">
                    <?= rex_i18n::msg('sprog_glossary_filter_submit') ?>
                </button>

                <?php if ($isFiltered) : ?>
                    <a class="sprog-btn sprog-btn--reset" href="<?= rex_escape(rex_url::currentBackendPage([], false)) ?>">
                        <?= rex_i18n::msg('sprog_glossary_filter_reset') ?>
                    </a>
                <?php endif ?>
            </form>

            <!--
                Anlegen rechts in der Zeile via <details>: das Formular klappt als
                Dropdown auf (kein JS, kein Modal), eigene Sprachauswahl, weil die
                Liste paarübergreifend ist.
            -->
            <details class="sprog-glossary--add"<?= $isEdit ? ' open' : '' ?>>
                <summary class="sprog-glossary--add-trigger sprog-btn sprog-btn--primary">
                    <span class="sprog-glossary--add-plus" aria-hidden="true">+</span>
                    <?= rex_i18n::msg($isEdit ? 'sprog_glossary_edit_open' : 'sprog_glossary_add_open') ?>
                </summary>

                <div class="sprog-glossary--add-popup">
                    <form method="post" class="sprog-glossary--add-form">
                        <?= $csrf->getHiddenField() ?>
                        <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'add' ?>">
                        <?php if ($isEdit) : ?>
                            <input type="hidden" name="id" value="<?= rex_escape((string) $editEntry->id) ?>">
                        <?php endif ?>

                        <?php // Quelle ist fix die Basissprache — keine Auswahl, nur Anzeige.?>
                        <label class="sprog-field">
                            <span class="sprog-field--label"><?= rex_i18n::msg('sprog_glossary_add_source_lang') ?></span>
                            <input type="text" class="sprog-control" readonly
                                   value="<?= null !== $baseClang ? rex_escape($baseClang->getCode() . ' · ' . $baseClang->getName()) : rex_escape((string) $baseClangId) ?>">
                        </label>

                        <label class="sprog-field">
                            <span class="sprog-field--label"><?= rex_i18n::msg('sprog_glossary_add_source_term') ?></span>
                            <input type="text" name="source_term" class="sprog-control" required
                                   maxlength="<?= rex_escape((string) GlossaryService::MAX_TERM_LENGTH) ?>"
                                   value="<?= rex_escape($formSourceTerm) ?>"
                                   autocomplete="off" spellcheck="false">
                        </label>

                        <?php // „Nicht übersetzen": Marken-/Produktname bleibt unverändert und gilt für alle Sprachen.?>
                        <label class="sprog-field sprog-field--wide sprog-glossary--verbatim">
                            <input type="checkbox" name="keep_verbatim" value="1" data-role="glossary-verbatim" <?= $formKeepVerbatim ? 'checked' : '' ?>>
                            <span><?= rex_i18n::msg('sprog_glossary_keep_verbatim') ?></span>
                        </label>

                        <?php // Ziel-Block — bei „Nicht übersetzen" ausgeblendet.?>
                        <div class="sprog-glossary--target-block" data-role="glossary-target-block"<?= $formKeepVerbatim ? ' hidden' : '' ?>>
                            <label class="sprog-field">
                                <span class="sprog-field--label"><?= rex_i18n::msg('sprog_glossary_add_target_lang') ?></span>
                                <select name="add_target_clang" class="sprog-control">
                                    <option value="0" <?= 0 === $formTargetClang ? 'selected' : '' ?>><?= rex_i18n::msg('sprog_glossary_target_all') ?></option>
                                    <?php foreach ($clangs as $cid => $clang) :
                                        if ($cid === $baseClangId || !$clangPerm->hasPerm($cid)) {
                                            continue;
                                        }
                                    ?>
                                        <option value="<?= rex_escape((string) $cid) ?>" <?= $cid === $formTargetClang ? 'selected' : '' ?>>
                                            <?= rex_escape($clang->getCode()) ?> · <?= rex_escape($clang->getName()) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            </label>

                            <label class="sprog-field">
                                <span class="sprog-field--label"><?= rex_i18n::msg('sprog_glossary_add_target_term') ?></span>
                                <input type="text" name="target_term" class="sprog-control"
                                       maxlength="<?= rex_escape((string) GlossaryService::MAX_TERM_LENGTH) ?>"
                                       value="<?= rex_escape($formTargetTerm) ?>"
                                       autocomplete="off" spellcheck="false">
                            </label>
                        </div>

                        <label class="sprog-field sprog-field--wide">
                            <span class="sprog-field--label"><?= rex_i18n::msg('sprog_glossary_add_notes') ?></span>
                            <textarea name="notes" class="sprog-control sprog-control--textarea"
                                      maxlength="<?= rex_escape((string) GlossaryService::MAX_NOTES_LENGTH) ?>"
                                      rows="2"><?= rex_escape($formNotes) ?></textarea>
                        </label>

                        <div class="sprog-glossary--add-actions">
                            <?php if ($isEdit) : ?>
                                <a class="sprog-btn" href="<?= rex_escape(rex_url::currentBackendPage([], false)) ?>"><?= rex_i18n::msg('sprog_glossary_edit_cancel') ?></a>
                            <?php endif ?>
                            <button type="submit" class="sprog-btn sprog-btn--primary">
                                <?= rex_i18n::msg($isEdit ? 'sprog_glossary_edit_submit' : 'sprog_glossary_add_submit') ?>
                            </button>
                        </div>
                    </form>
                </div>
            </details>
        </div>

        <!-- Listen-Header: Anzahl links, Sprachpaar-Filter rechts (wie Inbox). -->
        <div class="sprog-list-header">
            <p class="sprog-summary">
                <strong><?= rex_escape((string) $count) ?></strong>
                <?= rex_i18n::msg(1 === $count ? 'sprog_glossary_summary_word_one' : 'sprog_glossary_summary_word_many') ?>
            </p>

            <div class="sprog-list-header--filters">
                <label class="sprog-cell">
                    <span class="sprog-cell--label"><?= rex_i18n::msg('sprog_glossary_filter_target') ?></span>
                    <select name="target_clang" form="<?= $filterFormId ?>" data-filter-auto class="sprog-cell--select">
                        <option value="0"><?= rex_i18n::msg('sprog_glossary_filter_all') ?></option>
                        <?php foreach ($clangs as $cid => $clang) :
                            if (!$clangPerm->hasPerm($cid)) {
                                continue;
                            }
                        ?>
                            <option value="<?= rex_escape((string) $cid) ?>" <?= $cid === $filterTarget ? 'selected' : '' ?>>
                                <?= rex_escape($clang->getCode()) ?> · <?= rex_escape($clang->getName()) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </label>
            </div>
        </div>

        <?php if ([] === $entries) : ?>
            <p class="sprog-empty"><?= rex_i18n::msg($isFiltered ? 'sprog_glossary_empty_filtered' : 'sprog_glossary_empty') ?></p>
        <?php else : ?>
            <noscript>
                <p class="rex-alert rex-alert-warning"><?= rex_i18n::msg('sprog_noscript_confirm_warning') ?></p>
            </noscript>
            <ul class="sprog-list" role="list">
                <?php foreach ($entries as $entry) :
                    $srcClang = rex_clang::get($entry->sourceClangId);
                    $srcCode = null !== $srcClang ? $srcClang->getCode() : (string) $entry->sourceClangId;
                    // Ziel 0 = „Alle" (Kurzform für die schmale Badge), sonst der
                    // konkrete Sprachcode.
                    if (0 === $entry->targetClangId) {
                        $tgtCode = rex_i18n::msg('sprog_glossary_target_all_short');
                    } else {
                        $tgtClang = rex_clang::get($entry->targetClangId);
                        $tgtCode = null !== $tgtClang ? $tgtClang->getCode() : (string) $entry->targetClangId;
                    }
                ?>
                    <li class="sprog-row sprog-row--aligned">
                        <span class="sprog-row--icon sprog-glossary--langs"
                              title="<?= rex_escape(rex_i18n::msg('sprog_glossary_pair_aria', $srcCode, $tgtCode)) ?>">
                            <span class="sprog-clang-code"><?= rex_escape($srcCode) ?></span>
                            <span class="sprog-glossary--langs-sep" aria-hidden="true">→</span>
                            <span class="sprog-clang-code"><?= rex_escape($tgtCode) ?></span>
                        </span>

                        <span class="sprog-row--title sprog-glossary--source-term"><?= rex_escape($entry->sourceTerm) ?></span>
                        <span class="sprog-row--body">
                            <span class="sprog-glossary--arrow" aria-hidden="true">→</span>
                            <span class="sprog-glossary--target-term"><?= rex_escape($entry->targetTerm) ?></span>
                        </span>

                        <?php if (null !== $entry->notes) : ?>
                            <p class="sprog-hint sprog-glossary--notes"><?= rex_escape($entry->notes) ?></p>
                        <?php endif ?>

                        <span class="sprog-row--trailing sprog-glossary--row-actions">
                            <a class="sprog-btn sprog-btn--icon"
                               href="<?= rex_escape(rex_url::currentBackendPage(['action' => 'edit', 'id' => $entry->id], false)) ?>"
                               title="<?= rex_escape(rex_i18n::msg('sprog_glossary_edit_button')) ?>"
                               aria-label="<?= rex_escape(rex_i18n::msg('sprog_glossary_edit_button')) ?>">
                                <?= $pencilSvg ?>
                            </a>
                            <form method="post"
                                  class="sprog-glossary--delete-form"
                                  data-confirm="<?= rex_i18n::msg('sprog_glossary_delete_confirm') ?>">
                                <?= $csrf->getHiddenField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= rex_escape((string) $entry->id) ?>">
                                <input type="hidden" name="target_clang" value="<?= rex_escape((string) $filterTarget) ?>">
                                <input type="hidden" name="search" value="<?= rex_escape($search) ?>">
                                <button type="submit"
                                        class="sprog-btn sprog-btn--icon sprog-glossary--delete-button"
                                        title="<?= rex_escape(rex_i18n::msg('sprog_glossary_delete_button')) ?>"
                                        aria-label="<?= rex_escape(rex_i18n::msg('sprog_glossary_delete_button')) ?>">
                                    <?= $trashSvg ?>
                                </button>
                            </form>
                        </span>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
</article>

<script>
// Sprach-Filter sofort anwenden (Progressive Enhancement; ohne JS bleibt der
// „Filtern"-Button der Weg). Die Selects sind per form-Attribut dem GET-Form
// zugeordnet, submit() reicht also über die DOM-Distanz hinweg.
document.querySelectorAll('.sprog-glossary [data-filter-auto]').forEach((el) => {
    el.addEventListener('change', () => {
        const form = el.form;
        if (!form) {
            return;
        }
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });
});

// „Nicht übersetzen": Ziel-Block (Sprache + Ziel-Begriff) aus-/einblenden.
document.querySelectorAll('.sprog-glossary [data-role="glossary-verbatim"]').forEach((cb) => {
    const form = cb.closest('form');
    const block = form ? form.querySelector('[data-role="glossary-target-block"]') : null;
    if (!block) {
        return;
    }
    const sync = () => { block.hidden = cb.checked; };
    cb.addEventListener('change', sync);
    sync();
});

// Confirm-Dialog für Löschen-Forms (data-confirm-Attribut).
document.querySelectorAll('.sprog-glossary [data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const msg = form.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            event.preventDefault();
        }
    });
});
</script>
