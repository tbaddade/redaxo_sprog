<?php

declare(strict_types=1);

namespace Sprog\Model;

use DateTimeImmutable;
use Sprog\Enum\Status;

/**
 * Listen-Eintrag für die Inbox.
 *
 * Pro Unit ein Item. Enthält:
 *   - Unit-Stamm: id, namespace, unitKey, notes
 *   - Display-Translation: die Übersetzung in der Anzeige-Sprache (Sprache-
 *     Selector im Listen-Header). Wird im Summary als Wert-Preview gezeigt,
 *     das Status-Badge spiegelt diesen Status.
 *   - Optionaler Filter-Match: wenn ein Status-Filter aktiv ist und der
 *     Match nicht auf die Anzeige-Sprache fällt (z.B. Filter "Entwurf",
 *     Anzeige=DE übersetzt, aber EN ist Entwurf), liefert die Query
 *     matchClangId + matchClangCode + matchStatus für den UI-Hinweis
 *     "übersetzt · +en entwurf".
 *
 * Immutable; reine Lesen-Sicht.
 */
final readonly class TranslationListItem
{
    public function __construct(
        public int $unitId,
        public string $namespace,
        public string $unitKey,
        public string $context,
        public ?string $notes,

        public int $displayClangId,
        public int $displayTranslationId,
        public string $displayValue,
        public Status $displayStatus,
        public ?string $displayMtProvider,
        public ?float $displayMtConfidence,
        public int $displayRevision,
        public ?DateTimeImmutable $displayUpdatedAt,

        public ?int $matchClangId = null,
        public ?string $matchClangCode = null,
        public ?Status $matchStatus = null,
    ) {}
}
