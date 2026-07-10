<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use rex_user;

/**
 * Dispatcht den ?func=…-Query-Parameter aus pages/inbox.php auf den passenden
 * Controller. Bekannte Werte schlagen direkt nach `handle()` durch — der
 * Controller exitet via JsonResponse, der Page-Code nach dem Dispatch wird
 * nicht mehr ausgeführt. Unbekannte $func fallen durch zu null, die Page
 * rendert das HTML-Listing weiter.
 *
 * Die Page-Permission (sprog[]) und der eingeloggte User sind vor dem Aufruf
 * sichergestellt; alle Endpoint-spezifischen Checks (CSRF, clang-Perm, Body-
 * Validation) liegen im jeweiligen Controller.
 */
final class InboxRouter
{
    public static function dispatch(string $func, rex_user $user): void
    {
        match ($func) {
            'save' => SaveTranslationController::create()->handle($user),
            'update_unit' => UpdateUnitController::create()->handle($user),
            'create_unit' => CreateUnitController::create()->handle($user),
            'transition' => TransitionController::create()->handle($user),
            'mt' => MtController::create()->handle($user),
            'history' => HistoryController::create()->list($user),
            'restore' => HistoryController::create()->restore($user),
            'batch_prepare' => BatchTranslateController::create()->prepare($user),
            'batch_translate' => BatchTranslateController::create()->translate($user),
            default => null,
        };
    }
}
