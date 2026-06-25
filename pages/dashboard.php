<?php

declare(strict_types=1);

use Sprog\Enum\SourceType;
use Sprog\Enum\Status;
use Sprog\Model\CoverageStat;
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
    Status::Revise,
    Status::Stale,
    Status::Missing,
];

// Anzeige-Reihenfolge der Namespaces im Backend — folgt der Reihenfolge der
// SourceType-Enum-Cases, damit künftig erweiterte SourceTypes automatisch
// im Dashboard auftauchen (single source of truth).
$namespaceOrder = SourceType::values();

$languages = rex_clang::getAll();

// Stacked-Status-Balken: ein Segment je vorhandenem Status (Reihenfolge wie
// $statusOrder), Breite = Anteil an total. $large rendert den dickeren
// Gesamt-Balken im Sprach-Header. Bei leerem Bestand ein leerer Track.
$renderBar = static function (CoverageStat $stat, array $statusOrder, bool $large): string {
    $total = $stat->total();
    $cls = 'sprog-dashboard--bar' . ($large ? ' sprog-dashboard--bar--lg' : '');
    if (0 === $total) {
        return '<div class="' . $cls . ' sprog-dashboard--bar--empty"></div>';
    }
    $segments = '';
    foreach ($statusOrder as $status) {
        $count = $stat->count($status);
        if (0 === $count) {
            continue;
        }
        $width = $count / $total * 100;
        $segments .= '<span class="sprog-dashboard--seg"'
            . ' style="--c:var(--sprog-status-' . $status->value . ');width:' . $width . '%"'
            . ' title="' . rex_escape((string) $count) . ' ' . Labels::status($status) . '"></span>';
    }

    return '<div class="' . $cls . '">' . $segments . '</div>';
};

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
        <div class="sprog-dashboard--legend">
            <?php foreach ($statusOrder as $status) : ?>
                <span class="sprog-dashboard--legend-item">
                    <span class="sprog-dashboard--legend-swatch" style="--c:var(--sprog-status-<?= $status->value ?>)" aria-hidden="true"></span>
                    <span><?= Labels::status($status) ?></span>
                </span>
            <?php endforeach ?>
        </div>

        <?php foreach ($languages as $clangId => $clang) :
            if (!$user->getComplexPerm('clang')->hasPerm($clangId)) {
                continue;
            }

            $clangStats = $overview[$clangId] ?? [];
            $totalStat = $totals[$clangId] ?? null;
            $percent = null !== $totalStat ? $totalStat->percent() : 100;
            $doneUnits = null !== $totalStat ? $totalStat->done() : 0;
            $allUnits = null !== $totalStat ? $totalStat->total() : 0;
        ?>
            <section class="sprog-dashboard--clang<?= 100 === $percent ? ' sprog-dashboard--clang--done' : '' ?>">
                <header class="sprog-dashboard--clang-head">
                    <span class="sprog-dashboard--clang-code"><?= rex_escape($clang->getCode()) ?></span>
                    <span class="sprog-dashboard--clang-name"><?= rex_escape($clang->getName()) ?></span>
                    <div class="sprog-dashboard--clang-metric">
                        <span class="sprog-dashboard--clang-percent" data-percent="<?= rex_escape((string) $percent) ?>"><?= rex_escape((string) $percent) ?>&nbsp;%</span>
                        <span class="sprog-dashboard--clang-pct-label">
                            <?= rex_i18n::msg('sprog_dashboard_done') ?><br>
                            <span class="sprog-dashboard--clang-count"><?= rex_escape((string) $doneUnits) ?>&nbsp;/&nbsp;<?= rex_escape((string) $allUnits) ?> <?= rex_i18n::msg('sprog_dashboard_unit_many') ?></span>
                        </span>
                    </div>
                </header>

                <?php if ([] === $clangStats || null === $totalStat) : ?>
                    <p class="sprog-dashboard--no-stats"><?= rex_i18n::msg('sprog_dashboard_no_stats') ?></p>
                <?php else :
                    // Namespaces sortieren: bekannte (Enum-Reihenfolge) zuerst, dann alphabetisch.
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
                    <?= $renderBar($totalStat, $statusOrder, true) ?>

                    <div class="sprog-dashboard--stats">
                        <?php foreach ($statusOrder as $status) :
                            $count = $totalStat->count($status);
                            if (0 === $count) {
                                continue;
                            }
                        ?>
                            <span class="sprog-dashboard--stat" style="--c:var(--sprog-status-<?= $status->value ?>)">
                                <span class="sprog-dashboard--stat-count"><?= rex_escape((string) $count) ?></span>
                                <span class="sprog-dashboard--stat-label"><?= Labels::status($status) ?></span>
                            </span>
                        <?php endforeach ?>
                    </div>

                    <ul class="sprog-dashboard--namespaces" role="list">
                        <?php foreach ($ordered as $namespace => $stat) :
                            $nsTotal = $stat->total();
                        ?>
                            <li class="sprog-dashboard--ns">
                                <span class="sprog-dashboard--ns-name"><?= Labels::forNamespace((string) $namespace) ?></span>
                                <?= $renderBar($stat, $statusOrder, false) ?>
                                <span class="sprog-dashboard--ns-meta">
                                    <span class="sprog-dashboard--ns-percent"><?= rex_escape((string) $stat->percent()) ?>&nbsp;%</span>
                                    <span class="sprog-dashboard--ns-count"><?= rex_escape((string) $nsTotal) ?> <?= rex_i18n::msg(1 === $nsTotal ? 'sprog_dashboard_unit_one' : 'sprog_dashboard_unit_many') ?></span>
                                </span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            </section>
        <?php endforeach ?>
    <?php endif ?>
</article>
