<?php

declare(strict_types=1);

namespace Sprog\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sprog\Enum\Status;

final class StatusTest extends TestCase
{
    public function testValuesContainsAllStatusCases(): void
    {
        $values = Status::values();

        self::assertContains('missing', $values);
        self::assertContains('draft', $values);
        self::assertContains('needs_review', $values);
        self::assertContains('revise', $values);
        self::assertContains('approved', $values);
        self::assertContains('stale', $values);

        // Genau diese sechs Status existieren — Sentinel gegen versehentlich
        // hinzugefügte oder entfernte Cases.
        self::assertCount(6, Status::cases());
        self::assertCount(count(Status::cases()), $values);
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
        yield 'missing-open'      => [Status::Missing, true];
        yield 'draft-open'        => [Status::Draft, true];
        yield 'needs_review-open' => [Status::NeedsReview, true];
        yield 'revise-open'       => [Status::Revise, true];
        yield 'approved-closed'   => [Status::Approved, false];
        yield 'stale-open'        => [Status::Stale, true];
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
     * Whitelist gem. PHPDoc des Enums (`allowedNextStates()`). Jeder „true"-
     * Eintrag muss durchgehen, jeder „false"-Eintrag abgelehnt werden —
     * strikte Schwarz/Weiß-Tabelle, damit eine Änderung an
     * `allowedNextStates()` immer eine Test-Korrektur triggert.
     *
     * @return iterable<string, array{Status, Status, bool}>
     */
    public static function transitionMatrix(): iterable
    {
        // missing => [draft]
        yield 'missing -> draft'         => [Status::Missing, Status::Draft, true];
        yield 'missing -> approved'      => [Status::Missing, Status::Approved, false];
        yield 'missing -> needs_review'  => [Status::Missing, Status::NeedsReview, false];
        // draft => [needs_review, approved]
        yield 'draft -> needs_review'    => [Status::Draft, Status::NeedsReview, true];
        yield 'draft -> approved'        => [Status::Draft, Status::Approved, true];
        yield 'draft -> revise'          => [Status::Draft, Status::Revise, false];
        yield 'draft -> missing'         => [Status::Draft, Status::Missing, false];
        // needs_review => [approved, revise]
        yield 'needs_review -> approved' => [Status::NeedsReview, Status::Approved, true];
        yield 'needs_review -> revise'   => [Status::NeedsReview, Status::Revise, true];
        yield 'needs_review -> draft'    => [Status::NeedsReview, Status::Draft, false];
        // revise => [draft, approved]
        yield 'revise -> draft'          => [Status::Revise, Status::Draft, true];
        yield 'revise -> approved'       => [Status::Revise, Status::Approved, true];
        yield 'revise -> needs_review'   => [Status::Revise, Status::NeedsReview, false];
        // approved => [revise, stale, draft]
        yield 'approved -> revise'       => [Status::Approved, Status::Revise, true];
        yield 'approved -> stale'        => [Status::Approved, Status::Stale, true];
        yield 'approved -> draft'        => [Status::Approved, Status::Draft, true];
        yield 'approved -> needs_review' => [Status::Approved, Status::NeedsReview, false];
        // stale => [draft, approved]
        yield 'stale -> draft'           => [Status::Stale, Status::Draft, true];
        yield 'stale -> approved'        => [Status::Stale, Status::Approved, true];
        yield 'stale -> needs_review'    => [Status::Stale, Status::NeedsReview, false];
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
}
