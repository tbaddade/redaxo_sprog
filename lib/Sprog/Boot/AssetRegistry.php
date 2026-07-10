<?php

declare(strict_types=1);

namespace Sprog\Boot;

use rex_addon_interface;
use rex_be_controller;
use rex_view;

/**
 * Registriert Backend-CSS/JS für die Sprog-Seiten.
 *
 * Das v1-CSS (sprog.css) und das globalsichere v2-CSS werden auf jeder
 * Backend-Seite eingehängt — sie sind klein und ihre Klassen-Selektoren sind
 * präfixiert (`sprog-`, `sprog__`-BEM). Seiten-spezifische JS-Bundles
 * (migration, inbox, copy) hingegen nur dort, wo die jeweilige Page sie
 * tatsächlich braucht, jeweils mit `defer`, damit ihre Inline-`window.sprog*`-
 * Bootstrap-Blöcke garantiert davor parsen.
 *
 * Bisher inline in boot.php; ausgelagert, um boot.php auf reine Hook-
 * Verdrahtung zu reduzieren.
 */
final class AssetRegistry
{
    public static function publish(rex_addon_interface $addon): void
    {
        $version = $addon->getVersion();

        rex_view::addCssFile($addon->getAssetsUrl('css/sprog.css?v=' . $version));
        rex_view::addCssFile($addon->getAssetsUrl('css/sprog.v2.css?v=' . $version));

        // Seiten-spezifische v2-JS-Bundles. defer ist hier wichtig: der
        // Inline-`window.sprog*`-Bootstrap-Block in der jeweiligen Page muss
        // garantiert vor diesem External-Script parsen — sonst stolpern die
        // Click-Handler über noch nicht gesetzte Globals.
        //
        // Gekeyed auf das LETZTE Page-Segment (Leaf), damit das Matching
        // unabhängig von der Verschachtelung ist — z. B. liegt „migration"
        // jetzt unter „datenpflege" (sprog/datenpflege/migration).
        $pageParts = explode('/', (string) rex_be_controller::getCurrentPage());
        $leaf = (string) end($pageParts);
        $deferredBundles = [
            'migration' => 'js/sprog.migration.js',
            'inbox' => 'js/sprog.inbox.js',
            'copy_structure_content' => 'js/sprog.copy.js',
            'copy_structure_metadata' => 'js/sprog.copy.js',
        ];
        if (isset($deferredBundles[$leaf])) {
            rex_view::addJsFile(
                $addon->getAssetsUrl($deferredBundles[$leaf] . '?v=' . $version),
                [rex_view::JS_DEFERED => true],
            );
        }
    }
}
