<?php

namespace SignDeck\Veil;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Exceptions\ContractImplementationException;
use Spatie\DbSnapshots\SnapshotFactory;

class Veil
{
    protected Filesystem $disk;

    public function __construct(
        protected SnapshotFactory $snapshotFactory,
        protected FilesystemFactory $filesystemFactory,
    ) {
        $this->disk = $this->filesystemFactory->disk(config('veil.disk', 'local'));
    }

    /**
     * Indicate that a column value should remain unchanged.
     */
    public static function unchanged(): AsIs
    {
        return new AsIs;
    }

    /**
     * Handle the export process.
     */
    public function handle(): ?string
    {
        $veilTables = $this->resolveVeilTables();

        if (empty($veilTables)) {
            return null;
        }

        $tableNames = array_map(fn (VeilTable $table) => $table->table(), $veilTables);

        $snapshot = $this->createSnapshot($tableNames);

        $this->anonymizeSnapshot($snapshot, $veilTables);

        return $snapshot;
    }

    /**
     * Resolve and validate the VeilTable classes.
     *
     * @return VeilTable[]
     */
    protected function resolveVeilTables(): array
    {
        $tables = config('veil.tables', []);
        $resolved = [];

        foreach ($tables as $tableClass) {
            $instance = app($tableClass);

            if (! $instance instanceof VeilTable) {
                throw new ContractImplementationException(
                    $tableClass . ' must implement ' . VeilTable::class . ' interface.'
                );
            }

            $resolved[] = $instance;
        }

        return $resolved;
    }

    /**
     * Create a snapshot using Spatie's SnapshotFactory.
     */
    protected function createSnapshot(array $tableNames): string
    {
        $snapshotName = 'veil_' . Carbon::now()->format('Y-m-d_H-i-s');
        $diskName = config('veil.disk', 'local');
        $connectionName = config('veil.connection') ?? config('database.default');
        $compress = config('veil.compress', false);

        $snapshot = $this->snapshotFactory->create(
            $snapshotName,
            $diskName,
            $connectionName,
            $compress,
            $tableNames,
        );

        return $snapshot->fileName;
    }

    /**
     * Anonymize the snapshot by replacing column values in the SQL dump.
     *
     * @param VeilTable[] $veilTables
     */
    protected function anonymizeSnapshot(string $fileName, array $veilTables): void
    {
        $contents = $this->disk->get($fileName);

        foreach ($veilTables as $veilTable) {
            $contents = $this->processTableInSql($contents, $veilTable);
        }

        $this->disk->put($fileName, $contents);
    }

    /**
     * Process a specific table's data in the SQL dump.
     * Only exports columns defined in VeilTable::columns() and applies anonymization.
     */
    protected function processTableInSql(string $sql, VeilTable $veilTable): string
    {
        $tableName = $veilTable->table();
        $columns = $veilTable->columns();

        if (empty($columns)) {
            // No columns defined means remove all INSERT statements for this table
            $pattern = '/INSERT INTO [`"\']?' . preg_quote($tableName, '/') . '[`"\']?\s*\([^)]+\)\s*VALUES\s*.*?;/is';
            return preg_replace($pattern, '', $sql);
        }

        // Get the column names we want to export
        $exportColumnNames = array_keys($columns);

        // Find INSERT statements for this table and process them
        // Pattern matches: INSERT INTO `table_name` (`col1`, `col2`, ...) VALUES (...), (...);
        $pattern = '/INSERT INTO [`"\']?' . preg_quote($tableName, '/') . '[`"\']?\s*\(([^)]+)\)\s*VALUES\s*(.*?);/is';

        return preg_replace_callback($pattern, function ($matches) use ($columns, $tableName, $exportColumnNames) {
            $columnList = $matches[1];
            $valuesSection = $matches[2];

            // Parse column names from the original INSERT statement
            preg_match_all('/[`"\']?(\w+)[`"\']?/', $columnList, $columnMatches);
            $originalColumnNames = $columnMatches[1];

            // Build mapping: export column name => original column index
            $columnMapping = [];
            foreach ($exportColumnNames as $exportColumn) {
                $originalIndex = array_search($exportColumn, $originalColumnNames);
                if ($originalIndex !== false) {
                    $columnMapping[$exportColumn] = [
                        'originalIndex' => $originalIndex,
                        'value' => $columns[$exportColumn],
                    ];
                }
            }

            if (empty($columnMapping)) {
                // None of the export columns exist in this INSERT statement
                return '';
            }

            // Build new column list with only the exported columns
            $newColumnList = implode(', ', array_map(
                fn ($col) => "`{$col}`",
                array_keys($columnMapping)
            ));

            // Process each row's values
            $newValuesSection = $this->processValuesSection($valuesSection, $columnMapping);

            return "INSERT INTO `{$tableName}` ({$newColumnList}) VALUES {$newValuesSection};";
        }, $sql);
    }

    /**
     * Process the VALUES section, extracting only the columns we want and applying anonymization.
     */
    protected function processValuesSection(string $valuesSection, array $columnMapping): string
    {
        // Match individual value groups: (val1, val2, val3)
        $pattern = '/\(([^)]+)\)/';

        $processedRows = [];

        preg_match_all($pattern, $valuesSection, $rowMatches);

        foreach ($rowMatches[1] as $rowValues) {
            $values = $this->parseValues($rowValues);
            $newValues = [];

            foreach ($columnMapping as $columnName => $mapping) {
                $originalIndex = $mapping['originalIndex'];
                $anonymizedValue = $mapping['value'];

                if (!isset($values[$originalIndex])) {
                    continue;
                }

                $originalValue = $values[$originalIndex];

                // Check if value should be kept as-is
                if ($anonymizedValue instanceof AsIs) {
                    $newValues[] = $originalValue;
                } elseif (strtoupper(trim($originalValue)) === 'NULL') {
                    // Preserve NULL values
                    $newValues[] = 'NULL';
                } else {
                    // Apply anonymization
                    $newValues[] = $this->formatValue($anonymizedValue);
                }
            }

            if (!empty($newValues)) {
                $processedRows[] = '(' . implode(', ', $newValues) . ')';
            }
        }

        return implode(', ', $processedRows);
    }

    /**
     * Parse comma-separated values, respecting quoted strings.
     */
    protected function parseValues(string $valueString): array
    {
        $values = [];
        $current = '';
        $inQuote = false;
        $quoteChar = null;
        $escaped = false;

        for ($i = 0; $i < strlen($valueString); $i++) {
            $char = $valueString[$i];

            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $current .= $char;
                $escaped = true;
                continue;
            }

            if (!$inQuote && ($char === '"' || $char === "'")) {
                $inQuote = true;
                $quoteChar = $char;
                $current .= $char;
                continue;
            }

            if ($inQuote && $char === $quoteChar) {
                $inQuote = false;
                $quoteChar = null;
                $current .= $char;
                continue;
            }

            if (!$inQuote && $char === ',') {
                $values[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $values[] = trim($current);
        }

        return $values;
    }

    /**
     * Format a value for SQL insertion.
     */
    protected function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        // Escape single quotes and wrap in quotes
        $escaped = str_replace("'", "''", (string) $value);
        return "'{$escaped}'";
    }
}
