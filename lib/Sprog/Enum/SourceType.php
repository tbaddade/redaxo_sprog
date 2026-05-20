<?php

declare(strict_types=1);

namespace Sprog\Enum;

/**
 * Quelltyp einer Übersetzungseinheit.
 *
 * Bestimmt, woher der ursprüngliche Inhalt kommt und welche
 * TranslationSource für Schreibvorgänge zurückgespiegelt wird.
 *
 * Custom ist der Auffang-Slot für Quellen, die ein Drittaddon
 * via TranslationSourceInterface registriert.
 */
enum SourceType: string
{
    case Wildcard = 'wildcard';
    case Abbreviation = 'abbreviation';
    case Foreignword = 'foreignword';
    case Article = 'article';
    case Slice = 'slice';
    case YForm = 'yform';
    case Media = 'media';
    case Custom = 'custom';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
