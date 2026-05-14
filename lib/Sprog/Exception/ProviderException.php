<?php

declare(strict_types=1);

namespace Sprog\Exception;

use RuntimeException;
use Throwable;

/**
 * Wird geworfen, wenn ein MT-Provider den Übersetzungs-Aufruf nicht
 * bedienen kann — z.B. fehlender API-Key, HTTP-Fehler, Quota überschritten,
 * unparsbare Antwort, nicht unterstütztes Sprachpaar.
 *
 * Der Aufrufer (Backend-Page, Inbox-Bulk-Action, REST-Endpoint) fängt das
 * separat von anderen Throwables und zeigt eine klare Provider-Meldung, statt
 * eine generische "Übersetzung fehlgeschlagen".
 */
final class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $providerName = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
