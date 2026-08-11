<?php

declare(strict_types=1);

namespace App\Services\Directory;

/**
 * The answer the visibility rule gives for one viewer in one context: every
 * chart placement that viewer is authorized to see, and nothing else
 * (DIR-017 through DIR-026, DIR-030).
 *
 * Every path that returns Directory data — the chart read, the search, the
 * offline read set — consumes this one answer rather than re-deriving its own
 * (technical spec 21E.4). A person absent from these placements is absent from
 * the response, the index, and the synchronized set alike, and a location
 * absent from them is not listed against an otherwise-visible person.
 */
final class DirectoryVisibility
{
    /**
     * @param  list<DirectoryPlacement>  $placements
     */
    public function __construct(
        public readonly array $placements,
    ) {}

    /**
     * The staff members the viewer may see at all, in placement order.
     *
     * @return list<string>
     */
    public function staffIds(): array
    {
        $ids = [];

        foreach ($this->placements as $placement) {
            $ids[$placement->staffId] = true;
        }

        return array_keys($ids);
    }

    public function isAuthorized(string $staffId): bool
    {
        foreach ($this->placements as $placement) {
            if ($placement->staffId === $staffId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The authorized placements of one staff member (DIR-030): only the
     * locations the viewer is independently authorized to associate with them.
     *
     * @return list<DirectoryPlacement>
     */
    public function placementsFor(string $staffId): array
    {
        return array_values(array_filter(
            $this->placements,
            fn (DirectoryPlacement $placement): bool => $placement->staffId === $staffId,
        ));
    }
}
