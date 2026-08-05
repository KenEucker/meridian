<?php

declare(strict_types=1);

namespace App\Services\Imports;

use Illuminate\Support\Str;

/**
 * Reads the files the Alpha 1 Orchid / God Mode imports accept (technical
 * spec 22.2), whichever of the two shapes they arrive in.
 *
 * An operator builds a roster in a spreadsheet, so the file they have in hand
 * is a workbook. Making them re-export it as CSV first is a step that exists
 * only because the reader used to, and it is the step where the wrong sheet or
 * the wrong delimiter gets chosen. A `.xlsx` and a `.csv` of the same table
 * import identically here: the format is detected from the bytes rather than
 * from the file name, because a browser upload's name is whatever the operator
 * renamed it to.
 *
 * Both formats reduce to the same grid before anything else happens, so header
 * handling, required columns, blank rows, and row numbering are decided once
 * and behave the same either way. Header names are normalized (`Department
 * Code`, `department code`, and `DEPARTMENT_CODE` all resolve to
 * `department_code`), because operators export these files from whatever tool
 * they already use and column casing is not something worth failing an import
 * over. Column *order* does not matter and unknown columns are ignored, so a
 * file carrying extra bookkeeping columns still imports.
 */
final class ImportFileReader
{
    /**
     * @param  list<string>  $requiredColumns  Normalized header names the file must carry.
     * @return list<array{row: int, values: array<string, string>}> Row numbers are the ones the operator sees in their own file.
     *
     * @throws ImportException when the file is unreadable, empty, or missing a required column.
     */
    public static function read(string $contents, array $requiredColumns): array
    {
        // A legacy `.xls` is a compound binary file rather than a zip, and
        // nothing here can read one. Saying so is the point: read as CSV it
        // would be refused for a missing header column, which sends an
        // operator looking for a column that is in the file they are staring
        // at.
        if (str_starts_with($contents, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw new ImportException('That file is a legacy .xls workbook. Save it as .xlsx, or export the sheet as CSV.');
        }

        if (SpreadsheetImportReader::looksLikeWorkbook($contents)) {
            return self::shape(
                SpreadsheetImportReader::grid($contents),
                $requiredColumns,
                'The spreadsheet',
            );
        }

        return self::shape(
            CsvImportReader::grid($contents),
            $requiredColumns,
            'The CSV file',
        );
    }

    /**
     * @param  list<array{row: int, cells: list<string>}>  $grid
     * @param  list<string>  $requiredColumns
     * @param  string  $subject  How error messages name the file, so an operator who uploaded a workbook is not told about CSV.
     * @return list<array{row: int, values: array<string, string>}>
     *
     * @throws ImportException
     */
    private static function shape(array $grid, array $requiredColumns, string $subject): array
    {
        $first = array_shift($grid);

        if ($first === null) {
            throw new ImportException(sprintf('%s is empty.', $subject));
        }

        $header = array_map(
            static fn (string $column): string => Str::snake(Str::lower(trim($column))),
            $first['cells'],
        );

        $missing = array_values(array_diff($requiredColumns, $header));

        if ($missing !== []) {
            throw new ImportException(sprintf(
                '%s must include a "%s" header column.',
                $subject,
                implode('", "', $missing),
            ));
        }

        $records = [];

        foreach ($grid as $row) {
            if (self::isBlank($row['cells'])) {
                continue;
            }

            $values = [];

            foreach ($header as $index => $column) {
                if ($column === '') {
                    continue;
                }

                // A row that stops short of the last column is the same as one
                // whose trailing cells are empty, in both formats: the header
                // decides which columns the file carries, and a missing cell
                // within a row is an empty value rather than an absent column.
                $values[$column] = trim($row['cells'][$index] ?? '');
            }

            $records[] = [
                'row' => $row['row'],
                'values' => $values,
            ];
        }

        if ($records === []) {
            throw new ImportException(sprintf('%s has a header row but no data rows.', $subject));
        }

        return $records;
    }

    /**
     * @param  list<string>  $cells
     */
    private static function isBlank(array $cells): bool
    {
        foreach ($cells as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}
