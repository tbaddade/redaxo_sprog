<?php

declare(strict_types=1);

namespace Sprog\Service;

use InvalidArgumentException;
use rex_clang;
use rex_file;
use Sprog\Enum\Status;
use Sprog\Model\CsvImportResult;
use Sprog\Model\Unit;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Throwable;

use function in_array;
use function is_array;
use function strlen;

/**
 * CSV-Import im Export-Format (pages/artefact.export.php):
 *
 *     namespace ; context ; key ; notes ; <sprachcode> ; <sprachcode> …
 *
 * Units werden per (namespace, context, key) gesucht, fehlende über den
 * UnitService angelegt (gleiche Prüfungen wie in der Inbox). Werte laufen
 * über TranslationService::updateValue (origin=import, Auto-Status wie beim
 * Inbox-Edit). Freigegebene Übersetzungen bleiben unangetastet, leere Zellen
 * werden übersprungen.
 *
 * Aufrufer: pages/artefact.import.php, Konsole (sprog:import) und Code
 * anderer AddOns:
 *
 *     $result = CsvImportService::create()->importFile($path);
 *     $result = CsvImportService::create()->importString($csv, ',');
 */
final class CsvImportService
{
    public const DEFAULT_DELIMITER = ';';

    /** Spalten, die keine Sprachspalten sind. */
    private const RESERVED_COLUMNS = ['namespace', 'context', 'key', 'notes'];

    public function __construct(
        private readonly UnitRepository $units,
        private readonly TranslationRepository $translationRepo,
        private readonly TranslationService $translations,
        private readonly UnitService $unitService,
    ) {}

    public static function create(): self
    {
        return new self(new UnitRepository(), new TranslationRepository(), TranslationService::create(), UnitService::create());
    }

    /**
     * @throws InvalidArgumentException wenn die Datei nicht lesbar ist oder das Trennzeichen ungültig
     */
    public function importFile(string $path, string $delimiter = self::DEFAULT_DELIMITER, ?int $userId = null): CsvImportResult
    {
        $csv = rex_file::get($path);
        if (null === $csv) {
            throw new InvalidArgumentException('CSV-Datei nicht lesbar: ' . $path);
        }

        return $this->importString($csv, $delimiter, $userId);
    }

    /**
     * @throws InvalidArgumentException wenn das Trennzeichen ungültig ist
     */
    public function importString(string $csv, string $delimiter = self::DEFAULT_DELIMITER, ?int $userId = null): CsvImportResult
    {
        $result = new CsvImportResult();

        $clangByCode = [];
        foreach (rex_clang::getAll() as $clang) {
            $clangByCode[strtolower($clang->getCode())] = $clang->getId();
        }

        foreach (self::parse($csv, $delimiter) as $index => $row) {
            // Datei-Zeilennummer: Kopfzeile ist 1, erster Datensatz 2.
            $line = $index + 2;

            $namespace = trim($row['namespace'] ?? '');
            $unitKey = trim($row['key'] ?? '');
            $context = $row['context'] ?? '';

            if ('' === $namespace && '' === $unitKey) {
                continue;
            }

            $unit = $this->units->findByKey($namespace, $unitKey, Unit::normalizeContext($context));
            if (null === $unit) {
                try {
                    $unit = $this->unitService->createUnit($namespace, $unitKey, $context, $row['notes'] ?? null, $userId);
                    ++$result->unitsCreated;
                } catch (Throwable $e) {
                    // UnitValidationException trägt alle Prüf-Fehler als Message.
                    $result->addError($line, $e->getMessage());
                    continue;
                }
            }

            foreach ($row as $column => $value) {
                if (in_array($column, self::RESERVED_COLUMNS, true)) {
                    continue;
                }
                if (!isset($clangByCode[$column])) {
                    $result->ignoreColumn($column);
                    continue;
                }
                if ('' === trim($value)) {
                    ++$result->skippedEmpty;
                    continue;
                }

                $clangId = $clangByCode[$column];
                $current = $this->translationRepo->findForUnitAndClang((int) $unit->id, $clangId);
                if (null !== $current && Status::Approved === $current->status) {
                    ++$result->skippedApproved;
                    continue;
                }

                try {
                    $this->translations->updateValue($unit, $clangId, $value, $userId, origin: 'import');
                    ++$result->valuesSet;
                } catch (Throwable $e) {
                    $result->addError($line, $column . ': ' . $e->getMessage());
                }
            }
        }

        return $result;
    }

    /**
     * Dekodiert die CSV und normalisiert die Spaltennamen (UTF-8-BOM aus dem
     * Export entfernen, trimmen, klein schreiben). Werte bleiben unverändert.
     * Ohne REDAXO-Laufzeit nutzbar (Unit-Tests).
     *
     * @throws InvalidArgumentException wenn das Trennzeichen nicht genau ein Zeichen ist
     * @return list<array<string, string>>
     */
    public static function parse(string $csv, string $delimiter = self::DEFAULT_DELIMITER): array
    {
        if (1 !== strlen($delimiter)) {
            throw new InvalidArgumentException('Trennzeichen muss genau ein Zeichen sein.');
        }

        $csv = str_replace("\u{FEFF}", '', $csv);
        if ('' === trim($csv)) {
            return [];
        }

        $records = (new CsvEncoder())->decode($csv, 'csv', [
            CsvEncoder::DELIMITER_KEY => $delimiter,
            CsvEncoder::AS_COLLECTION_KEY => true,
        ]);
        if (!is_array($records)) {
            return [];
        }

        $rows = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $row = [];
            foreach ($record as $key => $value) {
                $row[strtolower(trim((string) $key))] = (string) $value;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
