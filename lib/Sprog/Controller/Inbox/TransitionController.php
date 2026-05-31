<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use InvalidArgumentException;
use rex_i18n;
use rex_user;
use Sprog\Enum\Status;
use Sprog\Exception\OptimisticLockException;
use Sprog\Http\JsonResponse;
use Sprog\Repository\TranslationRepository;
use Sprog\Service\TranslationService;
use Sprog\Support\Labels;
use Throwable;

use function in_array;

/**
 * Endpoint POST ?func=transition — Status-Übergang einer Übersetzung
 * (Buttons im Akkordeon).
 *
 * Die Whitelist-Validierung lebt im TranslationService::assertCanTransition;
 * dieser Controller prüft nur das, was der Service nicht weiß: CSRF,
 * clang-Permission und Translation-Belongs-To-Unit.
 */
final class TransitionController
{
    public function __construct(
        private readonly TranslationRepository $translationsRepo,
        private readonly TranslationService $translations,
    ) {}

    public static function create(): self
    {
        return new self(new TranslationRepository(), TranslationService::create());
    }

    public function handle(rex_user $user): never
    {
        $unitId = (int) rex_request('unit_id', 'int', 0);
        $translationId = (int) rex_request('translation_id', 'int', 0);
        $targetStatusIn = (string) rex_request('target_status', 'string', '');
        $expectedRev = (int) rex_request('revision', 'int', 0);

        JsonResponse::ensureCsrf('sprog_inbox_save', rex_i18n::rawMsg('sprog_inbox_save_csrf'));

        if ($translationId <= 0 || !in_array($targetStatusIn, Status::values(), true)) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_save_bad_request'));
        }

        $translation = $this->translationsRepo->find($translationId);
        if (null === $translation || $translation->unitId !== $unitId) {
            JsonResponse::notFound(rex_i18n::rawMsg('sprog_inbox_save_unit_missing'));
        }
        if (!$user->getComplexPerm('clang')->hasPerm($translation->clangId)) {
            JsonResponse::forbidden(rex_i18n::rawMsg('sprog_inbox_save_no_perm'));
        }

        try {
            $saved = $this->translations->transition(
                $translationId,
                Status::from($targetStatusIn),
                $user->getId(),
                expectedRevision: $expectedRev,
            );
        } catch (OptimisticLockException) {
            JsonResponse::conflict(rex_i18n::rawMsg('sprog_inbox_save_conflict'));
        } catch (InvalidArgumentException $e) {
            JsonResponse::badRequest($e->getMessage());
        } catch (Throwable $e) {
            JsonResponse::internalError($e->getMessage());
        }

        // Erlaubte Folge-Übergänge für den neuen Status mitliefern, damit das
        // JS die Buttons im Akkordeon disabled/enabled umschalten kann statt
        // sie zu entfernen.
        $nextAvailable = array_map(
            static fn (Status $s) => $s->value,
            $saved->status->userActions(),
        );

        JsonResponse::ok([
            'revision' => $saved->revision,
            'status' => $saved->status->value,
            'statusLabel' => Labels::status($saved->status),
            'availableTransitions' => $nextAvailable,
        ]);
    }
}
