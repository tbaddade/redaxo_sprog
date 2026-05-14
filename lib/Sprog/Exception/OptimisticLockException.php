<?php

declare(strict_types=1);

namespace Sprog\Exception;

use RuntimeException;
use Throwable;

/**
 * Wird geworfen, wenn ein UPDATE auf einer Translation nicht durchgeht,
 * weil die zugrundeliegende Row in der DB inzwischen eine andere Revision
 * hat — d.h. ein anderer Prozess hat zwischen Load und Save ge-updated.
 *
 * Der Aufrufer (Editor-Page) fängt diese Exception und zeigt eine klare
 * Konflikt-Meldung statt ein "Speichern fehlgeschlagen". Der Page-Reload
 * lädt dann die aktuelle Version und der User kann seine Änderungen erneut
 * applizieren.
 */
final class OptimisticLockException extends RuntimeException
{
    public function __construct(
        public readonly int $translationId,
        public readonly int $expectedRevision,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Translation %d wurde zwischenzeitlich von einem anderen Prozess verändert (erwartete Revision %d).',
                $translationId,
                $expectedRevision,
            ),
            0,
            $previous,
        );
    }
}
