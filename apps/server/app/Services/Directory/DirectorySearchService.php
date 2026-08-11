<?php

declare(strict_types=1);

namespace App\Services\Directory;

use App\Models\Department;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Handle search over the Directory's authorized set (M18.74; DIR-031 through
 * DIR-034; technical spec 21E.7).
 *
 * The index is built from the authorized set, never filtered down from
 * everybody (DIR-033). Filtering at the end is what produces the
 * discoverability failures the requirement lists — a count that includes
 * people who were removed, a partial match that confirms a handle exists, a
 * response measurably slower when there was something to take out. Here an
 * unauthorized person has no entry to leak: the entries exist only for the
 * placements the M18.71 rule authorized.
 *
 * Matching is on the handle alone (DIR-032). Meridian models one handle and
 * deliberately does not separately model callsign, playa name, or radio name
 * (requirements 3.4), so there is one field here and no second concept to
 * reconcile — and no legal name, preferred name, department name, team name,
 * or role name is ever consulted.
 *
 * One entry per authorized location (DIR-034): a person holding several
 * visible locations produces several rows, each carrying its chart location
 * as a breadcrumb, and a location the viewer may not associate with the
 * person has no row.
 */
class DirectorySearchService
{
    public function __construct(private readonly DirectoryVisibilityResolver $visibility) {}

    /**
     * @var array<string, string>
     */
    private const KIND_LABELS = [
        DirectoryPlacement::KIND_DEPARTMENT_LEAD => 'Department Lead',
        DirectoryPlacement::KIND_TEAM_LEAD => 'Team Lead',
        DirectoryPlacement::KIND_TEAM_MEMBER => 'Member',
        DirectoryPlacement::KIND_PROSPECTIVE => 'Prospectives',
    ];

    /**
     * The rows matching a handle query, from the authorized index.
     *
     * @return list<array<string, mixed>>
     */
    public function search(User $viewer, DirectoryContext $context, string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $needle = Str::lower($query);

        return array_values(array_filter(
            $this->index($viewer, $context),
            fn (array $entry): bool => str_contains(Str::lower((string) $entry['handle']), $needle),
        ));
    }

    /**
     * The authorized index: one entry per authorized placement, carrying the
     * handle and the location breadcrumb. This is the whole population search
     * can reach for this viewer — online here, and offline as the stored set
     * the device's index is built from (technical spec 21E.7, 21E.8).
     *
     * @return list<array<string, mixed>>
     */
    public function index(User $viewer, DirectoryContext $context): array
    {
        $visibility = $this->visibility->resolve($viewer, $context);
        $placements = collect($visibility->placements);

        if ($placements->isEmpty()) {
            return [];
        }

        $handles = $this->handles($placements);
        $departmentNames = $this->departmentNames($placements);
        $teamNames = $this->teamNames($placements);

        return $placements
            ->map(function (DirectoryPlacement $placement) use ($handles, $departmentNames, $teamNames): array {
                $parts = array_values(array_filter([
                    $departmentNames[$placement->departmentId] ?? null,
                    $placement->teamId !== null ? ($teamNames[$placement->teamId] ?? null) : null,
                    self::KIND_LABELS[$placement->kind],
                ], fn (?string $part): bool => $part !== null && $part !== ''));

                return [
                    'staff_id' => $placement->staffId,
                    'handle' => $handles[$placement->staffId] ?? '',
                    'location' => [
                        'department_id' => $placement->departmentId,
                        'team_id' => $placement->teamId,
                        'kind' => $placement->kind,
                        /*
                         * The membership status the placement was admitted
                         * under (DIR-025), so the DIR-036 filters narrow
                         * search results the same way they narrow the chart.
                         */
                        'status' => $placement->status,
                    ],
                    'breadcrumb' => implode(' → ', $parts),
                ];
            })
            ->filter(fn (array $entry): bool => $entry['handle'] !== '')
            ->sortBy(fn (array $entry): array => [
                Str::lower((string) $entry['handle']),
                (string) $entry['staff_id'],
                (string) $entry['breadcrumb'],
            ])
            ->values()
            ->all();
    }

    /**
     * Handles only (DIR-032): the one column read from staff records, so no
     * other name is in the index to be matched by mistake.
     *
     * @param  Collection<int, DirectoryPlacement>  $placements
     * @return array<string, string>
     */
    private function handles(Collection $placements): array
    {
        $handles = [];

        $staff = Staff::query()
            ->whereIn('id', $placements->map(
                fn (DirectoryPlacement $placement): string => $placement->staffId,
            )->unique()->values()->all())
            ->get(['id', 'handle']);

        foreach ($staff as $person) {
            $handle = trim((string) $person->handle);

            if ($handle !== '') {
                $handles[(string) $person->getKey()] = $handle;
            }
        }

        return $handles;
    }

    /**
     * @param  Collection<int, DirectoryPlacement>  $placements
     * @return array<string, string>
     */
    private function departmentNames(Collection $placements): array
    {
        /** @var array<string, string> $names */
        $names = Department::query()
            ->whereIn('id', $placements->map(
                fn (DirectoryPlacement $placement): string => $placement->departmentId,
            )->unique()->values()->all())
            ->pluck('name', 'id')
            ->all();

        return $names;
    }

    /**
     * @param  Collection<int, DirectoryPlacement>  $placements
     * @return array<string, string>
     */
    private function teamNames(Collection $placements): array
    {
        $teamIds = $placements
            ->map(fn (DirectoryPlacement $placement): ?string => $placement->teamId)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($teamIds === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = Team::query()
            ->whereIn('id', $teamIds)
            ->pluck('name', 'id')
            ->all();

        return $names;
    }
}
