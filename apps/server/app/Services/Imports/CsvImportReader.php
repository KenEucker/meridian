<?php

declare(strict_types=1);

namespace App\Services\Imports;

/**
 * Reduces a CSV file to the grid {@see ImportFileReader} shapes into records
 * (technical spec 22.2).
 *
 * Parsing goes through `fgetcsv` rather than splitting on newlines, so a quoted
 * description containing a comma or a line break survives the round trip from a
 * spreadsheet export.
 *
 * Row numbers count the header as row 1, which is what a spreadsheet shows the
 * operator, so a reported row can be opened without counting lines.
 */
final class CsvImportReader
{
    /**
     * @return list<array{row: int, cells: list<string>}>
     *
     * @throws ImportException when the file cannot be read at all.
     */
    public static function grid(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new ImportException('Unable to open a buffer for the import file.');
        }

        try {
            fwrite($handle, self::withoutByteOrderMark($csv));
            rewind($handle);

            $grid = [];
            $number = 0;

            while (($record = fgetcsv($handle, escape: '')) !== false) {
                $number++;

                if (! is_array($record)) {
                    continue;
                }

                $grid[] = [
                    'row' => $number,
                    'cells' => array_map(
                        static fn (mixed $cell): string => (string) $cell,
                        array_values($record),
                    ),
                ];
            }

            return $grid;
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
}
