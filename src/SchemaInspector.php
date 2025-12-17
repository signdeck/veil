<?php

namespace SignDeck\Veil;

use Illuminate\Support\Facades\DB;

/**
 * Inspects database schema to retrieve table metadata.
 */
class SchemaInspector
{
    /**
     * Get the primary key column name for a table.
     */
    public function getPrimaryKeyColumn(string $tableName): string
    {
        try {
            $connection = DB::connection($this->getConnectionName());
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
     * Get all column names for a table in their natural order from database schema.
     */
    public function getTableColumnNames(string $tableName): array
    {
        try {
            $connection = DB::connection($this->getConnectionName());
            $schema = $connection->getDoctrineSchemaManager();
            $table = $schema->listTableDetails($tableName);

            $columnNames = array_keys($table->getColumns());

            if (!empty($columnNames)) {
                return $columnNames;
            }
        } catch (\Exception $e) {
            // Fallback: try to get columns from information schema
            try {
                $dbName = DB::connection($this->getConnectionName())->getDatabaseName();
                $columns = DB::connection($this->getConnectionName())->select(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND TABLE_SCHEMA = ? ORDER BY ORDINAL_POSITION",
                    [$tableName, $dbName]
                );
                $columnNames = array_map(fn ($col) => $col->COLUMN_NAME, $columns);

                if (!empty($columnNames)) {
                    return $columnNames;
                }
            } catch (\Exception $e) {
                // If all else fails, return empty array
            }
        }

        return [];
    }

    /**
     * Get column names from CREATE TABLE statement in SQL dump.
     */
    public function getColumnNamesFromCreateTable(string $sql, string $tableName): array
    {
        // Use a robust approach: find the opening paren, then find the matching closing paren
        $tablePattern = '/CREATE\s+TABLE\s+[`"\']?' . preg_quote($tableName, '/') . '[`"\']?\s*\(/is';

        if (preg_match($tablePattern, $sql, $startMatches, PREG_OFFSET_CAPTURE)) {
            $startPos = $startMatches[0][1] + strlen($startMatches[0][0]) - 1;
            $depth = 0;
            $endPos = $startPos;

            for ($i = $startPos; $i < strlen($sql); $i++) {
                if ($sql[$i] === '(') {
                    $depth++;
                } elseif ($sql[$i] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $endPos = $i;
                        break;
                    }
                }
            }

            if ($endPos > $startPos) {
                $tableDefinition = substr($sql, $startPos + 1, $endPos - $startPos - 1);

                return $this->parseColumnDefinitions($tableDefinition);
            }
        }

        return [];
    }

    /**
     * Parse column definitions from a CREATE TABLE body.
     */
    protected function parseColumnDefinitions(string $tableDefinition): array
    {
        $columnNames = [];
        $lines = [];
        $current = '';
        $depth = 0;

        // Split by commas, tracking parentheses depth
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
            if (preg_match('/^[`"\']?(\w+)[`"\']?/i', $line, $colMatch)) {
                $columnNames[] = $colMatch[1];
            }
        }

        return $columnNames;
    }

    /**
     * Get the row count for a table.
     */
    public function getTableRowCount(string $tableName, ?array $allowedIds = null): int
    {
        if ($allowedIds !== null) {
            return count($allowedIds);
        }

        try {
            return DB::connection($this->getConnectionName())->table($tableName)->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get the configured database connection name.
     */
    protected function getConnectionName(): string
    {
        return config('veil.connection') ?? config('database.default');
    }
}

