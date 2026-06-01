<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use rex_i18n;
use rex_user;
use Sprog\Enum\SourceType;
use Sprog\Http\JsonResponse;
use Sprog\Model\Unit;
use Sprog\Repository\UnitRepository;
use Sprog\Service\ActivityService;
use Sprog\Service\TranslationService;
use Throwable;

use function in_array;
use function strlen;

/**
 * Endpoint POST ?func=create_unit — Modal-Create aus der Inbox.
 *
 * CSRF-Token ist hier inbox-global (sprog_inbox_create), nicht pro Unit, weil
 * bei der Anlage noch keine ID existiert. Permissions: jeder eingeloggte User
 * mit sprog-Zugriff darf eine Unit anlegen — dieselbe Schwelle wie auf
 * pages/create.php, die parallel weiter erreichbar bleibt.
 */
final class CreateUnitController
{
    private const MAX_KEY_LEN = 191;
    private const MAX_CONTEXT_LEN = 64;
    private const MAX_NOTES_LEN = 500;

    public function __construct(
        private readonly UnitRepository $units,
        private readonly TranslationService $translations,
        private readonly ActivityService $activity,
    ) {}

    public static function create(): self
    {
        return new self(new UnitRepository(), TranslationService::create(), ActivityService::create());
    }

    public function handle(rex_user $user): never
    {
        JsonResponse::ensureCsrf('sprog_inbox_create', rex_i18n::rawMsg('sprog_inbox_save_csrf'));

        $namespaceInput = trim((string) rex_request('namespace', 'string', ''));
        $unitKeyInput = trim((string) rex_request('unit_key', 'string', ''));
        $contextInput = trim((string) rex_request('context', 'string', ''));
        $notesInput = trim((string) rex_request('notes', 'string', ''));
        $notesValue = '' === $notesInput ? null : $notesInput;

        // Whitelist gegen SourceType::userCreatable() — UI-Modal bietet bewusst
        // nur wildcard/abbreviation/foreignword an; Article/Slice/YForm/Media/
        // Custom werden über den Sync angelegt und brauchen eine source_ref,
        // die das Modal nicht liefern kann. Wer per JS-Konsole oder curl einen
        // anderen namespace schickt, würde sonst eine orphan Article-Unit
        // ohne source_ref hinterlassen.
        if (!in_array($namespaceInput, SourceType::userCreatable(), true)) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_create_namespace_invalid'));
        }
        if ('' === $unitKeyInput) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_create_key_empty'));
        }
        if (strlen($unitKeyInput) > self::MAX_KEY_LEN) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_create_key_too_long'));
        }
        if (strlen($contextInput) > self::MAX_CONTEXT_LEN) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_unit_context_too_long'));
        }
        if (null !== $notesValue && strlen($notesValue) > self::MAX_NOTES_LEN) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_create_notes_too_long'));
        }

        if (null !== $this->units->findByKey($namespaceInput, $unitKeyInput, $contextInput)) {
            JsonResponse::badRequest(rex_i18n::msg('sprog_create_duplicate', $unitKeyInput, $namespaceInput));
        }

        try {
            $sourceType = SourceType::tryFrom($namespaceInput);
            $unit = $this->units->save(new Unit(
                id: null,
                namespace: $namespaceInput,
                unitKey: $unitKeyInput,
                context: $contextInput,
                sourceType: $sourceType,
                sourceRef: null,
                sourceHash: null,
                tags: [],
                notes: $notesValue,
            ));

            // Pro definierter clang eine missing-Row anlegen — spiegelt das
            // Verhalten von pages/create.php (siehe TranslationService::ensureRowsForUnit).
            $this->translations->ensureRowsForUnit($unit);

            $this->activity->logUnitCreated((int) $unit->id, $user->getId(), $unit->namespace, $unit->unitKey);
        } catch (Throwable $e) {
            JsonResponse::internalError($e->getMessage());
        }

        JsonResponse::ok([
            'unit_id' => $unit->id,
        ]);
    }
}
