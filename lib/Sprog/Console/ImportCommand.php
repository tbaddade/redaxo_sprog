<?php

declare(strict_types=1);

namespace Sprog\Console;

use rex_console_command;
use rex_i18n;
use Sprog\Service\CsvImportService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function count;

/**
 * bin/console sprog:import <datei> [--delimiter=;].
 *
 * Dünne Hülle über CsvImportService — gleiches Format und gleiches Verhalten
 * wie der Import im Backend (Datenpflege → CSV). Ohne eingeloggten User
 * werden Werte und Log-Einträge ohne user_id geschrieben.
 */
final class ImportCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription(rex_i18n::rawMsg('sprog_console_import_description'))
            ->addArgument('file', InputArgument::REQUIRED, rex_i18n::rawMsg('sprog_console_import_file'))
            ->addOption('delimiter', 'd', InputOption::VALUE_REQUIRED, rex_i18n::rawMsg('sprog_console_import_delimiter'), CsvImportService::DEFAULT_DELIMITER);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);

        try {
            $result = CsvImportService::create()->importFile(
                (string) $input->getArgument('file'),
                (string) $input->getOption('delimiter'),
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result->ignoredColumns as $column) {
            $io->warning(rex_i18n::rawMsg('sprog_import_language_ignored', $column));
        }

        if ([] !== $result->errors) {
            $io->warning(rex_i18n::rawMsg('sprog_import_errors', count($result->errors)));
            $io->table(['#', ''], array_map(
                static fn (array $error): array => [$error['row'], $error['message']],
                $result->errors,
            ));
        }

        $io->success(rex_i18n::rawMsg('sprog_import_result', $result->unitsCreated, $result->valuesSet, $result->skippedApproved, $result->skippedEmpty));

        return [] === $result->errors ? self::SUCCESS : self::FAILURE;
    }
}
