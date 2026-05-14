<?php

declare(strict_types=1);

use Sprog\Service\MtService;

$user = rex::getUser();
if (null === $user || !$user->isAdmin()) {
    throw new rex_exception('Zugriff verweigert.');
}

$csrf          = rex_csrf_token::factory('sprog_settings_mt');
$flashMessages = [];

/*
 |---------------------------------------------------------------------------
 | POST: API-Keys aktualisieren / entfernen
 |---------------------------------------------------------------------------
 | Pattern für Password-Felder:
 |   - Bei jedem Render: value="" (Key wird nie zurückgespielt)
 |   - Submit mit leerem Feld + nicht-gesetzter Remove-Checkbox: keine Änderung
 |   - Submit mit gefülltem Feld: überschreiben
 |   - Submit mit gesetzter Remove-Checkbox: löschen
 */
if ('post' === rex_request::requestMethod()) {
    if (!$csrf->isValid()) {
        $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_settings_mt_csrf_invalid'));
    } else {
        $deeplKeyInput  = trim((string) rex_request('deepl_key', 'string', ''));
        $deeplKeyRemove = 1 === (int) rex_request('deepl_key_remove', 'int', 0);
        $changed        = 0;

        if ($deeplKeyRemove) {
            rex_config::remove('sprog', 'mt_deepl_key');
            ++$changed;
        } elseif ('' !== $deeplKeyInput) {
            // Defensive Längen-/Format-Check: DeepL-Keys haben das Format
            // UUID + optionales ":fx"-Suffix; jedenfalls sicher unter 200 Zeichen.
            if (strlen($deeplKeyInput) > 200) {
                $flashMessages[] = rex_view::error(rex_i18n::msg('sprog_settings_mt_key_too_long'));
            } else {
                rex_config::set('sprog', 'mt_deepl_key', $deeplKeyInput);
                ++$changed;
            }
        }

        if ($changed > 0 && [] === $flashMessages) {
            $flashMessages[] = rex_view::success(rex_i18n::msg('sprog_settings_mt_saved'));
        }
    }
}

/*
 |---------------------------------------------------------------------------
 | Aktueller Status — frisch aus rex_config gelesen, nach POST also schon updated.
 |---------------------------------------------------------------------------
 */
$mtService       = MtService::create();
$configuredNames = $mtService->configuredProviderNames();

// Wir definieren die UI-bekannten Provider (alles ausser Noop) explizit als
// Liste; so kann auch ein Provider, der gerade nicht konfiguriert ist,
// als Slot in der UI auftauchen.
$uiProviders = [
    'deepl' => [
        'label'            => 'DeepL',
        'configured'       => in_array('deepl', $configuredNames, true),
        'key_field'        => 'deepl_key',
        'remove_field'     => 'deepl_key_remove',
        'hint_lang_key'    => 'sprog_settings_mt_deepl_key_hint',
    ],
];

?>
<article class="sprog-settings-mt">
    <header class="sprog-settings-mt--intro">
        <h1 class="sprog-settings-mt--heading"><?= rex_i18n::msg('sprog_settings_mt_heading') ?></h1>
        <p class="sprog-settings-mt--lead"><?= rex_i18n::msg('sprog_settings_mt_lead') ?></p>
    </header>

    <?php foreach ($flashMessages as $msg) {
        echo $msg;
    } ?>

    <form method="post" class="sprog-settings-mt--form" autocomplete="off">
        <?= $csrf->getHiddenField() ?>

        <?php foreach ($uiProviders as $name => $cfg) :
            $statusKey   = $cfg['configured']
                ? 'sprog_settings_mt_status_configured'
                : 'sprog_settings_mt_status_not_configured';
            $statusModif = $cfg['configured'] ? 'approved' : 'missing';
            $placeholder = $cfg['configured']
                ? rex_i18n::msg('sprog_settings_mt_key_placeholder_set')
                : rex_i18n::msg('sprog_settings_mt_key_placeholder_unset');
        ?>
            <fieldset class="sprog-settings-mt--provider">
                <legend class="sprog-settings-mt--provider-head">
                    <span class="sprog-settings-mt--provider-label"><?= rex_escape($cfg['label']) ?></span>
                    <span class="sprog-status sprog-status--<?= rex_escape($statusModif) ?>">
                        <?= rex_i18n::msg($statusKey) ?>
                    </span>
                </legend>

                <label class="sprog-settings-mt--field">
                    <span class="sprog-settings-mt--label">
                        <?= rex_i18n::msg('sprog_settings_mt_deepl_key_label') ?>
                    </span>
                    <input
                        type="password"
                        name="<?= rex_escape($cfg['key_field']) ?>"
                        value=""
                        placeholder="<?= rex_escape($placeholder) ?>"
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="off"
                        spellcheck="false"
                        maxlength="200"
                        class="sprog-settings-mt--input"
                    >
                    <span class="sprog-settings-mt--hint">
                        <?= rex_i18n::rawMsg($cfg['hint_lang_key']) ?>
                    </span>
                </label>

                <?php if ($cfg['configured']) : ?>
                    <label class="sprog-settings-mt--check">
                        <input
                            type="checkbox"
                            name="<?= rex_escape($cfg['remove_field']) ?>"
                            value="1"
                        >
                        <span><?= rex_i18n::msg('sprog_settings_mt_key_remove') ?></span>
                    </label>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>

        <div class="sprog-settings-mt--actions">
            <button type="submit" class="sprog-settings-mt--button sprog-settings-mt--button-primary">
                <?= rex_i18n::msg('sprog_settings_mt_save') ?>
            </button>
        </div>
    </form>
</article>
