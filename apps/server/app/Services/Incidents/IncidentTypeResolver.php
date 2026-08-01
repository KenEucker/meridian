<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\Event;
use App\Models\IncidentType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Turns the type names on an incident command into the organization's types
 * (M18.14A; INC-007).
 *
 * Both the create and the update service used to carry their own copy of this,
 * and both created a type from a name they did not recognize. That is how an
 * organization's incident type list came into existence: as a side effect of
 * incidents being filled in, one spelling at a time, by whoever was at the
 * keyboard. Now that organizers have a surface for the list (ORG-018), an
 * unrecognized name is a refusal.
 *
 * One deliberate asymmetry: **archived types still resolve.** They are absent
 * from what the authoring form offers, so nothing new is given one — but an
 * incident that already carries a type the organization has since retired has
 * to remain saveable, and the update command sends the whole set every time. A
 * resolver that refused archived names would make those incidents uneditable,
 * which is a strange punishment for having been filed before a list changed.
 */
final class IncidentTypeResolver
{
    /**
     * Resolve type names to pivot rows, reporting the ones this organization
     * does not have.
     *
     * @param  list<string>  $names
     * @return array{pivot: array<string, array{id: string, created_at: CarbonImmutable}>, unknown: list<string>}
     */
    public function resolve(Event $event, array $names, CarbonImmutable $now): array
    {
        $pivot = [];
        $unknown = [];

        foreach ($names as $name) {
            $type = IncidentType::query()
                ->where('organization_id', $event->organization_id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->first();

            if (! $type instanceof IncidentType) {
                $unknown[] = $name;

                continue;
            }

            $pivot[$type->id] = ['id' => (string) Str::uuid(), 'created_at' => $now];
        }

        return ['pivot' => $pivot, 'unknown' => $unknown];
    }

    /**
     * What an unrecognized name is told to whoever sent it.
     *
     * Names the types rather than only refusing, because the person reading it
     * is mid-incident and the useful next step is either picking a configured
     * type or asking an organizer to add one.
     *
     * @param  list<string>  $unknown
     */
    public function refusalFor(array $unknown): string
    {
        return sprintf(
            '%s %s not %s incident type for this organization. Incident types are configured by organizers.',
            implode(', ', $unknown),
            count($unknown) === 1 ? 'is' : 'are',
            count($unknown) === 1 ? 'a configured' : 'configured',
        );
    }
}
