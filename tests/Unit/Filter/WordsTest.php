<?php

declare(strict_types=1);

namespace Sprog\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use Sprog\Filter\Words;

final class WordsTest extends TestCase
{
    public function testReadmeExample(): void
    {
        self::assertSame('5 Affen sitzen auf', (new Words())->fire('5 Affen sitzen auf einem Baum', '4'));
    }

    public function testSuffixIsTrimmed(): void
    {
        self::assertSame('5 Affen sitzen auf…', (new Words())->fire('5 Affen sitzen auf einem Baum', '4, …'));
    }

    public function testShortValueIsReturnedUnchanged(): void
    {
        self::assertSame('Ein Baum', (new Words())->fire('Ein Baum', '4, …'));
    }
}
