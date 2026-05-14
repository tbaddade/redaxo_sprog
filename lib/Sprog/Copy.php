<?php

namespace Sprog\Copy;

class Copy
{
    /**
     * Split an array into chunks.
     *
     * @template TValue
     *
     * @param array<int|string, TValue> $items
     *
     * @return list<list<TValue>>
     */
    public static function chunk(array $items, int $chunkSize = 3): array
    {
        return array_chunk($items, max(1, $chunkSize));
    }

    /**
     * Clear output (show blank page).
     */
    public static function clearOutput(): void
    {
        \rex_extension::register('OUTPUT_FILTER', static function (\rex_extension_point $ep): void {
            $ep->setSubject(false);
        });
    }

    /**
     * Resolve items in query string
     * query string pattern: v1.v2,v1.v2,….
     *
     * @return list<list<string>>
     */
    public static function resolveItems(string $items): array
    {
        $itemsArray = explode(',', $items);
        $filteredItemsArray = [];

        if (count($itemsArray) > 0) {
            foreach ($itemsArray as $item) {
                $filteredItemsArray[] = explode('.', $item);
            }
        }

        return $filteredItemsArray;
    }
}
