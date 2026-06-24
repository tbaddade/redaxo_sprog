<?php

declare(strict_types=1);

namespace Sprog\Model;

use InvalidArgumentException;
use Sprog\Enum\Status;

use function sprintf;
use function strlen;

/**
 * Validierte Filter-Eingabe für die Translation-Inbox.
 *
 * Alle Eingaben werden im Konstruktor durchgereicht; ungültige Werte
 * werden hart abgelehnt (InvalidArgumentException), nicht stillschweigend
 * normalisiert. So bekommt der Caller (Backend-Page) ein klares Signal,
 * wenn die Filter-Query manipuliert wurde.
 */
final readonly class TranslationListFilter
{
    public const MAX_PAGE_SIZE = 200;
    public const DEFAULT_PAGE_SIZE = 50;
    public const MAX_SEARCH_LENGTH = 200;
    public const MAX_NAMESPACE_LEN = 64;

    /**
     * @param int           $clangId   rex_clang.id, > 0
     * @param ?string       $namespace Single Namespace-Filter (z.B. 'wildcard'); null = alle
     * @param list<Status>  $statuses  Erlaubte Status; leer = alle
     * @param ?string       $search    Substring-Suche in unit_key + value; null/leer = kein Filter
     * @param int           $page      1-basiert
     * @param int           $pageSize  1..MAX_PAGE_SIZE
     * @param bool          $conflictsOnly Nur Units mit Wildcard-Konflikt (Punkt-Trenner-Ambiguität)
     */
    public function __construct(
        public int $clangId,
        public ?string $namespace,
        public array $statuses,
        public ?string $search,
        public int $page,
        public int $pageSize,
        public bool $conflictsOnly = false,
    ) {
        if ($clangId <= 0) {
            throw new InvalidArgumentException('clangId muss > 0 sein.');
        }
        if (null !== $namespace) {
            if ('' === $namespace || strlen($namespace) > self::MAX_NAMESPACE_LEN) {
                throw new InvalidArgumentException('namespace hat ungültige Länge.');
            }
            if (1 !== preg_match('/^[a-z0-9_.]+$/i', $namespace)) {
                throw new InvalidArgumentException('namespace darf nur a-z, 0-9, "_" und "." enthalten.');
            }
        }
        foreach ($statuses as $candidate) {
            if (!$candidate instanceof Status) {
                throw new InvalidArgumentException('statuses muss eine Liste von Status-Enums sein.');
            }
        }
        if (null !== $search && strlen($search) > self::MAX_SEARCH_LENGTH) {
            throw new InvalidArgumentException('search übersteigt die Maximallänge.');
        }
        if ($page < 1) {
            throw new InvalidArgumentException('page muss >= 1 sein.');
        }
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException(sprintf('pageSize muss im Bereich [1, %d] liegen.', self::MAX_PAGE_SIZE));
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
