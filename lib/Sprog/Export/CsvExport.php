<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog\Export;

use Symfony\Component\Serializer\Encoder\CsvEncoder;

class CsvExport
{
    /** @var array<int|string, string> */
    protected array $headers;
    /** @var list<array<int|string, scalar|null>> */
    protected array $items = [];
    /** @var array<string, mixed> */
    private array $context;
    private CsvEncoder $encoder;

    public function __construct()
    {
        $this->encoder = new CsvEncoder();
        $this->context = [
            CsvEncoder::DELIMITER_KEY => ';',
            CsvEncoder::OUTPUT_UTF8_BOM_KEY => true,
            CsvEncoder::NO_HEADERS_KEY => true,
        ];
    }

    /**
     * @param array<int|string, string> $values
     */
    public function addHeaders(array $values): void
    {
        $this->context[CsvEncoder::HEADERS_KEY] = $values;
        $this->context[CsvEncoder::NO_HEADERS_KEY] = false;
    }

    /**
     * @param array<int|string, scalar|null> $values
     */
    public function addItem(array $values): void
    {
        $this->items[] = $values;
    }

    public function setDelimiter(string $value): void
    {
        $this->context[CsvEncoder::DELIMITER_KEY] = $value;
    }

    public function setUtf8Bom(bool $value = true): void
    {
        $this->context[CsvEncoder::OUTPUT_UTF8_BOM_KEY] = $value;
    }

    public function sendFile(string $fileName): void
    {
        if ('.csv' !== substr($fileName, -4)) {
            $fileName .= '.csv';
        }

        header('Content-Disposition: attachment; filename="' . $fileName . '"; charset=utf-8');
        \rex_response::sendContent($this->getStream(), 'text/csv');
        exit();
    }

    public function getStream(): string
    {
        if (false === $this->context[CsvEncoder::NO_HEADERS_KEY] && isset($this->context[CsvEncoder::HEADERS_KEY])) {
            /** @var array<int|string, string> $headers */
            $headers = $this->context[CsvEncoder::HEADERS_KEY];

            foreach ($this->items as $index => $item) {
                $data = [];
                foreach ($item as $header => $value) {
                    $data[$headers[$header]] = $value;
                }
                $this->items[$index] = $data;
            }
        }

        return $this->encoder->encode($this->items, 'csv', $this->context);
    }
}
