<?php

declare(strict_types=1);

use Sprog\Enum\SourceType;
use Sprog\Exception\UnitValidationException;
use Sprog\Service\UnitService;
use Sprog\Support\Labels;

$user = rex::getUser();
if (null === $user) {
    throw new rex_exception('Zugriff verweigert.');
}

$csrf = rex_csrf_token::factory('sprog_create');

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
        try {
            $unit = UnitService::create()->createUnit($namespaceInput, $unitKeyInput, '', $notesInput, (int) $user->getId());

            // No-JS-Fallback (mit JS läuft das Anlegen im Inbox-Modal):
            // zurück in die Inbox mit der frisch angelegten Unit aufgeklappt.
            rex_response::sendRedirect(rex_url::backendPage(
                'sprog/inbox',
                ['open_unit' => $unit->id],
                false,
            ));
            exit;
        } catch (UnitValidationException $e) {
            foreach ($e->errors as $err) {
                $flashMessages[] = rex_view::error(rex_escape($err));
            }
        } catch (Throwable $e) {
            $flashMessages[] = rex_view::error(rex_escape(rex_i18n::rawMsg('sprog_create_error', $e->getMessage())));
        }
    }
}

?>
<article class="sprog-ui sprog-create">
    <header class="sprog-intro">
        <a class="sprog-create--back" href="<?= rex_escape(rex_url::backendPage('sprog/inbox', [], false)) ?>">
            <?= rex_i18n::msg('sprog_create_back') ?>
        </a>
        <h1 class="sprog-heading"><?= rex_i18n::msg('sprog_create_heading') ?></h1>
        <p class="sprog-lead"><?= rex_i18n::rawMsg('sprog_create_lead') ?></p>
    </header>

    <?php foreach ($flashMessages as $msg) {
        echo $msg;
    } ?>

    <form method="post" class="sprog-panel sprog-create--form">
        <?= $csrf->getHiddenField() ?>

        <label class="sprog-field">
            <span class="sprog-field--label"><?= rex_i18n::msg('sprog_create_namespace_label') ?></span>
            <select name="namespace" class="sprog-control" required>
                <?php foreach (SourceType::userCreatable() as $ns) : ?>
                    <option
                        value="<?= rex_escape($ns) ?>"
                        <?= $ns === $namespaceInput ? 'selected' : '' ?>
                    ><?= Labels::forNamespace($ns) ?></option>
                <?php endforeach ?>
            </select>
            <span class="sprog-hint"><?= rex_i18n::msg('sprog_create_namespace_hint') ?></span>
        </label>

        <label class="sprog-field">
            <span class="sprog-field--label"><?= rex_i18n::msg('sprog_create_unitkey_label') ?></span>
            <input
                type="text"
                name="unit_key"
                class="sprog-control"
                value="<?= rex_escape($unitKeyInput) ?>"
                required
                maxlength="191"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
            >
            <span class="sprog-hint"><?= rex_i18n::rawMsg('sprog_create_unitkey_hint') ?></span>
        </label>

        <label class="sprog-field">
            <span class="sprog-field--label"><?= rex_i18n::msg('sprog_create_notes_label') ?></span>
            <textarea
                name="notes"
                class="sprog-control sprog-control--textarea"
                maxlength="500"
                rows="3"
                placeholder="<?= rex_i18n::msg('sprog_create_notes_placeholder') ?>"
            ><?= rex_escape($notesInput) ?></textarea>
        </label>

        <div class="sprog-create--actions">
            <button type="submit" class="sprog-btn sprog-btn--primary">
                <?= rex_i18n::msg('sprog_create_submit') ?>
            </button>
            <a
                class="sprog-btn"
                href="<?= rex_escape(rex_url::backendPage('sprog/inbox', [], false)) ?>"
            ><?= rex_i18n::msg('sprog_create_cancel') ?></a>
        </div>
    </form>
</article>
