<?php

declare(strict_types=1);

namespace Sprog\Model;

/**
 * Ergebnis eines CSV-Imports (Sprog\Service\CsvImportService). Wird während
 * des Imports befüllt und danach von Backend-Seite bzw. Konsole ausgegeben.
 */
final class CsvImportResult
{
    public int $unitsCreated = 0;
    public int $valuesSet = 0;
    public int $skippedApproved = 0;
    public int $skippedEmpty = 0;

    /** @var list<array{row: int, message: string}> Zeilennummer bezogen auf die Datei (Kopfzeile = 1) */
    public array $errors = [];

    /** @var array<string, string> Spalten, die weder reserviert noch ein bekannter Sprachcode sind */
    public array $ignoredColumns = [];

    public function addError(int $row, string $message): void
    {
        $this->errors[] = ['row' => $row, 'message' => $message];
    }

    public function ignoreColumn(string $column): void
    {
        $this->ignoredColumns[$column] = $column;
    }
}
