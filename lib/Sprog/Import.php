<?php

namespace Sprog;

use Symfony\Component\Serializer\Encoder\CsvEncoder;
use rex_addon;
use rex_file;
use rex_clang;
use rex_clang_service;
use rex_sql;
use rex;
use rex_logger;
use sprog\Wildcard;

class Import
{
    private rex_addon $addon;
    private string $delimiter;
    private string $missingLanguage;
    private rex_logger $logger;

    public function __construct(string $delimiter = ';', string $missingLanguage = '')
    {
        $this->addon = rex_addon::get('sprog');
        $this->delimiter = $delimiter;
        $this->missingLanguage = $missingLanguage;
        $this->logger = rex_logger::factory();
    }

    public function importCsv(string $source, bool $isFile = true): bool
    {
        if ($isFile) {
            if (!file_exists($source)) {
                $this->logger->error("File not found: {$source}");
                return false;
            }

            if (!is_readable($source)) {
                $this->logger->error("File is not readable: {$source}");
                return false;
            }

            $content = rex_file::get($source);
            if ($content === false) {
                $this->logger->error("Failed to read file: {$source}");
                return false;
            }
        } else {
            $content = $source; // Assume it's raw CSV data
        }

        // Remove BOM if present
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (empty($content)) {
            $this->logger->error('Empty CSV data.');
            return false;
        }

        $decoder = new CsvEncoder();
        try {
            $records = $decoder->decode($content, 'csv', [
                CsvEncoder::DELIMITER_KEY => $this->delimiter,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to decode CSV: ' . $e->getMessage());
            return false;
        }

        if (empty($records)) {
            $this->logger->error('No records found in the CSV data.');
            return false;
        }

        return $this->processRecords($records);
    }

    private function processRecords(array $records): bool
    {
        $countInserts = 0;
        $countUpdates = 0;

        $headers = array_keys($records[0]);
        $clangsExists = $this->getClangsExists();

        foreach ($headers as $index => $column) {
            if (0 === $index) {
                continue; // wildcard column
            }

            $column = strtolower(trim($column));

            if (!isset($clangsExists[$column])) {
                if ('add' === $this->missingLanguage) {
                    $this->addNewLanguage($column);
                    $this->logger->info("Language '{$column}' added.");
                } else {
                    $this->logger->info("Language '{$column}' ignored.");
                }
            }
        }

        $wildcards = $this->getExistingWildcards();

        foreach ($records as $record) {
            $result = $this->processRecord($record, $clangsExists, $wildcards);
            $countInserts += $result['inserts'];
            $countUpdates += $result['updates'];
        }

        Wildcard::checkAllLanguagesHaveAllWildcardsAndRepairIfNecessary();

        $this->logger->info("{$countInserts} wildcards added.");
        $this->logger->info("{$countUpdates} wildcards updated.");

        return true;
    }

    private function getClangsExists(): array
    {
        $clangsExists = [];
        foreach (rex_clang::getAll() as $clang) {
            $clangsExists[strtolower($clang->getCode())] = $clang->getId();
        }
        return $clangsExists;
    }

    private function addNewLanguage(string $code): int
    {
        $priority = rex_clang::count() + 1;
        rex_clang_service::addCLang($code, $code, $priority);
        $clangs = rex_clang::getAllIds();
        return $clangs[array_key_last($clangs)];
    }

    private function getExistingWildcards(): array
    {
        $sql = rex_sql::factory();
        $items = $sql->getArray('SELECT id, clang_id, wildcard FROM ' . rex::getTable('sprog_wildcard'));
        $wildcards = [];
        foreach ($items as $item) {
            $wildcards[$item['wildcard']][$item['clang_id']] = (int)$item['id'];
        }
        return $wildcards;
    }

    private function processRecord(array $record, array $clangsExists, array &$wildcards): array
    {
        $inserts = 0;
        $updates = 0;
        $wildcard = $record['wildcard'];
        unset($record['wildcard']);

        foreach ($record as $clangCode => $replace) {
            $clangCode = strtolower(trim($clangCode));

            if (!isset($clangsExists[$clangCode])) {
                continue;
            }

            $clangId = $clangsExists[$clangCode];

            $sql = rex_sql::factory();
            $sql->setTable(rex::getTable('sprog_wildcard'));
            $sql->setValue('replace', $replace);

            if (isset($wildcards[$wildcard][$clangId])) {
                $sql->setWhere('wildcard = :wildcard AND clang_id = :clangId', ['wildcard' => $wildcard, 'clangId' => $clangId]);
                $sql->update();
                $updates++;
            } else {
                if (!isset($id)) {
                    $id = $sql->setNewId('id');
                }
                $sql->setValue('id', $id);
                $sql->setValue('clang_id', $clangId);
                $sql->setValue('wildcard', $wildcard);
                $sql->insert();
                $inserts++;
            }
        }

        return ['inserts' => $inserts, 'updates' => $updates];
    }
}
