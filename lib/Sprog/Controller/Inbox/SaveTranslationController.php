<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use rex_clang;
use rex_i18n;
use rex_user;
use Sprog\Enum\Status;
use Sprog\Exception\OptimisticLockException;
use Sprog\Http\JsonResponse;
use Sprog\Repository\UnitRepository;
use Sprog\Service\TranslationService;
use Sprog\Support\Labels;
use Throwable;

/**
 * Endpoint POST ?func=save — Auto-Save aus dem Inbox-Akkordeon.
 *
 * Inhaltlich 1:1 das v1-Inline-Pattern aus pages/inbox.php; alles, was bisher
 * inline mit cleanOutputBuffers/setStatus/sendJson/exit zusammengeklebt war,
 * läuft jetzt über JsonResponse mit einheitlichem Envelope { ok, error?, … }.
 *
 * CSRF-Token ist pro Unit gebunden (sprog_inbox_save_<unit_id>), damit ein
 * gestohlenes Token nicht für andere Units missbraucht werden kann.
 */
final class SaveTranslationController
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TranslationService $translations,
    ) {}

    public static function create(): self
    {
        return new self(new UnitRepository(), TranslationService::create());
    }

    public function handle(rex_user $user): never
    {
        $unitId = (int) rex_request('unit_id', 'int', 0);
        $clangId = (int) rex_request('clang_id', 'int', 0);

        JsonResponse::ensureCsrf('sprog_inbox_save_' . $unitId, rex_i18n::rawMsg('sprog_inbox_save_csrf'));

        if ($unitId <= 0 || !rex_clang::exists($clangId)) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_save_bad_request'));
        }
        if (!$user->getComplexPerm('clang')->hasPerm($clangId)) {
            JsonResponse::forbidden(rex_i18n::rawMsg('sprog_inbox_save_no_perm'));
        }

        $unit = $this->units->find($unitId);
        if (null === $unit) {
            JsonResponse::notFound(rex_i18n::rawMsg('sprog_inbox_save_unit_missing'));
        }

        $value = (string) rex_request('value', 'string', '');
        $expectedRevision = (int) rex_request('revision', 'int', 0);

        try {
            $saved = $this->translations->updateValue(
                $unit,
                $clangId,
                $value,
                $user->getId(),
                expectedRevision: $expectedRevision,
            );
        } catch (OptimisticLockException) {
            JsonResponse::conflict(rex_i18n::rawMsg('sprog_inbox_save_conflict'));
        } catch (Throwable $e) {
            JsonResponse::internalError($e->getMessage());
        }

        // Erlaubte Folge-Übergänge mitliefern, damit das JS die Status-Buttons
        // nach einem Auto-Status-Sprung (missing → draft, stale → translated,
        // …) direkt aktualisieren kann ohne die Seite neu zu laden.
        $nextAvailable = array_map(
            static fn (Status $s) => $s->value,
            $saved->status->userActions(),
        );

        JsonResponse::ok([
            'revision' => $saved->revision,
            'status' => $saved->status->value,
            'statusLabel' => Labels::status($saved->status),
            'value_hash' => $saved->valueHash,
            'updatedAt' => null !== $saved->updatedAt ? $saved->updatedAt->format('c') : null,
            'availableTransitions' => $nextAvailable,
        ]);
    }
}
