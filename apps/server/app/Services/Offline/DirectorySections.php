<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Models\Organization;
use App\Services\Directory\DirectoryChartService;
use App\Services\Directory\DirectoryContext;

/**
 * The Directory's offline read set sections (M18.77; DIR-037; CLIENT-021,
 * CLIENT-022; technical spec 21E.8).
 *
 * The device holds what its user could have retrieved from the API and nothing
 * else — the property M18.47 asserts for every other scope — and here that is
 * made literal: the rows are composed by {@see DirectoryChartService}, the
 * same composition the online chart read serves, over the same M18.71
 * visibility rule. There is no second answer to "who may see whom" written for
 * replication, which was the whole of ADR-0003's case against the sync rules.
 *
 * The context is the scope's: resolved to an event, the event Directory for
 * that event; resolved to none, the organization Directory for each
 * organization the caller holds standing in (DIR-006, DIR-007). Each row names
 * its context, so a device holding one context's chart cannot render it as
 * another's.
 *
 * An organization that has disabled the Directory synchronizes nothing
 * (DIR-005): no section rows, and — where no enabled context exists at all —
 * no section, because an empty section is a claim about an enabled Directory
 * and a disabled one is absent rather than empty.
 *
 * Visibility loss needs no mechanism here (technical spec 21E.8): the set is
 * composed per request from live grants, so a demoted department lead's next
 * refresh simply composes without their former department's members, and the
 * store's own lifecycle drops the rest on sign-out, context switch, and
 * shared-workstation session end (M18.48, M18.49).
 */
final class DirectorySections implements OfflineReadSetContributor
{
    public function __construct(private readonly DirectoryChartService $chart) {}

    /**
     * @return list<OfflineReadSetSection>
     */
    public function sectionsFor(OfflineReadSetScope $scope): array
    {
        if (! $scope->hasStaffProfile()) {
            return [];
        }

        $departments = [];
        $people = [];
        $composed = false;

        foreach ($this->contexts($scope) as $context) {
            if (! $context->organization->directoryEnabled()) {
                continue;
            }

            $composed = true;
            $chart = $this->chart->chart($scope->user, $context);
            $contextRow = [
                'scope' => $context->isEventContext() ? 'event' : 'organization',
                'organization_id' => (string) $context->organization->getKey(),
                'organization_label' => $context->organization->name,
                'event_id' => $context->event !== null ? (string) $context->event->getKey() : null,
                'event_label' => $context->event?->name,
            ];

            foreach ($chart['departments'] as $row) {
                $departments[] = [...$contextRow, ...$row];
            }

            foreach ($chart['people'] as $row) {
                /*
                 * The picture reference stays behind (M8.2 replication
                 * boundary): profile pictures do not travel to devices, and
                 * the offline render falls back to the handle-derived
                 * lettermark the way every entry with no picture already
                 * does. The rest of the projection is the online answer.
                 */
                unset($row['profile_picture_url']);

                $people[] = [...$contextRow, ...$row];
            }
        }

        if (! $composed) {
            return [];
        }

        /*
         * Core rather than module-owned: the Directory reads departments,
         * teams, and memberships, which are core under MOD-004, and its own
         * availability boundary is the organization setting applied above.
         */
        return [
            OfflineReadSetSection::core('directory_departments', $departments),
            OfflineReadSetSection::core('directory_people', $people),
        ];
    }

    /**
     * @return list<DeferredOfflineReadSetSection>
     */
    public function deferredFor(OfflineReadSetScope $scope): array
    {
        return [];
    }

    /**
     * @return list<DirectoryContext>
     */
    private function contexts(OfflineReadSetScope $scope): array
    {
        if ($scope->contextEvent !== null) {
            $organization = $scope->contextEvent->organization;

            return $organization instanceof Organization
                ? [new DirectoryContext($organization, $scope->contextEvent)]
                : [];
        }

        if ($scope->standingOrganizationIds === []) {
            return [];
        }

        return Organization::query()
            ->whereKey($scope->standingOrganizationIds)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $organization): DirectoryContext => new DirectoryContext($organization))
            ->all();
    }
}
