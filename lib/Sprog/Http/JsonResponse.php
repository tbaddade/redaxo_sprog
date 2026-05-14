<?php

declare(strict_types=1);

namespace Sprog\Http;

use rex_csrf_token;
use rex_response;

/**
 * Einheitliches JSON-Response-Envelope für alle Sprog-Endpoints.
 *
 * Format: { ok: bool, error?: string, ...payload }.
 *
 * Vor jedem Send wird cleanOutputBuffers aufgerufen — Page-Output aus
 * Backend-Plugins oder Notice-Handlern würde sonst das JSON kontaminieren.
 *
 * Die *Status-Helper geben never zurück: die Endpoints exiten direkt nach
 * dem Send. Caller können sich darauf verlassen, dass nach einem
 * JsonResponse::ok / error / … kein weiterer Code mehr läuft — analog zum
 * v1-Inline-Pattern in pages/inbox.php, das hier nur zentralisiert wird.
 */
final class JsonResponse
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function ok(array $payload = []): never
    {
        rex_response::cleanOutputBuffers();
        rex_response::sendJson(['ok' => true] + $payload);
        exit;
    }

    /**
     * @param array<string, mixed> $payload optional zusätzliche Felder
     */
    public static function error(string $message, string $httpStatus = rex_response::HTTP_BAD_REQUEST, array $payload = []): never
    {
        rex_response::cleanOutputBuffers();
        rex_response::setStatus($httpStatus);
        rex_response::sendJson(['ok' => false, 'error' => $message] + $payload);
        exit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function badRequest(string $message, array $payload = []): never
    {
        self::error($message, rex_response::HTTP_BAD_REQUEST, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function forbidden(string $message, array $payload = []): never
    {
        self::error($message, rex_response::HTTP_FORBIDDEN, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function notFound(string $message, array $payload = []): never
    {
        self::error($message, rex_response::HTTP_NOT_FOUND, $payload);
    }

    /**
     * rex_response hat keine HTTP_CONFLICT-Konstante — Status als Literal.
     *
     * @param array<string, mixed> $payload
     */
    public static function conflict(string $message, array $payload = []): never
    {
        self::error($message, '409 Conflict', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function internalError(string $message, array $payload = []): never
    {
        self::error($message, rex_response::HTTP_INTERNAL_ERROR, $payload);
    }

    /**
     * Validiert ein CSRF-Token; sendet bei Fehlschlag ein 403-JSON-Error
     * und beendet den Request. Caller bleibt nur weiter, wenn das Token
     * gültig ist.
     */
    public static function ensureCsrf(string $tokenId, string $errorMessage): void
    {
        if (rex_csrf_token::factory($tokenId)->isValid()) {
            return;
        }

        self::forbidden($errorMessage);
    }
}
