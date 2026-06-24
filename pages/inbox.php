<?php

declare(strict_types=1);

use Sprog\Controller\Inbox\InboxRouter;
use Sprog\Enum\SourceType;
use Sprog\Enum\Status;
use Sprog\Model\TranslationListFilter;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Sprog\Service\TranslationListService;
use Sprog\Service\WildcardConflictService;
use Sprog\Support\Labels;

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
$clangs = rex_clang::getAll();

// Default-Sprache: aktuelle Backend-Sprache, sonst erste erlaubte.
$defaultClang = rex_clang::getCurrentId();
if (!$user->getComplexPerm('clang')->hasPerm($defaultClang)) {
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

// Per-Sprache Berechtigungs-Check vor der DB-Query — kein Bypass durch URL-Tampering.
if ($clangId <= 0 || !$user->getComplexPerm('clang')->hasPerm($clangId)) {
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

// Anzeige-Reihenfolge der Status-Optionen — schwergewichtige zuerst.
$statusOptions = [
    Status::Missing,
    Status::Stale,
    Status::Draft,
    Status::NeedsReview,
    Status::Revise,
    Status::Translated,
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

// Page-globaler CSRF-Token für Save / UpdateUnit / Transition. Per-Unit-
// Token wäre Resource-Scope, hier reicht der Operation-Scope: das Token
// ist Session-gebunden, eine Unit-Querverweis-Attacke ist außerhalb der
// Session ohnehin nicht möglich (CSRF schützt das Session-Cookie-Risiko).
$inboxSaveCsrf = rex_csrf_token::factory('sprog_inbox_save');

?>
<article
    class="sprog-inbox"
    data-sprog-inbox
    data-endpoint="<?= rex_escape($jsonEndpoint) ?>"
    data-endpoint-update-unit="<?= rex_escape($endpointUpdateUnit) ?>"
    data-endpoint-transition="<?= rex_escape($endpointTransition) ?>"
    data-can-edit-unit="<?= $canEditUnit ? '1' : '0' ?>"
    data-csrf-name="<?= rex_escape(rex_csrf_token::PARAM) ?>"
    data-csrf-value="<?= rex_escape($inboxSaveCsrf->getValue()) ?>"
>
    <header class="sprog-inbox--intro">
        <h1 class="sprog-inbox--heading"><?= rex_i18n::msg('sprog_inbox_heading') ?></h1>
        <p class="sprog-inbox--lead"><?= rex_i18n::msg('sprog_inbox_lead') ?></p>
    </header>

    <?php
    // Legende aller sieben Status, grob in Workflow-Reihenfolge: der Haupt-
    // pfad missing → draft → translated → approved, dazwischen die Review-
    // Status needs_review (jetzt nur noch system-gesetzt) und revise (Rückgabe
    // durch die Reviewerin), am Ende stale als Spezialfall bei Quell-Änderung.
    $legendOrder = [
        Status::Missing,
        Status::Draft,
        Status::Translated,
        Status::NeedsReview,
        Status::Revise,
        Status::Approved,
        Status::Stale,
    ];
    ?>
    <details class="sprog-inbox--legend">
        <summary class="sprog-inbox--legend-summary">
            <span class="sprog-inbox--legend-icon" aria-hidden="true">i</span>
            <?= rex_i18n::msg('sprog_inbox_legend_summary') ?>
            <span class="sprog-inbox--chevron"><?= $chevronSvg ?></span>
        </summary>
        <div class="sprog-inbox--legend-body">
            <section class="sprog-inbox--legend-section">
                <h3 class="sprog-inbox--legend-heading">
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

            <section class="sprog-inbox--legend-section">
                <h3 class="sprog-inbox--legend-heading">
                    <?= rex_i18n::msg('sprog_inbox_legend_workflow_heading') ?>
                </h3>
                <p class="sprog-inbox--legend-text">
                    <?= rex_i18n::rawMsg('sprog_inbox_legend_workflow_text') ?>
                </p>
            </section>

            <section class="sprog-inbox--legend-section">
                <h3 class="sprog-inbox--legend-heading">
                    <?= rex_i18n::msg('sprog_inbox_legend_pills_heading') ?>
                </h3>
                <p class="sprog-inbox--legend-text">
                    <?= rex_i18n::rawMsg('sprog_inbox_legend_pills_text') ?>
                </p>
            </section>
        </div>
    </details>

    <?php
    // Status-Summary fürs Dropdown-Label: 0 = „alle", sonst Anzahl.
    $statusSummaryText = [] === $statuses
        ? rex_i18n::msg('sprog_inbox_filter_all')
        : rex_i18n::msg('sprog_inbox_filter_status_count', (string) count($statuses));
    ?>
    <form method="get" action="index.php" class="sprog-inbox--toolbar" data-sprog-inbox-filter>
        <input type="hidden" name="page" value="sprog/inbox">

        <!-- Top-Row: Suchfeld füllt links, Filtern/Reset rechts daneben, Neue-Einheit ganz rechts. -->
        <div class="sprog-inbox--search-row">
            <label class="sprog-inbox--toolbar-search">
                <span class="sprog-inbox--toolbar-search-icon" aria-hidden="true">
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
                    class="sprog-inbox--toolbar-search-input"
                >
            </label>
            <button type="submit" class="sprog-inbox--toolbar-apply">
                <?= rex_i18n::msg('sprog_inbox_filter_submit') ?>
            </button>
            <a class="sprog-inbox--toolbar-reset"
               href="<?= rex_escape(rex_url::currentBackendPage(['clang_id' => $clangId], false)) ?>">
                <?= rex_i18n::msg('sprog_inbox_filter_reset') ?>
            </a>
            <!--
                href bleibt als no-JS-Fallback bestehen — wenn JavaScript aktiv ist,
                fängt der Click-Handler in sprog.inbox.js ab und öffnet stattdessen
                das Edit-Modal im Create-Mode.
            -->
            <a
                class="sprog-inbox--button sprog-inbox--button-primary"
                data-role="unit-create-trigger"
                href="<?= rex_escape(rex_url::backendPage('sprog/create', [], false)) ?>"
            ><?= rex_i18n::msg('sprog_inbox_button_new') ?></a>
        </div>

        <!-- Listen-Header: Anzahl links, Sprache/Quelle/Status-Dropdowns rechts. -->
        <div class="sprog-inbox--list-header">
            <p class="sprog-inbox--summary">
                <strong><?= rex_escape((string) $total) ?></strong>
                <?= rex_i18n::msg(1 === $total ? 'sprog_inbox_summary_entry' : 'sprog_inbox_summary_entries') ?>
                <?php if ($total > $pageSize) : ?>
                    · <?= rex_i18n::msg('sprog_inbox_summary_page', (string) $page, (string) $lastPage) ?>
                <?php endif ?>
            </p>

            <div class="sprog-inbox--list-header-filters">
                <label class="sprog-inbox--toolbar-cell sprog-inbox--toolbar-cell--select">
                    <span class="sprog-inbox--toolbar-cell-label"><?= rex_i18n::msg('sprog_inbox_filter_language') ?></span>
                    <span class="sprog-inbox--toolbar-cell-value"><?= rex_escape($currentClangLabel) ?></span>
                    <span class="sprog-inbox--chevron"><?= $chevronSvg ?></span>
                    <select name="clang_id" class="sprog-inbox--toolbar-cell-input">
                        <?php foreach ($clangs as $id => $clang) :
                            if (!$user->getComplexPerm('clang')->hasPerm($id)) {
                                continue;
                            }
                        ?>
                            <option
                                value="<?= rex_escape((string) $id) ?>"
                                <?= $id === $clangId ? 'selected' : '' ?>
                            >
                                <?= rex_escape($clang->getCode()) ?> · <?= rex_escape($clang->getName()) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </label>

                <label class="sprog-inbox--toolbar-cell sprog-inbox--toolbar-cell--select">
                    <span class="sprog-inbox--toolbar-cell-label"><?= rex_i18n::msg('sprog_inbox_filter_namespace') ?></span>
                    <span class="sprog-inbox--toolbar-cell-value"><?= rex_escape($currentNamespaceLabel) ?></span>
                    <span class="sprog-inbox--chevron"><?= $chevronSvg ?></span>
                    <select name="namespace" class="sprog-inbox--toolbar-cell-input">
                        <option value=""><?= rex_i18n::msg('sprog_inbox_filter_all') ?></option>
                        <?php foreach (SourceType::values() as $ns) : ?>
                            <option
                                value="<?= rex_escape($ns) ?>"
                                <?= $ns === $namespace ? 'selected' : '' ?>
                            ><?= Labels::forNamespace($ns) ?></option>
                        <?php endforeach ?>
                    </select>
                </label>

                <details class="sprog-inbox--toolbar-cell sprog-inbox--toolbar-cell--dropdown" tabindex="0" aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_filter_status_aria')) ?>">
                    <summary class="sprog-inbox--toolbar-cell-summary" tabindex="-1">
                        <span class="sprog-inbox--toolbar-cell-label"><?= rex_i18n::msg('sprog_inbox_filter_status') ?></span>
                        <span class="sprog-inbox--toolbar-cell-value"><?= rex_escape($statusSummaryText) ?></span>
                        <span class="sprog-inbox--chevron"><?= $chevronSvg ?></span>
                    </summary>
                    <div class="sprog-inbox--toolbar-popup">
                        <?php foreach ($statusOptions as $status) : ?>
                            <label class="sprog-inbox--toolbar-check">
                                <input
                                    type="checkbox"
                                    name="status[]"
                                    value="<?= rex_escape($status->value) ?>"
                                    <?= in_array($status, $statuses, true) ? 'checked' : '' ?>
                                >
                                <span><?= Labels::status($status) ?></span>
                            </label>
                        <?php endforeach ?>
                        <button type="submit" class="sprog-inbox--toolbar-popup-apply">
                            <?= rex_i18n::msg('sprog_inbox_filter_submit') ?>
                        </button>
                    </div>
                </details>
            </div>
        </div>

    <?php if ([] === $items) : ?>
        <p class="sprog-inbox--empty"><?= rex_i18n::msg('sprog_inbox_empty') ?></p>
    <?php else : ?>
        <ul class="sprog-inbox--list" role="list">
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
                    if (null !== $t && in_array($t->status, [Status::Translated, Status::Approved], true)) {
                        ++$coverageDone;
                    }
                }
            ?>
                <?php $conflictHint = $conflictMap[$unit->id] ?? null ?>
                <li class="sprog-inbox--card">
                    <details
                        class="sprog-inbox--unit"
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
                        <summary class="sprog-inbox--unit-summary">
                            <div class="sprog-inbox--summary-main">
                                <div class="sprog-inbox--key-line">
                                    <?php if (null !== $conflictHint) : ?>
                                        <span class="sprog-inbox--conflict-flag" title="<?= rex_escape($conflictHint) ?>" aria-label="<?= rex_escape($conflictHint) ?>">
                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                                <path d="M6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575L6.457 1.047ZM8 5a.75.75 0 0 0-.75.75v3.5a.75.75 0 0 0 1.5 0v-3.5A.75.75 0 0 0 8 5Zm1 7a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/>
                                            </svg>
                                        </span>
                                    <?php endif ?>
                                    <?php if ('' !== $item->context) : ?>
                                        <span class="sprog-inbox--context" data-role="context-text"><?= rex_escape($item->context) ?></span>
                                        <span class="sprog-inbox--context-sep" aria-hidden="true">/</span>
                                    <?php endif ?>
                                    <span class="sprog-inbox--key" data-role="key-text"><?= rex_escape($item->unitKey) ?></span>
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
                                <span class="sprog-inbox--value-preview <?= '' === $item->displayValue ? 'is-empty' : '' ?>">
                                    <?= '' === $item->displayValue
                                        ? rex_i18n::msg('sprog_inbox_value_empty')
                                        : rex_escape($item->displayValue) ?>
                                </span>
                            </div>
                            <div class="sprog-inbox--summary-meta">
                                <span class="sprog-inbox--namespace"><?= Labels::forNamespace($item->namespace) ?></span>
                                <!--
                                    Pro Sprache eine Pill: Sprachkürzel als Text,
                                    Hintergrundfarbe = Status-Farbe (.sprog-status--<status>),
                                    Tooltip = Sprache + Status-Label.
                                    Initial (Status=missing) wird die Pille im
                                    neutralen Coverage-Look gerendert — erst
                                    "berührte" Sprachen bekommen Status-Farbe.
                                -->
                                <div class="sprog-inbox--lang-badges">
                                    <?php foreach ($clangs as $cId => $clang) :
                                        $t = $unitTranslations[$cId] ?? null;
                                        $cStat = null !== $t ? $t->status : Status::Missing;
                                        $tipTxt = $clang->getName() . ' · ' . Labels::status($cStat);
                                    ?>
                                        <span
                                            class="sprog-status sprog-status--<?= rex_escape($cStat->value) ?> sprog-inbox--lang-badge"
                                            title="<?= rex_escape($tipTxt) ?>"
                                        ><?= rex_escape($clang->getCode()) ?></span>
                                    <?php endforeach ?>
                                </div>
                                <span class="sprog-inbox--coverage"
                                      title="<?= rex_escape(rex_i18n::msg('sprog_inbox_coverage_title', (string) $coverageDone, (string) $coverageAll)) ?>">
                                    <?= rex_escape($coverageDone . '/' . $coverageAll) ?>
                                </span>
                                <span class="sprog-inbox--chevron"><?= $chevronSvg ?></span>
                            </div>
                        </summary>

                        <div class="sprog-inbox--rows" role="group" aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_rows_label', $item->unitKey)) ?>">
                            <?php if (null !== $item->notes) : ?>
                                <p class="sprog-inbox--notes">
                                    <span class="sprog-inbox--notes-label"><?= rex_i18n::msg('sprog_inbox_notes_label') ?></span>
                                    <?= rex_escape($item->notes) ?>
                                </p>
                            <?php endif ?>

                            <?php foreach ($clangs as $cId => $clang) :
                                $hasPerm = $user->getComplexPerm('clang')->hasPerm($cId);
                                $tr = $unitTranslations[$cId] ?? null;
                                $value = null !== $tr ? $tr->value : '';
                                $status = null !== $tr ? $tr->status : Status::Missing;
                                $revision = null !== $tr ? $tr->revision : 0;
                                $isStale = null !== $tr && null !== $unit->sourceHash
                                    && $tr->isStaleAgainst($unit->sourceHash);

                                $rowClasses = ['sprog-inbox--row'];
                                if (!$hasPerm) {
                                    $rowClasses[] = 'is-readonly';
                                }
                                if ($isStale) {
                                    $rowClasses[] = 'is-stale';
                                }
                            ?>
                                <div
                                    class="<?= rex_escape(implode(' ', $rowClasses)) ?>"
                                    data-clang-id="<?= rex_escape((string) $cId) ?>"
                                    data-revision="<?= rex_escape((string) $revision) ?>"
                                    data-status="<?= rex_escape($status->value) ?>"
                                >
                                    <div class="sprog-inbox--row-head">
                                        <span class="sprog-inbox--clang-code"><?= rex_escape($clang->getCode()) ?></span>
                                        <span class="sprog-inbox--clang-name"><?= rex_escape($clang->getName()) ?></span>
                                        <span class="sprog-status sprog-status--<?= rex_escape($status->value) ?>" data-role="row-status">
                                            <?= Labels::status($status) ?>
                                        </span>
                                        <?php if (!$hasPerm) : ?>
                                            <span class="sprog-inbox--readonly-hint" title="<?= rex_escape(rex_i18n::msg('sprog_inbox_readonly_hint')) ?>">
                                                <?= rex_i18n::msg('sprog_inbox_readonly_short') ?>
                                            </span>
                                        <?php endif ?>
                                    </div>

                                    <div class="sprog-inbox--row-body">
                                        <?php if ($isStale) : ?>
                                            <p class="sprog-inbox--stale-hint"><?= rex_i18n::msg('sprog_editor_stale_hint') ?></p>
                                        <?php endif ?>

                                        <textarea
                                            class="sprog-inbox--textarea"
                                            data-role="value"
                                            data-original="<?= rex_escape($value) ?>"
                                            data-auto-grow
                                            data-max-rows="8"
                                            rows="1"
                                            <?= $hasPerm ? '' : 'readonly aria-readonly="true"' ?>
                                        ><?= rex_escape($value) ?></textarea>

                                        <?php
                                        /*
                                         * Workflow-Buttons fest in fester Reihenfolge rendern: Übersetzt
                                         * → Review nötig → Überarbeiten → Freigegeben. So sieht der User
                                         * den ganzen Workflow auf einen Blick — die jeweils nicht
                                         * erlaubten Übergänge erscheinen als disabled. Auch bei
                                         * Status=Missing rendern: sobald der User tippt, kippt der
                                         * Status auf Draft und die zwei Forward-Buttons werden ohne
                                         * Reload aktiv. Reibungsloses Arbeiten ohne DOM-Flicker.
                                         */
                                        if ($hasPerm && null !== $tr) :
                                            $activeTransitions = $status->userActions();
                                            // Ein-Reviewer-Modell: NeedsReview ist nur noch
                                            // System-Status (MT-Auto-Flag etc.), kein Button.
                                            $workflowButtons = [
                                                Status::Translated,
                                                Status::Revise,
                                                Status::Approved,
                                            ];
                                        ?>
                                            <div class="sprog-inbox--row-actions" role="group" aria-label="<?= rex_escape(rex_i18n::msg('sprog_inbox_row_actions_label')) ?>">
                                                <?php foreach ($workflowButtons as $targetStatus) :
                                                    $isActive = in_array($targetStatus, $activeTransitions, true);
                                                ?>
                                                    <button
                                                        type="button"
                                                        class="sprog-inbox--row-action sprog-inbox--row-action--<?= rex_escape($targetStatus->value) ?>"
                                                        data-role="transition"
                                                        data-translation-id="<?= rex_escape((string) $tr->id) ?>"
                                                        data-target-status="<?= rex_escape($targetStatus->value) ?>"
                                                        <?= $isActive ? '' : 'disabled' ?>
                                                    >
                                                        → <?= Labels::status($targetStatus) ?>
                                                    </button>
                                                <?php endforeach ?>
                                            </div>
                                        <?php endif ?>

                                        <p class="sprog-inbox--row-feedback" data-role="feedback" aria-live="polite"></p>
                                    </div>
                                </div>
                            <?php endforeach ?>
                        </div>
                    </details>
                </li>
            <?php endforeach ?>
        </ul>

        <?php if ($lastPage > 1) :
            $pageUrl = static function (int $p) use ($baseParams): string {
                return rex_url::currentBackendPage(['pg' => $p] + $baseParams, false);
            };
        ?>
            <nav class="sprog-inbox--pagination" aria-label="<?= rex_i18n::msg('sprog_inbox_filter_status') ?>">
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
                        class="sprog-inbox--unit-modal-input"
                        data-role="modal-namespace-select"
                        name="namespace"
                        hidden
                    >
                        <?php foreach (SourceType::userCreatable() as $ns) : ?>
                            <option value="<?= rex_escape($ns) ?>"><?= Labels::forNamespace($ns) ?></option>
                        <?php endforeach ?>
                    </select>
                    <span class="sprog-inbox--unit-modal-hint">
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
                        class="sprog-inbox--unit-modal-input"
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
                        class="sprog-inbox--unit-modal-input"
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
                    <span class="sprog-inbox--unit-modal-hint">
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
                        class="sprog-inbox--unit-modal-textarea"
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
                <button type="submit" class="sprog-inbox--button sprog-inbox--button-primary" data-role="unit-modal-submit">
                    <?= rex_i18n::msg('sprog_inbox_unit_modal_save') ?>
                </button>
            </footer>
        </form>
    </dialog>
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
        modalTitleCreate:<?= json_encode(rex_i18n::rawMsg('sprog_inbox_unit_modal_title_create'), JSON_THROW_ON_ERROR) ?>
    }
};
</script>
