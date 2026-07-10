<?php

declare(strict_types=1);

namespace Sprog\Support;

use rex_clang;
use rex_config;

/**
 * Basissprache (Quellsprache) für Übersetzungen und Glossar.
 *
 * Standard ist REDAXOs Start-Clang. Über die sprog-Config (`base_clang_id`)
 * lässt sich die Ausgangssprache aber fest verankern — so bleibt sie stabil,
 * auch wenn die REDAXO-Start-Clang später wechselt. Single Source of Truth:
 * ALLE Stellen, die „die Ausgangssprache" meinen (MT-Quelle, Batch, Glossar,
 * Inbox), fragen hier — nicht direkt rex_clang::getStartId().
 *
 * Config-Wert 0 (oder ungültig/gelöscht) → Fallback auf die Start-Clang.
 */
final class BaseLang
{
    public static function clangId(): int
    {
        $configured = (int) rex_config::get('sprog', 'base_clang_id', 0);
        if ($configured > 0 && rex_clang::exists($configured)) {
            return $configured;
        }

        return rex_clang::getStartId();
    }
}
