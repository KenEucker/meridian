<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Department;
use App\Models\Event;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds the file an export hands back: the CSV bytes and the download name
 * (technical spec 22.2).
 *
 * Both are part of the contract committed sample fixtures pin, so a spreadsheet
 * built against one export keeps working against the next, and every report
 * names its file the same way: report, event, optional department, timestamp.
 */
final class ReportingExportFile
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, string>>  $rows
     */
    public static function csv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open a buffer for the reporting export.');
        }

        fputcsv($handle, $columns, escape: '');

        foreach ($rows as $row) {
            fputcsv(
                $handle,
                array_map(static fn (string $column): string => $row[$column] ?? '', $columns),
                escape: '',
            );
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * @param  string  $report  Slug naming the report, such as `shift-roster`.
     * @param  string  $timestamp  Export moment formatted as `Ymd-His`.
     */
    public static function filename(
        string $report,
        Event $event,
        ReportingExportScope $scope,
        string $timestamp,
    ): string {
        $parts = [$report, Str::slug((string) ($event->slug ?: $event->name)) ?: 'event'];

        if (count($scope->departmentIds) === 1) {
            $department = Department::query()->find($scope->departmentIds[0]);
            $label = Str::slug((string) ($department?->code ?: $department?->name));

            if ($label !== '') {
                $parts[] = $label;
            }
        }

        $parts[] = $timestamp;

        return implode('-', $parts).'.csv';
    }
}
