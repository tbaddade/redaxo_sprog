<?php

declare(strict_types=1);

namespace Sprog\Support;

use rex_i18n;
use Sprog\Enum\SourceType;
use Sprog\Enum\Status;

/**
 * Zentrale Stelle für UI-Labels von Enum-Werten und Namespaces.
 *
 * Statt überall in den Pages `rex_i18n::msg('sprog_status_' . $status->value)`
 * zu schreiben, geht alles durch diese Klasse — falls ein Übergang gewünscht
 * ist (etwa von String-Keys zu Enum-Klassen) passiert das hier zentral.
 *
 * Alle Methoden geben HTML-safe Strings zurück (rex_i18n::msg escaped intern).
 */
final class Labels
{
    public static function status(Status $status): string
    {
        return rex_i18n::msg('sprog_status_' . $status->value);
    }

    public static function sourceType(SourceType $type): string
    {
        return rex_i18n::msg('sprog_source_' . $type->value);
    }

    /**
     * Beschriftung für einen Namespace-String. Wenn der Wert einem bekannten
     * SourceType entspricht, wird dessen Label genutzt; sonst wird der String
     * selbst zurückgegeben (HTML-escaped).
     */
    public static function forNamespace(string $namespace): string
    {
        $type = SourceType::tryFrom($namespace);
        if (null !== $type) {
            return self::sourceType($type);
        }

        return rex_escape($namespace);
    }
}
