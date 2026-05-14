<?php

declare(strict_types=1);

namespace Sprog\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Sprog\Cache\CacheInvalidationBus;
use Sprog\Cache\TranslationCacheInvalidator;

final class CacheInvalidationBusTest extends TestCase
{
    protected function setUp(): void
    {
        // Default-Bus ist Class-Singleton — wir müssen ihn zwischen Tests
        // zurücksetzen, sonst leakt Registrierung aus einem Test in den nächsten.
        CacheInvalidationBus::resetDefault();
    }

    public function testClangChangedFansOutToAllRegisteredInvalidators(): void
    {
        $bus    = CacheInvalidationBus::create();
        $first  = $this->recordingInvalidator();
        $second = $this->recordingInvalidator();
        $bus->register($first);
        $bus->register($second);

        $bus->clangChanged(7);

        self::assertSame([7], $first->clangCalls);
        self::assertSame([7], $second->clangCalls);
    }

    public function testAllChangedFansOutToAllRegisteredInvalidators(): void
    {
        $bus    = CacheInvalidationBus::create();
        $first  = $this->recordingInvalidator();
        $second = $this->recordingInvalidator();
        $bus->register($first);
        $bus->register($second);

        $bus->allChanged();

        self::assertSame(1, $first->allCalls);
        self::assertSame(1, $second->allCalls);
    }

    public function testRegisterIsIdempotentForSameInstance(): void
    {
        $bus         = CacheInvalidationBus::create();
        $invalidator = $this->recordingInvalidator();

        $bus->register($invalidator);
        $bus->register($invalidator);

        $bus->clangChanged(3);

        // Bei doppelter Registrierung dürfte clangCalls = [3, 3] sein —
        // wir wollen idempotent: ein Aufruf pro Instanz.
        self::assertSame([3], $invalidator->clangCalls);
    }

    public function testDifferentInstancesAreRegisteredSeparately(): void
    {
        $bus = CacheInvalidationBus::create();
        $a   = $this->recordingInvalidator();
        $b   = $this->recordingInvalidator();

        $bus->register($a);
        $bus->register($b);
        $bus->clangChanged(1);

        self::assertSame([1], $a->clangCalls);
        self::assertSame([1], $b->clangCalls);
    }

    public function testDefaultReturnsSameInstanceWithinProcess(): void
    {
        $first  = CacheInvalidationBus::default();
        $second = CacheInvalidationBus::default();

        self::assertSame($first, $second);
    }

    public function testResetDefaultProducesFreshInstance(): void
    {
        $first = CacheInvalidationBus::default();
        CacheInvalidationBus::resetDefault();
        $second = CacheInvalidationBus::default();

        self::assertNotSame($first, $second);
    }

    public function testCreateDoesNotAffectDefault(): void
    {
        $custom  = CacheInvalidationBus::create();
        $default = CacheInvalidationBus::default();

        self::assertNotSame($custom, $default);
    }

    public function testEmptyBusDoesNotThrow(): void
    {
        $bus = CacheInvalidationBus::create();

        // Sollte einfach durchgehen — kein Invalidator zu benachrichtigen.
        $bus->clangChanged(42);
        $bus->allChanged();

        self::assertTrue(true);
    }

    /**
     * Bauplan für einen Recording-Invalidator. Wir hätten ihn auch mit
     * createMock() bauen können; das hier liest sich klarer und gibt uns
     * ein konkretes "wann-wurde-was-aufgerufen"-Log.
     */
    private function recordingInvalidator(): TranslationCacheInvalidator
    {
        return new class () implements TranslationCacheInvalidator {
            /** @var list<int> */
            public array $clangCalls = [];
            public int $allCalls     = 0;

            public function invalidateClang(int $clangId): void
            {
                $this->clangCalls[] = $clangId;
            }

            public function invalidateAll(): void
            {
                ++$this->allCalls;
            }
        };
    }
}
