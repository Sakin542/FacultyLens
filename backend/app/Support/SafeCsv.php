<?php

namespace App\Support;

/**
 * CSV writer for admin exports. Cells that a spreadsheet would evaluate as a formula (=, +, -, @, tab, CR) are
 * prefixed with an apostrophe so an exported value can never execute when the file is opened in Excel/Sheets.
 */
class SafeCsv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int|string, mixed>>  $rows
     */
    public static function build(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_map([self::class, 'cell'], $header), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'cell'], array_values($row)), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $value = (string) $value;
        if ($value !== '' && is_numeric($value)) {
            return $value;
        }

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * Parse an uploaded CSV into associative rows keyed by the lower-cased header.
     *
     * @return array{header: list<string>, rows: list<array<string, string>>}
     */
    public static function parse(string $contents, int $maxRows): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $in = fopen('php://temp', 'r+');
        fwrite($in, $contents);
        rewind($in);
        $first = fgetcsv($in, null, ',', '"', '');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $first === false ? [] : $first);
        $rows = [];
        while (($values = fgetcsv($in, null, ',', '"', '')) !== false) {
            if ($values === [null] || implode('', array_map('strval', $values)) === '') {
                continue;
            }
            if (count($rows) >= $maxRows) {
                break;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = trim((string) ($values[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($in);

        return ['header' => $header, 'rows' => $rows];
    }
}
