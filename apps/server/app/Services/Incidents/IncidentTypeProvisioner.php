<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Domain\Incidents\IncidentTypeDefaults;
use App\Models\IncidentType;
use App\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * Gives an organization the incident types it starts with (INC-007).
 *
 * Idempotent and additive, which is what makes it safe to run on every
 * organization save and from a seeder against a database that already has data.
 * It creates the defaults an organization is missing and touches nothing else:
 *
 *  - a type the organization already has, under any casing, is left alone;
 *  - a type an organization has archived stays archived, because archiving one
 *    is a decision and re-creating it would quietly undo that decision;
 *  - a type an organization added itself is never removed, because this is a
 *    starting point rather than a vocabulary Meridian owns.
 *
 * The consequence worth stating: running this again after an organization has
 * curated its list does not restore what they removed, and that is the point.
 */
final class IncidentTypeProvisioner
{
    /**
     * Create the default types this organization does not have yet.
     *
     * @return int how many were created
     */
    public function ensureDefaults(Organization $organization, ?Carbon $now = null): int
    {
        $existing = IncidentType::query()
            ->where('organization_id', $organization->getKey())
            ->pluck('name')
            ->map(fn ($name): string => mb_strtolower((string) $name))
            ->all();

        $existing = array_flip($existing);
        $timestamp = $now ?? Carbon::now();
        $created = 0;

        foreach (IncidentTypeDefaults::names() as $name) {
            if (array_key_exists(mb_strtolower($name), $existing)) {
                continue;
            }

            IncidentType::query()->create([
                'organization_id' => (string) $organization->getKey(),
                'name' => $name,
                'created_at' => $timestamp,
            ]);

            $created++;
        }

        return $created;
    }
}
