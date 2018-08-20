<?php

namespace Sprog\Copy;

class Copy
{
    /**
     * Split an array into chunks.
     *
     * @param array $items
     * @param int   $chunkSize
     *
     * @return array
     */
    public static function chunk(array $items, $chunkSize = 3)
    {
        return array_chunk($items, $chunkSize);
    }

    /**
     * Clear output (show blank page).
     */
    public static function clearOutput()
    {
        \rex_extension::register('OUTPUT_FILTER', function (\rex_extension_point $ep) {
            $ep->setSubject(false);
        });
    }

    /**
     * Resolve items in query string
     * query string pattern: v1.v2,v1.v2,….
     *
     * @param string $items
     *
     * @return array
     */
    public static function resolveItems($items)
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
