<?php

namespace App\Services\Incidents;

use App\Exceptions\IncidentSearchException;
use App\Models\Incident;
use Illuminate\Support\Carbon;

/**
 * Validated IMS incident list search/filter selection (M11.19).
 *
 * UI implementation contract section 15.1 requires the incident list to offer
 * state and priority filters that exclude Closed incidents by default while
 * still allowing them to be included, plus sortable headings. This value object
 * is the single place those inputs are parsed, so the transport stays thin and
 * the same rules apply wherever incidents are listed.
 */
final class IncidentSearchFilters
{
    public const STATE_ALL = 'all';

    public const STATE_ACTIVE = 'active';

    public const ANY = 'all';

    public const SORT_UPDATED = 'updated';

    public const SORT_INCIDENT = 'incident';

    public const SORT_STATE = 'state';

    public const SORT_PRIORITY = 'priority';

    public const SORT_STARTED = 'started';

    public const SORT_LOCATION = 'location';

    public const DIRECTION_ASC = 'asc';

    public const DIRECTION_DESC = 'desc';

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    private function __construct(
        public readonly string $search,
        public readonly string $state,
        public readonly string $priority,
        public readonly string $type,
        public readonly string $responder,
        public readonly ?Carbon $startedFrom,
        public readonly ?Carbon $startedTo,
        public readonly string $sort,
        public readonly string $direction,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws IncidentSearchException
     */
    public static function fromQuery(array $query): self
    {
        $state = self::scalar($query, 'state', self::STATE_ACTIVE);
        $priority = self::scalar($query, 'priority', self::ANY);
        $sort = self::scalar($query, 'sort', self::SORT_UPDATED);
        $direction = self::scalar($query, 'direction', self::DIRECTION_DESC);

        if (! in_array($state, self::states(), true)) {
            throw IncidentSearchException::invalid(sprintf(
                'State filter must be one of: %s.',
                implode(', ', self::states()),
            ));
        }

        if (! in_array($priority, self::priorities(), true)) {
            throw IncidentSearchException::invalid(sprintf(
                'Priority filter must be one of: %s.',
                implode(', ', self::priorities()),
            ));
        }

        if (! in_array($sort, self::sorts(), true)) {
            throw IncidentSearchException::invalid(sprintf(
                'Sort must be one of: %s.',
                implode(', ', self::sorts()),
            ));
        }

        if (! in_array($direction, [self::DIRECTION_ASC, self::DIRECTION_DESC], true)) {
            throw IncidentSearchException::invalid('Sort direction must be asc or desc.');
        }

        $startedFrom = self::date($query, 'started_from');
        $startedTo = self::date($query, 'started_to', endOfDayWhenDateOnly: true);

        if ($startedFrom !== null && $startedTo !== null && $startedFrom->greaterThan($startedTo)) {
            throw IncidentSearchException::invalid(
                'Started from must be earlier than or equal to started to.'
            );
        }

        return new self(
            search: trim(self::scalar($query, 'search', '')),
            state: $state,
            priority: $priority,
            type: trim(self::scalar($query, 'type', self::ANY)),
            responder: trim(self::scalar($query, 'responder', self::ANY)),
            startedFrom: $startedFrom,
            startedTo: $startedTo,
            sort: $sort,
            direction: $direction,
            page: self::positiveInt($query, 'page', 1, 1),
            perPage: self::positiveInt($query, 'per_page', self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE),
        );
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [self::STATE_ACTIVE, self::STATE_ALL, ...Incident::statuses()];
    }

    /**
     * @return list<string>
     */
    public static function priorities(): array
    {
        return [self::ANY, ...Incident::priorityLabels()];
    }

    /**
     * @return list<string>
     */
    public static function sorts(): array
    {
        return [
            self::SORT_UPDATED,
            self::SORT_INCIDENT,
            self::SORT_STATE,
            self::SORT_PRIORITY,
            self::SORT_STARTED,
            self::SORT_LOCATION,
        ];
    }

    public function hasSearch(): bool
    {
        return $this->search !== '';
    }

    /**
     * The search text without a leading `@` or `#`, so Name Reference chips and
     * tag pills route through the same list search as typed queries (NR-010).
     */
    public function searchTerm(): string
    {
        $term = $this->search;

        return str_starts_with($term, '@') || str_starts_with($term, '#')
            ? substr($term, 1)
            : $term;
    }

    public function filtersType(): bool
    {
        return $this->type !== '' && $this->type !== self::ANY;
    }

    public function filtersResponder(): bool
    {
        return $this->responder !== '' && $this->responder !== self::ANY;
    }

    /**
     * Statuses this selection allows, or null when every status is allowed.
     *
     * @return list<string>|null
     */
    public function statuses(): ?array
    {
        if ($this->state === self::STATE_ALL) {
            return null;
        }

        if ($this->state === self::STATE_ACTIVE) {
            return array_values(array_filter(
                Incident::statuses(),
                fn (string $status): bool => $status !== Incident::STATUS_CLOSED,
            ));
        }

        return [$this->state];
    }

    /**
     * The full applied selection, including where the reader is in the list.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->toSelectionArray(),
            'page' => $this->page,
            'per_page' => $this->perPage,
        ];
    }

    /**
     * The saveable part of a selection: what is being looked for, not where the
     * reader had scrolled to. Paging is per-visit, so presets never store it.
     *
     * @return array<string, mixed>
     */
    public function toSelectionArray(): array
    {
        return [
            'search' => $this->search,
            'state' => $this->state,
            'priority' => $this->priority,
            'type' => $this->type,
            'responder' => $this->responder,
            'started_from' => $this->startedFrom?->toIso8601String(),
            'started_to' => $this->startedTo?->toIso8601String(),
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];
    }

    /**
     * The selection as list query parameters, so a saved preset can be applied
     * by handing it straight back to the list endpoint.
     *
     * @return array<string, string>
     */
    public function toQueryParameters(): array
    {
        $parameters = [];

        foreach ($this->toSelectionArray() as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $parameters[$key] = (string) $value;
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function scalar(array $query, string $key, string $default): string
    {
        $value = $query[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_scalar($value)) {
            throw IncidentSearchException::invalid(sprintf('%s filter must be a single value.', $key));
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function positiveInt(
        array $query,
        string $key,
        int $default,
        int $min,
        ?int $max = null,
    ): int {
        $value = self::scalar($query, $key, '');

        if ($value === '') {
            return $default;
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw IncidentSearchException::invalid(sprintf('%s must be a whole number.', $key));
        }

        $parsed = (int) $value;

        if ($parsed < $min || ($max !== null && $parsed > $max)) {
            throw IncidentSearchException::invalid($max === null
                ? sprintf('%s must be at least %d.', $key, $min)
                : sprintf('%s must be between %d and %d.', $key, $min, $max));
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function date(array $query, string $key, bool $endOfDayWhenDateOnly = false): ?Carbon
    {
        $value = self::scalar($query, $key, '');

        if ($value === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($value);
        } catch (\Throwable) {
            throw IncidentSearchException::invalid(sprintf('%s must be a valid date or timestamp.', $key));
        }

        // A bare date as the upper bound means "through that day", not midnight.
        return $endOfDayWhenDateOnly && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? $parsed->endOfDay()
            : $parsed;
    }
}
