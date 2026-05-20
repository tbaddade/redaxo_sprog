<?php

declare(strict_types=1);

namespace Sprog\Model;

use Sprog\Enum\Status;

/**
 * Aggregierte Coverage-Statistik für eine Sprache + Namespace-Kombination.
 *
 * Immutable Snapshot — produziert vom CoverageService aus einer einzigen
 * GROUP-BY-Query. Eine Instanz beschreibt z.B. "EN, namespace=wildcard:
 * 42 missing, 3 draft, 120 translated, 0 needs_review, 27 approved, 8 stale".
 */
final readonly class CoverageStat
{
    /**
     * @param array<string, int> $countByStatus Status::value => count
     */
    public function __construct(
        public int $clangId,
        public string $namespace,
        public array $countByStatus,
    ) {}

    public function count(Status $status): int
    {
        return $this->countByStatus[$status->value] ?? 0;
    }

    public function total(): int
    {
        return (int) array_sum($this->countByStatus);
    }

    /**
     * Erledigt = translated + approved. Wir trennen das bewusst von "open",
     * statt "everything not missing" zu nehmen, weil draft/stale/needs_review
     * fachlich auch noch Arbeit bedeuten.
     */
    public function done(): int
    {
        return $this->count(Status::Translated) + $this->count(Status::Approved);
    }

    public function open(): int
    {
        return $this->count(Status::Missing)
            + $this->count(Status::Draft)
            + $this->count(Status::NeedsReview)
            + $this->count(Status::Stale);
    }

    /**
     * Prozentwert für die Fortschrittsanzeige (0–100). Bei leerem Bestand
     * gilt 100% (es gibt nichts zu übersetzen), damit das UI nicht "0%"
     * meldet, wo eigentlich nichts zu tun ist.
     */
    public function percent(): int
    {
        $total = $this->total();
        if (0 === $total) {
            return 100;
        }

        return (int) min(100, round($this->done() * 100 / $total));
    }
}
