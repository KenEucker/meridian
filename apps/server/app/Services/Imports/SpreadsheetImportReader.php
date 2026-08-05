<?php

declare(strict_types=1);

namespace App\Services\Imports;

use Illuminate\Support\Carbon;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

/**
 * Reduces an `.xlsx` workbook to the grid {@see ImportFileReader} shapes into
 * records (technical spec 22.2).
 *
 * An `.xlsx` file is a zip of XML parts, so this reads it with the zip and XML
 * support PHP already ships rather than adding a spreadsheet library to the
 * technology baseline for four import screens. The scope is deliberately the
 * scope of these files: one sheet of names, codes, numbers, and times. It does
 * not evaluate formulas — it reads the cached result Excel and Sheets both
 * store beside them — and it does not read the legacy binary `.xls` format or
 * OpenDocument. Anything it cannot read is refused whole with a message telling
 * the operator to export CSV, which always works.
 *
 * The **first sheet** is the imported one. A workbook whose roster is on the
 * second tab imports the first tab and reports what it found there, which is
 * visible in the preview; guessing which sheet was meant would be worse.
 *
 * A cell is transcribed as the spreadsheet displays it, and the import service
 * decides whether the value is usable. A date-formatted number becomes
 * `2026-08-28 09:00:00`, which {@see ImportMoment} then reads in the event's
 * timezone — the same reading an operator typing that text into a CSV gets,
 * which is the point. A time-formatted number with no date in it becomes
 * `09:00:00` rather than a date at the spreadsheet epoch, because inventing a
 * date the operator did not write is not the reader's decision to make.
 */
final class SpreadsheetImportReader
{
    /**
     * Refuse a part that inflates past this. These files are lists of names and
     * codes; a part larger than this is a compression bomb rather than a
     * roster, and the upload limit upstream cannot see past the compression.
     */
    private const MAX_PART_BYTES = 16 * 1024 * 1024;

    private const RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Built-in number format ids that display a date (ECMA-376 18.8.30).
     *
     * @var list<int>
     */
    private const BUILTIN_DATE_FORMATS = [
        14, 15, 16, 17, 22,
        27, 28, 29, 30, 31, 32, 33, 34, 35, 36,
        50, 51, 52, 53, 54, 55, 56, 57, 58,
    ];

    /**
     * Built-in number format ids that display a time and no date.
     *
     * @var list<int>
     */
    private const BUILTIN_TIME_FORMATS = [18, 19, 20, 21, 45, 46, 47];

    /**
     * Every zip archive starts with a local file header signature, and no CSV
     * an operator could produce starts with those four bytes. Detection reads
     * the content rather than the file name because a browser upload's name is
     * whatever it was renamed to on the way here.
     */
    public static function looksLikeWorkbook(string $contents): bool
    {
        return str_starts_with($contents, "PK\x03\x04");
    }

    /**
     * @return list<array{row: int, cells: list<string>}>
     *
     * @throws ImportException when the workbook cannot be read.
     */
    public static function grid(string $workbook): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ImportException('This server cannot read spreadsheet files. Export the sheet as CSV and import that instead.');
        }

        $path = tempnam(sys_get_temp_dir(), 'meridian-import-');

        if ($path === false) {
            throw new ImportException('Unable to open a buffer for the import file.');
        }

        $archive = new ZipArchive;

        try {
            file_put_contents($path, $workbook);

            if ($archive->open($path) !== true) {
                throw new ImportException('The spreadsheet could not be opened. Save it as .xlsx, or export the sheet as CSV.');
            }

            return self::readSheet($archive);
        } finally {
            @$archive->close();
            @unlink($path);
        }
    }

    /**
     * @return list<array{row: int, cells: list<string>}>
     *
     * @throws ImportException
     */
    private static function readSheet(ZipArchive $archive): array
    {
        $workbook = self::xml($archive, 'xl/workbook.xml');

        if ($workbook === null) {
            throw new ImportException('That file is not a spreadsheet Meridian can read. Save it as .xlsx, or export the sheet as CSV.');
        }

        $sheet = self::xml($archive, self::firstSheetPath($archive, $workbook));

        if ($sheet === null) {
            throw new ImportException('The spreadsheet has no readable sheet in it.');
        }

        $strings = self::sharedStrings($archive);
        $formats = self::cellFormats($archive);
        $epoch = self::epoch($workbook);

        $grid = [];
        $implicit = 0;

        foreach ($sheet->sheetData->row as $row) {
            $number = (int) ($row['r'] ?? 0);
            $implicit = $number > 0 ? $number : $implicit + 1;

            $cells = [];

            foreach ($row->c as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $index = $reference === ''
                    ? (count($cells) === 0 ? 0 : max(array_keys($cells)) + 1)
                    : self::columnIndex($reference);

                $cells[$index] = self::value($cell, $strings, $formats, $epoch);
            }

            $grid[] = [
                'row' => $implicit,
                'cells' => self::dense($cells),
            ];
        }

        return $grid;
    }

    /**
     * A workbook omits empty cells entirely, so the grid is rebuilt from the
     * column references. Without this a row whose middle cell is blank would
     * shift every value after it into the wrong column.
     *
     * @param  array<int, string>  $cells
     * @return list<string>
     */
    private static function dense(array $cells): array
    {
        if ($cells === []) {
            return [];
        }

        ksort($cells);

        $dense = [];

        for ($index = 0; $index <= max(array_keys($cells)); $index++) {
            $dense[] = $cells[$index] ?? '';
        }

        return $dense;
    }

    /**
     * `AB7` names column 27, counting from zero.
     */
    private static function columnIndex(string $reference): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $reference));

        if ($letters === '') {
            return 0;
        }

        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - ord('A') + 1);
        }

        return $index - 1;
    }

    /**
     * @param  list<string>  $strings
     * @param  array<int, string>  $formats  Cell format index to `date`, `time`, or `number`.
     */
    private static function value(
        SimpleXMLElement $cell,
        array $strings,
        array $formats,
        Carbon $epoch,
    ): string {
        $type = (string) ($cell['t'] ?? 'n');

        if ($type === 'inlineStr') {
            return self::text($cell->is);
        }

        if ($type === 's') {
            return $strings[(int) $cell->v] ?? '';
        }

        $raw = (string) $cell->v;

        if ($type === 'b') {
            return $raw === '1' ? 'true' : 'false';
        }

        // A formula's cached string result, or a formula that evaluated to an
        // error. The error text (`#N/A`, `#REF!`) is carried through as written
        // rather than blanked, so the row is refused with the operator's own
        // broken cell in the reason instead of looking merely empty.
        if ($type === 'str' || $type === 'e') {
            return $raw;
        }

        if ($raw === '' || ! is_numeric($raw)) {
            return $raw;
        }

        $format = $formats[(int) ($cell['s'] ?? 0)] ?? 'number';

        if ($format === 'number') {
            return $raw;
        }

        return self::moment((float) $raw, $epoch, $format === 'time');
    }

    /**
     * A serial number is a count of days from the workbook's epoch, with the
     * fraction carrying the time of day.
     */
    private static function moment(float $serial, Carbon $epoch, bool $timeOnly): string
    {
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);

        $moment = $epoch->copy()->addDays($days)->addSeconds($seconds);

        if ($timeOnly) {
            return $moment->format('H:i:s');
        }

        return $seconds === 0
            ? $moment->format('Y-m-d')
            : $moment->format('Y-m-d H:i:s');
    }

    /**
     * The 1900 workbook epoch is 1899-12-30 rather than 1899-12-31 because
     * Excel counts a 29 February 1900 that never happened; serials below that
     * phantom day are one lower, and Meridian's files are modern dates well
     * above it either way. A workbook saved in the 1904 system says so.
     */
    private static function epoch(SimpleXMLElement $workbook): Carbon
    {
        $date1904 = (string) ($workbook->workbookPr['date1904'] ?? '');

        if ($date1904 === '1' || $date1904 === 'true') {
            return Carbon::create(1904, 1, 1, 0, 0, 0, 'UTC');
        }

        return Carbon::create(1899, 12, 30, 0, 0, 0, 'UTC');
    }

    /**
     * @throws ImportException
     */
    private static function firstSheetPath(ZipArchive $archive, SimpleXMLElement $workbook): string
    {
        $sheet = $workbook->sheets->sheet[0] ?? null;

        if ($sheet === null) {
            throw new ImportException('The spreadsheet has no sheets in it.');
        }

        $id = (string) ($sheet->attributes(self::RELATIONSHIPS_NAMESPACE)['id'] ?? '');
        $relationships = self::xml($archive, 'xl/_rels/workbook.xml.rels');

        if ($id !== '' && $relationships !== null) {
            foreach ($relationships->Relationship as $relationship) {
                if ((string) $relationship['Id'] !== $id) {
                    continue;
                }

                $target = (string) $relationship['Target'];

                return str_starts_with($target, '/')
                    ? ltrim($target, '/')
                    : 'xl/'.ltrim($target, './');
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $archive): array
    {
        $xml = self::xml($archive, 'xl/sharedStrings.xml');

        if ($xml === null) {
            return [];
        }

        $strings = [];

        foreach ($xml->si as $item) {
            $strings[] = self::text($item);
        }

        return $strings;
    }

    /**
     * Maps each cell format index to what it displays, so a number can be told
     * apart from the same number wearing a date format.
     *
     * @return array<int, string> `date`, `time`, or `number`.
     */
    private static function cellFormats(ZipArchive $archive): array
    {
        $xml = self::xml($archive, 'xl/styles.xml');

        if ($xml === null) {
            return [];
        }

        $custom = [];

        foreach ($xml->numFmts->numFmt ?? [] as $numFmt) {
            $custom[(int) $numFmt['numFmtId']] = (string) $numFmt['formatCode'];
        }

        $formats = [];
        $index = 0;

        foreach ($xml->cellXfs->xf ?? [] as $xf) {
            $id = (int) ($xf['numFmtId'] ?? 0);

            $formats[$index++] = isset($custom[$id])
                ? self::classifyFormatCode($custom[$id])
                : self::classifyBuiltinFormat($id);
        }

        return $formats;
    }

    private static function classifyBuiltinFormat(int $id): string
    {
        if (in_array($id, self::BUILTIN_DATE_FORMATS, true)) {
            return 'date';
        }

        return in_array($id, self::BUILTIN_TIME_FORMATS, true) ? 'time' : 'number';
    }

    /**
     * Quoted literals and `[...]` sections are stripped first, because a
     * currency format carrying the word "days" is not a date format.
     */
    private static function classifyFormatCode(string $code): string
    {
        $code = (string) preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code);
        $code = strtolower($code);

        $hasDate = str_contains($code, 'y') || str_contains($code, 'd');
        $hasTime = str_contains($code, 'h') || str_contains($code, 's');

        if ($hasDate) {
            return 'date';
        }

        if ($hasTime) {
            return 'time';
        }

        // A lone `m` is minutes next to an `h` and months otherwise, and both
        // have been ruled out above, so a format that is only `mm` is a month.
        return str_contains($code, 'm') ? 'date' : 'number';
    }

    /**
     * A shared string or an inline string may be split into runs by formatting,
     * so the text is every `<t>` under the element joined back together.
     */
    private static function text(?SimpleXMLElement $element): string
    {
        if ($element === null) {
            return '';
        }

        $text = (string) $element->t;

        foreach ($element->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /**
     * @throws ImportException when a part is present but too large or malformed.
     */
    private static function xml(ZipArchive $archive, string $name): ?SimpleXMLElement
    {
        $stat = $archive->statName($name);

        if ($stat === false) {
            return null;
        }

        if (($stat['size'] ?? 0) > self::MAX_PART_BYTES) {
            throw new ImportException('The spreadsheet is too large to read. Export the sheet as CSV and import that instead.');
        }

        $contents = $archive->getFromName($name);

        if ($contents === false) {
            return null;
        }

        // A workbook has no legitimate reason to declare a document type, and
        // an XML parser that follows one is how a file gets to read the server
        // it was uploaded to. Refuse rather than parse.
        if (stripos($contents, '<!DOCTYPE') !== false) {
            throw new ImportException('The spreadsheet contains XML Meridian will not read. Export the sheet as CSV and import that instead.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($contents, options: LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable) {
            $xml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new ImportException('The spreadsheet is damaged and could not be read. Export the sheet as CSV and import that instead.');
        }

        return $xml;
    }
}
