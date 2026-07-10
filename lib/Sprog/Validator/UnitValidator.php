<?php

declare(strict_types=1);

namespace Sprog\Validator;

use rex_i18n;
use Sprog\Model\Unit;
use Sprog\Repository\UnitRepository;

use function strlen;

/**
 * Gemeinsame Validierung für Unit-Updates aus Inbox-Modal (UpdateUnitController)
 * und Editor-Page (pages/editor.php action=update_unit). Bündelt Längen-Checks
 * und UNIQUE-Vorprüfung an einer Stelle — sonst entsteht Drift zwischen den
 * Edit-Surfaces (siehe Review-MAJOR editor.php: findByKey ohne context, hier
 * jetzt zentral korrekt).
 *
 * Aufrufer reichen das aktuelle Unit-Model + die neuen Werte herein. Wer
 * eine Spalte nicht editierbar macht (z.B. editor.php berührt context nicht),
 * gibt einfach den unveränderten Wert aus dem Unit-Model zurück.
 */
final class UnitValidator
{
    public const MAX_KEY_LEN = 191;
    public const MAX_CONTEXT_LEN = 64;
    public const MAX_NOTES_LEN = 500;

    public function __construct(
        private readonly UnitRepository $units,
    ) {}

    public static function create(): self
    {
        return new self(new UnitRepository());
    }

    /**
     * Validiert ein Update gegen Längen-Limits und den UNIQUE-Index
     * (namespace, context, unit_key). Liefert eine list mit aufgelösten
     * i18n-Fehlertexten zurück — leeres Array = alle Checks bestanden.
     *
     * @return list<string>
     */
    public function validateUpdate(Unit $current, string $newKey, string $newContext, ?string $newNotes): array
    {
        $errors = [];

        if ('' === $newKey) {
            $errors[] = rex_i18n::msg('sprog_create_key_empty');
        } elseif (strlen($newKey) > self::MAX_KEY_LEN) {
            $errors[] = rex_i18n::msg('sprog_create_key_too_long');
        }

        if (strlen($newContext) > self::MAX_CONTEXT_LEN) {
            $errors[] = rex_i18n::msg('sprog_inbox_unit_context_too_long');
        }

        if (null !== $newNotes && strlen($newNotes) > self::MAX_NOTES_LEN) {
            $errors[] = rex_i18n::msg('sprog_create_notes_too_long');
        }

        // UNIQUE-Vorprüfung nur bei tatsächlichem Wechsel von Key oder Context
        // — sonst würde unser eigener Eintrag als „Duplikat" gewertet. Der
        // dritte Parameter (context) ist Pflicht: der DB-UNIQUE-Index lautet
        // (namespace, context, unit_key), nicht (namespace, unit_key).
        if ([] === $errors && ($newKey !== $current->unitKey || $newContext !== $current->context)) {
            $existing = $this->units->findByKey($current->namespace, $newKey, $newContext);
            if (null !== $existing && $existing->id !== $current->id) {
                $errors[] = rex_i18n::msg('sprog_inbox_unit_edit_duplicate', $newKey, $current->namespace);
            }
        }

        return $errors;
    }
}
