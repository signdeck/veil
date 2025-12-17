<?php

namespace SignDeck\Veil;

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use SignDeck\Veil\Contracts\VeilTable;

/**
 * Processes SQL dumps for data anonymization.
 */
class SqlProcessor
{
    public function __construct(
        protected SchemaInspector $schemaInspector,
        protected RowAnonymizer $rowAnonymizer,
        protected ?VeilProgressBar $progressBar = null,
    ) {}

    /**
     * Set the progress bar for tracking processing.
     */
    public function setProgressBar(VeilProgressBar $progressBar): self
    {
        $this->progressBar = $progressBar;

        return $this;
    }

    /**
     * Process a specific table's data in the SQL dump.
     * Only exports columns defined in VeilTable::columns() and applies anonymization.
     *
     * @param  array|null  $allowedIds  If provided, only rows with these IDs will be included
     */
    public function processTableInSql(string $sql, VeilTable $veilTable, ?array $allowedIds = null): string
    {
        $tableName = $veilTable->table();
        $columns = $veilTable->columns();
        $primaryKey = $this->schemaInspector->getPrimaryKeyColumn($tableName);

        if (empty($columns)) {
            return $this->removeInsertStatementsForTable($sql, $tableName);
        }

        $exportColumnNames = array_keys($columns);

        // Pre-fetch column names from database schema for INSERT statements without column lists
        $defaultColumnNames = $this->schemaInspector->getTableColumnNames($tableName);
        $createTableColumnNames = empty($defaultColumnNames)
            ? $this->schemaInspector->getColumnNamesFromCreateTable($sql, $tableName)
            : [];

        // Use regex to find INSERT statements for our table (memory-efficient)
        $pattern = '/INSERT\s+INTO\s+[`"\']?' . preg_quote($tableName, '/') . '[`"\']?\s*(?:\([^)]+\))?\s*VALUES\s*/is';

        $offset = 0;
        $result = $sql;
        $replacements = [];

        while (preg_match($pattern, $result, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $matchLength = strlen($matches[0][0]);
            $valuesStart = $matchStart + $matchLength;

            // Extract VALUES section manually to handle very long sections
            $valuesSection = $this->extractValuesSection($result, $valuesStart);

            if ($valuesSection === null) {
                $offset = $valuesStart + 100;

                continue;
            }

            $valuesEnd = $valuesStart + strlen($valuesSection);
            $fullInsertEnd = $valuesEnd + 1;
            $fullInsert = substr($result, $matchStart, $fullInsertEnd - $matchStart);

            try {
                $insertParser = new Parser($fullInsert);
                $insertStatement = $insertParser->statements[0] ?? null;

                if ($insertStatement instanceof InsertStatement) {
                    $processedInsert = $this->processInsertStatement(
                        $insertStatement,
                        $tableName,
                        $columns,
                        $exportColumnNames,
                        $allowedIds,
                        $primaryKey,
                        $defaultColumnNames,
                        $createTableColumnNames
                    );

                    $replacements[] = [
                        'start' => $matchStart,
                        'end' => $fullInsertEnd,
                        'replacement' => $processedInsert ?? '',
                    ];
                }
            } catch (\Exception $e) {
                // If parsing fails, skip this INSERT
            }

            $offset = $fullInsertEnd;
        }

        // Apply replacements in reverse order to preserve offsets
        foreach (array_reverse($replacements) as $replacement) {
            $result = substr_replace(
                $result,
                $replacement['replacement'],
                $replacement['start'],
                $replacement['end'] - $replacement['start']
            );
        }

        return $result;
    }

    /**
     * Remove all INSERT statements for a specific table (memory-efficient approach).
     */
    public function removeInsertStatementsForTable(string $sql, string $tableName): string
    {
        $pattern = '/INSERT\s+INTO\s+[`"\']?' . preg_quote($tableName, '/') . '[`"\']?\s*(?:\([^)]+\))?\s*VALUES\s*/is';

        $offset = 0;
        $result = $sql;
        $replacements = [];

        while (preg_match($pattern, $result, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $matchLength = strlen($matches[0][0]);
            $valuesStart = $matchStart + $matchLength;

            $valuesSection = $this->extractValuesSection($result, $valuesStart);

            if ($valuesSection === null) {
                $offset = $valuesStart + 100;

                continue;
            }

            $valuesEnd = $valuesStart + strlen($valuesSection);
            $fullInsertEnd = $valuesEnd + 1;

            $replacements[] = [
                'start' => $matchStart,
                'end' => $fullInsertEnd,
                'replacement' => '',
            ];

            $offset = $fullInsertEnd;
        }

        foreach (array_reverse($replacements) as $replacement) {
            $result = substr_replace(
                $result,
                $replacement['replacement'],
                $replacement['start'],
                $replacement['end'] - $replacement['start']
            );
        }

        return $result;
    }

    /**
     * Process a single INSERT statement and return the anonymized SQL or null if no rows to include.
     */
    protected function processInsertStatement(
        InsertStatement $statement,
        string $tableName,
        array $columns,
        array $exportColumnNames,
        ?array $allowedIds,
        string $primaryKey,
        array $defaultColumnNames,
        array $createTableColumnNames
    ): ?string {
        $originalColumnNames = $this->extractColumnNamesFromStatement($statement, $defaultColumnNames, $createTableColumnNames);

        if (empty($originalColumnNames)) {
            return null;
        }

        $columnMapping = $this->buildColumnMapping($exportColumnNames, $originalColumnNames, $columns);

        if (empty($columnMapping)) {
            return null;
        }

        $processedRows = [];

        foreach ($statement->values as $valueArray) {
            $rowValues = $valueArray->values ?? [];

            $this->progressBar?->advance();

            // Build full row data for callable access
            $row = [];
            foreach ($originalColumnNames as $index => $colName) {
                $row[$colName] = $rowValues[$index] ?? null;
            }

            // Check if row should be included based on allowedIds
            if ($allowedIds !== null) {
                $rowId = $row[$primaryKey] ?? null;
                if ($rowId === null || ! in_array($rowId, $allowedIds)) {
                    continue;
                }
            }

            $newValues = $this->rowAnonymizer->anonymizeRow($rowValues, $columnMapping, $row);

            if (! empty($newValues)) {
                $processedRows[] = '(' . implode(', ', $newValues) . ')';
            }
        }

        if (empty($processedRows)) {
            return null;
        }

        $newColumnList = implode(', ', array_map(
            fn ($col) => "`{$col}`",
            array_keys($columnMapping)
        ));

        $valuesSection = implode(', ', $processedRows);

        return "INSERT INTO `{$tableName}` ({$newColumnList}) VALUES {$valuesSection};";
    }

    /**
     * Extract column names from an INSERT statement.
     */
    protected function extractColumnNamesFromStatement(
        InsertStatement $statement,
        array $defaultColumnNames,
        array $createTableColumnNames
    ): array {
        if (! empty($statement->into->columns)) {
            $columnNames = [];
            foreach ($statement->into->columns as $column) {
                if (is_string($column)) {
                    $columnName = $column;
                } elseif (is_object($column)) {
                    $columnName = $column->column ?? $column->expr ?? null;
                } else {
                    $columnName = null;
                }

                if ($columnName) {
                    $columnNames[] = trim($columnName, '`"\'');
                }
            }

            return $columnNames;
        }

        return ! empty($defaultColumnNames) ? $defaultColumnNames : $createTableColumnNames;
    }

    /**
     * Build a mapping of column names to their original indices and anonymization values.
     */
    protected function buildColumnMapping(array $exportColumnNames, array $originalColumnNames, array $columns): array
    {
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

        return $columnMapping;
    }

    /**
     * Manually extract the VALUES section from an INSERT statement.
     */
    public function extractValuesSection(string $sql, int $startOffset): ?string
    {
        $length = strlen($sql);
        $pos = $startOffset;
        $depth = 0;
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $escaped = false;
        $start = $pos;

        while ($pos < $length) {
            $char = $sql[$pos];

            if ($escaped) {
                $escaped = false;
                $pos++;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                $pos++;

                continue;
            }

            if ($char === "'" && ! $inDoubleQuote && ! $inBacktick) {
                $inSingleQuote = ! $inSingleQuote;
            } elseif ($char === '"' && ! $inSingleQuote && ! $inBacktick) {
                $inDoubleQuote = ! $inDoubleQuote;
            } elseif ($char === '`' && ! $inSingleQuote && ! $inDoubleQuote) {
                $inBacktick = ! $inBacktick;
            }

            if (! $inSingleQuote && ! $inDoubleQuote && ! $inBacktick) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ';' && $depth === 0) {
                    return substr($sql, $start, $pos - $start);
                }
            }

            $pos++;
        }

        return null;
    }
}

