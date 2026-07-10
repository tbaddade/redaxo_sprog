<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use rex_i18n;
use rex_user;
use Sprog\Http\JsonResponse;
use Sprog\Model\Unit;
use Sprog\Repository\UnitRepository;
use Sprog\Validator\UnitValidator;
use Throwable;

/**
 * Endpoint POST ?func=update_unit — Inline-Edit des Unit-Key aus dem
 * Akkordeon (Stift-Button) und aus dem Modal im Edit-Mode.
 *
 * Eigene Permission `sprog[unit_edit]`; Admin geht immer durch.
 * Nur unit_key / context / notes werden aktualisiert; namespace, source_type,
 * source_ref, tags bleiben unverändert. Längen- und UNIQUE-Check laufen
 * über Sprog\Validator\UnitValidator — dasselbe Validator-Objekt nutzt auch
 * pages/editor.php (action=update_unit), damit Drift zwischen den Edit-
 * Surfaces ausgeschlossen ist.
 */
final class UpdateUnitController
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly UnitValidator $validator,
    ) {}

    public static function create(): self
    {
        return new self(new UnitRepository(), UnitValidator::create());
    }

    public function handle(rex_user $user): never
    {
        $unitId = (int) rex_request('unit_id', 'int', 0);

        JsonResponse::ensureCsrf('sprog_inbox_save', rex_i18n::rawMsg('sprog_inbox_save_csrf'));

        if (!($user->isAdmin() || $user->hasPerm('sprog[unit_edit]'))) {
            JsonResponse::forbidden(rex_i18n::rawMsg('sprog_inbox_unit_edit_no_perm'));
        }

        $unit = $this->units->find($unitId);
        if (null === $unit) {
            JsonResponse::notFound(rex_i18n::rawMsg('sprog_inbox_save_unit_missing'));
        }

        $newKey = trim((string) rex_request('unit_key', 'string', ''));
        $newContext = Unit::normalizeContext((string) rex_request('context', 'string', ''));
        $newNotesIn = trim((string) rex_request('notes', 'string', ''));
        $newNotes = '' === $newNotesIn ? null : $newNotesIn;

        $errors = $this->validator->validateUpdate($unit, $newKey, $newContext, $newNotes);
        if ([] !== $errors) {
            // JSON-Endpoint: erster Fehler reicht — die Inbox-Modal-UX hat eh
            // nur ein Feedback-Feld. Editor-Page zeigt dagegen alle Fehler.
            JsonResponse::badRequest($errors[0]);
        }

        try {
            $this->units->save(new Unit(
                id: $unit->id,
                namespace: $unit->namespace,
                unitKey: $newKey,
                context: $newContext,
                sourceType: $unit->sourceType,
                sourceRef: $unit->sourceRef,
                sourceHash: $unit->sourceHash,
                tags: $unit->tags,
                notes: $newNotes,
            ));
        } catch (Throwable $e) {
            JsonResponse::internalError($e->getMessage());
        }

        JsonResponse::ok([
            'unit_key' => $newKey,
            'context' => $newContext,
            'notes' => $newNotes,
        ]);
    }
}
