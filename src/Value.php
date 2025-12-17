<?php

namespace SignDeck\Veil;

class Value
{
    /**
     * Parse comma-separated SQL values, respecting quoted strings.
     */
    public static function parse(string $valueString): array
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
     * Format a PHP value for SQL insertion.
     */
    public static function format(mixed $value): string
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

        $escaped = str_replace("'", "''", (string) $value);
        return "'{$escaped}'";
    }

    /**
     * Convert a SQL-formatted value back to a PHP value.
     */
    public static function unformat(string $sqlValue): mixed
    {
        $trimmed = trim($sqlValue);

        if (strtoupper($trimmed) === 'NULL') {
            return null;
        }

        if (
            (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'")) ||
            (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
        ) {
            $unquoted = substr($trimmed, 1, -1);
            $unquoted = str_replace("''", "'", $unquoted);
            $unquoted = str_replace('""', '"', $unquoted);
            $unquoted = stripcslashes($unquoted);
            return $unquoted;
        }

        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
        }

        return $trimmed;
    }
}

