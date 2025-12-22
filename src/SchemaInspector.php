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

