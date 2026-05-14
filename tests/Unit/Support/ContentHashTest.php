<?php

declare(strict_types=1);

namespace Sprog\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Sprog\Support\ContentHash;

final class ContentHashTest extends TestCase
{
    public function testHashIsStableForSameInput(): void
    {
        self::assertSame(
            ContentHash::of('Hello'),
            ContentHash::of('Hello'),
        );
    }

    public function testHashIsDifferentForDifferentInput(): void
    {
        self::assertNotSame(
            ContentHash::of('Hello'),
            ContentHash::of('hello'),
        );
    }

    public function testHashIsHexSha256WidthSixtyFour(): void
    {
        // Schema-Annahme: sprog_translation.value_hash ist char(64). Falls jemand
        // den Algo auf sha512/256 wechselt, würde die DB-Spalte überlaufen.
        self::assertSame(64, strlen(ContentHash::of('beliebiger Inhalt')));
    }

    public function testHashIsSha256ByContract(): void
    {
        // Falls jemand den Algo wechselt, soll dieser Test feuern und einen
        // bewussten Schema-Migration-Schritt erzwingen.
        self::assertSame(
            hash('sha256', 'fixed'),
            ContentHash::of('fixed'),
        );
    }

    public function testEqualsTrueOnIdenticalHashes(): void
    {
        $hash = ContentHash::of('foo');
        self::assertTrue(ContentHash::equals($hash, $hash));
    }

    public function testEqualsFalseOnDifferentHashes(): void
    {
        self::assertFalse(
            ContentHash::equals(ContentHash::of('foo'), ContentHash::of('bar')),
        );
    }

    public function testEqualsFalseWhenEitherSideIsNull(): void
    {
        // Repository liefert null als source_hash_at_translation, bevor je
        // eine Übersetzung gespeichert wurde — der Vergleich muss false sein,
        // nicht true durch Type-Juggling.
        $hash = ContentHash::of('foo');
        self::assertFalse(ContentHash::equals(null, $hash));
        self::assertFalse(ContentHash::equals($hash, null));
        self::assertFalse(ContentHash::equals(null, null));
    }
}
