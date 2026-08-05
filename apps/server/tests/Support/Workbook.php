<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Carbon;
use RuntimeException;
use ZipArchive;

/**
 * Builds real `.xlsx` workbooks for the import tests (technical spec 22.2).
 *
 * The alternative is committing binary sample workbooks, which nobody can read
 * in a diff and which quietly stop matching the case they were written for. A
 * workbook is a zip of XML parts, so a test can state the grid it means and get
 * a file Excel and Sheets would both open — including the parts that make a
 * spreadsheet different from a CSV, which are exactly the parts worth testing:
 * strings live in a shared table, empty cells are absent rather than empty,
 * deleted rows leave a gap in the row numbering, and a date is a number wearing
 * a format.
 *
 * Cells are plain strings unless one of the helpers below says otherwise, and a
 * `null` cell is omitted from the XML the way a spreadsheet omits an empty one.
 */
final class Workbook
{
    /**
     * Days from the 1900 workbook epoch. Excel counts a 29 February 1900 that
     * never happened, so the epoch is 1899-12-30 rather than 1899-12-31.
     */
    private const EPOCH = '1899-12-30 00:00:00';

    private const STYLE_GENERAL = 0;

    private const STYLE_BUILTIN_DATETIME = 1;

    private const STYLE_BUILTIN_TIME = 2;

    private const STYLE_CUSTOM_DATE = 3;

    /**
     * A date-formatted cell using a built-in number format, which is what a
     * spreadsheet produces when a date is typed into a cell.
     *
     * @return array{t: ?string, v: string, s: int}
     */
    public static function date(string $moment): array
    {
        return self::serial($moment, self::STYLE_BUILTIN_DATETIME);
    }

    /**
     * The same date wearing a workbook-defined format code rather than a
     * built-in id, which is what a shared template usually produces.
     *
     * @return array{t: ?string, v: string, s: int}
     */
    public static function customDate(string $moment): array
    {
        return self::serial($moment, self::STYLE_CUSTOM_DATE);
    }

    /**
     * A cell holding a time and no date at all, which a spreadsheet stores as
     * the fraction of a day on its own.
     *
     * @return array{t: ?string, v: string, s: int}
     */
    public static function time(string $clock): array
    {
        [$hours, $minutes, $seconds] = array_pad(array_map(intval(...), explode(':', $clock)), 3, 0);

        return [
            't' => null,
            'v' => (string) round(($hours * 3600 + $minutes * 60 + $seconds) / 86400, 10),
            's' => self::STYLE_BUILTIN_TIME,
        ];
    }

    /**
     * @return array{t: ?string, v: string, s: int}
     */
    public static function number(int|float $value): array
    {
        return ['t' => null, 'v' => (string) $value, 's' => self::STYLE_GENERAL];
    }

    /**
     * Text stored in the cell rather than in the shared string table.
     *
     * @return array{t: string, v: string, s: int}
     */
    public static function inline(string $text): array
    {
        return ['t' => 'inlineStr', 'v' => $text, 's' => self::STYLE_GENERAL];
    }

    /**
     * A formula that evaluated to an error, which is what a lookup against a
     * missing row leaves behind in a roster built with formulas.
     *
     * @return array{t: string, v: string, s: int}
     */
    public static function error(string $code = '#N/A'): array
    {
        return ['t' => 'e', 'v' => $code, 's' => self::STYLE_GENERAL];
    }

    /**
     * @param  list<list<array{t: ?string, v: string, s: int}|string|null>>  $grid  An empty row is one the operator emptied, and is absent from the file.
     */
    public static function of(array $grid): string
    {
        return self::ofSheets([$grid]);
    }

    /**
     * @param  list<list<list<array{t: ?string, v: string, s: int}|string|null>>>  $grids  One grid per sheet, in tab order.
     */
    public static function ofSheets(array $grids): string
    {
        $strings = [];
        $sheets = [];

        foreach ($grids as $grid) {
            $sheets[] = self::sheetXml($grid, $strings);
        }

        $parts = [
            '[Content_Types].xml' => self::contentTypesXml(count($sheets)),
            '_rels/.rels' => self::packageRelationshipsXml(),
            'xl/workbook.xml' => self::workbookXml(count($sheets)),
            'xl/_rels/workbook.xml.rels' => self::workbookRelationshipsXml(count($sheets)),
            'xl/styles.xml' => self::stylesXml(),
            'xl/sharedStrings.xml' => self::sharedStringsXml($strings),
        ];

        foreach ($sheets as $index => $xml) {
            $parts['xl/worksheets/sheet'.($index + 1).'.xml'] = $xml;
        }

        return self::zip($parts);
    }

    /**
     * A zip carrying none of the parts a workbook needs, for the case where an
     * operator uploads some other archive.
     */
    public static function notAWorkbook(): string
    {
        return self::zip(['readme.txt' => 'This is not a workbook.']);
    }

    /**
     * A workbook whose sheet declares a document type, which is how an XML
     * parser gets talked into reading the server it is running on.
     */
    public static function withDocumentType(): string
    {
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<!DOCTYPE worksheet [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&xxe;</t></is></c></row></sheetData>'
            .'</worksheet>';

        return self::zip([
            '[Content_Types].xml' => self::contentTypesXml(1),
            '_rels/.rels' => self::packageRelationshipsXml(),
            'xl/workbook.xml' => self::workbookXml(1),
            'xl/_rels/workbook.xml.rels' => self::workbookRelationshipsXml(1),
            'xl/worksheets/sheet1.xml' => $sheet,
        ]);
    }

    /**
     * @return array{t: ?string, v: string, s: int}
     */
    private static function serial(string $moment, int $style): array
    {
        $epoch = Carbon::parse(self::EPOCH, 'UTC');
        $value = Carbon::parse($moment, 'UTC');

        return [
            't' => null,
            'v' => (string) round(($value->getTimestamp() - $epoch->getTimestamp()) / 86400, 10),
            's' => $style,
        ];
    }

    /**
     * @param  list<list<array{t: ?string, v: string, s: int}|string|null>>  $grid
     * @param  list<string>  $strings
     */
    private static function sheetXml(array $grid, array &$strings): string
    {
        $rows = '';

        foreach ($grid as $index => $cells) {
            // An emptied row is not written at all, which is why the row
            // numbers a spreadsheet shows can have gaps in them.
            if ($cells === []) {
                continue;
            }

            $number = $index + 1;
            $rows .= '<row r="'.$number.'">';

            foreach ($cells as $column => $cell) {
                if ($cell === null) {
                    continue;
                }

                $rows .= self::cellXml(self::reference($column, $number), $cell, $strings);
            }

            $rows .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$rows.'</sheetData>'
            .'</worksheet>';
    }

    /**
     * @param  array{t: ?string, v: string, s: int}|string  $cell
     * @param  list<string>  $strings
     */
    private static function cellXml(string $reference, array|string $cell, array &$strings): string
    {
        if (is_string($cell)) {
            $index = array_search($cell, $strings, true);

            if ($index === false) {
                $strings[] = $cell;
                $index = count($strings) - 1;
            }

            return '<c r="'.$reference.'" t="s"><v>'.$index.'</v></c>';
        }

        $style = $cell['s'] === 0 ? '' : ' s="'.$cell['s'].'"';

        if ($cell['t'] === 'inlineStr') {
            return '<c r="'.$reference.'" t="inlineStr"'.$style.'><is><t>'.self::escape($cell['v']).'</t></is></c>';
        }

        $type = $cell['t'] === null ? '' : ' t="'.$cell['t'].'"';

        return '<c r="'.$reference.'"'.$type.$style.'><v>'.self::escape($cell['v']).'</v></c>';
    }

    private static function reference(int $column, int $row): string
    {
        $letters = '';

        for ($index = $column + 1; $index > 0; $index = intdiv($index - 1, 26)) {
            $letters = chr(ord('A') + (($index - 1) % 26)).$letters;
        }

        return $letters.$row;
    }

    /**
     * @param  list<string>  $strings
     */
    private static function sharedStringsXml(array $strings): string
    {
        $items = '';

        foreach ($strings as $string) {
            $items .= '<si><t>'.self::escape($string).'</t></si>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">'
            .$items
            .'</sst>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd&quot; at &quot;hh:mm"/></numFmts>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0"/>'      // General
            .'<xf numFmtId="22"/>'     // m/d/yy h:mm
            .'<xf numFmtId="21"/>'     // h:mm:ss
            .'<xf numFmtId="164"/>'    // the workbook's own date format
            .'</cellXfs>'
            .'</styleSheet>';
    }

    private static function workbookXml(int $sheets): string
    {
        $entries = '';

        for ($index = 1; $index <= $sheets; $index++) {
            $entries .= '<sheet name="Sheet'.$index.'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$entries.'</sheets>'
            .'</workbook>';
    }

    private static function workbookRelationshipsXml(int $sheets): string
    {
        $entries = '';

        for ($index = 1; $index <= $sheets; $index++) {
            $entries .= '<Relationship Id="rId'.$index.'"'
                .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                .' Target="worksheets/sheet'.$index.'.xml"/>';
        }

        $entries .= '<Relationship Id="rIdStyles"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            .' Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$entries
            .'</Relationships>';
    }

    private static function packageRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            .' Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function contentTypesXml(int $sheets): string
    {
        $overrides = '';

        for ($index = 1; $index <= $sheets; $index++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml"'
                .' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml"'
            .' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'</Types>';
    }

    /**
     * @param  array<string, string>  $parts
     */
    private static function zip(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'meridian-workbook-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary workbook.');
        }

        $archive = new ZipArchive;

        if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open a temporary workbook.');
        }

        foreach ($parts as $name => $contents) {
            $archive->addFromString($name, $contents);
        }

        $archive->close();

        $workbook = (string) file_get_contents($path);

        unlink($path);

        return $workbook;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
