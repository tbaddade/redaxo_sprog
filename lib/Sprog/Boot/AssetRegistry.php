<?php

declare(strict_types=1);

namespace Sprog\Boot;

use rex_addon_interface;
use rex_be_controller;
use rex_view;

use function in_array;

/**
 * Registriert Backend-CSS/JS für die Sprog-Seiten.
 *
 * v1-Bundle (sprog.css + sprog.js) sowie das globalsichere v2-CSS werden auf
 * jeder Backend-Seite eingehängt — sie sind klein und ihre Klassen-Selektoren
 * sind präfixiert (`sprog-`, `sprog__`-BEM). Seiten-spezifische JS-Bundles
 * (migration, editor, inbox) hingegen nur dort, wo die jeweilige Page sie
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

        // Popup-only Vendor-JS (Copy-Workflow): Handlebars + jQuery-Timer
        // werden nur in den Popups gebraucht und sind zu schwer für den
        // globalen Bundle.
        $popupPages = [
            'sprog.copy.structure_content_popup',
            'sprog.copy.structure_metadata_popup',
        ];
        if (in_array(rex_be_controller::getCurrentPagePart(1), $popupPages, true)) {
            rex_view::addJsFile($addon->getAssetsUrl('js/handlebars.min.js?v=' . $version));
            rex_view::addJsFile($addon->getAssetsUrl('js/timer.jquery.min.js?v=' . $version));
        }

        rex_view::addCssFile($addon->getAssetsUrl('css/sprog.css?v=' . $version));
        rex_view::addJsFile($addon->getAssetsUrl('js/sprog.js?v=' . $version));
        rex_view::addCssFile($addon->getAssetsUrl('css/sprog.v2.css?v=' . $version));

        // Seiten-spezifische v2-JS-Bundles. defer ist hier wichtig: der
        // Inline-`window.sprog*`-Bootstrap-Block in der jeweiligen Page muss
        // garantiert vor diesem External-Script parsen — sonst stolpern die
        // Click-Handler über noch nicht gesetzte Globals.
        // (string)-Cast: getCurrentPagePart() liefert null, wenn es keinen
        // zweiten Page-Part gibt — null als Array-Offset ist ab PHP 8.1
        // deprecated. '' trifft schlicht keinen Bundle-Key.
        $pagePart2 = (string) rex_be_controller::getCurrentPagePart(2);
        $deferredBundles = [
            'migration' => 'js/sprog.migration.js',
            'inbox' => 'js/sprog.inbox.js',
        ];
        if (isset($deferredBundles[$pagePart2])) {
            rex_view::addJsFile(
                $addon->getAssetsUrl($deferredBundles[$pagePart2] . '?v=' . $version),
                [rex_view::JS_DEFERED => true],
            );
        }
    }
}
