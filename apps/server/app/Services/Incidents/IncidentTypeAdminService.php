<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\AuditEvent;
use App\Models\IncidentType;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Product-path administration of an organization's incident types (M18.14A;
 * ORG-018, ORG-020; INC-007).
 *
 * The requirements have listed incident types as a configurable area since the
 * first draft, and `incident_types` has carried `organization_id` and
 * `archived_at` since M11.7A. What never existed was a way to configure them,
 * so the incident form created them as a side effect of being filled in. This
 * is that way.
 *
 * Three rules, each of which exists to protect history:
 *
 *  1. **Archive, never delete.** `incident_incident_types` restricts deletion
 *     and an incident's type is part of what was recorded about it. Archiving
 *     removes a type from what an incident may be given next and leaves it on
 *     every incident that already carries it.
 *  2. **Rename in place.** The pivot references the type by id, so renaming
 *     one re-labels it everywhere at once — which is the point. An
 *     organization correcting a name is not creating a category.
 *  3. **Names are unique per organization, case-insensitively.** The database
 *     enforces exact uniqueness; this enforces it the way a person reads it,
 *     so "medical" and "Medical" cannot both exist and split an event's
 *     incidents across two spellings of one category.
 *
 * **No mid-event freeze**, deliberately, and this is the one place M18.14A
 * departs from the branding and policy governance rules. Those freeze because
 * an edit mid-event is a second source of truth for a record central will push
 * down again. An incident type list is different: it is read at the moment an
 * IC operator is categorizing a live incident, the list is now chosen from
 * rather than typed, and an organization that meets something its list does not
 * cover during an event needs to add it during the event. Freezing here would
 * mean an incident that fits nothing gets filed under whichever category is
 * nearest, which is worse than the inconsistency the freeze protects against.
 */
final class IncidentTypeAdminService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(
        Organization $organization,
        string $name,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): IncidentType {
        $name = $this->validName($name);

        $this->assertNameAvailable($organization, $name);

        return DB::transaction(function () use ($organization, $name, $actor, $sourceContext): IncidentType {
            $type = IncidentType::query()->create([
                'organization_id' => (string) $organization->getKey(),
                'name' => $name,
                'created_at' => Carbon::now(),
            ]);

            $this->audit->recordForEntity(
                entity: $type,
                action: 'incident_type.created',
                actorUser: $actor,
                organizationId: (string) $organization->getKey(),
                after: $this->snapshot($type),
                sourceContext: $sourceContext,
            );

            return $type->refresh();
        });
    }

    public function rename(
        IncidentType $type,
        string $name,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): IncidentType {
        $name = $this->validName($name);
        $organization = $this->organizationFor($type);

        $this->assertNameAvailable($organization, $name, $type);

        return DB::transaction(function () use ($type, $name, $actor, $organization, $sourceContext): IncidentType {
            $before = $this->snapshot($type);

            $type->forceFill(['name' => $name])->save();

            $this->audit->recordForEntity(
                entity: $type,
                action: 'incident_type.renamed',
                actorUser: $actor,
                organizationId: (string) $organization->getKey(),
                before: $before,
                after: $this->snapshot($type),
                sourceContext: $sourceContext,
            );

            return $type->refresh();
        });
    }

    public function archive(
        IncidentType $type,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): IncidentType {
        if ($type->archived_at !== null) {
            throw IncidentTypeAdminException::invalid('This incident type is already archived.');
        }

        return $this->setArchivedAt($type, Carbon::now(), 'incident_type.archived', $actor, $sourceContext);
    }

    public function restore(
        IncidentType $type,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): IncidentType {
        if ($type->archived_at === null) {
            throw IncidentTypeAdminException::invalid('This incident type is not archived.');
        }

        // A name freed up while this one was archived may have been taken.
        $this->assertNameAvailable($this->organizationFor($type), (string) $type->name, $type);

        return $this->setArchivedAt($type, null, 'incident_type.restored', $actor, $sourceContext);
    }

    private function setArchivedAt(
        IncidentType $type,
        ?Carbon $archivedAt,
        string $action,
        User $actor,
        string $sourceContext,
    ): IncidentType {
        $organization = $this->organizationFor($type);

        return DB::transaction(function () use ($type, $archivedAt, $action, $actor, $organization, $sourceContext): IncidentType {
            $before = $this->snapshot($type);

            $type->forceFill(['archived_at' => $archivedAt])->save();

            $this->audit->recordForEntity(
                entity: $type,
                action: $action,
                actorUser: $actor,
                organizationId: (string) $organization->getKey(),
                before: $before,
                after: $this->snapshot($type),
                sourceContext: $sourceContext,
            );

            return $type->refresh();
        });
    }

    private function validName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw IncidentTypeAdminException::invalid('Incident type name is required.');
        }

        if (mb_strlen($name) > 100) {
            throw IncidentTypeAdminException::invalid(
                'Incident type name may not be greater than 100 characters.',
            );
        }

        return $name;
    }

    /**
     * Refuse a name the organization already uses, whatever its casing.
     *
     * Archived types are included: the row still holds the name, the unique
     * index still covers it, and restoring one later has to land somewhere.
     */
    private function assertNameAvailable(
        Organization $organization,
        string $name,
        ?IncidentType $ignore = null,
    ): void {
        $query = IncidentType::query()
            ->where('organization_id', (string) $organization->getKey())
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)]);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore->getKey());
        }

        if ($query->exists()) {
            throw IncidentTypeAdminException::invalid(
                sprintf('This organization already has an incident type named %s.', $name),
            );
        }
    }

    private function organizationFor(IncidentType $type): Organization
    {
        $type->loadMissing('organization');
        $organization = $type->organization;

        if (! $organization instanceof Organization) {
            throw IncidentTypeAdminException::invalid('This incident type has no organization.');
        }

        return $organization;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(IncidentType $type): array
    {
        return [
            'id' => (string) $type->getKey(),
            'organization_id' => (string) $type->organization_id,
            'name' => $type->name,
            'archived_at' => optional($type->archived_at)?->toIso8601String(),
        ];
    }
}
