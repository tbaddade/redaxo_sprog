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
use Sprog\Service\WorkflowService;
use Sprog\Support\Labels;
use Sprog\View\InboxRowActions;
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
        // Rollen-Autorisierung über den WorkflowService — dieselbe Logik wie
        // die UI-Buttons (Übersetzer reicht ein, Reviewer gibt frei/zurück,
        // Selbst-Freigabe je nach Config). Deckt die clang-Permission mit ab.
        $workflow = WorkflowService::create();
        $targetStatus = Status::from($targetStatusIn);
        if (!$workflow->mayTransitionTo(
            $translation->status,
            $targetStatus,
            $user,
            $translation->clangId,
            $user->getId(),
            $translation->translatorId,
        )) {
            JsonResponse::forbidden(rex_i18n::rawMsg('sprog_inbox_action_forbidden'));
        }

        try {
            $saved = $this->translations->transition(
                $translationId,
                $targetStatus,
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

        // Fertig gerenderte Aktionszeile für den neuen Status mitliefern — das
        // JS tauscht sie 1:1 ein (identisch zum Reload, keine Button-Logik im
        // Client). Die Zeile ist editierbar (der Übergang wurde autorisiert).
        $rowActionsHtml = InboxRowActions::render($workflow, $user, $saved->clangId, $user->getId(), $saved->status, $saved, true);

        JsonResponse::ok([
            'revision' => $saved->revision,
            'status' => $saved->status->value,
            'statusLabel' => Labels::status($saved->status),
            'rowActionsHtml' => $rowActionsHtml,
        ]);
    }
}
