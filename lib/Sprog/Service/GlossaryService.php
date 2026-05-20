<?php

declare(strict_types=1);

namespace Sprog\Service;

use InvalidArgumentException;
use rex_clang;
use Sprog\Model\GlossaryEntry;
use Sprog\Repository\GlossaryRepository;

use function sprintf;
use function strlen;

/**
 * Glossar — feste Wort-/Phrasen-Bindungen pro Sprachpaar.
 *
 * Zwei Konsumenten:
 *   - Backend-Page (Pflege durch Übersetzer/Admins)
 *   - MtService → mapForPair() liefert Source→Target-Map, die DeepL-Glossary
 *     bzw. LLM-System-Prompts angereichert werden (folgt in eigener Tranche).
 *
 * Validierungen passieren hier — der Repository-Layer bleibt SQL-pur.
 * UNIQUE-Verletzungen aus dem DB-Layer reicht der Service durch; die Page
 * fängt die rex_sql_exception ab und zeigt eine klare Meldung.
 */
final class GlossaryService
{
    /** Schema-konformer Max-Length-Wert für indizierte VARCHAR-Felder unter utf8mb4. */
    public const MAX_TERM_LENGTH = 191;

    public const MAX_NOTES_LENGTH = 500;

    public function __construct(
        private readonly GlossaryRepository $repository,
    ) {}

    public static function create(): self
    {
        return new self(new GlossaryRepository());
    }

    /**
     * @return list<GlossaryEntry>
     */
    public function listForPair(int $sourceClangId, int $targetClangId): array
    {
        return $this->repository->findByPair($sourceClangId, $targetClangId);
    }

    /**
     * Wird vom MtService genutzt, um Provider mit dem Glossar anzureichern.
     *
     * @return array<string, string>
     */
    public function mapForPair(int $sourceClangId, int $targetClangId): array
    {
        return $this->repository->mapForPair($sourceClangId, $targetClangId);
    }

    /**
     * Legt einen neuen Eintrag an.
     *
     * Validiert vor dem Save; ein UNIQUE-Verstoß landet als rex_sql_exception
     * beim Caller, weil race-condition-frei nur die DB diesen Fall erkennt.
     */
    public function add(
        int $sourceClangId,
        int $targetClangId,
        string $sourceTerm,
        string $targetTerm,
        ?string $notes = null,
    ): GlossaryEntry {
        $this->assertValidPair($sourceClangId, $targetClangId);
        $this->assertValidTerm($sourceTerm, 'sourceTerm');
        $this->assertValidTerm($targetTerm, 'targetTerm');
        $this->assertValidNotes($notes);

        return $this->repository->save(new GlossaryEntry(
            id: null,
            sourceClangId: $sourceClangId,
            targetClangId: $targetClangId,
            sourceTerm: trim($sourceTerm),
            targetTerm: trim($targetTerm),
            notes: $this->normalizeNotes($notes),
        ));
    }

    /**
     * Aktualisiert einen Eintrag. Sprach-Paar bleibt fix; wenn der User
     * die Sprache eines Eintrags ändern will, soll er löschen und neu anlegen
     * (weil sich sonst die UNIQUE-Constraint hinterrücks verschiebt).
     */
    public function update(
        int $id,
        string $sourceTerm,
        string $targetTerm,
        ?string $notes = null,
    ): GlossaryEntry {
        $existing = $this->repository->find($id);
        if (null === $existing) {
            throw new InvalidArgumentException('Glossar-Eintrag ' . $id . ' nicht gefunden.');
        }

        $this->assertValidTerm($sourceTerm, 'sourceTerm');
        $this->assertValidTerm($targetTerm, 'targetTerm');
        $this->assertValidNotes($notes);

        return $this->repository->save(new GlossaryEntry(
            id: $existing->id,
            sourceClangId: $existing->sourceClangId,
            targetClangId: $existing->targetClangId,
            sourceTerm: trim($sourceTerm),
            targetTerm: trim($targetTerm),
            notes: $this->normalizeNotes($notes),
        ));
    }

    public function remove(int $id): void
    {
        $this->repository->delete($id);
    }

    private function assertValidPair(int $sourceClangId, int $targetClangId): void
    {
        if ($sourceClangId <= 0 || !rex_clang::exists($sourceClangId)) {
            throw new InvalidArgumentException('Ungültige sourceClangId: ' . $sourceClangId);
        }
        if ($targetClangId <= 0 || !rex_clang::exists($targetClangId)) {
            throw new InvalidArgumentException('Ungültige targetClangId: ' . $targetClangId);
        }
        if ($sourceClangId === $targetClangId) {
            throw new InvalidArgumentException('Quell- und Ziel-Sprache müssen unterschiedlich sein.');
        }
    }

    private function assertValidTerm(string $term, string $argName): void
    {
        $trimmed = trim($term);
        if ('' === $trimmed) {
            throw new InvalidArgumentException($argName . ' darf nicht leer sein.');
        }
        if (strlen($trimmed) > self::MAX_TERM_LENGTH) {
            throw new InvalidArgumentException(sprintf('%s übersteigt die Maximallänge von %d Zeichen.', $argName, self::MAX_TERM_LENGTH));
        }
    }

    private function assertValidNotes(?string $notes): void
    {
        if (null === $notes) {
            return;
        }
        if (strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new InvalidArgumentException(sprintf('notes übersteigen die Maximallänge von %d Zeichen.', self::MAX_NOTES_LENGTH));
        }
    }

    private function normalizeNotes(?string $notes): ?string
    {
        if (null === $notes) {
            return null;
        }
        $trimmed = trim($notes);

        return '' === $trimmed ? null : $trimmed;
    }
}
