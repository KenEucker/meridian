<?php

namespace App\Services\NameReferences;

use App\Models\Event;
use App\Models\FieldReport;
use App\Models\NameReferenceToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Permission-filtered Field Report search by derived Name Reference token
 * (NR-006, NR-011, NR-012; technical spec 17.7).
 *
 * Visibility always comes from FieldReportPolicy on the parent report. The
 * derived index is never an authorization source.
 */
final class NameReferenceSearchService
{
    public function __construct(private readonly NameReferenceParser $parser) {}

    /**
     * @return Collection<int, FieldReport>
     */
    public function searchFieldReports(User $user, string $query, ?Event $event = null): Collection
    {
        $normalized = $this->parser->normalizeQuery($query);

        if ($normalized === '') {
            return new Collection;
        }

        $reportIds = NameReferenceToken::query()
            ->where('normalized_token', $normalized)
            ->when(
                $event !== null,
                fn ($queryBuilder) => $queryBuilder->whereHas(
                    'fieldReport',
                    fn ($reportQuery) => $reportQuery->where('event_id', $event->id),
                ),
            )
            ->distinct()
            ->pluck('field_report_id');

        if ($reportIds->isEmpty()) {
            return new Collection;
        }

        return FieldReport::query()
            ->whereIn('id', $reportIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (FieldReport $report): bool => Gate::forUser($user)->allows('view', $report))
            ->values();
    }
}
