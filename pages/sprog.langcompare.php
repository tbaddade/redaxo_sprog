<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Sprog\Service\GlossaryService;
use Sprog\Service\MtService;
use Sprog\View\LangCompare;

/*
 |-----------------------------------------------------------------------------
 | AJAX-Endpoint des Artikel-Sprachvergleichs (hasLayout:false)
 |-----------------------------------------------------------------------------
 | func=panel       → rendert das 2-Spalten-Vergleichs-Panel (HTML)
 | func=slice_copy  → kopiert EINEN Slice an eine Position (Insert, nie Replace)
 | func=batch_copy  → kopiert die ganze (leere) Zielsprache
 |
 | Es werden ausschließlich Core-Services genutzt (addSlice / copyContent).
 | Rechte-Muster wie rex_api_content_copy: copyContent[] (nur schreibend) +
 | clang-complexPerm für Quelle UND Ziel + Kategorierecht.
 */

$csrf = rex_csrf_token::factory('sprog_langcompare');
$func = rex_request('func', 'string', '');
$user = rex::getUser();

rex_response::cleanOutputBuffers();

$sendJson = static function (array $data, int $httpStatus = 200): never {
    rex_response::setStatus(match ($httpStatus) {
        403 => rex_response::HTTP_FORBIDDEN,
        400 => rex_response::HTTP_BAD_REQUEST,
        500 => rex_response::HTTP_INTERNAL_ERROR,
        default => rex_response::HTTP_OK,
    });
    rex_response::sendJson($data);
    exit;
};

// Rechte-Gate für das Kopieren (analog api_content_copy): braucht copyContent[]
// und clang-Recht für Quelle UND Ziel + Kategorierecht.
$assertAccess = static function (int $articleId, int $clangFrom, int $clangTo, bool $write) use ($user): void {
    if (null === $user) {
        throw new rex_exception(rex_i18n::msg('sprog_langcompare_no_perm'));
    }
    if ($write && !$user->hasPerm('copyContent[]')) {
        throw new rex_exception(rex_i18n::msg('sprog_langcompare_no_perm'));
    }
    $article = rex_article::get($articleId, $clangFrom);
    if (null === $article) {
        throw new rex_exception(rex_i18n::msg('sprog_langcompare_no_article'));
    }
    $clangPerm = $user->getComplexPerm('clang');
    if (
        !$clangPerm->hasPerm($clangFrom)
        || !$clangPerm->hasPerm($clangTo)
        || !$user->getComplexPerm('structure')->hasCategoryPerm($article->getCategoryId())
    ) {
        throw new rex_exception(rex_i18n::msg('sprog_langcompare_no_perm'));
    }
};

// Rechte-Gate für Slice-Aktionen in EINER Sprache (Status/Verschieben/Löschen):
// clang-Recht + Kategorierecht (wie die native Content-Maske). Modulrecht wird
// zusätzlich dort geprüft, wo der Slice bekannt ist.
$assertClang = static function (int $articleId, int $clang) use ($user): void {
    if (null === $user) {
        throw new rex_exception(rex_i18n::msg('sprog_langcompare_no_perm'));
    }
    $article = rex_article::get($articleId, $clang);
    if (null === $article) {
        throw new rex_exception(rex_i18n::msg('sprog_langcompare_no_article'));
    }
    if (
        !$user->getComplexPerm('clang')->hasPerm($clang)
        || !$user->getComplexPerm('structure')->hasCategoryPerm($article->getCategoryId())
    ) {
        throw new rex_exception(rex_i18n::msg('sprog_langcompare_no_perm'));
    }
};

try {
    if ('panel' === $func) {
        $articleId = rex_request('article_id', 'int', 0);
        $clangA = rex_request('clang_a', 'int', 0);
        $clangB = rex_request('clang_b', 'int', 0);
        $ctype = rex_request('ctype', 'int', 1);
        $revision = rex_request('revision', 'int', 0);

        $assertAccess($articleId, $clangA, $clangB, false);
        echo LangCompare::renderPanel($articleId, $clangA, $clangB, $ctype, $revision);
        exit;
    }

    if ('meta' === $func) {
        // Metadaten-Vergleich (volle Sidebar) beider Sprachen.
        $articleId = rex_request('article_id', 'int', 0);
        $clangA = rex_request('clang_a', 'int', 0);
        $clangB = rex_request('clang_b', 'int', 0);
        $ctype = rex_request('ctype', 'int', 1);

        $assertAccess($articleId, $clangA, $clangB, false);
        echo LangCompare::renderMetaPanel($articleId, $clangA, $clangB, $ctype);
        exit;
    }

    if ('meta_edit' === $func) {
        // Metadaten-Formular EINER Sprache (Bearbeiten-Modus).
        $articleId = rex_request('article_id', 'int', 0);
        $clang = rex_request('clang', 'int', 0);
        $ctype = rex_request('ctype', 'int', 1);

        $assertClang($articleId, $clang);
        echo LangCompare::renderMetaEdit($articleId, $clang, $ctype);
        exit;
    }

    if ('mt' === $func) {
        // MT-Vorschlag für ein Feld: Quelltext von Quell- nach Zielsprache
        // übersetzen (reuse MtService + Glossar wie in der Inbox).
        if (!$csrf->isValid()) {
            $sendJson(['success' => false, 'error' => rex_i18n::msg('csrf_token_invalid')], 403);
        }

        $text = rex_request('text', 'string', '');
        $sourceClang = rex_request('source_clang', 'int', 0);
        $targetClang = rex_request('target_clang', 'int', 0);

        if (null === $user) {
            throw new rex_exception(rex_i18n::msg('sprog_langcompare_no_perm'));
        }
        $clangPerm = $user->getComplexPerm('clang');
        if (!$clangPerm->hasPerm($sourceClang) || !$clangPerm->hasPerm($targetClang)) {
            throw new rex_exception(rex_i18n::msg('sprog_langcompare_no_perm'));
        }

        if ('' === trim($text)) {
            $sendJson(['success' => true, 'text' => '']);
        }

        $src = rex_clang::get($sourceClang);
        $tgt = rex_clang::get($targetClang);
        if (null === $src || null === $tgt) {
            throw new rex_exception(rex_i18n::msg('sprog_langcompare_no_perm'));
        }

        $mt = MtService::create();
        $configured = $mt->configuredProviderNames();
        $requested = trim((string) rex_request('provider', 'string', ''));

        // Angeforderten Provider nehmen (wenn echt konfiguriert), sonst ersten
        // echten (nicht-noop) — analog zur Inbox.
        $provider = null;
        if ('' !== $requested && 'noop' !== $requested && in_array($requested, $configured, true)) {
            $provider = $requested;
        } else {
            foreach ($configured as $name) {
                if ('noop' !== $name) {
                    $provider = $name;
                    break;
                }
            }
        }
        if (null === $provider) {
            $sendJson(['success' => false, 'error' => rex_i18n::msg('sprog_langcompare_mt_no_provider')], 400);
        }

        $glossary = GlossaryService::create()->mapForPair($sourceClang, $targetClang);
        set_time_limit(120);

        $result = $mt->translate(
            $text,
            strtolower($src->getCode()),
            strtolower($tgt->getCode()),
            $provider,
            $glossary,
        );

        $sendJson(['success' => true, 'text' => $result->text, 'provider' => $result->provider]);
    }

    if ('column' === $func) {
        // Eine einzelne Spalte neu rendern — für den Wechsel Liste ↔ Bearbeiten-/
        // Hinzufügen-Formular inline (ohne die Gegenspalte anzufassen).
        $articleId = rex_request('article_id', 'int', 0);
        $clang = rex_request('clang', 'int', 0);
        $ctype = rex_request('ctype', 'int', 1);
        $revision = rex_request('revision', 'int', 0);
        $function = rex_request('function', 'string', '');
        $sliceId = rex_request('slice_id', 'int', 0);
        if (!in_array($function, ['', 'edit', 'add'], true)) {
            $function = '';
        }

        $assertClang($articleId, $clang);
        echo LangCompare::renderColumn($articleId, $clang, $ctype, $revision, $function, $sliceId);
        exit;
    }

    if ('slice_copy' === $func) {
        if (!$csrf->isValid()) {
            $sendJson(['success' => false, 'error' => rex_i18n::msg('csrf_token_invalid')], 403);
        }

        $articleId = rex_request('article_id', 'int', 0);
        $sliceId = rex_request('slice_id', 'int', 0);
        $clangFrom = rex_request('clang_from', 'int', 0);
        $clangTo = rex_request('clang_to', 'int', 0);
        $targetPosition = rex_request('target_position', 'int', 1);
        $revision = rex_request('revision', 'int', 0);

        $assertAccess($articleId, $clangFrom, $clangTo, true);

        // Quell-Slice roh lesen; alle Inhaltsspalten übernehmen (Identität,
        // Sprache, Position setzt addSlice bzw. wir selbst).
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT * FROM ' . rex::getTable('article_slice') . ' WHERE `id` = :id AND `clang_id` = :clang AND `revision` = :rev',
            ['id' => $sliceId, 'clang' => $clangFrom, 'rev' => $revision],
        );
        if (1 !== $sql->getRows()) {
            throw new rex_exception(rex_i18n::msg('sprog_langcompare_slice_missing'));
        }

        $skip = ['id', 'pid', 'clang_id', 'article_id', 'ctype_id', 'module_id', 'createuser', 'updateuser', 'createdate', 'updatedate'];
        $data = [];
        foreach ($sql->getFieldnames() as $column) {
            if (!in_array($column, $skip, true)) {
                $data[$column] = $sql->getValue($column);
            }
        }
        // target_position > 0 → an diese Position einfügen; sonst anhängen
        // (addSlice setzt bei fehlender/≤0 priority selbst max+1).
        if ($targetPosition > 0) {
            $data['priority'] = $targetPosition;
        }
        $data['revision'] = $revision;

        rex_content_service::addSlice(
            $articleId,
            $clangTo,
            (int) $sql->getValue('ctype_id'),
            (int) $sql->getValue('module_id'),
            $data,
        );

        $sendJson(['success' => true]);
    }

    if ('slice_status' === $func) {
        if (!$csrf->isValid()) {
            $sendJson(['success' => false, 'error' => rex_i18n::msg('csrf_token_invalid')], 403);
        }

        $articleId = rex_request('article_id', 'int', 0);
        $sliceId = rex_request('slice_id', 'int', 0);
        $clang = rex_request('clang', 'int', 0);

        $assertClang($articleId, $clang);

        // aktuellen Status lesen und umschalten (wie der native Toggle)
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT `status` FROM ' . rex::getTable('article_slice') . ' WHERE `id` = ? AND `clang_id` = ?', [$sliceId, $clang]);
        if (1 !== $sql->getRows()) {
            throw new rex_exception(rex_i18n::msg('sprog_langcompare_slice_missing'));
        }
        $newStatus = 1 === (int) $sql->getValue('status') ? 0 : 1;

        rex_content_service::sliceStatus($sliceId, $newStatus);
        $sendJson(['success' => true, 'status' => $newStatus]);
    }

    if ('slice_move' === $func) {
        if (!$csrf->isValid()) {
            $sendJson(['success' => false, 'error' => rex_i18n::msg('csrf_token_invalid')], 403);
        }

        $articleId = rex_request('article_id', 'int', 0);
        $sliceId = rex_request('slice_id', 'int', 0);
        $clang = rex_request('clang', 'int', 0);
        $direction = rex_request('direction', 'string', '');

        if (!in_array($direction, ['moveup', 'movedown'], true)) {
            $sendJson(['success' => false, 'error' => 'bad direction'], 400);
        }

        $assertClang($articleId, $clang);

        try {
            rex_content_service::moveSlice($sliceId, $clang, $direction);
        } catch (rex_api_exception $e) {
            // Slice ist bereits ganz oben/unten — kein echter Fehler, nur nichts zu tun.
            $sendJson(['success' => true, 'moved' => false]);
        }

        $sendJson(['success' => true, 'moved' => true]);
    }

    if ('slice_delete' === $func) {
        if (!$csrf->isValid()) {
            $sendJson(['success' => false, 'error' => rex_i18n::msg('csrf_token_invalid')], 403);
        }

        $articleId = rex_request('article_id', 'int', 0);
        $sliceId = rex_request('slice_id', 'int', 0);
        $clang = rex_request('clang', 'int', 0);

        $assertClang($articleId, $clang);

        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('article_slice') . ' WHERE `id` = ? AND `clang_id` = ?', [$sliceId, $clang]);
        if (1 !== $sql->getRows()) {
            throw new rex_exception(rex_i18n::msg('sprog_langcompare_slice_missing'));
        }
        $ctype = (int) $sql->getValue('ctype_id');
        $moduleId = (int) $sql->getValue('module_id');
        $sliceRevision = (int) $sql->getValue('revision');

        if (!$user->getComplexPerm('modules')->hasPerm($moduleId)) {
            throw new rex_exception(rex_i18n::msg('sprog_langcompare_no_perm'));
        }

        $article = rex_article::get($articleId, $clang);

        // deleteSlice() feuert SLICE_DELETE (PRE) + löscht + ordnet Prioritäten;
        // die POST-EPs + Cache-Invalidierung ergänzen wir wie content.php.
        rex_content_service::deleteSlice($sliceId);

        $epParams = [
            'article_id' => $articleId,
            'clang' => $clang,
            'function' => 'delete',
            'slice_id' => $sliceId,
            'page' => '',
            'ctype' => $ctype,
            'category_id' => null !== $article ? $article->getCategoryId() : 0,
            'module_id' => $moduleId,
            'slice_revision' => $sliceRevision,
        ];
        rex_extension::registerPoint(new rex_extension_point('SLICE_DELETED', '', $epParams));
        /* deprecated */ rex_extension::registerPoint(new rex_extension_point('STRUCTURE_CONTENT_SLICE_DELETED', '', $epParams));
        if (null !== $article) {
            rex_extension::registerPoint(new rex_extension_point_art_content_updated($article, 'slice_deleted'));
        }
        rex_article_cache::deleteContent($articleId, $clang);

        $sendJson(['success' => true]);
    }

    if ('batch_copy' === $func) {
        if (!$csrf->isValid()) {
            $sendJson(['success' => false, 'error' => rex_i18n::msg('csrf_token_invalid')], 403);
        }

        $articleId = rex_request('article_id', 'int', 0);
        $clangA = rex_request('clang_a', 'int', 0);
        $clangB = rex_request('clang_b', 'int', 0);
        $revision = rex_request('revision', 'int', 0);

        $assertAccess($articleId, $clangA, $clangB, true);

        // Nur wenn das Ziel komplett leer ist — verhindert Dubletten by design.
        if (count(rex_article_slice::getSlicesForArticle($articleId, $clangB, $revision)) > 0) {
            throw new rex_exception(rex_i18n::msg('sprog_langcompare_target_not_empty'));
        }

        rex_content_service::copyContent($articleId, $articleId, $clangA, $clangB, $revision, false);
        $sendJson(['success' => true]);
    }

    $sendJson(['success' => false, 'error' => 'unknown func'], 400);
} catch (Throwable $e) {
    if ('panel' === $func) {
        echo '<div class="sprog-note sprog-note--warning">' . rex_escape($e->getMessage()) . '</div>';
        exit;
    }
    $sendJson(['success' => false, 'error' => $e->getMessage()], 500);
}
