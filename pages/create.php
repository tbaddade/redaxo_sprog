<?php

declare(strict_types=1);

use Sprog\Enum\SourceType;
use Sprog\Model\Unit;
use Sprog\Repository\UnitRepository;
use Sprog\Service\TranslationService;
use Sprog\Support\Labels;

$user = rex::getUser();
if (null === $user) {
    throw new rex_exception('Zugriff verweigert.');
}

$csrf = rex_csrf_token::factory('sprog_create');
$units = new UnitRepository();

$flashMessages = [];

/* Form-Werte aus POST (mit Default-Echo, damit nach einem Validation-Fehler
   die Eingaben nicht verloren gehen). */
$namespaceInput = trim((string) rex_request('namespace', 'string', 'wildcard'));
$unitKeyInput = trim((string) rex_request('unit_key', 'string', ''));
$notesInput = trim((string) rex_request('notes', 'string', ''));

if ('post' === rex_request::requestMethod()) {
    if (!$csrf->isValid()) {
        $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_create_csrf_invalid'));
    } else {
        /*
         |---------------------------------------------------------------
         | Validierung
         |---------------------------------------------------------------
         */
        $errors = [];

        if (!in_array($namespaceInput, SourceType::values(), true)) {
            $errors[] = rex_i18n::msg('sprog_create_namespace_invalid');
        }
        if ('' === $unitKeyInput) {
            $errors[] = rex_i18n::msg('sprog_create_key_empty');
        } elseif (strlen($unitKeyInput) > 191) {
            $errors[] = rex_i18n::msg('sprog_create_key_too_long');
        }
        if (strlen($notesInput) > 500) {
            $errors[] = rex_i18n::msg('sprog_create_notes_too_long');
        }

        // Duplikat-Check: (namespace, unit_key) ist UNIQUE im Schema.
        if ([] === $errors && null !== $units->findByKey($namespaceInput, $unitKeyInput)) {
            $errors[] = rex_i18n::msg('sprog_create_duplicate', $unitKeyInput, $namespaceInput);
        }

        if ([] === $errors) {
            try {
                $sourceType = SourceType::tryFrom($namespaceInput);

                $unit = $units->save(new Unit(
                    id: null,
                    namespace: $namespaceInput,
                    unitKey: $unitKeyInput,
                    sourceType: $sourceType,
                    sourceRef: null,
                    sourceHash: null,
                    tags: [],
                    notes: '' === $notesInput ? null : $notesInput,
                ));

                /*
                 * Für jede definierte clang eine missing-Translation anlegen
                 * — unabhängig davon, ob der erstellende User dort Edit-Rechte
                 * hat. Sonst entsteht Drift: Inbox-Filter "missing in clang X"
                 * würde Units, die der User ohne X-Recht angelegt hat, nie
                 * finden. Edit-Permission greift später auf der Editor-Seite.
                 */
                TranslationService::create()->ensureRowsForUnit($unit);

                rex_response::sendRedirect(rex_url::backendPage(
                    'sprog/editor',
                    ['unit' => $unit->id],
                    false,
                ));
                exit;
            } catch (Throwable $e) {
                $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_create_error', $e->getMessage()));
            }
        } else {
            foreach ($errors as $err) {
                $flashMessages[] = rex_view::error($err);
            }
        }
    }
}

?>
<article class="sprog-create">
    <header class="sprog-create--intro">
        <a class="sprog-create--back" href="<?= rex_escape(rex_url::backendPage('sprog/inbox', [], false)) ?>">
            <?= rex_i18n::msg('sprog_create_back') ?>
        </a>
        <h1 class="sprog-create--heading"><?= rex_i18n::msg('sprog_create_heading') ?></h1>
        <p class="sprog-create--lead"><?= rex_i18n::rawMsg('sprog_create_lead') ?></p>
    </header>

    <?php foreach ($flashMessages as $msg) {
        echo $msg;
    } ?>

    <form method="post" class="sprog-create--form">
        <?= $csrf->getHiddenField() ?>

        <label class="sprog-create--field">
            <span class="sprog-create--label"><?= rex_i18n::msg('sprog_create_namespace_label') ?></span>
            <select name="namespace" class="sprog-create--select" required>
                <?php foreach (SourceType::values() as $ns) : ?>
                    <option
                        value="<?= rex_escape($ns) ?>"
                        <?= $ns === $namespaceInput ? 'selected' : '' ?>
                    ><?= Labels::forNamespace($ns) ?></option>
                <?php endforeach ?>
            </select>
            <span class="sprog-create--hint"><?= rex_i18n::msg('sprog_create_namespace_hint') ?></span>
        </label>

        <label class="sprog-create--field">
            <span class="sprog-create--label"><?= rex_i18n::msg('sprog_create_unitkey_label') ?></span>
            <input
                type="text"
                name="unit_key"
                class="sprog-create--input"
                value="<?= rex_escape($unitKeyInput) ?>"
                required
                maxlength="191"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
            >
            <span class="sprog-create--hint"><?= rex_i18n::rawMsg('sprog_create_unitkey_hint') ?></span>
        </label>

        <label class="sprog-create--field">
            <span class="sprog-create--label"><?= rex_i18n::msg('sprog_create_notes_label') ?></span>
            <textarea
                name="notes"
                class="sprog-create--textarea"
                maxlength="500"
                rows="3"
                placeholder="<?= rex_i18n::msg('sprog_create_notes_placeholder') ?>"
            ><?= rex_escape($notesInput) ?></textarea>
        </label>

        <div class="sprog-create--actions">
            <button type="submit" class="sprog-create--button sprog-create--button-primary">
                <?= rex_i18n::msg('sprog_create_submit') ?>
            </button>
            <a
                class="sprog-create--button sprog-create--button-ghost"
                href="<?= rex_escape(rex_url::backendPage('sprog/inbox', [], false)) ?>"
            ><?= rex_i18n::msg('sprog_create_cancel') ?></a>
        </div>
    </form>
</article>
