<?php

declare(strict_types=1);

namespace Sprog\Service;

use rex;
use rex_config;
use rex_sql;
use rex_user;
use Sprog\Enum\Status;

/**
 * Entscheidet, WER welchen Workflow-Übergang auslösen darf — die eine Quelle
 * für Button-Sichtbarkeit (UI) UND serverseitige Autorisierung (Endpoints),
 * damit beides nie auseinanderläuft.
 *
 * Rollen (je Sprache, zusätzlich zur REDAXO-clang-Zuweisung):
 *   - `sprog[translator]` → darf übersetzen + einreichen (》Zur Prüfung《)
 *   - `sprog[reviewer]`   → darf freigeben / zurückgeben
 *   - Admin (Dev)         → beides; Selbst-Freigabe nur, wenn Config erlaubt
 *
 * Kernregeln:
 *   - Wer nur übersetzt, REICHT EIN (submit → needs_review).
 *   - Wer abnehmen darf UND selbst schreibt (oder ohne Übersetzer-Team ist),
 *     gibt DIREKT frei (kein Doppelklick über needs_review).
 *   - 》Zurückgeben《 gibt es nur, wenn es einen (anderen) Übersetzer für die
 *     Sprache gibt — sonst korrigiert der Reviewer selbst.
 *   - Eine EIGENE Übersetzung darf ein Redakteur freigeben; ein Admin/Dev nur,
 *     wenn `workflow_dev_self_approve` an ist. Fremde Übersetzungen darf jeder
 *     Reviewer freigeben.
 */
final class WorkflowService
{
    /** @var array<int, bool> clangId → gibt es einen (Nicht-Admin-)Übersetzer ≠ aktueller User */
    private array $translatorPoolCache = [];

    public static function create(): self
    {
        return new self();
    }

    public function canTranslate(rex_user $user, int $clangId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasPerm('sprog[translator]') && $user->getComplexPerm('clang')->hasPerm($clangId);
    }

    public function canReview(rex_user $user, int $clangId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasPerm('sprog[reviewer]') && $user->getComplexPerm('clang')->hasPerm($clangId);
    }

    /**
     * Darf der User den Text dieser Sprache überhaupt bearbeiten? Reines
     * clang-Recht ohne Übersetzer-/Reviewer-Rolle reicht nicht (nur lesen).
     */
    public function canEdit(rex_user $user, int $clangId): bool
    {
        return $this->canTranslate($user, $clangId) || $this->canReview($user, $clangId);
    }

    /**
     * Config: darf ein Admin/Dev seine EIGENE Übersetzung selbst freigeben?
     */
    public function devSelfApproveEnabled(): bool
    {
        return (bool) rex_config::get('sprog', 'workflow_dev_self_approve', false);
    }

    /**
     * Gibt es einen NICHT-Admin-Übersetzer (≠ $excludeUserId) mit clang-Recht
     * für diese Sprache? Steuert 》Zurückgeben《 (und die Direkt-Freigabe eines
     * reinen Reviewers). Admins zählen bewusst nicht als Pool — sonst gäbe es
     * beim Dev-allein-Fall immer einen.
     *
     * Ergebnis pro Request je clang gecacht ($excludeUserId ist der konstante
     * aktuelle User). Prüfung über rex_user-Objekte, damit rollen-vererbte
     * Rechte (rex_user_role) korrekt einbezogen werden.
     */
    public function hasOtherTranslator(int $clangId, int $excludeUserId): bool
    {
        if (isset($this->translatorPoolCache[$clangId])) {
            return $this->translatorPoolCache[$clangId];
        }

        $result = false;
        $sql = rex_sql::factory();
        $rows = $sql->getArray('SELECT id FROM ' . rex::getTable('user') . ' WHERE status = 1');
        foreach ($rows as $row) {
            $uid = (int) $row['id'];
            if ($uid === $excludeUserId) {
                continue;
            }
            $candidate = rex_user::get($uid);
            if (null === $candidate || $candidate->isAdmin()) {
                continue;
            }
            if ($candidate->hasPerm('sprog[translator]') && $candidate->getComplexPerm('clang')->hasPerm($clangId)) {
                $result = true;
                break;
            }
        }

        return $this->translatorPoolCache[$clangId] = $result;
    }

    /**
     * Status-unabhängige Rollen-Fakten für eine konkrete Zeile (Sprache +
     * Übersetzer). Basis für availableActions() — an einer Stelle berechnet,
     * damit UI-Buttons und Autorisierung (mayTransitionTo) nie divergieren.
     *
     * @return array{
     *     submit: bool, approve: bool, return: bool, directApprove: bool
     * }
     */
    private function predicates(rex_user $user, int $clangId, int $userId, ?int $translatorId): array
    {
        $canTranslate = $this->canTranslate($user, $clangId);
        $canReview = $this->canReview($user, $clangId);
        if (!$canTranslate && !$canReview) {
            return ['submit' => false, 'approve' => false, 'return' => false, 'directApprove' => false];
        }

        $pool = $this->hasOtherTranslator($clangId, $userId);
        $isSelf = null !== $translatorId && $translatorId === $userId;
        // Eigene Übersetzung freigeben: Redakteur immer, Admin/Dev nur per Config.
        $mayApproveOwn = !$user->isAdmin() || $this->devSelfApproveEnabled();

        $mayApprove = $canReview && (!$isSelf || $mayApproveOwn);
        $mayReturn = $canReview && $pool;
        // Direkt-Freigabe (ohne Einreich-Umweg): wer abnehmen darf UND
        //  - selbst übersetzen darf, ODER
        //  - ohne Übersetzer-Team für die Sprache ist, ODER
        //  - den Text zuletzt selbst bearbeitet hat ($isSelf) — dann verantwortet
        //    er den Inhalt und muss ihn nicht an sich selbst einreichen.
        // Ohne den $isSelf-Fall säße ein reiner Reviewer, der einen Entwurf
        // korrigiert, in einer Sackgasse (kann weder einreichen noch freigeben).
        $mayDirectApprove = $mayApprove && ($canTranslate || !$pool || $isSelf);

        return [
            // Nur-Übersetzer reicht ein; wer direkt freigeben kann, braucht kein submit.
            'submit' => $canTranslate && !$mayDirectApprove,
            'approve' => $mayApprove,
            'return' => $mayReturn,
            'directApprove' => $mayDirectApprove,
        ];
    }

    /**
     * Verfügbare Workflow-Aktionen für (Status, Zeile). Rückgabe: Liste von
     * ['action' => string, 'target' => Status]. action ∈ {submit, approve, return}.
     *
     * @return list<array{action: string, target: Status}>
     */
    public function availableActions(
        Status $status,
        rex_user $user,
        int $clangId,
        int $userId,
        ?int $translatorId,
    ): array {
        $p = $this->predicates($user, $clangId, $userId, $translatorId);

        $actions = [];
        switch ($status) {
            case Status::Draft:
            case Status::Revise:
            case Status::Stale:
                if ($p['submit']) {
                    $actions[] = ['action' => 'submit', 'target' => Status::NeedsReview];
                }
                if ($p['directApprove']) {
                    $actions[] = ['action' => 'approve', 'target' => Status::Approved];
                }
                break;

            case Status::NeedsReview:
                if ($p['approve']) {
                    $actions[] = ['action' => 'approve', 'target' => Status::Approved];
                }
                if ($p['return']) {
                    $actions[] = ['action' => 'return', 'target' => Status::Revise];
                }
                break;

            case Status::Approved:
                if ($p['return']) {
                    $actions[] = ['action' => 'return', 'target' => Status::Revise];
                }
                break;

            case Status::Missing:
                // Keine Aktion — erst Text eintippen (→ Entwurf).
                break;
        }

        return $actions;
    }

    /**
     * Serverseitige Autorisierung: ist der Übergang auf $target für diesen
     * User in diesem Status erlaubt? Nutzt exakt dieselbe Logik wie die UI.
     */
    public function mayTransitionTo(
        Status $status,
        Status $target,
        rex_user $user,
        int $clangId,
        int $userId,
        ?int $translatorId,
    ): bool {
        foreach ($this->availableActions($status, $user, $clangId, $userId, $translatorId) as $action) {
            if ($action['target'] === $target) {
                return true;
            }
        }

        return false;
    }
}
