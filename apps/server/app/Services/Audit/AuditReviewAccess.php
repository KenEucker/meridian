<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Attachment;
use App\Models\FieldReport;
use App\Models\FieldReportAppend;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\IncidentLink;
use App\Models\IncidentListPreset;
use App\Models\IncidentStaff;
use App\Models\IncidentTimelineEntry;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may review an organization's audit record, and what organizing reaches
 * (M18.29; requirements 2.4; UI contract 12.6 `organizer.audit`; ORG-015).
 *
 * Organizers and Lead Organizers, through `organization.audit.review`, scoped
 * to the organizations their granting team belongs to — the same
 * organization-scoping every other organizer authority makes.
 *
 * **The IMS exclusion is kept here rather than at the surface.** ORG-015 says
 * membership in the Organizers Department grants no access to incidents or
 * Field Reports, and an audit trail is a way of reading a record: a row saying
 * that Omar reopened incident IMS-2026-014 at 03:12 discloses that the incident
 * exists, when it happened, and who worked it. So the read excludes the IMS
 * entity types outright. An organizer who also holds IC standing reads that
 * history through the incident's own timeline as an IC user, which is where
 * incident history has always lived — the same shape M18.28 gives the organizer
 * dashboard.
 *
 * The exclusion is a named list rather than a namespace test because
 * `incident_types` is deliberately *not* on it: the configurable type list is
 * organization configuration an organizer maintains (M18.14A; ORG-018), and
 * hiding who renamed a type would hide an organizer's own work from them.
 */
final class AuditReviewAccess
{
    /**
     * Entity types an organizer's audit review never returns (ORG-015).
     *
     * Stored as morph classes, because that is what `audit_events.entity_type`
     * holds: {@see \App\Services\Audit\AuditService::recordForEntity()} writes
     * `getMorphClass()`, which is the fully-qualified name for everything the
     * application has not aliased and the alias for the ones it has.
     *
     * @return list<string>
     */
    public static function excludedEntityTypes(): array
    {
        return [
            (new Incident)->getMorphClass(),
            (new IncidentTimelineEntry)->getMorphClass(),
            (new IncidentFieldReport)->getMorphClass(),
            (new IncidentLink)->getMorphClass(),
            (new IncidentStaff)->getMorphClass(),
            (new IncidentListPreset)->getMorphClass(),
            (new FieldReport)->getMorphClass(),
            (new FieldReportAppend)->getMorphClass(),
            // The alias the morph map gives Field Reports, so a row written
            // through an attachment relationship is excluded by the same rule.
            Attachment::MORPH_FIELD_REPORT,
        ];
    }

    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canReviewAudit(User $user, Organization $organization): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_ORGANIZATION_AUDIT_REVIEW,
                )) {
                    continue;
                }

                $team = Team::query()->with('department')->find($role->teamId);

                if ($team === null || $team->department === null) {
                    continue;
                }

                if ((string) $team->department->organization_id === (string) $organization->getKey()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Narrow a query to what organizing may read of one organization's history.
     *
     * Rows carrying no organization are node and system history — pairing,
     * node configuration, device trust — and belong to the God Mode console
     * rather than to an organizer, so they are outside this scope too.
     *
     * @param  Builder<\App\Models\AuditEvent>  $query
     * @return Builder<\App\Models\AuditEvent>
     */
    public function scopeReviewable(Builder $query, Organization $organization): Builder
    {
        return $query
            ->where('organization_id', (string) $organization->getKey())
            ->whereNotIn('entity_type', self::excludedEntityTypes());
    }
}
