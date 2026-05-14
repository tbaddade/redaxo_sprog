<?php

declare(strict_types=1);

namespace Sprog\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Sprog\Enum\SourceType;

final class SourceTypeTest extends TestCase
{
    public function testValuesContainsAllCases(): void
    {
        $values = SourceType::values();

        self::assertContains('wildcard', $values);
        self::assertContains('abbreviation', $values);
        self::assertContains('foreignword', $values);
        self::assertContains('article', $values);
        self::assertContains('slice', $values);
        self::assertContains('yform', $values);
        self::assertContains('media', $values);
        self::assertContains('custom', $values);

        // Sentinel — fängt versehentlich entfernte oder umbenannte Cases ab.
        self::assertCount(count(SourceType::cases()), $values);
    }

    public function testValuesOrderMatchesCasesDeclarationOrder(): void
    {
        // Manche Pages binden die Reihenfolge aus values() an Select-Boxen;
        // wir fixieren sie hier, damit ein casual-Refactor sie nicht stillschweigend dreht.
        self::assertSame(
            array_map(static fn (SourceType $c): string => $c->value, SourceType::cases()),
            SourceType::values(),
        );
    }

    public function testTryFromHandlesUnknownValueAsNull(): void
    {
        self::assertNull(SourceType::tryFrom('does-not-exist'));
    }
}
