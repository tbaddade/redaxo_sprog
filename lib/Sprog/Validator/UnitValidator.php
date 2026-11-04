<?php

declare(strict_types=1);

namespace Sprog\Validator;

use rex_i18n;
use Sprog\Enum\SourceType;
use Sprog\Model\Unit;
use Sprog\Repository\UnitRepository;

use function in_array;
use function strlen;

/**
 * Gemeinsame Validierung für das Anlegen und Bearbeiten von Units. Bündelt
 * Namespace-Whitelist, Längen-Checks und UNIQUE-Vorprüfung an einer Stelle,
 * damit die Eingabewege (Inbox-Modal, pages/create.php, CSV-Import, Konsole)
 * nicht auseinanderlaufen.
 *
 * Die Fehlertexte sind unescaped (rawMsg): JSON-Endpoints setzen sie per
 * textContent, HTML-Ausgaben escapen selbst.
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
     * Validiert eine neue Unit. Erwartet bereits normalisierte Werte (Key
     * getrimmt, Context via Unit::normalizeContext). Leeres Array = gültig.
     *
     * Erlaubt sind nur die per Hand anlegbaren Namespaces
     * (SourceType::userCreatable()) — andere bräuchten eine source_ref, die
     * keiner dieser Eingabewege liefern kann.
     *
     * @return list<string>
     */
    public function validateCreate(string $namespace, string $unitKey, string $context, ?string $notes): array
    {
        $errors = [];

        if (!in_array($namespace, SourceType::userCreatable(), true)) {
            $errors[] = rex_i18n::rawMsg('sprog_create_namespace_invalid');
        }

        $errors = [...$errors, ...self::checkFields($unitKey, $context, $notes)];

        if ([] === $errors && null !== $this->units->findByKey($namespace, $unitKey, $context)) {
            $errors[] = rex_i18n::rawMsg('sprog_create_duplicate', $unitKey, $namespace);
        }

        return $errors;
    }

    /**
     * Validiert ein Update gegen Längen-Limits und den UNIQUE-Index
     * (namespace, context, unit_key). Leeres Array = alle Checks bestanden.
     *
     * Wer eine Spalte nicht editierbar macht, gibt den unveränderten Wert aus
     * dem Unit-Model herein.
     *
     * @return list<string>
     */
    public function validateUpdate(Unit $current, string $newKey, string $newContext, ?string $newNotes): array
    {
        $errors = self::checkFields($newKey, $newContext, $newNotes);

        // UNIQUE-Vorprüfung nur bei tatsächlichem Wechsel von Key oder Context
        // — sonst würde unser eigener Eintrag als „Duplikat" gewertet. Der
        // dritte Parameter (context) ist Pflicht: der DB-UNIQUE-Index lautet
        // (namespace, context, unit_key), nicht (namespace, unit_key).
        if ([] === $errors && ($newKey !== $current->unitKey || $newContext !== $current->context)) {
            $existing = $this->units->findByKey($current->namespace, $newKey, $newContext);
            if (null !== $existing && $existing->id !== $current->id) {
                $errors[] = rex_i18n::rawMsg('sprog_inbox_unit_edit_duplicate', $newKey, $current->namespace);
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function checkFields(string $unitKey, string $context, ?string $notes): array
    {
        $errors = [];

        if ('' === $unitKey) {
            $errors[] = rex_i18n::rawMsg('sprog_create_key_empty');
        } elseif (strlen($unitKey) > self::MAX_KEY_LEN) {
            $errors[] = rex_i18n::rawMsg('sprog_create_key_too_long');
        }

        if (strlen($context) > self::MAX_CONTEXT_LEN) {
            $errors[] = rex_i18n::rawMsg('sprog_inbox_unit_context_too_long');
        }

        if (null !== $notes && strlen($notes) > self::MAX_NOTES_LEN) {
            $errors[] = rex_i18n::rawMsg('sprog_create_notes_too_long');
        }

        return $errors;
    }
}
