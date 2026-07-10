<?php

declare(strict_types=1);

use Sprog\Controller\Inbox\InboxRouter;
use Sprog\Enum\SourceType;
use Sprog\Enum\Status;
use Sprog\Model\TranslationListFilter;
use Sprog\Mt\AiPlatformProvider;
use Sprog\Support\BaseLang;
use Sprog\Support\ClangBase;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Sprog\Service\MtService;
use Sprog\Service\TranslationListService;
use Sprog\Service\WildcardConflictService;
use Sprog\Service\WorkflowService;
use Sprog\Support\Labels;
use Sprog\View\InboxRowActions;

$user = rex::getUser();
if (null === $user) {
    throw new rex_exception('Zugriff verweigert.');
}

/*
 |---------------------------------------------------------------------------
 | JSON-Endpoints
 |---------------------------------------------------------------------------
 | Die vier Inbox-Endpoints (save / update_unit / create_unit / transition)
 | leben jeweils in einer Controller-Klasse unter Sprog\Controller\Inbox\*.
 | Bekannte $func-Werte werden vom Router dispatched; der Controller exitet
 | via JsonResponse direkt. Unbekannte $func fallen durch zum HTML-Render.
 */
$func = (string) rex_request('func', 'string', '');
InboxRouter::dispatch($func, $user);

/*
 |---------------------------------------------------------------------------
 | Filter-Eingaben einlesen (GET-basiert, damit URLs shareable bleiben)
 |---------------------------------------------------------------------------
 */
// Nur eigenständig übersetzbare Sprachen. Via Sprachbasis (clang_base)
// abgeleitete Sprachen (z. B. nl_BE → nl_NL) spiegeln eine andere Sprache und
// werden nicht eigenständig übersetzt — sie erscheinen daher weder als
// Anzeige-Sprache noch in Coverage/Badges/Zeilen/Batch.
$clangs = ClangBase::translatableClangs();

// Default-Sprache: aktuelle Backend-Sprache, sonst erste erlaubte. Muss
// eigenständig sein (in $clangs) — eine abgeleitete Backend-Sprache fällt durch.
$defaultClang = rex_clang::getCurrentId();
if (!isset($clangs[$defaultClang]) || !$user->getComplexPerm('clang')->hasPerm($defaultClang)) {
    $defaultClang = 0;
    foreach ($clangs as $id => $_clang) {
        if ($user->getComplexPerm('clang')->hasPerm($id)) {
            $defaultClang = $id;
            break;
        }
    }
}

$clangId = (int) rex_request('clang_id', 'int', $defaultClang);
$namespaceInput = trim((string) rex_request('namespace', 'string', ''));
$statusesInput = rex_request('status', 'array', []);
$searchInput = trim((string) rex_request('search', 'string', ''));
$page = max(1, (int) rex_request('pg', 'int', 1));
$pageSize = (int) rex_request('page_size', 'int', TranslationListFilter::DEFAULT_PAGE_SIZE);
$openUnit = (int) rex_request('open_unit', 'int', 0);
// Konflikt-Filter: nur Einträge mit Wildcard-Mehrdeutigkeit (rotes Dreieck).
$conflictsOnly = (bool) rex_request('conflict', 'bool', false);

// Sortierung: Feld + Richtung. Ungültige (z.B. manipulierte) Werte fallen still
// auf den Standard zurück, damit ein alter Link die Seite nicht mit einer
// Exception abbricht.
$sortInput = (string) rex_request('sort', 'string', TranslationListFilter::DEFAULT_SORT);
if (!in_array($sortInput, TranslationListFilter::SORT_FIELDS, true)) {
    $sortInput = TranslationListFilter::DEFAULT_SORT;
}
$orderInput = (string) rex_request('order', 'string', TranslationListFilter::DEFAULT_ORDER);
if (!in_array($orderInput, TranslationListFilter::SORT_ORDERS, true)) {
    $orderInput = TranslationListFilter::DEFAULT_ORDER;
}

// Anzeige-Sprache absichern: eine unbekannte, abgeleitete (nicht in $clangs)
// oder nicht erlaubte clang_id aus der URL fällt still auf die Default-Sprache
// zurück, statt die Seite abzubrechen (alte Links bleiben nutzbar).
if (!isset($clangs[$clangId]) || !$user->getComplexPerm('clang')->hasPerm($clangId)) {
    $clangId = $defaultClang;
}

// Erst wenn gar keine eigenständige, erlaubte Sprache übrig bleibt: abbrechen.
if ($clangId <= 0 || !isset($clangs[$clangId]) || !$user->getComplexPerm('clang')->hasPerm($clangId)) {
    echo rex_view::error(rex_i18n::msg('sprog_inbox_no_clang_perm'));

    return;
}

// statuses säubern: nur valide Status-Strings akzeptieren.
$statuses = [];
$validStatus = array_flip(Status::values());
foreach ((array) $statusesInput as $candidate) {
    $candidate = is_string($candidate) ? $candidate : '';
    if ('' !== $candidate && isset($validStatus[$candidate])) {
        $statuses[] = Status::from($candidate);
    }
}

$namespace = '' === $namespaceInput ? null : $namespaceInput;
$search = '' === $searchInput ? null : $searchInput;

try {
    $filter = new TranslationListFilter(
        clangId: $clangId,
        namespace: $namespace,
        statuses: $statuses,
        search: $search,
        page: $page,
        pageSize: $pageSize,
        conflictsOnly: $conflictsOnly,
        sort: $sortInput,
        order: $orderInput,
    );
} catch (InvalidArgumentException $e) {
    echo rex_view::error(rex_i18n::msg('sprog_inbox_filter_invalid', $e->getMessage()));

    return;
}

$result = TranslationListService::create()->query($filter);
$items = $result['items'];
$total = $result['total'];
$lastPage = max(1, (int) ceil($total / $pageSize));

/*
 |---------------------------------------------------------------------------
 | Bulk-Load: alle Units + alle Translations dieser Page in zwei Queries
 |---------------------------------------------------------------------------
 | $items liefert eine Row pro (unit × filterClang). Daraus ziehen wir die
 | distinct unit_ids und laden bulk-mässig alle Translations für ALLE Sprachen.
 | Das ist der Kern des neuen Akkordeon-Layouts: pro Unit eine Card mit Edit-
 | Inputs für jede definierte clang.
 */
$units = new UnitRepository();
$translations = new TranslationRepository();

$unitIds = [];
foreach ($items as $item) {
    $unitIds[$item->unitId] = true;
}
$unitIds = array_keys($unitIds);

$unitMap = $units->findMany($unitIds);
$translationMap = $translations->findByUnits($unitIds);

// Konflikt-Hinweise für Wildcard-Units: falls Context-Splits sich gegenseitig
// oder einen globalen Punkt-Key überschatten, bekommt jede betroffene Unit
// einen Hinweistext für das Warn-Icon im Summary.
$conflictMap = WildcardConflictService::create()->findConflictsByUnitId();

// Existierende Bereich-Werte für das Datalist im Edit-Modal — der User
// sieht beim Tippen ins Bereich-Feld bekannte Werte als Vorschläge.
$existingContexts = $units->findAllContexts();

// Wildcard-Tags für den Copy-Button an Bereich/Schlüssel: damit baut das JS
// den Platzhalter exakt so, wie der Frontend-Parser ihn erwartet. Defaults
// wie in package.yml / CLAUDE.md dokumentiert ({{ … }}).
$wildcardOpenTag = (string) rex_config::get('sprog', 'wildcard_open_tag', '{{ ');
$wildcardCloseTag = (string) rex_config::get('sprog', 'wildcard_close_tag', ' }}');

// Anzeige-Reihenfolge der Status-Optionen — schwergewichtige zuerst.
$statusOptions = [
    Status::Missing,
    Status::Stale,
    Status::Draft,
    Status::NeedsReview,
    Status::Revise,
    Status::Approved,
];

/*
 |---------------------------------------------------------------------------
 | URL-Builder: aktuelle Filter beibehalten, nur "open_unit" tauschen
 |---------------------------------------------------------------------------
 | Wird genutzt, um Deep-Links auf eine geöffnete Unit zu erzeugen
 | (z.B. nach einem Redirect aus dem Create-Flow).
 */
// status als nativer Array-Wert — http_build_query (intern in
// rex_url::currentBackendPage) serialisiert das als status[0]=…&status[1]=…
// Vorher haben wir die Bracket-Indizes manuell als String-Keys gesetzt; das
// wäre an Refactorings an rex_url::* zerbrechen können.
$baseParams = [
    'clang_id' => $clangId,
    'namespace' => $namespace ?? '',
    'search' => $searchInput,
    'pg' => $page,
    'page_size' => $pageSize,
    'status' => array_map(static fn (Status $st) => $st->value, $statuses),
    'conflict' => $conflictsOnly ? '1' : '',
    'sort' => $sortInput,
    'order' => $orderInput,
];

$jsonEndpoint = rex_url::currentBackendPage(['func' => 'save'], false);
$endpointUpdateUnit = rex_url::currentBackendPage(['func' => 'update_unit'], false);
$endpointTransition = rex_url::currentBackendPage(['func' => 'transition'], false);

// Permission fürs Inline-Edit des Unit-Keys (Stift-Button). Editierung von
// notes lassen wir bewusst draußen — wer Notizen pflegt, hat eh den
// Deep-Link auf die Editor-Page.
$canEditUnit = $user->isAdmin() || $user->hasPerm('sprog[unit_edit]');

// Gemeinsame Anzeige-Werte für die Toolbar-Cells (Sprache + Quelle).
// Liegen in der Cell sichtbar — der eigentliche <select> ist absolut darüber
// transparent, sodass das ganze Pill klickbar bleibt.
$currentClang = rex_clang::get($clangId);
$currentClangLabel = null !== $currentClang
    ? $currentClang->getCode() . ' · ' . $currentClang->getName()
    : '';
$currentNamespaceLabel = null !== $namespace
    ? Labels::forNamespace($namespace)
    : rex_i18n::msg('sprog_inbox_filter_all');

// Ein einheitlicher Chevron für alle Dropdowns + das Unit-Akkordeon —
// GitHub-Octicon "chevron-down". `currentColor` damit es Themes mit-rendert.
$chevronSvg = '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M12.78 6.22a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L3.22 7.28a.75.75 0 1 1 1.06-1.06L8 9.94l3.72-3.72a.75.75 0 0 1 1.06 0Z"/></svg>';

// Rechts-Chevron als führende Aufklapp-Indikator vor dem Bezeichner (rotiert
// per CSS um 90° beim Öffnen).
$unitChevronSvg = '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z"/></svg>';

// Kompaktes Quelle-Icon je Namespace — hält die Schlüssel-Spalte linksbündig
// (Tooltip trägt das volle Label). Dänisches æø signalisiert „Fremdwort".
$sourceGlyph = static function (string $ns): string {
    return match ($ns) {
        SourceType::Wildcard->value     => '{}',
        SourceType::Abbreviation->value => 'Ab',
        SourceType::Foreignword->value  => 'æø',
        SourceType::Article->value      => 'Ar',
        SourceType::Slice->value        => 'Sl',
        SourceType::YForm->value        => 'YF',
        SourceType::Media->value        => 'Me',
        SourceType::Custom->value       => '∗',
        default                         => rex_escape(mb_strtoupper(mb_substr($ns, 0, 2))),
    };
};

// Page-globaler CSRF-Token für Save / UpdateUnit / Transition. Per-Unit-
// Token wäre Resource-Scope, hier reicht der Operation-Scope: das Token
// ist Session-gebunden, eine Unit-Querverweis-Attacke ist außerhalb der
// Session ohnehin nicht möglich (CSRF schützt das Session-Cookie-Risiko).
$inboxSaveCsrf = rex_csrf_token::factory('sprog_inbox_save');

// MT-Verfügbarkeit + Button-Beschriftungen einmal page-weit ermitteln: die
// MT-Leiste im Akkordeon rendert nur, wenn mindestens ein echter (nicht-noop)
// Provider konfiguriert ist. Je echtem Provider bekommt der User einen eigenen
// Button (Provider pro Klick wählbar, kein globales Setting). Das Label kommt
// aus dem Lang-Key sprog_inbox_mt_provider_<name>; beim KI-Provider hängen wir
// den konkreten Provider des Standard-Profils an → z.B. „KI (Ollama)".
$mtProviderLabels = [];
foreach (MtService::create()->providers() as $mtName => $mtProviderObj) {
    if ('noop' === $mtName || !$mtProviderObj->isConfigured()) {
        continue;
    }
    $mtLabelKey = 'sprog_inbox_mt_provider_' . $mtName;
    $mtLabel = rex_i18n::hasMsg($mtLabelKey) ? rex_i18n::msg($mtLabelKey) : $mtName;
    if ($mtProviderObj instanceof AiPlatformProvider) {
        $mtProfileProvider = $mtProviderObj->profileProviderName();
        if (null !== $mtProfileProvider && '' !== $mtProfileProvider) {
            $mtLabel .= ' (' . $mtProfileProvider . ')';
        }
    }
    $mtProviderLabels[$mtName] = $mtLabel;
}
$mtEnabled = [] !== $mtProviderLabels;
// Quelle für MT ist immer die Basissprache (konfigurierbar, Fallback Start-Clang)
// — dort gibt es keinen MT-Button und sie ist keine Batch-Zielsprache.
$baseClangId = BaseLang::clangId();

// Workflow-Rollenlogik (Buttons + Autorisierung) — eine Instanz für die ganze
// Seite, damit der hasOtherTranslator-Cache je Sprache greift.
$workflow = WorkflowService::create();
$userId = $user->getId();

// Zielsprachen für die Stapelverarbeitung: alle editierbaren Sprachen außer der
// Quell-/Start-Clang (dorthin wird nicht übersetzt). Der Batch-Auslöser erscheint
// nur, wenn es solche Sprachen gibt UND ein echter MT-Provider konfiguriert ist.
$batchTargets = [];
foreach ($clangs as $bId => $bClang) {
    if ($bId === $baseClangId || !$workflow->canEdit($user, $bId)) {
        continue;
    }
    $batchTargets[$bId] = $bClang;
}
$batchEnabled = $mtEnabled && [] !== $batchTargets;

?>
<article
    class="sprog-ui sprog-inbox"
    data-sprog-inbox
    data-endpoint="<?= rex_escape($jsonEndpoint) ?>"
    data-endpoint-update-unit="<?= rex_escape($endpointUpdateUnit) ?>"
    data-endpoint-transition="<?= rex_escape($endpointTransition) ?>"
    data-endpoint-mt="<?= rex_escape(rex_url::currentBackendPage(['func' => 'mt'], false)) ?>"
    data-endpoint-history="<?= rex_escape(rex_url::currentBackendPage(['func' => 'history'], false)) ?>"
    data-endpoint-restore="<?= rex_escape(rex_url::currentBackendPage(['func' => 'restore'], false)) ?>"
    data-endpoint-batch-prepare="<?= rex_escape(rex_url::currentBackendPage(['func' => 'batch_prepare'], false)) ?>"
    data-endpoint-batch-translate="<?= rex_escape(rex_url::currentBackendPage(['func' => 'batch_translate'], false)) ?>"
    data-can-edit-unit="<?= $canEditUnit ? '1' : '0' ?>"
    data-display-clang="<?= rex_escape((string) $clangId) ?>"
    data-csrf-name="<?= rex_escape(rex_csrf_token::PARAM) ?>"
    data-csrf-value="<?= rex_escape($inboxSaveCsrf->getValue()) ?>"
    data-wildcard-open="<?= rex_escape($wildcardOpenTag) ?>"
    data-wildcard-close="<?= rex_escape($wildcardCloseTag) ?>"
>
    <header class="sprog-intro">
        <h1 class="sprog-heading"><?= rex_i18n::msg('sprog_inbox_heading') ?></h1>
        <p class="sprog-lead"><?= rex_i18n::msg('sprog_inbox_lead') ?></p>
    </header>

    <?php
    // Legende der Status, grob in Workflow-Reihenfolge: missing → draft →
    // needs_review (eingereicht) → approved, dazwischen revise (vom Reviewer
    // zurückgegeben), am Ende stale als Spezialfall bei Quell-Änderung.
    $legendOrder = [
        Status::Missing,
        Status::Draft,
        Status::NeedsReview,
        Status::Revise,
        Status::Approved,
        Status::Stale,
    ];
    ?>
    <details class="sprog-legend">
        <summary class="sprog-legend--summary">
            <span class="sprog-legend--icon" aria-hidden="true">i</span>
            <?= rex_i18n::msg('sprog_inbox_legend_summary') ?>
            <span class="sprog-chevron"><?= $chevronSvg ?></span>
        </summary>
        <div class="sprog-legend--body sprog-legend--body--split">
            <div class="sprog-legend--col">
                <section class="sprog-legend--section">
                    <h3 class="sprog-legend--heading">
                        <?= rex_i18n::msg('sprog_inbox_legend_workflow_heading') ?>
                    </h3>
                    <p class="sprog-legend--text">
                        <?= rex_i18n::rawMsg('sprog_inbox_legend_workflow_text') ?>
                    </p>
                </section>

                <section class="sprog-legend--section">
                    <h3 class="sprog-legend--heading">
                        <?= rex_i18n::msg('sprog_inbox_legend_pills_heading') ?>
                    </h3>
                    <p class="sprog-legend--text">
                        <?= rex_i18n::rawMsg('sprog_inbox_legend_pills_text') ?>
                    </p>
                </section>
            </div>

            <div class="sprog-legend--col">
                <section class="sprog-legend--section">
                    <h3 class="sprog-legend--heading">
                        <?= rex_i18n::msg('sprog_inbox_legend_status_heading') ?>
                    </h3>
                    <dl class="sprog-inbox--legend-statuses">
                        <?php foreach ($legendOrder as $st) : ?>
                            <div class="sprog-inbox--legend-status">
                                <dt>
                                    <span class="sprog-status sprog-status--<?= rex_escape($st->value) ?>">
                                        <?= Labels::status($st) ?>
                                    </span>
                                </dt>
                                <dd><?= rex_i18n::msg('sprog_inbox_legend_status_' . $st->value) ?></dd>
                            </div>
                        <?php endforeach ?>
                    </dl>
                </section>

                <section class="sprog-legend--section">
                    <h3 class="sprog-legend--heading">
                        <?= rex_i18n::msg('sprog_inbox_legend_actions_heading') ?>
                    </h3>
                    <dl class="sprog-inbox--legend-statuses">
                        <div class="sprog-inbox--legend-status">
                            <dt><span class="sprog-btn sprog-btn--sm sprog-inbox--row-action sprog-inbox--row-action--submit"><?= rex_i18n::msg('sprog_inbox_action_submit') ?></span></dt>
                            <dd><?= rex_i18n::msg('sprog_inbox_legend_action_submit') ?></dd>
                        </div>
                        <div class="sprog-inbox--legend-status">
                            <dt><span class="sprog-btn sprog-btn--sm sprog-inbox--row-action sprog-inbox--row-action--return"><?= rex_i18n::msg('sprog_inbox_action_return') ?></span></dt>
                            <dd><?= rex_i18n::msg('sprog_inbox_legend_action_return') ?></dd>
                        </div>
                        <div class="sprog-inbox--legend-status">
                            <dt><span class="sprog-btn sprog-btn--sm sprog-inbox--row-action sprog-inbox--row-action--approve"><?= rex_i18n::msg('sprog_inbox_action_approve') ?></span></dt>
                            <dd><?= rex_i18n::msg('sprog_inbox_legend_action_approve') ?></dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>
    </details>

    <?php
    // Status-Summary fürs Dropdown-Label: 0 = „alle", sonst Anzahl.
    $statusSummaryText = [] === $statuses
        ? rex_i18n::msg('sprog_inbox_filter_all')
        : rex_i18n::msg('sprog_inbox_filter_status_count', (string) count($statuses));
    ?>
    <form method="get" action="index.php" class="sprog-toolbar" data-sprog-inbox-filter>
        <input type="hidden" name="page" value="sprog/inbox">

        <!-- Top-Row: Suchfeld füllt links, Filtern/Reset rechts daneben, Neue-Einheit ganz rechts. -->
        <div class="sprog-toolbar--row">
            <label class="sprog-search">
                <span class="sprog-search--icon" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M11.5 7a4.499 4.499 0 1 1-8.998 0A4.499 4.499 0 0 1 11.5 7Zm-.82 4.74a6 6 0 1 1 1.06-1.06l3.04 3.04a.75.75 0 1 1-1.06 1.06l-3.04-3.04Z"/>
                    </svg>
                </span>
                <input
                    type="search"
                    name="search"
                    value="<?= rex_escape($searchInput) ?>"
                    placeholder="<?= rex_i18n::msg('sprog_inbox_filter_search_placeholder') ?>"
                    maxlength="<?= rex_escape((string) TranslationListFilter::MAX_SEARCH_LENGTH) ?>"
                    class="sprog-search--input"
                >
            </label>
            <button type="submit" class="sprog-btn">
                <?= rex_i18n::msg('sprog_inbox_filter_submit') ?>
            </button>
            <a class="sprog-btn sprog-btn--reset"
               href="<?= rex_escape(rex_url::currentBackendPage(['clang_id' => $clangId], false)) ?>">
                <?= rex_i18n::msg('sprog_inbox_filter_reset') ?>
            </a>
            <!--
                href bleibt als no-JS-Fallback bestehen — wenn JavaScript aktiv ist,
                fängt der Click-Handler in sprog.inbox.js ab und öffnet stattdessen
                das Edit-Modal im Create-Mode.
            -->
            <a
                class="sprog-btn sprog-btn--primary"
                data-role="unit-create-trigger"
                href="<?= rex_escape(rex_url::backendPage('sprog/create', [], false)) ?>"
            ><?= rex_i18n::msg('sprog_inbox_button_new') ?></a>
            <?php if ($batchEnabled) : ?>
                <button
                    type="button"
                    class="sprog-btn"
                    data-role="batch-trigger"
                ><?= rex_i18n::msg('sprog_inbox_batch_button') ?></button>
            <?php endif ?>
        </div>

        <!-- Listen-Header: Anzahl links, Sprache/Quelle/Status-Dropdowns rechts. -->
        <div class="sprog-list-header">
            <p class="sprog-summary">
                <strong><?= rex_escape((string) $total) ?></strong>
                <?= rex_i18n::msg(1 === $total ? 'sprog_inbox_summary_entry' : 'sprog_inbox_summary_entries') ?>
                <?php if ($total > $pageSize) : ?>
                    · <?= rex_i18n::msg('sprog_inbox_summary_page', (string) $page, (string) $lastPage) ?>
                <?php endif ?>
            </p>

            <div class="sprog-list-header--filters">
                <?php
                // Konflikt-Toggle: an erster Stelle, aber nur wenn tatsächlich
                // Wildcard-Konflikte existieren (oder der Filter gerade aktiv ist,
                // damit er sich wieder abschalten lässt). Reine Navigation.
                if ([] !== $conflictMap || $conflictsOnly) :
                    $conflictToggleParams = $baseParams;
                    $conflictToggleParams['conflict'] = $conflictsOnly ? '' : '1';
                    $conflictToggleParams['pg'] = 1;
                ?>
                    <a
                        class="sprog-cell sprog-inbox--conflict-toggle<?= $conflictsOnly ? ' is-active' : '' ?>"
                        href="<?= rex_escape(rex_url::currentBackendPage($conflictToggleParams, false)) ?>"
                        aria-pressed="<?= $conflictsOnly ? 'true' : 'false' ?>"
                        title="<?= rex_escape(rex_i18n::msg('sprog_inbox_filter_conflicts_title')) ?>"
                    >
                        <span class="sprog-inbox--conflict-toggle-icon" aria-hidden="true">
                            <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575L6.457 1.047ZM8 5a.75.75 0 0 0-.75.75v3.5a.75.75 0 0 0 1.5 0v-3.5A.75.75 0 0 0 8 5Zm1 7a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg>
                        </span>
                        <span class="sprog-cell--label"><?= rex_i18n::msg('sprog_inbox_filter_conflicts') ?></span>
                    </a>
                <?php endif ?>

                <?php // Sprache (Anzeige-Sprache) — Single-Select, submittet sofort. ?>
                <details class="sprog-inbox--filter-dd" data-role="filter-dropdown" tabindex="0" aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_filter_language')) ?>">
                    <summary class="sprog-cell" tabindex="-1">
                        <span class="sprog-cell--label"><?= rex_i18n::msg('sprog_inbox_filter_language') ?></span>
                        <span class="sprog-inbox--filter-value"><?= isset($clangs[$clangId]) ? rex_escape($clangs[$clangId]->getCode()) : '' ?></span>
                        <span class="sprog-chevron"><?= $chevronSvg ?></span>
                    </summary>
                    <div class="sprog-inbox--filter-popup" data-role="filter-popup">
                        <?php foreach ($clangs as $id => $clang) :
                            if (!$user->getComplexPerm('clang')->hasPerm($id)) {
                                continue;
                            }
                        ?>
                            <label class="sprog-inbox--filter-opt">
                                <input type="radio" name="clang_id" value="<?= rex_escape((string) $id) ?>" data-autosubmit <?= $id === $clangId ? 'checked' : '' ?>>
                                <span><?= rex_escape($clang->getCode()) ?> · <?= rex_escape($clang->getName()) ?></span>
                            </label>
                        <?php endforeach ?>
                    </div>
                </details>

                <?php // Bereich (Namespace) — Single-Select, submittet sofort. ?>
                <details class="sprog-inbox--filter-dd" data-role="filter-dropdown" tabindex="0" aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_filter_namespace')) ?>">
                    <summary class="sprog-cell" tabindex="-1">
                        <span class="sprog-cell--label"><?= rex_i18n::msg('sprog_inbox_filter_namespace') ?></span>
                        <span class="sprog-inbox--filter-value"><?= null === $namespace ? rex_i18n::msg('sprog_inbox_filter_all') : Labels::forNamespace($namespace) ?></span>
                        <span class="sprog-chevron"><?= $chevronSvg ?></span>
                    </summary>
                    <div class="sprog-inbox--filter-popup" data-role="filter-popup">
                        <label class="sprog-inbox--filter-opt">
                            <input type="radio" name="namespace" value="" data-autosubmit <?= null === $namespace ? 'checked' : '' ?>>
                            <span><?= rex_i18n::msg('sprog_inbox_filter_all') ?></span>
                        </label>
                        <?php foreach (SourceType::values() as $ns) : ?>
                            <label class="sprog-inbox--filter-opt">
                                <input type="radio" name="namespace" value="<?= rex_escape($ns) ?>" data-autosubmit <?= $ns === $namespace ? 'checked' : '' ?>>
                                <span><?= Labels::forNamespace($ns) ?></span>
                            </label>
                        <?php endforeach ?>
                    </div>
                </details>

                <?php // Status — Multi-Select (Checkboxen + Anwenden). ?>
                <details class="sprog-inbox--filter-dd" data-role="filter-dropdown" tabindex="0" aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_filter_status_aria')) ?>">
                    <summary class="sprog-cell" tabindex="-1">
                        <span class="sprog-cell--label"><?= rex_i18n::msg('sprog_inbox_filter_status') ?></span>
                        <span class="sprog-inbox--filter-value"><?= rex_escape($statusSummaryText) ?></span>
                        <span class="sprog-chevron"><?= $chevronSvg ?></span>
                    </summary>
                    <div class="sprog-inbox--filter-popup" data-role="filter-popup">
                        <?php foreach ($statusOptions as $status) : ?>
                            <label class="sprog-inbox--filter-opt">
                                <input
                                    type="checkbox"
                                    name="status[]"
                                    value="<?= rex_escape($status->value) ?>"
                                    <?= in_array($status, $statuses, true) ? 'checked' : '' ?>
                                >
                                <span><?= Labels::status($status) ?></span>
                            </label>
                        <?php endforeach ?>
                    </div>
                </details>

                <?php
                // Sortierung: ein Dropdown mit zwei Bereichen (Feld + Richtung).
                // Die aktuelle Richtung wird als Pfeil-Icon gezeigt; der
                // Anwenden-Button submittet Feld + Richtung in einem Schritt.
                $orderArrowUp = '<svg class="sprog-inbox--order-icon" width="11" height="11" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 4l5 7H3z"/></svg>';
                $orderArrowDown = '<svg class="sprog-inbox--order-icon" width="11" height="11" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 12 3 5h10z"/></svg>';
                ?>
                <details class="sprog-inbox--filter-dd" data-role="filter-dropdown" tabindex="0" aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_sort_label')) ?>">
                    <summary class="sprog-cell" tabindex="-1">
                        <span class="sprog-cell--label"><?= rex_i18n::msg('sprog_inbox_sort_label') ?></span>
                        <span class="sprog-inbox--filter-value"><?= rex_i18n::msg('sprog_inbox_sort_' . $sortInput) ?><?= 'desc' === $orderInput ? $orderArrowDown : $orderArrowUp ?></span>
                        <span class="sprog-chevron"><?= $chevronSvg ?></span>
                    </summary>
                    <div class="sprog-inbox--filter-popup" data-role="filter-popup">
                        <div class="sprog-inbox--filter-section">
                            <p class="sprog-inbox--filter-section-title"><?= rex_i18n::msg('sprog_inbox_sort_label') ?></p>
                            <?php foreach (TranslationListFilter::SORT_FIELDS as $sortField) : ?>
                                <label class="sprog-inbox--filter-opt">
                                    <input type="radio" name="sort" value="<?= rex_escape($sortField) ?>" data-autosubmit <?= $sortField === $sortInput ? 'checked' : '' ?>>
                                    <span><?= rex_i18n::msg('sprog_inbox_sort_' . $sortField) ?></span>
                                </label>
                            <?php endforeach ?>
                        </div>
                        <div class="sprog-inbox--filter-section">
                            <p class="sprog-inbox--filter-section-title"><?= rex_i18n::msg('sprog_inbox_order_label') ?></p>
                            <label class="sprog-inbox--filter-opt">
                                <input type="radio" name="order" value="asc" data-autosubmit <?= 'asc' === $orderInput ? 'checked' : '' ?>>
                                <span><?= $orderArrowUp ?> <?= rex_i18n::msg('sprog_inbox_order_asc') ?></span>
                            </label>
                            <label class="sprog-inbox--filter-opt">
                                <input type="radio" name="order" value="desc" data-autosubmit <?= 'desc' === $orderInput ? 'checked' : '' ?>>
                                <span><?= $orderArrowDown ?> <?= rex_i18n::msg('sprog_inbox_order_desc') ?></span>
                            </label>
                        </div>
                    </div>
                </details>
            </div>
        </div>

    <?php if ([] === $items) : ?>
        <p class="sprog-empty"><?= rex_i18n::msg('sprog_inbox_empty') ?></p>
    <?php else : ?>
        <ul class="sprog-list" role="list">
            <?php foreach ($items as $item) :
                $unit = $unitMap[$item->unitId] ?? null;
                if (null === $unit) {
                    continue;
                }

                $unitTranslations = $translationMap[$unit->id] ?? [];
                $isOpen = $openUnit === $unit->id;

                // Coverage über alle clangs: wie viele sind translated/approved?
                $coverageDone = 0;
                $coverageAll = 0;
                foreach ($clangs as $cId => $_c) {
                    ++$coverageAll;
                    $t = $unitTranslations[$cId] ?? null;
                    if (null !== $t && Status::Approved === $t->status) {
                        ++$coverageDone;
                    }
                }
            ?>
                <?php
                $conflictHint = $conflictMap[$unit->id] ?? null;
                // Copy nur für Wildcards, Edit nur mit Recht — nur dann das
                // Action-Overlay (+ reservierten Platz rechts in der Zeile) rendern.
                $hasUnitActions = SourceType::Wildcard->value === $item->namespace || $canEditUnit;
                ?>
                <li class="sprog-inbox--unit-item<?= $hasUnitActions ? ' sprog-inbox--unit-item--has-actions' : '' ?>">
                    <details
                        class="sprog-accordion sprog-inbox--unit"
                        aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_unit_card_aria', $item->unitKey)) ?>"
                        data-unit-id="<?= rex_escape((string) $unit->id) ?>"
                        data-unit-namespace="<?= rex_escape($item->namespace) ?>"
                        data-unit-namespace-label="<?= rex_escape(Labels::forNamespace($item->namespace)) ?>"
                        data-unit-key="<?= rex_escape($item->unitKey) ?>"
                        data-unit-context="<?= rex_escape($item->context) ?>"
                        data-unit-notes="<?= rex_escape($item->notes ?? '') ?>"
                        <?php if (null !== $conflictHint) : ?>data-unit-conflict="<?= rex_escape($conflictHint) ?>"<?php endif ?>
                        <?= $isOpen ? 'open' : '' ?>
                    >
                        <summary class="sprog-row sprog-row--accordion sprog-row--aligned sprog-inbox--unit-summary">
                            <span class="sprog-row--lead sprog-inbox--unit-chevron"><?= $unitChevronSvg ?></span>
                            <span class="sprog-visually-hidden"><?= Labels::forNamespace($item->namespace) ?>:</span>
                            <span class="sprog-row--icon sprog-inbox--source-icon" title="<?= rex_escape(Labels::forNamespace($item->namespace)) ?>" aria-hidden="true"><?= $sourceGlyph($item->namespace) ?></span>
                            <div class="sprog-row--title sprog-inbox--key-line" data-role="key-line">
                                    <?php if (null !== $conflictHint) : ?>
                                        <span class="sprog-inbox--conflict-flag" title="<?= rex_escape($conflictHint) ?>" aria-label="<?= rex_escape($conflictHint) ?>">
                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                                <path d="M6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575L6.457 1.047ZM8 5a.75.75 0 0 0-.75.75v3.5a.75.75 0 0 0 1.5 0v-3.5A.75.75 0 0 0 8 5Zm1 7a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/>
                                            </svg>
                                        </span>
                                    <?php endif ?>
                                    <?php if ('' !== $item->context) : ?>
                                        <span class="sprog-inbox--context" data-role="context-text"><?= rex_escape($item->context) ?></span>
                                        <span class="sprog-inbox--context-sep" data-role="context-sep" aria-hidden="true">.</span>
                                    <?php endif ?>
                                    <span class="sprog-inbox--key" data-role="key-text"><?= rex_escape($item->unitKey) ?></span>
                                </div>

                                <div class="sprog-row--body sprog-inbox--translation<?= '' === $item->displayValue ? ' sprog-inbox--translation--missing' : '' ?>">
                                    <?php if ('' === $item->displayValue) : ?>
                                        <span class="sprog-inbox--translation-dot" aria-hidden="true"></span>
                                        <span class="sprog-inbox--translation-missing"><?= rex_i18n::msg('sprog_inbox_value_empty') ?></span>
                                    <?php else : ?>
                                        <span class="sprog-inbox--translation-dot" data-role="translation-dot" aria-hidden="true" style="background:var(--sprog-status-<?= rex_escape($item->displayStatus->value) ?>)"></span>
                                        <span class="sprog-inbox--translation-text"><?= rex_escape($item->displayValue) ?></span>
                                    <?php endif ?>
                                </div>

                            <div class="sprog-row--trailing sprog-inbox--coverage-cell">
                                <!--
                                    Coverage-Rail: pro Sprache eine kompakte Pille
                                    (Sprachkürzel + Status-Farbe). missing rendert
                                    neutral im Coverage-Look — nur bearbeitete
                                    Sprachen bekommen Gewicht. N/M = fertige Sprachen.
                                -->
                                <div class="sprog-inbox--lang-badges">
                                    <?php foreach ($clangs as $cId => $clang) :
                                        $t = $unitTranslations[$cId] ?? null;
                                        $cStat = null !== $t ? $t->status : Status::Missing;
                                        $tipTxt = $clang->getName() . ' · ' . Labels::status($cStat);
                                    ?>
                                        <span
                                            class="sprog-status sprog-status--<?= rex_escape($cStat->value) ?> sprog-inbox--lang-badge"
                                            data-role="lang-badge"
                                            data-clang-id="<?= rex_escape((string) $cId) ?>"
                                            data-clang-name="<?= rex_escape($clang->getName()) ?>"
                                            data-status="<?= rex_escape($cStat->value) ?>"
                                            title="<?= rex_escape($tipTxt) ?>"
                                        ><?= rex_escape($clang->getCode()) ?></span>
                                    <?php endforeach ?>
                                </div>
                                <span class="sprog-inbox--coverage" data-role="coverage"
                                      title="<?= rex_escape(rex_i18n::msg('sprog_inbox_coverage_title', (string) $coverageDone, (string) $coverageAll)) ?>">
                                    <?= rex_escape($coverageDone . '/' . $coverageAll) ?>
                                </span>
                            </div>
                        </summary>

                        <div class="sprog-accordion--body sprog-inbox--rows" data-role="rows" role="group" aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_rows_label', $item->unitKey)) ?>">
                            <?php if (null !== $conflictHint) : ?>
                                <p class="sprog-note sprog-note--warning">
                                    <span class="sprog-note--icon" aria-hidden="true">
                                        <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575L6.457 1.047ZM8 5a.75.75 0 0 0-.75.75v3.5a.75.75 0 0 0 1.5 0v-3.5A.75.75 0 0 0 8 5Zm1 7a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg>
                                    </span>
                                    <span><strong><?= rex_i18n::msg('sprog_inbox_conflict_label') ?></strong> <?= rex_escape($conflictHint) ?></span>
                                </p>
                            <?php endif ?>
                            <?php if (null !== $item->notes) : ?>
                                <p class="sprog-note sprog-note--info" data-role="notes">
                                    <span class="sprog-note--label"><?= rex_i18n::msg('sprog_inbox_notes_label') ?></span>
                                    <?= rex_escape($item->notes) ?>
                                </p>
                            <?php endif ?>

                            <?php foreach ($clangs as $cId => $clang) :
                                $canEdit = $workflow->canEdit($user, $cId);
                                $tr = $unitTranslations[$cId] ?? null;
                                $value = null !== $tr ? $tr->value : '';
                                $status = null !== $tr ? $tr->status : Status::Missing;
                                $revision = null !== $tr ? $tr->revision : 0;
                                // Stale-Hinweis + is-stale-Deko folgen dem
                                // persistierten Status — single source of truth,
                                // identisch zum Badge und zur Live-Aktualisierung
                                // nach einem Quell-Edit (dort ebenfalls
                                // status-basiert). Damit sind Live und Reload
                                // deckungsgleich. Die Basissprache wird nie stale
                                // markiert und taucht hier ohnehin nicht auf.
                                $isStale = Status::Stale === $status;

                                $rowClasses = ['sprog-inbox--row'];
                                if (!$canEdit) {
                                    $rowClasses[] = 'is-readonly';
                                }
                                if ($isStale) {
                                    $rowClasses[] = 'is-stale';
                                }
                            ?>
                                <div
                                    class="<?= rex_escape(implode(' ', $rowClasses)) ?>"
                                    data-role="row"
                                    data-clang-id="<?= rex_escape((string) $cId) ?>"
                                    data-revision="<?= rex_escape((string) $revision) ?>"
                                    data-status="<?= rex_escape($status->value) ?>"
                                >
                                    <div class="sprog-inbox--row-head">
                                        <span class="sprog-clang-code"><?= rex_escape($clang->getCode()) ?></span>
                                        <span class="sprog-inbox--clang-name"><?= rex_escape($clang->getName()) ?></span>
                                        <?php if (!$canEdit) : ?>
                                            <span class="sprog-hint sprog-hint--italic sprog-inbox--readonly-hint" title="<?= rex_escape(rex_i18n::msg('sprog_inbox_readonly_hint')) ?>">
                                                <?= rex_i18n::msg('sprog_inbox_readonly_short') ?>
                                            </span>
                                        <?php endif ?>
                                    </div>

                                    <div class="sprog-inbox--row-body">
                                        <?php if ($isStale) : ?>
                                            <p class="sprog-hint sprog-hint--warning sprog-inbox--stale-hint"><?= rex_i18n::msg('sprog_inbox_stale_hint') ?></p>
                                        <?php endif ?>

                                        <textarea
                                            class="sprog-control sprog-control--textarea"
                                            data-role="value"
                                            data-last-saved="<?= rex_escape($value) ?>"
                                            data-auto-grow
                                            data-max-rows="8"
                                            rows="1"
                                            <?= $canEdit ? '' : 'readonly aria-readonly="true"' ?>
                                        ><?= rex_escape($value) ?></textarea>

                                        <?php if ($canEdit) : ?>
                                            <div class="sprog-inbox--field-tools" data-role="field-tools">
                                                <?php if ($mtEnabled && $cId !== $baseClangId) :
                                                    // Init-Werte für den MT-Marker: nur gefüllt, wenn die
                                                    // aktuelle Übersetzung MT-induziert ist. Die hidden Felder
                                                    // haben bewusst kein name-Attribut — der Blur-Auto-Save
                                                    // liest sie per data-role und hängt sie an die fetch-Payload.
                                                    $mtProviderInit = null !== $tr ? ($tr->mtProvider ?? '') : '';
                                                    $mtConfidenceInit = null !== $tr && null !== $tr->mtConfidence
                                                        ? (string) $tr->mtConfidence
                                                        : '';
                                                ?>
                                                    <div class="sprog-inbox--mt-bar" data-role="mt-bar" data-mt-active="<?= '' !== $mtProviderInit ? 'true' : 'false' ?>">
                                                        <?php foreach ($mtProviderLabels as $mtProvider => $mtProviderLabel) : ?>
                                                            <button
                                                                type="button"
                                                                class="sprog-btn sprog-btn--sm sprog-inbox--mt-trigger"
                                                                data-role="mt-trigger"
                                                                data-clang-id="<?= rex_escape((string) $cId) ?>"
                                                                data-provider="<?= rex_escape((string) $mtProvider) ?>"
                                                            >
                                                                <?= rex_escape($mtProviderLabel) ?>
                                                            </button>
                                                        <?php endforeach ?>
                                                        <span class="sprog-inbox--mt-status" data-role="mt-status" role="status" aria-live="polite"></span>
                                                        <input type="hidden" data-role="mt-provider" value="<?= rex_escape($mtProviderInit) ?>">
                                                        <input type="hidden" data-role="mt-confidence" value="<?= rex_escape($mtConfidenceInit) ?>">
                                                    </div>
                                                <?php endif ?>

                                                <?php // „Zurücksetzen" verwirft die aktuelle, noch nicht gespeicherte
                                                      // Änderung (Feld zurück auf den zuletzt gespeicherten Wert). Nur
                                                      // sichtbar, wenn das Feld „dirty" ist (JS toggelt es). ?>
                                                <button
                                                    type="button"
                                                    class="sprog-btn sprog-btn--sm sprog-inbox--reset"
                                                    data-role="reset"
                                                    hidden
                                                >
                                                    <?= rex_i18n::msg('sprog_inbox_reset_button') ?>
                                                </button>

                                                <?php // „Verlauf" lädt die gespeicherten Versionen lazy nach und erlaubt
                                                      // mehrstufiges Wiederherstellen. ?>
                                                <button
                                                    type="button"
                                                    class="sprog-btn sprog-btn--sm sprog-inbox--history-toggle"
                                                    data-role="history-toggle"
                                                    aria-expanded="false"
                                                >
                                                    <?= rex_i18n::msg('sprog_inbox_history_button') ?>
                                                </button>
                                            </div>
                                        <?php endif ?>

                                        <?php
                                        /*
                                         * Aktionszeile (rechts, unter dem Feld): Status-Chip als fester
                                         * Anker + die JETZT möglichen Workflow-Buttons. Nicht mögliche
                                         * Aktionen sind ausgeblendet (hidden), nicht ausgegraut — man
                                         * liest 》hier stehe ich → das kann ich tun《. Der Chip ersetzt die
                                         * frühere Status-Pille im Zeilenkopf; data-role bleibt, das JS
                                         * aktualisiert Chip + Button-Sichtbarkeit ohne Reload.
                                         */
                                        ?>
                                        <div class="sprog-inbox--row-actions">
                                            <?= InboxRowActions::render($workflow, $user, $cId, $userId, $status, $tr, $canEdit) ?>
                                        </div>

                                        <?php if ($canEdit) : ?>
                                            <?php // Versionsliste — leer gerendert, wird vom JS beim Aufklappen befüllt. ?>
                                            <div class="sprog-inbox--history-panel" data-role="history-panel" hidden></div>
                                        <?php endif ?>

                                        <p class="sprog-hint sprog-inbox--row-feedback" data-role="feedback" aria-live="polite"></p>
                                    </div>
                                </div>
                            <?php endforeach ?>
                        </div>
                    </details>
                    <?php if ($hasUnitActions) : ?>
                        <!--
                            Action-Overlay: liegt bewusst AUSSERHALB des <summary>
                            (verschachtelte interaktive Elemente im Summary wären
                            ungültiges HTML + a11y-Problem). Per Grid-Overlay im
                            <li> sitzt es oben rechts über der Zeile; Sichtbarkeit
                            via Hover/Fokus/offen (CSS).
                        -->
                        <div class="sprog-inbox--unit-actions">
                            <?php if (SourceType::Wildcard->value === $item->namespace) : ?>
                                <button
                                    type="button"
                                    class="sprog-inbox--key-copy"
                                    data-role="unit-copy-placeholder"
                                    title="<?= rex_escape(rex_i18n::msg('sprog_inbox_copy_placeholder_title')) ?>"
                                    aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_copy_placeholder_title')) ?>"
                                >
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                        <path d="M0 6.75C0 5.784.784 5 1.75 5h1.5a.75.75 0 0 1 0 1.5h-1.5a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-1.5a.75.75 0 0 1 1.5 0v1.5A1.75 1.75 0 0 1 9.25 16h-7.5A1.75 1.75 0 0 1 0 14.25Z"/>
                                        <path d="M5 1.75C5 .784 5.784 0 6.75 0h7.5C15.216 0 16 .784 16 1.75v7.5A1.75 1.75 0 0 1 14.25 11h-7.5A1.75 1.75 0 0 1 5 9.25Zm1.75-.25a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-7.5a.25.25 0 0 0-.25-.25Z"/>
                                    </svg>
                                </button>
                            <?php endif ?>
                            <?php if ($canEditUnit) : ?>
                                <button
                                    type="button"
                                    class="sprog-inbox--key-edit"
                                    data-role="unit-edit-trigger"
                                    title="<?= rex_escape(rex_i18n::msg('sprog_inbox_key_edit_title')) ?>"
                                    aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_key_edit_title')) ?>"
                                >
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                        <path d="M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.21.21-.47.364-.756.445l-3.251.93a.75.75 0 0 1-.927-.928l.929-3.25c.081-.286.235-.547.445-.757l8.61-8.61Zm.176 4.823L9.75 4.81l-6.286 6.287a.253.253 0 0 0-.064.108l-.558 1.953 1.953-.558a.253.253 0 0 0 .108-.064Zm1.238-3.763a.25.25 0 0 0-.354 0L10.811 3.75l1.439 1.44 1.263-1.263a.25.25 0 0 0 0-.354Z"/>
                                    </svg>
                                </button>
                            <?php endif ?>
                        </div>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>

        <?php if ($lastPage > 1) :
            $pageUrl = static function (int $p) use ($baseParams): string {
                return rex_url::currentBackendPage(['pg' => $p] + $baseParams, false);
            };
        ?>
            <nav class="sprog-inbox--pagination" aria-label="<?= rex_i18n::msg('sprog_inbox_pagination_label') ?>">
                <?php if ($page > 1) : ?>
                    <a class="sprog-inbox--page-link" href="<?= rex_escape($pageUrl($page - 1)) ?>" rel="prev">
                        <?= rex_i18n::msg('sprog_inbox_pagination_prev') ?>
                    </a>
                <?php else : ?>
                    <span class="sprog-inbox--page-link sprog-inbox--page-link-disabled">
                        <?= rex_i18n::msg('sprog_inbox_pagination_prev') ?>
                    </span>
                <?php endif ?>

                <span class="sprog-inbox--page-info">
                    <?= rex_i18n::msg('sprog_inbox_pagination_page', (string) $page, (string) $lastPage) ?>
                </span>

                <?php if ($page < $lastPage) : ?>
                    <a class="sprog-inbox--page-link" href="<?= rex_escape($pageUrl($page + 1)) ?>" rel="next">
                        <?= rex_i18n::msg('sprog_inbox_pagination_next') ?>
                    </a>
                <?php else : ?>
                    <span class="sprog-inbox--page-link sprog-inbox--page-link-disabled">
                        <?= rex_i18n::msg('sprog_inbox_pagination_next') ?>
                    </span>
                <?php endif ?>
            </nav>
        <?php endif ?>
    <?php endif ?>
    </form>

    <div class="sprog-inbox--toast" data-role="toast" role="status" aria-live="polite" hidden></div>

    <!--
        Modal zum Bearbeiten ODER Anlegen einer Unit. Wird immer gerendert
        (Create steht jedem authenticated User offen); im Edit-Mode wird der
        Stift-Button im Akkordeon-Summary nur bei vorhandener `sprog[unit_edit]`-
        Permission angezeigt — wer kein Edit-Recht hat, sieht das Modal nur
        über den „+ Neue Einheit"-Button im Create-Mode.

        Genau ein <dialog> für die ganze Page — beim Klick füllt das JS die
        Felder. method="dialog" lässt den nativen Close-Mechanismus
        unangetastet; das eigentliche Save schickt JS als fetch ab.
    -->
    <?php $createCsrf = rex_csrf_token::factory('sprog_inbox_create') ?>
    <dialog
        class="sprog-inbox--unit-modal"
        data-role="unit-modal"
        data-endpoint-create="<?= rex_escape(rex_url::currentBackendPage(['func' => 'create_unit'], false)) ?>"
        data-create-csrf-name="<?= rex_escape(rex_csrf_token::PARAM) ?>"
        data-create-csrf-value="<?= rex_escape($createCsrf->getValue()) ?>"
        aria-labelledby="sprog-unit-modal-title"
    >
        <form method="dialog" class="sprog-inbox--unit-modal-form" data-role="unit-modal-form">
            <header class="sprog-inbox--unit-modal-header">
                <h2 id="sprog-unit-modal-title" class="sprog-inbox--unit-modal-title" data-role="modal-title">
                    <?= rex_i18n::msg('sprog_inbox_unit_modal_title') ?>
                </h2>
                <button
                    type="button"
                    class="sprog-inbox--unit-modal-close"
                    data-role="unit-modal-close"
                    aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_unit_modal_close')) ?>"
                >×</button>
            </header>

            <div class="sprog-inbox--unit-modal-body">
                <div class="sprog-inbox--unit-modal-field">
                    <span class="sprog-inbox--unit-modal-label">
                        <?= rex_i18n::msg('sprog_inbox_unit_modal_namespace_label') ?>
                    </span>
                    <!--
                        Im Edit-Mode: read-only Anzeige (Provider darf nicht
                        nachträglich gewechselt werden).
                        Im Create-Mode: Select aus den derzeit aktiven Providern
                        (wildcard / abbreviation / foreignword). Article/Slice/
                        YForm/Media/Custom werden über Sync angelegt, nicht
                        manuell — daher bewusst NICHT als Option im Modal.
                    -->
                    <span class="sprog-inbox--unit-modal-readonly" data-role="modal-namespace">—</span>
                    <select
                        class="sprog-control sprog-control--mono"
                        data-role="modal-namespace-select"
                        name="namespace"
                        hidden
                    >
                        <?php foreach (SourceType::userCreatable() as $ns) : ?>
                            <option value="<?= rex_escape($ns) ?>"><?= Labels::forNamespace($ns) ?></option>
                        <?php endforeach ?>
                    </select>
                    <span class="sprog-hint">
                        <?= rex_i18n::msg('sprog_inbox_unit_modal_namespace_hint') ?>
                    </span>
                </div>

                <label class="sprog-inbox--unit-modal-field">
                    <span class="sprog-inbox--unit-modal-label">
                        <?= rex_i18n::msg('sprog_inbox_unit_modal_key_label') ?>
                    </span>
                    <input
                        type="text"
                        name="unit_key"
                        data-role="modal-unit-key"
                        class="sprog-control sprog-control--mono"
                        maxlength="191"
                        required
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                    >
                </label>

                <label class="sprog-inbox--unit-modal-field">
                    <span class="sprog-inbox--unit-modal-label">
                        <?= rex_i18n::msg('sprog_inbox_unit_modal_context_label') ?>
                    </span>
                    <input
                        type="text"
                        name="context"
                        data-role="modal-context"
                        class="sprog-control sprog-control--mono"
                        list="sprog-inbox-context-list"
                        maxlength="64"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                    >
                    <!--
                        Datalist mit bekannten Bereich-Werten. Browser bietet
                        sie als Auswahl-Dropdown an, der User kann aber auch
                        frei einen neuen Wert tippen.
                    -->
                    <datalist id="sprog-inbox-context-list">
                        <?php foreach ($existingContexts as $ctx) : ?>
                            <option value="<?= rex_escape($ctx) ?>"></option>
                        <?php endforeach ?>
                    </datalist>
                    <span class="sprog-hint">
                        <?= rex_i18n::rawMsg('sprog_inbox_unit_modal_context_hint') ?>
                    </span>
                </label>

                <label class="sprog-inbox--unit-modal-field">
                    <span class="sprog-inbox--unit-modal-label">
                        <?= rex_i18n::msg('sprog_inbox_unit_modal_notes_label') ?>
                    </span>
                    <textarea
                        name="notes"
                        data-role="modal-notes"
                        class="sprog-control sprog-control--textarea"
                        rows="3"
                        maxlength="500"
                    ></textarea>
                </label>

                <p class="sprog-inbox--unit-modal-error" data-role="modal-error" hidden></p>
            </div>

            <footer class="sprog-inbox--unit-modal-footer">
                <button type="button" class="sprog-inbox--unit-modal-cancel" data-role="unit-modal-cancel">
                    <?= rex_i18n::msg('sprog_inbox_unit_modal_cancel') ?>
                </button>
                <button type="submit" class="sprog-btn sprog-btn--primary" data-role="unit-modal-submit">
                    <?= rex_i18n::msg('sprog_inbox_unit_modal_save') ?>
                </button>
            </footer>
        </form>
    </dialog>

    <?php if ($batchEnabled) : ?>
        <?php
        // Stapelverarbeitung: fehlende Übersetzungen einer Sprache per MT
        // vorübersetzen. Reused die generischen Modal-Klassen; die Chunk-Schleife
        // + Fortschritt treibt sprog.inbox.js über die batch_*-Endpoints.
        ?>
        <dialog
            class="sprog-inbox--unit-modal sprog-inbox--batch-modal"
            data-role="batch-modal"
            aria-labelledby="sprog-batch-modal-title"
        >
            <div class="sprog-inbox--unit-modal-form">
                <header class="sprog-inbox--unit-modal-header">
                    <h2 id="sprog-batch-modal-title" class="sprog-inbox--unit-modal-title">
                        <?= rex_i18n::msg('sprog_inbox_batch_title') ?>
                    </h2>
                    <button
                        type="button"
                        class="sprog-inbox--unit-modal-close"
                        data-role="batch-close"
                        aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_unit_modal_close')) ?>"
                    >×</button>
                </header>

                <div class="sprog-inbox--unit-modal-body">
                    <label class="sprog-inbox--unit-modal-field">
                        <span class="sprog-inbox--unit-modal-label">
                            <?= rex_i18n::msg('sprog_inbox_batch_language') ?>
                        </span>
                        <select class="sprog-control" data-role="batch-language">
                            <?php foreach ($batchTargets as $bId => $bClang) : ?>
                                <option value="<?= rex_escape((string) $bId) ?>" <?= $bId === $clangId ? 'selected' : '' ?>>
                                    <?= rex_escape($bClang->getCode()) ?> · <?= rex_escape($bClang->getName()) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </label>

                    <label class="sprog-inbox--unit-modal-field">
                        <span class="sprog-inbox--unit-modal-label">
                            <?= rex_i18n::msg('sprog_inbox_batch_provider') ?>
                        </span>
                        <select class="sprog-control" data-role="batch-provider">
                            <?php foreach ($mtProviderLabels as $bpName => $bpLabel) : ?>
                                <option value="<?= rex_escape((string) $bpName) ?>"><?= rex_escape($bpLabel) ?></option>
                            <?php endforeach ?>
                        </select>
                    </label>

                    <p class="sprog-inbox--batch-count" data-role="batch-count" aria-live="polite"></p>

                    <div class="sprog-inbox--batch-progress" data-role="batch-progress" hidden>
                        <div class="sprog-inbox--batch-bar">
                            <div class="sprog-inbox--batch-bar-fill" data-role="batch-bar"></div>
                        </div>
                        <span class="sprog-inbox--batch-progress-text" data-role="batch-progress-text"></span>
                    </div>

                    <p class="sprog-inbox--batch-summary" data-role="batch-summary" aria-live="polite" hidden></p>
                    <?php // Liste der tatsächlich übersetzten (und fehlgeschlagenen) Platzhalter — vom JS befüllt. ?>
                    <ul class="sprog-inbox--batch-results" data-role="batch-results" hidden></ul>
                    <p class="sprog-inbox--unit-modal-error" data-role="batch-error" hidden></p>
                </div>

                <footer class="sprog-inbox--unit-modal-footer">
                    <button type="button" class="sprog-inbox--unit-modal-cancel" data-role="batch-close">
                        <?= rex_i18n::msg('sprog_inbox_unit_modal_cancel') ?>
                    </button>
                    <button type="button" class="sprog-btn sprog-btn--primary" data-role="batch-start">
                        <?= rex_i18n::msg('sprog_inbox_batch_start') ?>
                    </button>
                    <button type="button" class="sprog-btn sprog-btn--primary" data-role="batch-reload" hidden>
                        <?= rex_i18n::msg('sprog_inbox_batch_reload') ?>
                    </button>
                </footer>
            </div>
        </dialog>
    <?php endif ?>
</article>

<script>
window.sprogInbox = {
    strings: {
        saving:        <?= json_encode(rex_i18n::rawMsg('sprog_inbox_save_saving'), JSON_THROW_ON_ERROR) ?>,
        saved:         <?= json_encode(rex_i18n::rawMsg('sprog_inbox_save_saved'), JSON_THROW_ON_ERROR) ?>,
        conflict:      <?= json_encode(rex_i18n::rawMsg('sprog_inbox_save_conflict'), JSON_THROW_ON_ERROR) ?>,
        errorPrefix:     <?= json_encode(rex_i18n::rawMsg('sprog_inbox_save_error'), JSON_THROW_ON_ERROR) ?>,
        reloadHint:      <?= json_encode(rex_i18n::rawMsg('sprog_inbox_save_reload_hint'), JSON_THROW_ON_ERROR) ?>,
        keyEditTitle:    <?= json_encode(rex_i18n::rawMsg('sprog_inbox_key_edit_title'), JSON_THROW_ON_ERROR) ?>,
        keyEditSaved:    <?= json_encode(rex_i18n::rawMsg('sprog_inbox_key_edit_saved'), JSON_THROW_ON_ERROR) ?>,
        transitionSaved: <?= json_encode(rex_i18n::rawMsg('sprog_inbox_transition_saved'), JSON_THROW_ON_ERROR) ?>,
        notesLabel:      <?= json_encode(rex_i18n::rawMsg('sprog_inbox_notes_label'), JSON_THROW_ON_ERROR) ?>,
        modalTitleEdit:  <?= json_encode(rex_i18n::rawMsg('sprog_inbox_unit_modal_title'), JSON_THROW_ON_ERROR) ?>,
        modalTitleCreate:<?= json_encode(rex_i18n::rawMsg('sprog_inbox_unit_modal_title_create'), JSON_THROW_ON_ERROR) ?>,
        copyDone:        <?= json_encode(rex_i18n::rawMsg('sprog_inbox_copy_placeholder_done'), JSON_THROW_ON_ERROR) ?>,
        mtLoading:        <?= json_encode(rex_i18n::rawMsg('sprog_inbox_mt_button_loading'), JSON_THROW_ON_ERROR) ?>,
        mtApplyConfirm:   <?= json_encode(rex_i18n::rawMsg('sprog_inbox_mt_apply_confirm'), JSON_THROW_ON_ERROR) ?>,
        historyLoading:        <?= json_encode(rex_i18n::rawMsg('sprog_inbox_history_loading'), JSON_THROW_ON_ERROR) ?>,
        historyEmpty:          <?= json_encode(rex_i18n::rawMsg('sprog_inbox_history_empty'), JSON_THROW_ON_ERROR) ?>,
        historyRestore:        <?= json_encode(rex_i18n::rawMsg('sprog_inbox_history_restore'), JSON_THROW_ON_ERROR) ?>,
        historyRestoreConfirm: <?= json_encode(rex_i18n::rawMsg('sprog_inbox_history_restore_confirm'), JSON_THROW_ON_ERROR) ?>,
        historyCurrent:        <?= json_encode(rex_i18n::rawMsg('sprog_inbox_history_current'), JSON_THROW_ON_ERROR) ?>,
        historyEmptyValue:     <?= json_encode(rex_i18n::rawMsg('sprog_inbox_history_empty_value'), JSON_THROW_ON_ERROR) ?>,
        historyOrigin_manual:  <?= json_encode(rex_i18n::rawMsg('sprog_inbox_history_origin_manual'), JSON_THROW_ON_ERROR) ?>,
        historyOrigin_mt:      <?= json_encode(rex_i18n::rawMsg('sprog_inbox_history_origin_mt'), JSON_THROW_ON_ERROR) ?>,
        historyOrigin_restore: <?= json_encode(rex_i18n::rawMsg('sprog_inbox_history_origin_restore'), JSON_THROW_ON_ERROR) ?>,
        restored:              <?= json_encode(rex_i18n::rawMsg('sprog_inbox_restored'), JSON_THROW_ON_ERROR) ?>,
        batchCountOne:         <?= json_encode(rex_i18n::rawMsg('sprog_inbox_batch_count_one'), JSON_THROW_ON_ERROR) ?>,
        batchCountMany:        <?= json_encode(rex_i18n::rawMsg('sprog_inbox_batch_count_many'), JSON_THROW_ON_ERROR) ?>,
        batchNone:             <?= json_encode(rex_i18n::rawMsg('sprog_inbox_batch_none'), JSON_THROW_ON_ERROR) ?>,
        batchWithoutSource:    <?= json_encode(rex_i18n::rawMsg('sprog_inbox_batch_without_source'), JSON_THROW_ON_ERROR) ?>,
        batchOnlyWithoutSourceOne:  <?= json_encode(rex_i18n::rawMsg('sprog_inbox_batch_only_without_source_one'), JSON_THROW_ON_ERROR) ?>,
        batchOnlyWithoutSourceMany: <?= json_encode(rex_i18n::rawMsg('sprog_inbox_batch_only_without_source_many'), JSON_THROW_ON_ERROR) ?>,
        batchRunning:          <?= json_encode(rex_i18n::rawMsg('sprog_inbox_batch_running'), JSON_THROW_ON_ERROR) ?>,
        batchSummary:          <?= json_encode(rex_i18n::rawMsg('sprog_inbox_batch_summary'), JSON_THROW_ON_ERROR) ?>,
        staleHint:             <?= json_encode(rex_i18n::rawMsg('sprog_inbox_stale_hint'), JSON_THROW_ON_ERROR) ?>
    }
};
</script>
