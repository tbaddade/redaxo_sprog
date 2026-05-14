<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use rex_i18n;
use rex_request;
use rex_user;
use Sprog\Http\JsonResponse;
use Sprog\Model\Unit;
use Sprog\Repository\UnitRepository;
use Throwable;

/**
 * Endpoint POST ?func=update_unit — Inline-Edit des Unit-Key aus dem
 * Akkordeon (Stift-Button) und aus dem Modal im Edit-Mode.
 *
 * Eigene Permission `sprog[unit_edit]`; Admin geht immer durch.
 * Nur unit_key / context / notes werden aktualisiert; namespace, source_type,
 * source_ref, tags bleiben unverändert. Längen- und UNIQUE-Check inline,
 * identisch zum Pendant in editor.php (action=update_unit) — Drift hier hätte
 * je nach Edit-Surface unterschiedliche Validierungen zur Folge.
 */
final class UpdateUnitController
{
    private const MAX_KEY_LEN     = 191;
    private const MAX_CONTEXT_LEN = 64;
    private const MAX_NOTES_LEN   = 500;

    public function __construct(
        private readonly UnitRepository $units,
    ) {}

    public static function create(): self
    {
        return new self(new UnitRepository());
    }

    public function handle(rex_user $user): never
    {
        $unitId = (int) rex_request('unit_id', 'int', 0);

        JsonResponse::ensureCsrf('sprog_inbox_save_' . $unitId, rex_i18n::rawMsg('sprog_inbox_save_csrf'));

        if (!($user->isAdmin() || $user->hasPerm('sprog[unit_edit]'))) {
            JsonResponse::forbidden(rex_i18n::rawMsg('sprog_editor_unit_edit_no_perm'));
        }

        $unit = $this->units->find($unitId);
        if (null === $unit) {
            JsonResponse::notFound(rex_i18n::rawMsg('sprog_inbox_save_unit_missing'));
        }

        $newKey     = trim((string) rex_request('unit_key', 'string', ''));
        $newContext = trim((string) rex_request('context', 'string', ''));
        $newNotesIn = trim((string) rex_request('notes', 'string', ''));
        $newNotes   = '' === $newNotesIn ? null : $newNotesIn;

        if ('' === $newKey) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_create_key_empty'));
        }
        if (strlen($newKey) > self::MAX_KEY_LEN) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_create_key_too_long'));
        }
        if (strlen($newContext) > self::MAX_CONTEXT_LEN) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_unit_context_too_long'));
        }
        if (null !== $newNotes && strlen($newNotes) > self::MAX_NOTES_LEN) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_create_notes_too_long'));
        }

        // UNIQUE-Vorprüfung nur, wenn sich Key oder Context tatsächlich ändert
        // — sonst würde der eigene Eintrag als „Duplikat" gewertet.
        if ($newKey !== $unit->unitKey || $newContext !== $unit->context) {
            $existing = $this->units->findByKey($unit->namespace, $newKey, $newContext);
            if (null !== $existing && $existing->id !== $unit->id) {
                JsonResponse::badRequest(rex_i18n::msg('sprog_editor_unit_edit_duplicate', $newKey, $unit->namespace));
            }
        }

        try {
            $this->units->save(new Unit(
                id:         $unit->id,
                namespace:  $unit->namespace,
                unitKey:    $newKey,
                context:    $newContext,
                sourceType: $unit->sourceType,
                sourceRef:  $unit->sourceRef,
                sourceHash: $unit->sourceHash,
                tags:       $unit->tags,
                notes:      $newNotes,
            ));
        } catch (Throwable $e) {
            JsonResponse::internalError($e->getMessage());
        }

        JsonResponse::ok([
            'unit_key' => $newKey,
            'context'  => $newContext,
            'notes'    => $newNotes,
        ]);
    }
}
