<?php

namespace App\Services\NameReferences;

use App\Models\FieldReport;
use App\Models\FieldReportAppend;
use App\Models\NameReferenceToken;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Maintains the rebuildable derived Name Reference index for Field Reports
 * and appends (NR-003, NR-004, NR-007, NR-011 through NR-014; technical
 * spec 17.7; data/API 10.16 Name Reference derived index).
 */
final class NameReferenceIndexService
{
    public function __construct(private readonly NameReferenceParser $parser) {}

    /**
     * @return list<NameReferenceToken>
     */
    public function synchronizeFieldReport(FieldReport $report): array
    {
        if (! $report->exists) {
            throw new LogicException('Persist the Field Report before synchronizing Name References.');
        }

        return $this->synchronizeSource(
            NameReferenceToken::SOURCE_TYPE_FIELD_REPORT,
            (string) $report->id,
            (string) $report->id,
            (string) $report->body,
        );
    }

    /**
     * @return list<NameReferenceToken>
     */
    public function synchronizeAppend(FieldReportAppend $append): array
    {
        if (! $append->exists) {
            throw new LogicException('Persist the Field Report append before synchronizing Name References.');
        }

        return $this->synchronizeSource(
            NameReferenceToken::SOURCE_TYPE_FIELD_REPORT_APPEND,
            (string) $append->id,
            (string) $append->field_report_id,
            (string) $append->body,
        );
    }

    /**
     * Clear and regenerate the Field Report Name Reference index from source text.
     */
    public function rebuild(): int
    {
        return (int) DB::transaction(function (): int {
            NameReferenceToken::query()->delete();

            $count = 0;

            FieldReport::query()->orderBy('created_at')->orderBy('id')->each(
                function (FieldReport $report) use (&$count): void {
                    $count += count($this->synchronizeFieldReport($report));
                },
            );

            FieldReportAppend::query()->orderBy('created_at')->orderBy('id')->each(
                function (FieldReportAppend $append) use (&$count): void {
                    $count += count($this->synchronizeAppend($append));
                },
            );

            return $count;
        });
    }

    /**
     * @return list<NameReferenceToken>
     */
    private function synchronizeSource(
        string $sourceType,
        string $sourceId,
        string $fieldReportId,
        string $body,
    ): array {
        $parsed = $this->parser->parse($body);

        return DB::transaction(function () use ($sourceType, $sourceId, $fieldReportId, $parsed): array {
            $normalizedTokens = array_column($parsed, 'normalized_token');

            $stale = NameReferenceToken::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId);

            if ($normalizedTokens === []) {
                $stale->delete();

                return [];
            }

            $stale->whereNotIn('normalized_token', $normalizedTokens)->delete();

            return array_map(function (array $reference) use ($sourceType, $sourceId, $fieldReportId): NameReferenceToken {
                return NameReferenceToken::query()->updateOrCreate(
                    [
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'normalized_token' => $reference['normalized_token'],
                    ],
                    [
                        'field_report_id' => $fieldReportId,
                        'token' => $reference['token'],
                    ],
                );
            }, $parsed);
        });
    }
}
