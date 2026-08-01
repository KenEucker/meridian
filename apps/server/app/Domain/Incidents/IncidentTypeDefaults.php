<?php

declare(strict_types=1);

namespace App\Domain\Incidents;

/**
 * The incident types a new organization starts with (INC-007; requirements
 * "Configurable areas").
 *
 * Incident types are configurable per organization, which is why they live in
 * `incident_types` keyed by `organization_id` rather than in an enum. What was
 * missing was a starting point: an organization began with none, and the only
 * thing that had ever created one was an incident naming it.
 *
 * This list is a starting point and nothing more. It is not a vocabulary
 * Meridian enforces, no code branches on any of these names, and an
 * organization is expected to cut, rename, and add to it. Two properties are
 * deliberate:
 *
 *  1. **Broad categories, not causes.** An IC operator picks a type in the
 *     first minute of an incident, often over the radio, from what they know
 *     then. Categories a person can choose without a diagnosis keep the field
 *     usable at the moment it is filled in.
 *  2. **`Other` is on the list.** These are chosen from rather than typed, so
 *     an incident that fits nothing else still needs somewhere to go. Without
 *     it the pressure lands on whichever category is closest, which is how a
 *     type field stops meaning anything.
 */
final class IncidentTypeDefaults
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'Non-Consensual Violence',
            'Fire',
            'Law Enforcement',
            'Domestic Violence',
            'Medical',
            'Mental Health',
            'Found Child',
            'Missing Child',
            'Elder Abuse',
            'Sexual Violence',
            'Vehicle',
            'Camp Dispute',
            'Other',
        ];
    }
}
