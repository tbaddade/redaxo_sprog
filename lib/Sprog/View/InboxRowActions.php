<?php

declare(strict_types=1);

namespace Sprog\View;

use rex_i18n;
use rex_user;
use Sprog\Enum\Status;
use Sprog\Model\Translation;
use Sprog\Service\WorkflowService;
use Sprog\Support\Labels;

use function rex_escape;

/**
 * Rendert die Aktionszeile einer Inbox-Übersetzungszeile: Status-Chip +
 * Workflow-Buttons (Einreichen / Zurückgeben / Freigeben).
 *
 * Single source of truth — genutzt vom initialen Seiten-Render
 * (pages/inbox.php) UND von den JSON-Endpoints (Save / Transition / Restore),
 * die dieses HTML nach einer Änderung zurückliefern. Das JS tauscht es 1:1 in
 * die Zeile. Dadurch ist die Live-Ansicht garantiert identisch zu einem Reload:
 * die rollen- und statusabhängige Button-Logik existiert nur hier, nicht
 * zusätzlich (und divergierend) im JS.
 *
 * Alle drei Buttons sind fester Zeilen-Bestand — nur ihr hidden-Zustand ergibt
 * sich aus availableActions(). So bleibt der DOM-Bestand stabil, auch wenn sich
 * translatorId (isSelf) beim Bearbeiten ändert und dadurch andere Aktionen
 * möglich werden.
 */
final class InboxRowActions
{
    /**
     * @return string HTML für den Inhalt von .sprog-inbox--row-actions
     */
    public static function render(
        WorkflowService $workflow,
        rex_user $user,
        int $clangId,
        int $userId,
        Status $status,
        ?Translation $translation,
        bool $canEdit,
    ): string {
        $html = '<span class="sprog-status sprog-status--' . rex_escape($status->value) . '" data-role="row-status">'
            . rex_escape(Labels::status($status)) . '</span>';

        // Buttons nur für editierbare, tatsächlich existierende Übersetzungen.
        if (!$canEdit || null === $translation) {
            return $html;
        }

        // Ziel-Status je Aktion (fest) und die im aktuellen Status erlaubten.
        $targets = [
            'submit' => Status::NeedsReview->value,
            'return' => Status::Revise->value,
            'approve' => Status::Approved->value,
        ];
        $enabled = [];
        foreach ($workflow->availableActions($status, $user, $clangId, $userId, $translation->translatorId) as $action) {
            $enabled[$action['action']] = true;
        }

        $buttons = '';
        foreach ($targets as $action => $targetStatus) {
            $buttons .= '<button type="button"'
                . ' class="sprog-btn sprog-btn--sm sprog-inbox--row-action sprog-inbox--row-action--' . rex_escape($action) . '"'
                . ' data-role="transition"'
                . ' data-translation-id="' . rex_escape((string) $translation->id) . '"'
                . ' data-action="' . rex_escape($action) . '"'
                . ' data-target-status="' . rex_escape($targetStatus) . '"'
                . (isset($enabled[$action]) ? '' : ' hidden') . '>'
                . rex_escape(rex_i18n::msg('sprog_inbox_action_' . $action))
                . '</button>';
        }

        return $html
            . '<div class="sprog-btn-group sprog-inbox--row-action-group" role="group" aria-label="'
            . rex_escape(rex_i18n::msg('sprog_inbox_row_actions_label')) . '">'
            . $buttons
            . '</div>';
    }
}
