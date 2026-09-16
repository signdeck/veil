<?php

namespace SignDeck\Veil;

class Value
{
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

        if (is_int($value) || is_float($value)) {
            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                return 'NULL';
            }

            return (string) $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value);

        return "'{$escaped}'";
    }
}
