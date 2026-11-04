<?php

declare(strict_types=1);

namespace Sprog\Tests\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sprog\Service\CsvImportService;

final class CsvImportParseTest extends TestCase
{
    public function testParsesExportFormatWithBomAndNormalizesHeaders(): void
    {
        $csv = "\u{FEFF}Namespace; Context ;KEY;notes;DE;en\nwildcard;page.about;title;;Über uns;About us\n";

        self::assertSame(
            [[
                'namespace' => 'wildcard',
                'context' => 'page.about',
                'key' => 'title',
                'notes' => '',
                'de' => 'Über uns',
                'en' => 'About us',
            ]],
            CsvImportService::parse($csv),
        );
    }

    public function testSingleRecordIsStillAList(): void
    {
        // CsvEncoder liefert ohne as_collection bei genau einem Datensatz ein
        // assoziatives Array statt einer Liste — der Import würde dann jede
        // Spalte als eigene „Zeile" behandeln.
        $rows = CsvImportService::parse("namespace;key\nwildcard;a\n");

        self::assertCount(1, $rows);
        self::assertSame('a', $rows[0]['key']);
    }

    public function testValuesKeepWhitespaceAndQuotedDelimiters(): void
    {
        $rows = CsvImportService::parse("namespace;key;de\nwildcard;a;\" Wert; mit Semikolon \"\n");

        self::assertSame(' Wert; mit Semikolon ', $rows[0]['de']);
    }

    public function testCustomDelimiter(): void
    {
        $rows = CsvImportService::parse("namespace,key,de\nwildcard,a,Hallo\n", ',');

        self::assertSame('Hallo', $rows[0]['de']);
    }

    public function testEmptyInputYieldsNoRows(): void
    {
        self::assertSame([], CsvImportService::parse(''));
        self::assertSame([], CsvImportService::parse("\u{FEFF}\n"));
    }

    public function testRejectsMultiCharacterDelimiter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CsvImportService::parse("a;b\n1;2\n", ';;');
    }
}
