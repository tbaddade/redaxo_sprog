<?php

declare(strict_types=1);

use Sprog\Service\GlossaryService;

$user = rex::getUser();
if (null === $user || !$user->isAdmin()) {
    throw new rex_exception('Zugriff verweigert.');
}

$csrf          = rex_csrf_token::factory('sprog_glossary');
$service       = GlossaryService::create();
$flashMessages = [];
$action        = rex_request('action', 'string', '');

/*
 |---------------------------------------------------------------------------
 | Sprach-Auswahl (GET-Filter, damit URLs shareable bleiben)
 |---------------------------------------------------------------------------
 */
$clangs = rex_clang::getAll();

$defaultSource = rex_clang::getStartId();
$defaultTarget = 0;
foreach ($clangs as $cid => $_clang) {
    if ($cid !== $defaultSource) {
        $defaultTarget = $cid;
        break;
    }
}

$sourceClangId = (int) rex_request('source_clang', 'int', $defaultSource);
$targetClangId = (int) rex_request('target_clang', 'int', $defaultTarget);

$pairIsValid = $sourceClangId > 0
    && $targetClangId > 0
    && rex_clang::exists($sourceClangId)
    && rex_clang::exists($targetClangId)
    && $sourceClangId !== $targetClangId
    && $user->getComplexPerm('clang')->hasPerm($sourceClangId)
    && $user->getComplexPerm('clang')->hasPerm($targetClangId);

/*
 |---------------------------------------------------------------------------
 | POST: Eintrag anlegen
 |---------------------------------------------------------------------------
 */
if ('add' === $action) {
    if (!$csrf->isValid()) {
        $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_glossary_add_csrf'));
    } elseif (!$pairIsValid) {
        $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_glossary_clang_no_perm'));
    } else {
        $sourceTerm = trim((string) rex_request('source_term', 'string', ''));
        $targetTerm = trim((string) rex_request('target_term', 'string', ''));
        $notesIn    = trim((string) rex_request('notes', 'string', ''));
        $notes      = '' === $notesIn ? null : $notesIn;

        try {
            $service->add($sourceClangId, $targetClangId, $sourceTerm, $targetTerm, $notes);
            $flashMessages[] = rex_view::success(rex_i18n::msg(
                'sprog_glossary_add_success',
                $sourceTerm,
                $targetTerm,
            ));
        } catch (InvalidArgumentException $e) {
            $flashMessages[] = rex_view::error(rex_escape($e->getMessage()));
        } catch (rex_sql_exception $e) {
            // UNIQUE-Constraint-Verstoss landet hier — sauberer wäre ein eigener
            // ErrorCode aus dem SQLState, aber das Message-Match reicht für die
            // einzige Constraint auf der Tabelle.
            if (false !== stripos($e->getMessage(), 'duplicate')) {
                $flashMessages[] = rex_view::error(rex_i18n::msg(
                    'sprog_glossary_add_duplicate',
                    $sourceTerm,
                ));
            } else {
                $flashMessages[] = rex_view::error(rex_i18n::msg(
                    'sprog_glossary_add_error',
                    $e->getMessage(),
                ));
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
$entries = $pairIsValid ? $service->listForPair($sourceClangId, $targetClangId) : [];

?>
<article class="sprog-glossary">
    <header class="sprog-glossary--intro">
        <h1 class="sprog-glossary--heading"><?= rex_i18n::msg('sprog_glossary_heading') ?></h1>
        <p class="sprog-glossary--lead"><?= rex_i18n::msg('sprog_glossary_lead') ?></p>
    </header>

    <?php foreach ($flashMessages as $msg) {
        echo $msg;
    } ?>

    <form method="get" class="sprog-glossary--filter">
        <input type="hidden" name="page" value="sprog/glossary">

        <label class="sprog-glossary--field">
            <span class="sprog-glossary--label"><?= rex_i18n::msg('sprog_glossary_filter_source') ?></span>
            <select name="source_clang">
                <?php foreach ($clangs as $cid => $clang) :
                    if (!$user->getComplexPerm('clang')->hasPerm($cid)) {
                        continue;
                    }
                ?>
                    <option value="<?= rex_escape((string) $cid) ?>"
                            <?= $cid === $sourceClangId ? 'selected' : '' ?>>
                        <?= rex_escape($clang->getCode()) ?> · <?= rex_escape($clang->getName()) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="sprog-glossary--field">
            <span class="sprog-glossary--label"><?= rex_i18n::msg('sprog_glossary_filter_target') ?></span>
            <select name="target_clang">
                <?php foreach ($clangs as $cid => $clang) :
                    if (!$user->getComplexPerm('clang')->hasPerm($cid)) {
                        continue;
                    }
                ?>
                    <option value="<?= rex_escape((string) $cid) ?>"
                            <?= $cid === $targetClangId ? 'selected' : '' ?>>
                        <?= rex_escape($clang->getCode()) ?> · <?= rex_escape($clang->getName()) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="sprog-glossary--filter-actions">
            <button type="submit" class="sprog-glossary--button sprog-glossary--button-primary">
                <?= rex_i18n::msg('sprog_glossary_filter_submit') ?>
            </button>
        </div>
    </form>

    <?php if (!$pairIsValid) : ?>
        <p class="sprog-glossary--empty"><?= rex_i18n::msg('sprog_glossary_same_lang') ?></p>
    <?php else :
        $sourceClang = rex_clang::get($sourceClangId);
        $targetClang = rex_clang::get($targetClangId);
    ?>
        <section class="sprog-glossary--add" aria-labelledby="sprog-glossary-add-heading">
            <h2 id="sprog-glossary-add-heading" class="sprog-glossary--section-heading">
                <?= rex_i18n::msg('sprog_glossary_add_heading') ?>
            </h2>

            <form method="post" class="sprog-glossary--add-form">
                <?= $csrf->getHiddenField() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="source_clang" value="<?= rex_escape((string) $sourceClangId) ?>">
                <input type="hidden" name="target_clang" value="<?= rex_escape((string) $targetClangId) ?>">

                <label class="sprog-glossary--field">
                    <span class="sprog-glossary--label">
                        <?= rex_i18n::msg('sprog_glossary_add_source_term') ?>
                        <?php if (null !== $sourceClang) : ?>
                            <span class="sprog-glossary--clang-tag"><?= rex_escape($sourceClang->getCode()) ?></span>
                        <?php endif; ?>
                    </span>
                    <input type="text"
                           name="source_term"
                           class="sprog-glossary--input"
                           required
                           maxlength="<?= rex_escape((string) GlossaryService::MAX_TERM_LENGTH) ?>"
                           autocomplete="off"
                           spellcheck="false">
                </label>

                <label class="sprog-glossary--field">
                    <span class="sprog-glossary--label">
                        <?= rex_i18n::msg('sprog_glossary_add_target_term') ?>
                        <?php if (null !== $targetClang) : ?>
                            <span class="sprog-glossary--clang-tag"><?= rex_escape($targetClang->getCode()) ?></span>
                        <?php endif; ?>
                    </span>
                    <input type="text"
                           name="target_term"
                           class="sprog-glossary--input"
                           required
                           maxlength="<?= rex_escape((string) GlossaryService::MAX_TERM_LENGTH) ?>"
                           autocomplete="off"
                           spellcheck="false">
                </label>

                <label class="sprog-glossary--field sprog-glossary--field-notes">
                    <span class="sprog-glossary--label"><?= rex_i18n::msg('sprog_glossary_add_notes') ?></span>
                    <textarea name="notes"
                              class="sprog-glossary--textarea"
                              maxlength="<?= rex_escape((string) GlossaryService::MAX_NOTES_LENGTH) ?>"
                              rows="2"></textarea>
                </label>

                <div class="sprog-glossary--add-actions">
                    <button type="submit" class="sprog-glossary--button sprog-glossary--button-primary">
                        <?= rex_i18n::msg('sprog_glossary_add_submit') ?>
                    </button>
                </div>
            </form>
        </section>

        <p class="sprog-glossary--count">
            <?= rex_i18n::msg(
                1 === count($entries) ? 'sprog_glossary_count_one' : 'sprog_glossary_count_many',
                (string) count($entries),
            ) ?>
        </p>

        <?php if ([] === $entries) : ?>
            <p class="sprog-glossary--empty"><?= rex_i18n::msg('sprog_glossary_empty') ?></p>
        <?php else : ?>
            <ul class="sprog-glossary--list" role="list">
                <?php foreach ($entries as $entry) : ?>
                    <li class="sprog-glossary--card">
                        <div class="sprog-glossary--pair">
                            <span class="sprog-glossary--source-term"><?= rex_escape($entry->sourceTerm) ?></span>
                            <span class="sprog-glossary--arrow" aria-hidden="true">→</span>
                            <span class="sprog-glossary--target-term"><?= rex_escape($entry->targetTerm) ?></span>
                        </div>

                        <?php if (null !== $entry->notes) : ?>
                            <p class="sprog-glossary--notes"><?= rex_escape($entry->notes) ?></p>
                        <?php endif; ?>

                        <form method="post"
                              class="sprog-glossary--delete-form"
                              data-confirm="<?= rex_i18n::msg('sprog_glossary_delete_confirm') ?>">
                            <?= $csrf->getHiddenField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= rex_escape((string) $entry->id) ?>">
                            <input type="hidden" name="source_clang" value="<?= rex_escape((string) $sourceClangId) ?>">
                            <input type="hidden" name="target_clang" value="<?= rex_escape((string) $targetClangId) ?>">
                            <button type="submit"
                                    class="sprog-glossary--button sprog-glossary--button-ghost">
                                <?= rex_i18n::msg('sprog_glossary_delete_button') ?>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</article>

<script>
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
