<?php

declare(strict_types=1);

namespace Sprog\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use Sprog\Filter\Limit;

final class LimitTest extends TestCase
{
    public function testReadmeExample(): void
    {
        self::assertSame('5 Aff...', (new Limit())->fire('5 Affen sitzen auf einem Baum', '5,...'));
    }

    public function testSuffixIsTrimmed(): void
    {
        self::assertSame('5 Aff...', (new Limit())->fire('5 Affen sitzen auf einem Baum', '5, ...'));
    }

    public function testShortValueIsReturnedUnchanged(): void
    {
        self::assertSame('Baum', (new Limit())->fire('Baum', '5, ...'));
    }
}
