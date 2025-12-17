<?php

namespace SignDeck\Veil;

/**
 * Handles row-level data anonymization.
 */
class RowAnonymizer
{
    /**
     * Anonymize a single row's values based on the column mapping.
     *
     * @param  array  $rowValues  The original row values
     * @param  array  $columnMapping  Mapping of column names to their indices and anonymization values
     * @param  array  $row  Full row data as associative array (for callable access)
     * @return array The anonymized values
     */
    public function anonymizeRow(array $rowValues, array $columnMapping, array $row = []): array
    {
        $newValues = [];

        foreach ($columnMapping as $columnName => $mapping) {
            $originalIndex = $mapping['originalIndex'];
            $anonymizedValue = $mapping['value'];
            $originalValue = $rowValues[$originalIndex] ?? null;

            // Check if original value is NULL (parser returns 'NULL' as string or null)
            $isNull = ($originalValue === null ||
                      (is_string($originalValue) && strtoupper(trim($originalValue)) === 'NULL'));

            if ($isNull) {
                $newValues[] = 'NULL';
            } elseif ($anonymizedValue instanceof AsIs) {
                $newValues[] = $this->formatSqlValue($originalValue);
            } elseif (is_callable($anonymizedValue)) {
                // Execute callable with original value and full row data
                $unformattedValue = Value::unformat($originalValue);
                $result = $anonymizedValue($unformattedValue, $row);
                $newValues[] = Value::format($result);
            } else {
                // Apply anonymization
                $newValues[] = Value::format($anonymizedValue);
            }
        }

        return $newValues;
    }

    /**
     * Format a SQL value, preserving its original format.
     */
    public function formatSqlValue(string $value): string
    {
        $trimmed = trim($value);

        if (strlen($trimmed) >= 2) {
            $first = $trimmed[0];
            $last = substr($trimmed, -1);

            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                return $trimmed;
            }
        }

        return Value::format($trimmed);
    }
}

