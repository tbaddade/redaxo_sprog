<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog\Compat;

use Sprog\Service\StructureSyncService;

use function array_values;

/**
 * BC-Adapter für die v1-Sync-API. Die eigentliche Logik liegt jetzt in
 * {@see StructureSyncService}; diese Klasse übersetzt nur noch die alten
 * Extension-Point-Param-Arrays in dessen typisierte Aufrufe.
 *
 * @deprecated since 2.0 — nutze Sprog\Service\StructureSyncService direkt.
 */
class Sync
{
    /**
     * @param array<string, mixed> $params
     */
    public static function articleNameToCategoryName(array $params): void
    {
        StructureSyncService::syncArticleNameToCategoryName((int) $params['id'], (int) $params['clang'], (string) ($params['name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function categoryNameToArticleName(array $params): void
    {
        StructureSyncService::syncCategoryNameToArticleName((int) $params['id'], (int) $params['clang'], (string) ($params['name'] ?? $params['data']['catname'] ?? ''));
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function articleStatus(array $params): void
    {
        StructureSyncService::syncStatusAcrossLanguages((int) $params['id'], (int) $params['clang'], (int) $params['status']);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function articleTemplate(array $params): void
    {
        StructureSyncService::syncTemplateAcrossLanguages((int) $params['id'], (int) $params['clang'], (int) ($params['template_id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $fields
     */
    public static function articleMetainfo(array $params, array $fields, int $toClangId = 0): void
    {
        StructureSyncService::syncMetainfoAcrossLanguages((int) $params['id'], (int) $params['clang'], array_values($fields), $toClangId);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $fields
     */
    public static function categoryMetainfo(array $params, array $fields): void
    {
        StructureSyncService::syncMetainfoAcrossLanguages((int) $params['id'], (int) $params['clang'], array_values($fields));
    }
}
