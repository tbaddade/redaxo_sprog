<?php

/* set up base structure */

use Sprog\Copy\StructureMetadata;

$csrfToken = \rex_csrf_token::factory('sprog-copy-metadata');

if (!$csrfToken->isValid()) {
    echo \rex_view::error(\rex_i18n::msg('csrf_token_invalid'));
    return;
}

$body = '
    <h3 class="sprog-copy__target__title"></h3>
    <hr>
    <div class="sprog-copy__target__content row"></div>
    <div class="sprog-copy__target__progressbar"></div>';

$footer = '
    <div class="row">
        <div class="col-xs-12 sprog-copy__target__footer"></div>
    </div>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'sprog-copy');
$fragment->setVar('body', $body, false);
$fragment->setVar('footer', $footer, false);
echo $fragment->parse('core/page/section.php');

/* add sprog items JSON */

echo '
<script nonce="'.rex_response::getNonce().'">
    var sprogItems = '.json_encode(StructureMetadata::prepareItems()).'; 
    var sprogGeneratePage = "sprog.copy.structure_metadata_generate";
    var sprogCsrfToken = "'.\rex_string::buildQuery($csrfToken->getUrlParams()).'";
</script>';
?>


<?php /* templates: content */ ?>

<script id="sprog_copy_tpl_content_task" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <div class="col-xs-6">
        <p class="sprog-copy__target__task"></p>
    </div>
    <div class="col-xs-6 text-right">
        <p class="sprog-copy__target__elapsed"></p>
    </div>
</script>

<script id="sprog_copy_tpl_content_info" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <div class="col-xs-2 text-right sprog-copy__target__icon">
    </div>
    <div class="col-xs-10 sprog-copy__target__text">
    </div>
</script>


<?php /* templates: components */ ?>

<script id="sprog_copy_tpl_stopwatch" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <?php echo rex_i18n::rawMsg('sprog_copy_time_elapsed') ?>: <span id="sprog_copy_time"></span>
</script>


<script id="sprog_copy_tpl_progressbar" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <div class="progress sprog-progressbar sprog-copy__progressbar">
        <div class="progress-bar progress-bar-striped active" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
    </div>
</script>


<?php /* templates: titles */ ?>

<script id="sprog_copy_tpl_title_articles" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <?php echo rex_i18n::rawMsg('sprog_copy_structure_metadata_title') ?>
</script>


<script id="sprog_copy_tpl_title_finished" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <?php echo rex_i18n::rawMsg('sprog_copy_structure_metadata_finished_title') ?>
</script>


<script id="sprog_copy_tpl_title_error" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <?php echo rex_i18n::rawMsg('sprog_copy_error_title') ?>
</script>


<script id="sprog_copy_tpl_title_nothing" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <?php echo rex_i18n::rawMsg('sprog_copy_nothing_title') ?>
</script>


<?php /* templates: progress */ ?>

<script id="sprog_copy_tpl_progress_articles" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <?php echo rex_i18n::rawMsg('sprog_copy_structure_metadata_progress') ?>
</script>


<?php /* templates: icons */ ?>

<script id="sprog_copy_tpl_icon_finished" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <i class="fa fa-flag fa-5x sprog-text-finished" aria-hidden="true"></i>
</script>


<script id="sprog_copy_tpl_icon_error" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <i class="fa fa-meh-o fa-5x sprog-text-error" aria-hidden="true"></i>
</script>


<script id="sprog_copy_tpl_icon_nothing" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <i class="fa fa-user-md fa-5x" aria-hidden="true"></i>
</script>


<?php /* templates: texts */ ?>

<script id="sprog_copy_tpl_text_finished" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <p><?php echo rex_i18n::rawMsg('sprog_copy_structure_metadata_finished_text') ?></p>
</script>


<script id="sprog_copy_tpl_text_error" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <p><?php echo rex_i18n::rawMsg('sprog_copy_error_text') ?></p>
</script>


<script id="sprog_copy_tpl_text_nothing" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <p><?php echo rex_i18n::rawMsg('sprog_copy_nothing_text') ?></p>
</script>


<?php /* templates: links */ ?>

<script id="sprog_copy_tpl_error_link" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <?php echo rex_i18n::rawMsg('sprog_copy_error_link') ?>
</script>


<?php /* templates: buttons */ ?>

<script id="sprog_copy_tpl_button_success" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <button class="btn btn-success sprog-button-success"><?php echo rex_i18n::rawMsg('sprog_copy_button_success') ?></button>
</script>


<script id="sprog_copy_tpl_button_again" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <div class="text-right">
        <button class="btn btn-link sprog-button-again"><?php echo rex_i18n::rawMsg('sprog_copy_button_again') ?></button>
        <button class="btn btn-danger sprog-button-cancel"><?php echo rex_i18n::rawMsg('sprog_copy_button_cancel') ?></button>
    </div>
</script>


<script id="sprog_copy_tpl_button_cancel" type="text/x-handlebars-template" nonce="<?= rex_response::getNonce() ?>">
    <div class="text-right">
        <button class="btn btn-danger sprog-button-cancel"><?php echo rex_i18n::rawMsg('sprog_copy_button_cancel') ?></button>
    </div>
</script>
