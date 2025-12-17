<?php

namespace SignDeck\Veil;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Events\ExportCompleted;
use SignDeck\Veil\Events\ExportStarted;
use SignDeck\Veil\Exceptions\ContractImplementationException;
use Spatie\DbSnapshots\SnapshotFactory;

class Veil
{
    protected Filesystem $disk;

    protected ?VeilProgressBar $progressBar = null;

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
     * Set a progress bar for the export process.
     *
     * @param Command $command Command instance for progress updates
     * @return $this
     */
    public function withProgressBar(Command $command): self
    {
        $this->progressBar = new VeilProgressBar($command);

        return $this;
    }

    /**
     * Handle the export process.
     *
     * @param string|null $snapshotName Custom name for the snapshot. If null, uses timestamped name.
     * @return string|null Snapshot filename or null
     */
    public function handle(?string $snapshotName = null): ?string
    {
        $veilTables = $this->resolveVeilTables();

        if (empty($veilTables)) {
            return null;
        }

        $tableNames = array_map(
            fn (VeilTable $table) => $table->table(), 
            $veilTables
        );

        // Fire pre-export event
        Event::dispatch(new ExportStarted($snapshotName, $tableNames));

        if ($this->progressBar) {
            $this->progressBar->info('Creating database snapshot...');
        }

        $snapshot = $this->createSnapshot($tableNames, $snapshotName);

        if ($this->progressBar) {
            $this->progressBar->info('Anonymizing data...');
            $this->progressBar->setMaxSteps(count($veilTables));
            $this->progressBar->setMessage('Processing tables');
            $this->progressBar->start();
        }

        $this->anonymizeSnapshot($snapshot, $veilTables);

        if ($this->progressBar) {
            $this->progressBar->finish();
            $this->progressBar->newLine(2);
        }

        // Fire post-export event
        Event::dispatch(new ExportCompleted($snapshot, $snapshotName, $tableNames));

        return $snapshot;
    }

    /**
     * Resolve and validate the VeilTable classes.
     *
     * @return VeilTable[]
     */
    public function resolveVeilTables(): array
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
     *
     * @param string|null $customName Custom name for the snapshot. If null, uses timestamped name.
     */
    protected function createSnapshot(array $tableNames, ?string $customName = null): string
    {
        $snapshotName = $customName ?? 'veil_' . Carbon::now()->format('Y-m-d_H-i-s');
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
    protected function anonymizeSnapshot(
        string $fileName, 
        array $veilTables
    ): void {
        $contents = $this->disk->get($fileName);

        foreach ($veilTables as $index => $veilTable) {
            if ($this->progressBar) {
                $tableName = $veilTable->table();
                $this->progressBar->setMessage("Processing {$tableName}");
                $this->progressBar->advance();
            }

            $allowedIds = $this->getAllowedIds($veilTable);
            $contents = $this->processTableInSql($contents, $veilTable, $allowedIds);
        }

        $this->disk->put($fileName, $contents);
    }

    /**
     * Get allowed IDs based on the query scope.
     *
     * @return array|null Array of allowed IDs, or null if no filtering
     */
    protected function getAllowedIds(VeilTable $veilTable): ?array
    {
        $query = $veilTable->query();

        if (!$query) {
            return null;
        }

        $tableName = $veilTable->table();

        // Get the primary key column name (default to 'id')
        $primaryKey = $this->getPrimaryKeyColumn($tableName);

        // Execute the query and get IDs
        return $query->pluck($primaryKey)->toArray();
    }

    /**
     * Get the primary key column name for a table.
     */
    protected function getPrimaryKeyColumn(string $tableName): string
    {
        try {
            // Try to get primary key from schema
            $connection = DB::connection();
            $schema = $connection->getDoctrineSchemaManager();
            $table = $schema->listTableDetails($tableName);
            $primaryKey = $table->getPrimaryKey();

            if ($primaryKey && count($primaryKey->getColumns()) > 0) {
                return $primaryKey->getColumns()[0];
            }
        } catch (\Exception $e) {
            // Fallback to 'id' if we can't detect
        }

        return 'id';
    }

    /**
     * Get column names from CREATE TABLE statement in SQL dump.
     */
    protected function getColumnNamesFromCreateTable(string $sql, string $tableName): array
    {
        // Match CREATE TABLE statement for this table (handles multi-line)
        $pattern = '/CREATE\s+TABLE\s+[`"\']?' . preg_quote($tableName, '/') . '[`"\']?\s*\(([^;]+)\)/is';
        
        if (preg_match($pattern, $sql, $matches)) {
            $tableDefinition = $matches[1];
            $columnNames = [];
            
            // Split by commas, but be careful with nested parentheses (e.g., in column definitions)
            // Use a more sophisticated approach: track parentheses depth
            $lines = [];
            $current = '';
            $depth = 0;
            
            for ($i = 0; $i < strlen($tableDefinition); $i++) {
                $char = $tableDefinition[$i];
                
                if ($char === '(') {
                    $depth++;
                    $current .= $char;
                } elseif ($char === ')') {
                    $depth--;
                    $current .= $char;
                } elseif ($char === ',' && $depth === 0) {
                    $lines[] = trim($current);
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
            
            if (!empty($current)) {
                $lines[] = trim($current);
            }
            
            // Extract column names, skipping constraints
            foreach ($lines as $line) {
                $line = trim($line);
                
                // Skip constraint definitions
                if (preg_match('/^\s*(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX|FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN\s+KEY)/i', $line)) {
                    continue;
                }
                
                // Extract column name (first identifier, handling backticks/quotes)
                if (preg_match('/^[`"\']?(\w+)[`"\']?\s+/i', $line, $colMatch)) {
                    $columnNames[] = $colMatch[1];
                }
            }
            
            return $columnNames;
        }
        
        return [];
    }

    /**
     * Get all column names for a table in their natural order from database schema.
     */
    protected function getTableColumnNames(string $tableName): array
    {
        try {
            $connection = DB::connection();
            $schema = $connection->getDoctrineSchemaManager();
            $table = $schema->listTableDetails($tableName);
            
            return array_keys($table->getColumns());
        } catch (\Exception $e) {
            // Fallback: try to get columns from information schema
            try {
                $columns = DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE() ORDER BY ORDINAL_POSITION", [$tableName]);
                return array_map(fn($col) => $col->COLUMN_NAME, $columns);
            } catch (\Exception $e) {
                // If all else fails, return empty array
                return [];
            }
        }
    }

    /**
     * Process a specific table's data in the SQL dump.
     * Only exports columns defined in VeilTable::columns() and applies anonymization.
     *
     * @param array|null $allowedIds If provided, only rows with these IDs will be included
     */
    protected function processTableInSql(string $sql, VeilTable $veilTable, ?array $allowedIds = null): string
    {
        $tableName = $veilTable->table();
        $columns = $veilTable->columns();
        $primaryKey = $this->getPrimaryKeyColumn($tableName);

        if (empty($columns)) {
            // No columns defined means remove all INSERT statements for this table
            // Pattern matches both formats: with and without column list
            $pattern = '/INSERT INTO [`"\']?' . preg_quote($tableName, '/') . '[`"\']?\s*(?:\([^)]+\))?\s*VALUES\s*.*?;/is';
            return preg_replace($pattern, '', $sql);
        }

        // Get the column names we want to export
        $exportColumnNames = array_keys($columns);

        // Find INSERT statements for this table and process them
        // Pattern matches both formats:
        // 1. INSERT INTO `table_name` (`col1`, `col2`, ...) VALUES (...), (...);
        // 2. INSERT INTO `table_name` VALUES (...), (...);
        $pattern = '/INSERT INTO [`"\']?' . preg_quote($tableName, '/') . '[`"\']?\s*(?:\(([^)]+)\))?\s*VALUES\s*(.*?);/is';

        return preg_replace_callback($pattern, function ($matches) use ($columns, $tableName, $exportColumnNames, $allowedIds, $primaryKey, $sql) {
            $columnList = $matches[1] ?? '';
            $valuesSection = $matches[2];

            // Parse column names from the original INSERT statement
            if (!empty($columnList)) {
                // Column list provided in INSERT statement
                preg_match_all('/[`"\']?(\w+)[`"\']?/', $columnList, $columnMatches);
                $originalColumnNames = $columnMatches[1];
            } else {
                // No column list - get column names from CREATE TABLE statement in SQL dump
                $originalColumnNames = $this->getColumnNamesFromCreateTable($sql, $tableName);
                
                // Fallback: try to get from database schema if available
                if (empty($originalColumnNames)) {
                    $originalColumnNames = $this->getTableColumnNames($tableName);
                }
            }

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
            $newValuesSection = $this->processValuesSection(
                $valuesSection, 
                $columnMapping, 
                $allowedIds,
                $originalColumnNames,
                $primaryKey
            );

            if (empty($newValuesSection)) {
                return '';
            }

            return "INSERT INTO `{$tableName}` ({$newColumnList}) VALUES {$newValuesSection};";
        }, $sql);
    }

    /**
     * Process the VALUES section, extracting only the columns we want and applying anonymization.
     *
     * @param array|null $allowedIds If provided, only rows with these IDs will be included
     * @param array $originalColumnNames Original column names from INSERT statement
     * @param string $primaryKey Primary key column name
     */
    protected function processValuesSection(
        string $valuesSection, 
        array $columnMapping, 
        ?array $allowedIds = null,
        array $originalColumnNames = [],
        string $primaryKey = 'id'
    ): string {
        // Match individual value groups: (val1, val2, val3)
        $pattern = '/\(([^)]+)\)/';

        $processedRows = [];

        preg_match_all($pattern, $valuesSection, $rowMatches);

        // Find the index of the primary key column
        $primaryKeyIndex = null;
        if ($allowedIds !== null && !empty($originalColumnNames)) {
            $primaryKeyIndex = array_search($primaryKey, $originalColumnNames);
        }

        foreach ($rowMatches[1] as $rowValues) {
            $values = Value::parse($rowValues);

            // Filter by allowed IDs if provided
            if ($allowedIds !== null && $primaryKeyIndex !== false && isset($values[$primaryKeyIndex])) {
                $rowId = Value::unformat($values[$primaryKeyIndex]);
                if (!in_array($rowId, $allowedIds)) {
                    continue; // Skip this row
                }
            }

            $newValues = [];

            // Build the row array with ALL column names as keys for callable access
            // This allows callables to access any column, not just the ones being exported
            $row = [];
            foreach ($originalColumnNames as $index => $columnName) {
                if (isset($values[$index])) {
                    $row[$columnName] = Value::unformat($values[$index]);
                }
            }

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
                } elseif (is_callable($anonymizedValue)) {
                    // Execute callable with original value and full row data
                    $result = $anonymizedValue(Value::unformat($originalValue), $row);
                    $newValues[] = Value::format($result);
                } else {
                    // Apply anonymization
                    $newValues[] = Value::format($anonymizedValue);
                }
            }

            if (!empty($newValues)) {
                $processedRows[] = '(' . implode(', ', $newValues) . ')';
            }
        }

        return implode(', ', $processedRows);
    }
}
