<?php

namespace SignDeck\Veil;

/**
 * Handles row-level data anonymization.
 */
class RowAnonymizer
{
    /**
     * Anonymize a single row's values based on the column definitions.
     *
     * @param  array  $row  Associative array of column names to PHP values (from query builder)
     * @param  array  $columns  Column definitions from VeilTable::columns()
     * @return array SQL-formatted values for the INSERT statement
     */
    public function anonymizeRow(array $row, array $columns): array
    {
        $values = [];

        foreach ($columns as $columnName => $anonymizedValue) {
            $original = $row[$columnName] ?? null;

            if ($original === null) {
                $values[] = 'NULL';
            } elseif ($anonymizedValue instanceof AsIs) {
                $values[] = Value::format($original);
            } elseif (is_callable($anonymizedValue)) {
                $result = $anonymizedValue($original, $row);
                $values[] = Value::format($result);
            } else {
                $values[] = Value::format($anonymizedValue);
            }
        }

        return $values;
    }
}
