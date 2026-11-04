<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use rex_i18n;
use rex_user;
use Sprog\Exception\UnitValidationException;
use Sprog\Http\JsonResponse;
use Sprog\Service\UnitService;
use Throwable;

/**
 * Endpoint POST ?func=create_unit — Modal-Create aus der Inbox.
 *
 * CSRF-Token ist hier inbox-global (sprog_inbox_create), nicht pro Unit, weil
 * bei der Anlage noch keine ID existiert. Permissions: jeder eingeloggte User
 * mit sprog-Zugriff darf eine Unit anlegen — dieselbe Schwelle wie auf
 * pages/create.php, die parallel weiter erreichbar bleibt.
 *
 * Validierung, missing-Rows und Log liegen im UnitService.
 */
final class CreateUnitController
{
    public function __construct(
        private readonly UnitService $units,
    ) {}

    public static function create(): self
    {
        return new self(UnitService::create());
    }

    public function handle(rex_user $user): never
    {
        JsonResponse::ensureCsrf('sprog_inbox_create', rex_i18n::rawMsg('sprog_inbox_save_csrf'));

        try {
            $unit = $this->units->createUnit(
                (string) rex_request('namespace', 'string', ''),
                (string) rex_request('unit_key', 'string', ''),
                (string) rex_request('context', 'string', ''),
                (string) rex_request('notes', 'string', ''),
                $user->getId(),
            );
        } catch (UnitValidationException $e) {
            JsonResponse::badRequest($e->errors[0]);
        } catch (Throwable $e) {
            JsonResponse::internalError($e->getMessage());
        }

        JsonResponse::ok([
            'unit_id' => $unit->id,
        ]);
    }
}
