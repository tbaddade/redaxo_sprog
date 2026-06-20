<?php

declare(strict_types=1);

namespace Sprog\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sprog\Enum\Status;

final class StatusTest extends TestCase
{
    public function testValuesContainsAllSevenStatusCases(): void
    {
        $values = Status::values();

        self::assertContains('missing', $values);
        self::assertContains('draft', $values);
        self::assertContains('translated', $values);
        self::assertContains('needs_review', $values);
        self::assertContains('revise', $values);
        self::assertContains('approved', $values);
        self::assertContains('stale', $values);
        self::assertCount(7, $values);
    }

    public function testApprovedIsFinal(): void
    {
        self::assertTrue(Status::Approved->isFinal());
    }

    /**
     * @return iterable<string, array{Status}>
     */
    public static function nonFinalStates(): iterable
    {
        yield 'missing'      => [Status::Missing];
        yield 'draft'        => [Status::Draft];
        yield 'translated'   => [Status::Translated];
        yield 'needs_review' => [Status::NeedsReview];
        yield 'revise'       => [Status::Revise];
        yield 'stale'        => [Status::Stale];
    }

    #[DataProvider('nonFinalStates')]
    public function testNonApprovedStatesAreNotFinal(Status $status): void
    {
        self::assertFalse($status->isFinal());
    }

    /**
     * @return iterable<string, array{Status, bool}>
     */
    public static function openExpectations(): iterable
    {
        yield 'missing-open'         => [Status::Missing, true];
        yield 'draft-open'           => [Status::Draft, true];
        yield 'translated-closed'    => [Status::Translated, false];
        yield 'needs_review-open'    => [Status::NeedsReview, true];
        yield 'revise-open'          => [Status::Revise, true];
        yield 'approved-closed'      => [Status::Approved, false];
        yield 'stale-open'           => [Status::Stale, true];
    }

    #[DataProvider('openExpectations')]
    public function testIsOpenForEachStatus(Status $status, bool $expected): void
    {
        self::assertSame($expected, $status->isOpen());
    }

    public function testIdempotentTransitionIsAlwaysAllowed(): void
    {
        foreach (Status::cases() as $status) {
            self::assertTrue(
                $status->canTransitionTo($status),
                "Idempotent transition should be allowed for {$status->value}",
            );
        }
    }

    /**
     * Whitelist gem. PHPDoc des Enums. Jeder „yes"-Eintrag muss durchgehen,
     * jeder „no"-Eintrag muss abgelehnt werden — strikte Schwarz/Weiß-
     * Tabelle, damit eine Änderung an `allowedNextStates()` immer eine
     * Test-Korrektur triggert.
     *
     * @return iterable<string, array{Status, Status, bool}>
     */
    public static function transitionMatrix(): iterable
    {
        // missing
        yield 'missing -> draft'       => [Status::Missing, Status::Draft, true];
        yield 'missing -> translated'  => [Status::Missing, Status::Translated, true];
        yield 'missing -> approved'    => [Status::Missing, Status::Approved, false];
        // draft
        yield 'draft -> translated'    => [Status::Draft, Status::Translated, true];
        yield 'draft -> needs_review'  => [Status::Draft, Status::NeedsReview, true];
        yield 'draft -> missing'       => [Status::Draft, Status::Missing, true];
        yield 'draft -> approved'      => [Status::Draft, Status::Approved, false];
        // translated
        yield 'translated -> approved' => [Status::Translated, Status::Approved, true];
        yield 'translated -> stale'    => [Status::Translated, Status::Stale, true];
        yield 'translated -> draft'    => [Status::Translated, Status::Draft, true];
        yield 'translated -> missing'  => [Status::Translated, Status::Missing, false];
        yield 'translated -> revise'   => [Status::Translated, Status::Revise, true];
        // needs_review
        yield 'needs_review -> approved' => [Status::NeedsReview, Status::Approved, true];
        yield 'needs_review -> revise'   => [Status::NeedsReview, Status::Revise, true];
        // revise
        yield 'revise -> draft'         => [Status::Revise, Status::Draft, true];
        yield 'revise -> translated'    => [Status::Revise, Status::Translated, true];
        yield 'revise -> approved'      => [Status::Revise, Status::Approved, false];
        // approved
        yield 'approved -> stale'       => [Status::Approved, Status::Stale, true];
        yield 'approved -> needs_review' => [Status::Approved, Status::NeedsReview, true];
        yield 'approved -> revise'      => [Status::Approved, Status::Revise, true];
        yield 'approved -> draft'       => [Status::Approved, Status::Draft, false];
        // stale
        yield 'stale -> translated'     => [Status::Stale, Status::Translated, true];
        yield 'stale -> approved'       => [Status::Stale, Status::Approved, false];
    }

    #[DataProvider('transitionMatrix')]
    public function testCanTransitionToFollowsWhitelist(Status $from, Status $to, bool $allowed): void
    {
        self::assertSame(
            $allowed,
            $from->canTransitionTo($to),
            "{$from->value} -> {$to->value} should be " . ($allowed ? 'allowed' : 'rejected'),
        );
    }

    public function testUserActionsAreSubsetOfAllowedNextStates(): void
    {
        foreach (Status::cases() as $status) {
            $allowed = $status->allowedNextStates();
            foreach ($status->userActions() as $action) {
                self::assertContains(
                    $action,
                    $allowed,
                    "userActions() bot {$action->value} an, obwohl der Service den Übergang "
                        . "{$status->value} -> {$action->value} ablehnen würde — UI würde 409 produzieren",
                );
            }
        }
    }

    public function testMissingHasNoUserActions(): void
    {
        // missing → … läuft per Auto-Status über updateValue, nicht über Button-Klicks
        self::assertSame([], Status::Missing->userActions());
    }

    public function testUserActionsExcludeMissingAndStaleAsTargets(): void
    {
        // Diese beiden Ziel-Status sind System-induziert; UI bietet sie nicht an.
        foreach (Status::cases() as $status) {
            self::assertNotContains(Status::Missing, $status->userActions());
            self::assertNotContains(Status::Stale, $status->userActions());
        }
    }
}
