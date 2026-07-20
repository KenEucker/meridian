<?php

namespace App\Services\NameReferences;

use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\NameReferenceToken;
use App\Models\User;
use App\Services\Incidents\IncidentReadAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Permission-filtered search by derived Name Reference token (NR-006,
 * NR-010 through NR-013; technical spec 17.7, 19.9, and 19.10).
 *
 * Visibility always comes from source-record permissions. The derived index is
 * never an authorization source.
 */
final class NameReferenceSearchService
{
    public function __construct(
        private readonly NameReferenceParser $parser,
        private readonly IncidentReadAccess $incidentReadAccess,
    ) {}

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
            ->whereNotNull('field_report_id')
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

    /**
     * @return Collection<int, Incident>
     */
    public function searchIncidents(User $user, string $query, Event $event): Collection
    {
        if (! $this->incidentReadAccess->canViewIncidents($user, $event)) {
            return new Collection;
        }

        $normalized = $this->parser->normalizeQuery($query);

        if ($normalized === '') {
            return new Collection;
        }

        $incidentIds = NameReferenceToken::query()
            ->where('normalized_token', $normalized)
            ->whereNotNull('incident_id')
            ->whereHas(
                'incident',
                fn ($incidentQuery) => $incidentQuery->where('event_id', $event->id),
            )
            ->distinct()
            ->pluck('incident_id');

        $fieldReportIds = NameReferenceToken::query()
            ->where('normalized_token', $normalized)
            ->whereNotNull('field_report_id')
            ->distinct()
            ->pluck('field_report_id');

        $linkedIncidentIds = $fieldReportIds->isEmpty()
            ? collect()
            : IncidentFieldReport::query()
                ->whereNull('unlinked_at')
                ->whereIn('field_report_id', $fieldReportIds)
                ->whereHas(
                    'incident',
                    fn ($incidentQuery) => $incidentQuery->where('event_id', $event->id),
                )
                ->distinct()
                ->pluck('incident_id');

        $incidentIds = $incidentIds->merge($linkedIncidentIds)->unique()->values();

        if ($incidentIds->isEmpty()) {
            return new Collection;
        }

        return Incident::query()
            ->with('createdByUser')
            ->whereIn('id', $incidentIds)
            ->where('event_id', $event->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('started_at')
            ->get();
    }

    /**
     * @return list<array{token: string, normalized_token: string}>
     */
    public function incidentChips(Incident $incident): array
    {
        $fieldReportIds = IncidentFieldReport::query()
            ->where('incident_id', $incident->id)
            ->whereNull('unlinked_at')
            ->pluck('field_report_id');

        $tokens = NameReferenceToken::query()
            ->where(function ($query) use ($incident, $fieldReportIds): void {
                $query->where('incident_id', $incident->id);

                if ($fieldReportIds->isNotEmpty()) {
                    $query->orWhereIn('field_report_id', $fieldReportIds);
                }
            })
            ->orderBy('created_at')
            ->orderBy('token')
            ->get(['token', 'normalized_token']);

        $chips = [];

        foreach ($tokens as $token) {
            if (array_key_exists($token->normalized_token, $chips)) {
                continue;
            }

            $chips[$token->normalized_token] = [
                'token' => $token->token,
                'normalized_token' => $token->normalized_token,
            ];
        }

        return array_values($chips);
    }
}
