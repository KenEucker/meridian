<?php

declare(strict_types=1);

namespace App\Services\Imports;

use Illuminate\Support\Str;

/**
 * Reads the CSV files the Alpha 1 Orchid / God Mode imports accept (technical
 * spec 22.2).
 *
 * Parsing goes through `fgetcsv` rather than splitting on newlines, so a quoted
 * description containing a comma or a line break survives the round trip from a
 * spreadsheet export.
 *
 * Header names are normalized (`Department Code`, `department code`, and
 * `DEPARTMENT_CODE` all resolve to `department_code`), because operators export
 * these files from whatever spreadsheet tool they already use and column
 * casing is not something worth failing an import over. Column *order* does not
 * matter and unknown columns are ignored, so a file carrying extra bookkeeping
 * columns still imports.
 */
final class CsvImportReader
{
    /**
     * @param  list<string>  $requiredColumns  Normalized header names the file must carry.
     * @return list<array{row: int, values: array<string, string>}> Row numbers count the header as row 1.
     *
     * @throws ImportException when the file is empty or missing a required column.
     */
    public static function read(string $csv, array $requiredColumns): array
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new ImportException('Unable to open a buffer for the import file.');
        }

        try {
            fwrite($handle, self::withoutByteOrderMark($csv));
            rewind($handle);

            $header = fgetcsv($handle, escape: '');

            if (! is_array($header)) {
                throw new ImportException('The CSV file is empty.');
            }

            $header = array_map(
                static fn (mixed $column): string => Str::snake(Str::lower(trim((string) $column))),
                $header,
            );

            $missing = array_values(array_diff($requiredColumns, $header));

            if ($missing !== []) {
                throw new ImportException(sprintf(
                    'The CSV file must include a "%s" header column.',
                    implode('", "', $missing),
                ));
            }

            $rows = [];
            $number = 1;

            while (($record = fgetcsv($handle, escape: '')) !== false) {
                $number++;

                if (! is_array($record) || self::isBlank($record)) {
                    continue;
                }

                $values = [];

                foreach ($header as $index => $column) {
                    if ($column === '') {
                        continue;
                    }

                    $values[$column] = trim((string) ($record[$index] ?? ''));
                }

                $rows[] = [
                    'row' => $number,
                    'values' => $values,
                ];
            }

            if ($rows === []) {
                throw new ImportException('The CSV file has a header row but no data rows.');
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Spreadsheet tools commonly write a UTF-8 BOM, which would otherwise
     * become part of the first header name and hide a required column.
     */
    private static function withoutByteOrderMark(string $csv): string
    {
        return str_starts_with($csv, "\u{FEFF}") ? substr($csv, 3) : $csv;
    }

    /**
     * @param  array<int, mixed>  $record
     */
    private static function isBlank(array $record): bool
    {
        foreach ($record as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
