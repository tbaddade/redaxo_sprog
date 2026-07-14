<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use rex_clang;
use rex_i18n;
use rex_user;
use Sprog\Exception\OptimisticLockException;
use Sprog\Http\JsonResponse;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Sprog\Service\MtService;
use Sprog\Service\TranslationService;
use Sprog\Service\WorkflowService;
use Sprog\Support\BaseLang;
use Sprog\Support\Labels;
use Sprog\View\InboxRowActions;
use Throwable;

use function in_array;

/**
 * Endpoint POST ?func=save — Auto-Save aus dem Inbox-Akkordeon.
 *
 * Inhaltlich 1:1 das v1-Inline-Pattern aus pages/inbox.php; alles, was bisher
 * inline mit cleanOutputBuffers/setStatus/sendJson/exit zusammengeklebt war,
 * läuft jetzt über JsonResponse mit einheitlichem Envelope { ok, error?, … }.
 *
 * CSRF-Token ist page-global (sprog_inbox_save) und wird von Save / UpdateUnit /
 * Transition gemeinsam genutzt. Operation-Scope reicht: CSRF schützt das
 * Session-Cookie-Risiko, eine Unit-spezifische Token-Bindung war Resource-
 * Scope und damit Overhead ohne Sicherheits-Gewinn.
 */
final class SaveTranslationController
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TranslationService $translations,
        private readonly TranslationRepository $translationRepo,
    ) {}

    public static function create(): self
    {
        return new self(new UnitRepository(), TranslationService::create(), new TranslationRepository());
    }

    public function handle(rex_user $user): never
    {
        $unitId = (int) rex_request('unit_id', 'int', 0);
        $clangId = (int) rex_request('clang_id', 'int', 0);

        JsonResponse::ensureCsrf('sprog_inbox_save', rex_i18n::rawMsg('sprog_inbox_save_csrf'));

        if ($unitId <= 0 || !rex_clang::exists($clangId)) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_save_bad_request'));
        }
        // Bearbeiten setzt eine Übersetzer- oder Reviewer-Rolle voraus (reines
        // clang-Recht ohne Rolle darf nur lesen).
        $workflow = WorkflowService::create();
        if (!$workflow->canEdit($user, $clangId)) {
            JsonResponse::forbidden(rex_i18n::rawMsg('sprog_inbox_save_no_perm'));
        }

        $unit = $this->units->find($unitId);
        if (null === $unit) {
            JsonResponse::notFound(rex_i18n::rawMsg('sprog_inbox_save_unit_missing'));
        }

        $value = (string) rex_request('value', 'string', '');
        $expectedRevision = (int) rex_request('revision', 'int', 0);

        // MT-Marker: nur durchreichen, wenn der Client einen echten (nicht-noop)
        // Provider aus der Whitelist mitschickt UND die Confidence (falls
        // vorhanden) im erlaubten Range [0.0, 1.0] liegt. Sonst beide NULL — der
        // TranslationService speichert die Übersetzung dann ohne MT-Marker.
        // Beim normalen Tippen (ohne MT) sind die hidden Felder leer → NULL.
        $mtProvider = null;
        $mtConfidence = null;
        $providerRaw = trim((string) rex_request('mt_provider', 'string', ''));
        if ('' !== $providerRaw && 'noop' !== $providerRaw
            && in_array($providerRaw, MtService::create()->configuredProviderNames(), true)
        ) {
            $mtProvider = $providerRaw;
            $confidenceRaw = (string) rex_request('mt_confidence', 'string', '');
            if ('' !== $confidenceRaw) {
                $confidence = (float) $confidenceRaw;
                if ($confidence >= 0.0 && $confidence <= 1.0) {
                    $mtConfidence = $confidence;
                }
            }
        }

        try {
            $saved = $this->translations->updateValue(
                $unit,
                $clangId,
                $value,
                $user->getId(),
                mtProvider: $mtProvider,
                mtConfidence: $mtConfidence,
                expectedRevision: $expectedRevision,
            );
        } catch (OptimisticLockException) {
            JsonResponse::conflict(rex_i18n::rawMsg('sprog_inbox_save_conflict'));
        } catch (Throwable $e) {
            JsonResponse::internalError($e->getMessage());
        }

        // Fertig gerenderte Aktionszeile (Chip + Buttons) mitliefern — das JS
        // tauscht sie 1:1 ein. So ist die Live-Ansicht identisch zu einem Reload
        // (dieselbe Render-Logik), ohne Button-Zustände im Client nachzubauen.
        $rowActionsHtml = InboxRowActions::render($workflow, $user, $clangId, $user->getId(), $saved->status, $saved, true);

        // Basissprache gespeichert → der Service hat abhängige Übersetzungen
        // dieser Unit ggf. veralten lassen (stale). Den aktuellen Stand aller
        // Nicht-Basis-Sprachen mitliefern, damit das JS Badges, Coverage und
        // offene Zeilen ohne Reload auf „veraltet" umschalten kann.
        $siblings = [];
        if ($clangId === BaseLang::clangId()) {
            foreach ($this->translationRepo->findByUnit($unitId) as $sibling) {
                if ($sibling->clangId === $clangId) {
                    continue;
                }
                $siblingCanEdit = $workflow->canEdit($user, $sibling->clangId);
                $siblings[] = [
                    'clangId' => $sibling->clangId,
                    'status' => $sibling->status->value,
                    'statusLabel' => Labels::status($sibling->status),
                    'revision' => $sibling->revision,
                    'rowActionsHtml' => InboxRowActions::render($workflow, $user, $sibling->clangId, $user->getId(), $sibling->status, $sibling, $siblingCanEdit),
                ];
            }
        }

        JsonResponse::ok([
            'revision' => $saved->revision,
            'status' => $saved->status->value,
            'statusLabel' => Labels::status($saved->status),
            'value_hash' => $saved->valueHash,
            'updatedAt' => null !== $saved->updatedAt ? $saved->updatedAt->format('c') : null,
            'rowActionsHtml' => $rowActionsHtml,
            'siblings' => $siblings,
        ]);
    }
}
