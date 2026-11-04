<?php

declare(strict_types=1);

namespace Sprog\Service;

use rex_sql;
use Sprog\Enum\SourceType;
use Sprog\Exception\UnitValidationException;
use Sprog\Model\Unit;
use Sprog\Repository\UnitRepository;
use Sprog\Validator\UnitValidator;

/**
 * Legt Units an — der einzige Weg dafür aus Inbox-Modal (CreateUnitController),
 * pages/create.php, CSV-Import und Konsole.
 *
 * Eine neue Unit bekommt sofort je Sprache eine missing-Translation (sonst
 * findet der Inbox-Filter „fehlt in Sprache X" sie nie) und einen
 * unit.created-Eintrag im Aktivitäts-Log.
 */
final class UnitService
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly UnitValidator $validator,
        private readonly TranslationService $translations,
        private readonly ActivityService $activity,
    ) {}

    public static function create(): self
    {
        $units = new UnitRepository();

        return new self($units, new UnitValidator($units), TranslationService::create(), ActivityService::create());
    }

    /**
     * Normalisiert und validiert die Eingaben, legt die Unit samt
     * missing-Translations an und protokolliert das — alles in einer
     * Transaktion.
     *
     * @throws UnitValidationException wenn die Eingaben ungültig sind
     */
    public function createUnit(string $namespace, string $unitKey, string $context = '', ?string $notes = null, ?int $userId = null): Unit
    {
        $namespace = trim($namespace);
        $unitKey = trim($unitKey);
        $context = Unit::normalizeContext($context);
        $notes = null === $notes ? null : trim($notes);
        if ('' === $notes) {
            $notes = null;
        }

        $errors = $this->validator->validateCreate($namespace, $unitKey, $context, $notes);
        if ([] !== $errors) {
            throw new UnitValidationException($errors);
        }

        return rex_sql::factory()->transactional(function () use ($namespace, $unitKey, $context, $notes, $userId): Unit {
            $unit = $this->units->save(new Unit(
                id: null,
                namespace: $namespace,
                unitKey: $unitKey,
                context: $context,
                sourceType: SourceType::tryFrom($namespace),
                sourceRef: null,
                sourceHash: null,
                tags: [],
                notes: $notes,
            ));

            $this->translations->ensureRowsForUnit($unit);
            $this->activity->logUnitCreated((int) $unit->id, $userId, $unit->namespace, $unit->unitKey);

            return $unit;
        });
    }
}
