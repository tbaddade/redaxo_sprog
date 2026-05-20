<?php

declare(strict_types=1);

namespace Sprog\Model;

use DateTimeImmutable;

/**
 * Glossar-Eintrag pro Sprachpaar.
 *
 * Ein Eintrag bindet einen Quell-Term aus Sprache A an einen Ziel-Term in
 * Sprache B. Wird im MT-Workflow als bindende Vorgabe verwendet (DeepL via
 * Glossary-ID, Claude/OpenAI via System-Prompt) und im Editor als
 * Konsistenz-Hinweis angezeigt.
 *
 * UNIQUE-Constraint im Schema: (source_clang_id, target_clang_id, source_term).
 * Der Service-Layer fängt Duplikate als rex_sql_exception ab und zeigt eine
 * Benutzer-freundliche Meldung.
 */
final readonly class GlossaryEntry
{
    public function __construct(
        public ?int $id,
        public int $sourceClangId,
        public int $targetClangId,
        public string $sourceTerm,
        public string $targetTerm,
        public ?string $notes = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}
}
