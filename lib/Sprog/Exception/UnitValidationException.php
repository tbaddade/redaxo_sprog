<?php

declare(strict_types=1);

namespace Sprog\Exception;

use InvalidArgumentException;

/**
 * Wird von UnitService::createUnit() geworfen, wenn die Eingaben die
 * Validierung (UnitValidator) nicht bestehen. Trägt alle Fehlertexte
 * (unescaped) — der Aufrufer entscheidet, ob er den ersten oder alle zeigt.
 */
final class UnitValidationException extends InvalidArgumentException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(implode(' ', $errors));
    }
}
