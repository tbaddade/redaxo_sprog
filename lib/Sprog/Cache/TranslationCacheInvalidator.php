<?php

declare(strict_types=1);

namespace Sprog\Cache;

/**
 * Vertrag für Caches, die auf Translation-Writes hin invalidieren müssen.
 *
 * Aktuelle Implementierungen:
 *   - Sprog\Service\WildcardLookupService      (request-scoped In-Memory-Cache)
 *   - Sprog\Service\AbbreviationLookupService  (request-scoped In-Memory-Cache)
 *   - Sprog\Service\ForeignwordLookupService   (request-scoped In-Memory-Cache)
 *
 * Eine spätere Persistent-Cache-Schicht (`rex_cache`) hängt sich als weitere
 * Implementierung an denselben Bus und bekommt dieselben Notifications —
 * darum existiert das Interface jetzt, bevor der persistente Cache überhaupt
 * gezogen wird.
 */
interface TranslationCacheInvalidator
{
    /**
     * Eine konkrete Sprache hat einen Write erhalten. Implementierungen, die
     * ihren Cache nach clang_id schlüsseln, sollen nur diesen Bucket
     * invalidieren statt allen Caches.
     */
    public function invalidateClang(int $clangId): void;

    /**
     * Vollständige Cache-Invalidation. Wird bei Bulk-Operationen ohne klare
     * clang-Affinität gefeuert (z.B. Migration, Schema-Reset).
     */
    public function invalidateAll(): void;
}
