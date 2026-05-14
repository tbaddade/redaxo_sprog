<?php

declare(strict_types=1);

namespace Sprog\Cache;

/**
 * Sammelt TranslationCacheInvalidator-Implementierungen und benachrichtigt
 * sie nach Schreib-Aktionen des TranslationService.
 *
 * Designentscheidung — der Bus ist explizit ein Shared-State-Hub (das ist
 * sein Job), wird aber NICHT als verstecktes Class-Singleton gebaut wie es
 * die alten Lookup-Services taten: die Tests können eine eigene Bus-Instanz
 * via `new` aufmachen und sie dem TranslationService per Constructor
 * injizieren. Für produktiven Code gibt es zusätzlich `default()` als
 * App-weiten Default-Bus, der vom Service-Layer-`create()` benutzt wird.
 */
final class CacheInvalidationBus
{
    private static ?self $defaultBus = null;

    /** @var list<TranslationCacheInvalidator> */
    private array $invalidators = [];

    /**
     * Frische Bus-Instanz — bewusst NICHT global. Für Tests und für Caller,
     * die einen eigenen Bus brauchen (z.B. Hintergrund-Job mit isoliertem
     * Invalidations-Verbund).
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * App-weiter Default-Bus. Wird vom Service-Layer beim `create()`
     * angezogen, damit produktive Aufrufe automatisch die Lookup-Services
     * invalidieren, die sich beim Bootstrap registriert haben.
     */
    public static function default(): self
    {
        return self::$defaultBus ??= new self();
    }

    /**
     * Hauptsächlich für Tests — verwirft den Default-Bus, sodass der nächste
     * `default()`-Aufruf eine frische Instanz liefert.
     */
    public static function resetDefault(): void
    {
        self::$defaultBus = null;
    }

    public function register(TranslationCacheInvalidator $invalidator): void
    {
        // Idempotent: dieselbe Instanz darf nicht doppelt registriert werden.
        // Strict-Comparison reicht — wir vergleichen Objekt-Identitäten.
        foreach ($this->invalidators as $existing) {
            if ($existing === $invalidator) {
                return;
            }
        }
        $this->invalidators[] = $invalidator;
    }

    /**
     * Notification: eine konkrete clang hat einen Translation-Write erhalten.
     */
    public function clangChanged(int $clangId): void
    {
        foreach ($this->invalidators as $invalidator) {
            $invalidator->invalidateClang($clangId);
        }
    }

    /**
     * Notification: alle Caches müssen entwertet werden. Wird von Bulk-
     * Operationen (Migration, Mass-Import) gefeuert.
     */
    public function allChanged(): void
    {
        foreach ($this->invalidators as $invalidator) {
            $invalidator->invalidateAll();
        }
    }
}
