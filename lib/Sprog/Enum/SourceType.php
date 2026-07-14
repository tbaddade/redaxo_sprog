<?php

declare(strict_types=1);

namespace Sprog\Enum;

/**
 * Quelltyp / Namespace einer Übersetzungseinheit — bestimmt, WIE der Wert
 * beim Output ersetzt wird. Persistiert als string in `sprog_unit.namespace`
 * (bzw. `source_type`); Whitelist und Single Source of Truth ist dieses Enum.
 */
enum SourceType: string
{
    case Wildcard = 'wildcard';
    case Abbreviation = 'abbreviation';
    case Foreignword = 'foreignword';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /**
     * Namespaces, die ein User per Inbox-Modal oder pages/create.php manuell
     * anlegen darf. Wird sowohl in pages/inbox.php (Modal-Dropdown-Optionen)
     * als auch in Sprog\Controller\Inbox\CreateUnitController (Server-
     * Validierung) gelesen — Single source of truth gegen UI/Controller-Drift.
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
