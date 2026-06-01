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

    /**
     * Namespaces, die ein User per Inbox-Modal oder pages/create.php manuell
     * anlegen darf. Article/Slice/YForm/Media/Custom landen ausschließlich
     * über die Sync-/Drittaddon-Pfade in sprog_unit — diese SourceTypes
     * brauchen eine source_ref und sind im UI bewusst ausgeschlossen.
     *
     * Wird sowohl in pages/inbox.php (Modal-Dropdown-Optionen) als auch in
     * Sprog\Controller\Inbox\CreateUnitController (Server-Validierung) gelesen
     * — Single source of truth gegen UI/Controller-Drift.
     *
     * @return list<string>
     */
    public static function userCreatable(): array
    {
        return [
            self::Wildcard->value,
            self::Abbreviation->value,
            self::Foreignword->value,
        ];
    }
}
