<?php

declare(strict_types=1);

namespace Sprog\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use Sprog\Filter\Format;

final class FormatTest extends TestCase
{
    private Format $filter;

    protected function setUp(): void
    {
        $this->filter = new Format();
    }

    public function testNameIsFormat(): void
    {
        self::assertSame('format', $this->filter->name());
    }

    public function testReadmeExampleHasSingleSpaces(): void
    {
        self::assertSame(
            '5 Affen sitzen auf einem Baum',
            $this->filter->fire('%s Affen sitzen auf einem %s', '5, Baum'),
        );
    }

    public function testHtmlArgumentWithInnerCommasStaysIntact(): void
    {
        // Fall aus Issue #101: onclick mit Kommas und Klammern im ersten Argument.
        $link = '<a href="/x" onclick="window.open(\'/x\',\'popup\',\'width=760,height=600\'); return false;">';

        self::assertSame(
            'Ich bin mit den ' . $link . 'Datenschutzbestimmungen</a> einverstanden.',
            $this->filter->fire('Ich bin mit den %sDatenschutzbestimmungen%s einverstanden.', $link . ', </a>'),
        );
    }

    public function testWithoutArgumentsValueIsReturnedUnchanged(): void
    {
        self::assertSame('%s Affen', $this->filter->fire('%s Affen', ''));
    }

    public function testEmptyArgumentKeepsItsPosition(): void
    {
        self::assertSame('[] [b]', $this->filter->fire('[%s] [%s]', ', b'));
    }

    public function testMissingArgumentsArePaddedInsteadOfThrowing(): void
    {
        // Bisher ValueError (PHP 8): "The arguments array must contain 2 items, 1 given".
        self::assertSame('a und ', $this->filter->fire('%s und %s', 'a'));
    }

    public function testSurplusArgumentsAreIgnored(): void
    {
        self::assertSame('a', $this->filter->fire('%s', 'a, b, c'));
    }

    public function testInvalidFormatSpecifierFallsBackToRawValue(): void
    {
        self::assertSame('50%-Rabatt auf %s', $this->filter->fire('50%-Rabatt auf %s', 'alles'));
    }
}
