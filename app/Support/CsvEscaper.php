<?php

namespace App\Support;

/**
 * Neutralizes spreadsheet formula injection in exported CSV cells
 * (spec §15). Numeric values are left untouched.
 */
final class CsvEscaper
{
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r", '|', '%'], true) && ! is_numeric($text)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * @param  list<mixed>  $row
     */
    public static function line(array $row): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_map(self::cell(...), $row), escape: '');
        rewind($handle);
        $line = stream_get_contents($handle);
        fclose($handle);

        return $line;
    }
}
