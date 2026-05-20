<?php

declare(strict_types=1);

use Sprog\Enum\Status;
use Sprog\Service\CoverageService;
use Sprog\Support\Labels;

$user = rex::getUser();
if (null === $user) {
    throw new rex_exception('Zugriff verweigert.');
}

$coverage = CoverageService::create();
$overview = $coverage->overview();
$totals = $coverage->totalsByClang();
$hasAnyData = 0 < $coverage->totalUnits();

// Stabile Sortierung der angezeigten Status-Pills.
$statusOrder = [
    Status::Approved,
    Status::Translated,
    Status::NeedsReview,
    Status::Draft,
    Status::Stale,
    Status::Missing,
];

// Anzeige-Reihenfolge der Namespaces im Backend.
$namespaceOrder = ['wildcard', 'abbreviation', 'foreignword', 'article', 'slice', 'yform', 'media'];

$languages = rex_clang::getAll();

?>
<article class="sprog-dashboard">
    <header class="sprog-dashboard--intro">
        <h1 class="sprog-dashboard--heading"><?= rex_i18n::msg('sprog_dashboard_heading') ?></h1>
        <p class="sprog-dashboard--lead"><?= rex_i18n::rawMsg('sprog_dashboard_lead') ?></p>
    </header>

    <?php if (!$hasAnyData) : ?>
        <section class="sprog-dashboard--empty">
            <h2><?= rex_i18n::msg('sprog_dashboard_empty_heading') ?></h2>
            <p><?= rex_i18n::msg('sprog_dashboard_empty_lead') ?></p>
            <?php if ($user->isAdmin()) : ?>
                <a class="sprog-dashboard--cta"
                   href="<?= rex_escape(rex_url::backendPage('sprog/migration', [], false)) ?>">
                    <?= rex_i18n::msg('sprog_dashboard_empty_cta') ?>
                </a>
            <?php endif ?>
        </section>
    <?php else : ?>
        <?php foreach ($languages as $clangId => $clang) :
            if (!$user->getComplexPerm('clang')->hasPerm($clangId)) {
                continue;
            }

            $clangStats = $overview[$clangId] ?? [];
            $totalStat = $totals[$clangId] ?? null;
            $percent = null !== $totalStat ? $totalStat->percent() : 100;
        ?>
            <section class="sprog-dashboard--clang">
                <header class="sprog-dashboard--clang-head">
                    <h2 class="sprog-dashboard--clang-title">
                        <span class="sprog-dashboard--clang-code"><?= rex_escape($clang->getCode()) ?></span>
                        <?= rex_escape($clang->getName()) ?>
                    </h2>
                    <span class="sprog-dashboard--clang-percent" data-percent="<?= rex_escape((string) $percent) ?>">
                        <?= rex_escape((string) $percent) ?>&nbsp;%
                    </span>
                </header>

                <?php if ([] === $clangStats) : ?>
                    <p class="sprog-dashboard--no-stats"><?= rex_i18n::msg('sprog_dashboard_no_stats') ?></p>
                <?php else :
                    // Namespaces sortieren: bekannte zuerst, dann alphabetisch.
                    $ordered = [];
                    foreach ($namespaceOrder as $ns) {
                        if (isset($clangStats[$ns])) {
                            $ordered[$ns] = $clangStats[$ns];
                            unset($clangStats[$ns]);
                        }
                    }
                    ksort($clangStats);
                    $ordered += $clangStats;
                ?>
                    <ul class="sprog-dashboard--namespaces" role="list">
                        <?php foreach ($ordered as $namespace => $stat) :
                            $nsPercent = $stat->percent();
                            $nsTotal = $stat->total();
                        ?>
                            <li class="sprog-dashboard--ns-item">
                                <header class="sprog-dashboard--ns-head">
                                    <h3 class="sprog-dashboard--ns-title"><?= Labels::forNamespace((string) $namespace) ?></h3>
                                    <output class="sprog-dashboard--ns-percent">
                                        <?= rex_escape((string) $nsPercent) ?>&nbsp;%
                                    </output>
                                </header>

                                <progress
                                    class="sprog-dashboard--bar"
                                    max="100"
                                    value="<?= rex_escape((string) $nsPercent) ?>"
                                ></progress>

                                <p class="sprog-dashboard--ns-total">
                                    <?= rex_escape((string) $nsTotal) ?>
                                    <?= rex_i18n::msg(1 === $nsTotal ? 'sprog_dashboard_unit_one' : 'sprog_dashboard_unit_many') ?>
                                </p>

                                <ul class="sprog-dashboard--status-list" role="list">
                                    <?php foreach ($statusOrder as $status) :
                                        $count = $stat->count($status);
                                        if (0 === $count) {
                                            continue;
                                        }
                                    ?>
                                        <li class="sprog-dashboard--status sprog-dashboard--status-<?= rex_escape($status->value) ?>">
                                            <span class="sprog-dashboard--status-count"><?= rex_escape((string) $count) ?></span>
                                            <span class="sprog-dashboard--status-label"><?= Labels::status($status) ?></span>
                                        </li>
                                    <?php endforeach ?>
                                </ul>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            </section>
        <?php endforeach ?>
    <?php endif ?>
</article>
