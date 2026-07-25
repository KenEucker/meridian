<?php

namespace App\Services\Incidents;

use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentStaff;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\NameReferences\NameReferenceSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * IMS incident list search, filtering, and sorting (M11.19).
 *
 * Explicit list behavior beyond list/detail reads and Name Reference chip
 * navigation: free text across the incident record and its history, state /
 * priority / type / responder / started-window filters, and sortable headings
 * (IMS surface specification section 9; UI contract sections 12.7 and 15.1).
 *
 * Permission comes first and comes from `IncidentReadAccess` alone. Nothing
 * here widens visibility: a user who cannot view event incidents gets an empty
 * result no matter what they search for, and matches found through related
 * records (types, responders, notes, attached Field Reports, Name Reference
 * tokens) only ever resolve to incidents inside the requested event.
 */
final class IncidentSearchService
{
    /**
     * Columns matched by free-text search, in incident-record order.
     *
     * @var list<string>
     */
    private const SEARCHABLE_COLUMNS = [
        'incident_number',
        'title',
        'location_name',
        'location_address',
        'location_details',
    ];

    public function __construct(
        private readonly IncidentReadAccess $access,
        private readonly NameReferenceSearchService $nameReferences,
    ) {}

    /**
     * @return Collection<int, Incident>
     */
    public function search(User $user, Event $event, IncidentSearchFilters $filters): Collection
    {
        if (! $this->access->canViewIncidents($user, $event)) {
            return new Collection;
        }

        $query = Incident::query()
            ->with(['createdByUser', 'incidentTypes', 'incidentStaff.staff'])
            ->forEvent($event);

        $this->applyFilters($query, $filters);

        if ($filters->hasSearch()) {
            $this->applySearch($query, $user, $event, $filters);
        }

        $this->applySort($query, $filters);

        return $query->get();
    }

    /**
     * Incident type names in use for this event, for filter controls.
     *
     * @return list<string>
     */
    public function typeOptions(User $user, Event $event): array
    {
        if (! $this->access->canViewIncidents($user, $event)) {
            return [];
        }

        return IncidentType::query()
            ->whereHas('incidents', fn (Builder $query) => $query->forEvent($event))
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            ->values()
            ->all();
    }

    /**
     * Responders attached to this event's incidents, for filter controls.
     *
     * @return list<array{staff_id: string, display_name: string}>
     */
    public function responderOptions(User $user, Event $event): array
    {
        if (! $this->access->canViewIncidents($user, $event)) {
            return [];
        }

        return IncidentStaff::query()
            ->with('staff')
            ->whereHas('incident', fn (Builder $query) => $query->forEvent($event))
            ->get()
            ->map(fn (IncidentStaff $responder): array => [
                'staff_id' => (string) $responder->staff_id,
                'display_name' => $responder->staff?->preferred_name
                    ?? $responder->staff?->handle
                    ?? $responder->staff?->legal_name
                    ?? 'Unknown responder',
            ])
            ->unique('staff_id')
            ->sortBy('display_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Incident>  $query
     */
    private function applyFilters(Builder $query, IncidentSearchFilters $filters): void
    {
        $statuses = $filters->statuses();

        if ($statuses !== null) {
            $query->whereIn('status', $statuses);
        }

        if ($filters->priority !== IncidentSearchFilters::ANY) {
            $query->where('priority_label', $filters->priority);
        }

        if ($filters->filtersType()) {
            $query->whereHas(
                'incidentTypes',
                fn (Builder $typeQuery) => $typeQuery->whereRaw(
                    'lower(incident_types.name) = ?',
                    [mb_strtolower($filters->type)],
                ),
            );
        }

        if ($filters->filtersResponder()) {
            $query->whereHas(
                'incidentStaff',
                fn (Builder $staffQuery) => $staffQuery->where('staff_id', $filters->responder),
            );
        }

        if ($filters->startedFrom !== null) {
            $query->where('started_at', '>=', $filters->startedFrom);
        }

        if ($filters->startedTo !== null) {
            $query->where('started_at', '<=', $filters->startedTo);
        }
    }

    /**
     * @param  Builder<Incident>  $query
     */
    private function applySearch(
        Builder $query,
        User $user,
        Event $event,
        IncidentSearchFilters $filters,
    ): void {
        $term = $filters->searchTerm();
        $pattern = $this->likePattern($term);
        $tokenIncidentIds = $this->nameReferences
            ->searchIncidents($user, $filters->search, $event)
            ->modelKeys();

        $query->where(function (Builder $searchQuery) use ($pattern, $tokenIncidentIds): void {
            foreach (self::SEARCHABLE_COLUMNS as $column) {
                $searchQuery->orWhereRaw(
                    sprintf("lower(incidents.%s) like ? escape '\\'", $column),
                    [$pattern],
                );
            }

            $searchQuery->orWhereHas(
                'incidentTypes',
                fn (Builder $typeQuery) => $typeQuery->whereRaw(
                    "lower(incident_types.name) like ? escape '\\'",
                    [$pattern],
                ),
            );

            $searchQuery->orWhereHas(
                'incidentStaff',
                fn (Builder $staffQuery) => $staffQuery->whereHas(
                    'staff',
                    fn (Builder $profile) => $profile
                        ->whereRaw("lower(staff.preferred_name) like ? escape '\\'", [$pattern])
                        ->orWhereRaw("lower(staff.legal_name) like ? escape '\\'", [$pattern])
                        ->orWhereRaw("lower(staff.handle) like ? escape '\\'", [$pattern]),
                ),
            );

            // Notes are the incident narrative; stricken entries stay out of
            // search results the same way they stay out of the default timeline.
            $searchQuery->orWhereHas(
                'timelineEntries',
                fn (Builder $entryQuery) => $entryQuery
                    ->whereNull('stricken_at')
                    ->whereRaw("lower(incident_timeline_entries.body) like ? escape '\\'", [$pattern]),
            );

            $searchQuery->orWhereHas(
                'fieldReportLinks',
                fn (Builder $linkQuery) => $linkQuery
                    ->whereNull('unlinked_at')
                    ->whereHas(
                        'fieldReport',
                        fn (Builder $reportQuery) => $reportQuery
                            ->whereRaw("lower(field_reports.title) like ? escape '\\'", [$pattern])
                            ->orWhereRaw("lower(field_reports.body) like ? escape '\\'", [$pattern])
                            ->orWhereRaw("lower(field_reports.fra_number) like ? escape '\\'", [$pattern]),
                    ),
            );

            if ($tokenIncidentIds !== []) {
                $searchQuery->orWhereIn('incidents.id', $tokenIncidentIds);
            }
        });
    }

    /**
     * @param  Builder<Incident>  $query
     */
    private function applySort(Builder $query, IncidentSearchFilters $filters): void
    {
        $direction = $filters->direction === IncidentSearchFilters::DIRECTION_ASC ? 'asc' : 'desc';

        match ($filters->sort) {
            IncidentSearchFilters::SORT_INCIDENT => $query->orderBy('incident_number', $direction),
            IncidentSearchFilters::SORT_STATE => $query->orderByRaw(
                $this->caseOrder('status', Incident::statuses(), $direction),
                Incident::statuses(),
            ),
            IncidentSearchFilters::SORT_PRIORITY => $query->orderByRaw(
                $this->caseOrder('priority_label', $this->prioritiesBySeverity(), $direction),
                $this->prioritiesBySeverity(),
            ),
            IncidentSearchFilters::SORT_STARTED => $query->orderBy('started_at', $direction),
            IncidentSearchFilters::SORT_LOCATION => $query->orderByRaw(
                sprintf("lower(coalesce(incidents.location_name, '')) %s", $direction),
            ),
            default => $query->orderBy('updated_at', $direction),
        };

        // Deterministic tiebreakers so paging and repeat reads stay stable.
        if ($filters->sort !== IncidentSearchFilters::SORT_UPDATED) {
            $query->orderByDesc('updated_at');
        }

        $query->orderByDesc('started_at')->orderBy('id');
    }

    /**
     * Order by documented operational sequence rather than alphabetically, so
     * state and priority sorts read the way IMS labels are ranked.
     *
     * @param  list<string>  $values
     */
    private function caseOrder(string $column, array $values, string $direction): string
    {
        $cases = '';

        foreach (array_keys($values) as $index) {
            $cases .= sprintf(' when ? then %d', $index);
        }

        return sprintf(
            'case incidents.%s%s else %d end %s',
            $column,
            $cases,
            count($values),
            $direction,
        );
    }

    /**
     * @return list<string>
     */
    private function prioritiesBySeverity(): array
    {
        return [
            Incident::PRIORITY_CRITICAL,
            Incident::PRIORITY_SERIOUS,
            Incident::PRIORITY_IMPORTANT,
            Incident::PRIORITY_ROUTINE,
        ];
    }

    private function likePattern(string $term): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term));

        return '%'.$escaped.'%';
    }
}
