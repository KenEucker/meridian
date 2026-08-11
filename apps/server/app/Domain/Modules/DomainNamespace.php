<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * Meridian's domain namespaces, and the product module that owns each
 * (technical spec 5.2, 15A.2; MOD-004; M19.11).
 *
 * Two different boundaries meet here. A *domain namespace* is a code
 * organization boundary — the modular-monolith folders of technical spec 5.2. A
 * *product module* is a capability an organization turns on and off (section
 * 15A). One module owns one or more namespaces, and most namespaces are owned by
 * no module at all: they are core (MOD-004) and have no toggle.
 *
 * The declaration is here rather than spread across the namespaces themselves
 * because the question "who owns this" is asked from outside them — the route
 * gate of M19.12, the synced-table declaration of M19.17, and the aggregators of
 * M19.18 each need the answer for a domain they are not inside of.
 *
 * A namespace with no declaration is core. {@see self::ownerOf()} answers null
 * for a name this enum has never heard of, so the failure mode of forgetting to
 * declare one is a capability that stays reachable rather than one that silently
 * disappears (technical spec 15A.2). That direction is deliberate: an
 * undeclared namespace that defaulted to *some* module would vanish from every
 * organization that did not run it, and nobody would find out until an event.
 *
 * The case values are the spec's own terms for these domains, normalized. That
 * is what lets `DomainNamespaceOwnershipTest` read technical spec 5.2 and 15A.2
 * and assert this enum still says what they say.
 */
enum DomainNamespace: string
{
    // Scheduling (MOD-002).
    case Shifts = 'shifts';

    case ShiftSignupsAndRequirements = 'shift_signups_and_requirements';

    // Incident Management (MOD-002).
    case Incidents = 'incidents';

    case FieldReports = 'field_reports';

    // Documents (MOD-002).
    case PolicyDocuments = 'policy_documents';

    case ProcedureDocuments = 'procedure_documents';

    case DocumentFragments = 'document_fragments';

    case DocumentAcknowledgments = 'document_acknowledgments';

    case DocumentExports = 'document_exports';

    case Waivers = 'waivers';

    // Qualifications (MOD-002).
    case Trainings = 'trainings';

    case Credentials = 'credentials';

    // Equipment (MOD-002).
    case Equipment = 'equipment';

    // Event Geography (MOD-002).
    case EventMaps = 'event_maps';

    case Camps = 'camps';

    case MapLocations = 'map_locations';

    case Deployments = 'deployments';

    case PlacementDesignation = 'placement_designation';

    // The Briefing (MOD-002).
    case Notes = 'notes';

    case BriefingNoteInclusions = 'briefing_note_inclusions';

    case AfterActionReports = 'after_action_reports';

    case BriefingDirections = 'briefing_directions';

    case ActionPlans = 'action_plans';

    case BriefingNotices = 'briefing_notices';

    // Insights (MOD-002).
    case Insights = 'insights';

    // Core (MOD-004). No module owns these and none of them has a toggle.
    case Organizations = 'organizations';

    case Events = 'events';

    case Departments = 'departments';

    case Teams = 'teams';

    case Staff = 'staff';

    case Users = 'users';

    case Memberships = 'memberships';

    case Roles = 'roles';

    case Permissions = 'permissions';

    case Attendance = 'attendance';

    case Hours = 'hours';

    case Credits = 'credits';

    case Devices = 'devices';

    case SharedWorkstations = 'shared_workstations';

    case NodeConfig = 'node_config';

    case NodeSync = 'node_sync';

    case Audit = 'audit';

    case SyncConflicts = 'sync_conflicts';

    case Files = 'files';

    /**
     * The module that owns this namespace, or null when it is core.
     *
     * Attendance, hours, and credits are core rather than Scheduling's, because
     * check-in does not require a shift (requirements 5.8) and an organization
     * with Scheduling inactive still records who worked and what they earned
     * (MOD-004).
     */
    public function module(): ?ModuleKey
    {
        return match ($this) {
            self::Shifts,
            self::ShiftSignupsAndRequirements => ModuleKey::Scheduling,

            self::Incidents,
            self::FieldReports => ModuleKey::IncidentManagement,

            self::PolicyDocuments,
            self::ProcedureDocuments,
            self::DocumentFragments,
            self::DocumentAcknowledgments,
            self::DocumentExports,
            self::Waivers => ModuleKey::Documents,

            self::Trainings,
            self::Credentials => ModuleKey::Qualifications,

            self::Equipment => ModuleKey::Equipment,

            self::EventMaps,
            self::Camps,
            self::MapLocations,
            self::Deployments,
            self::PlacementDesignation => ModuleKey::EventGeography,

            self::Notes,
            self::BriefingNoteInclusions,
            self::AfterActionReports,
            self::BriefingDirections,
            self::ActionPlans,
            self::BriefingNotices => ModuleKey::Briefing,

            self::Insights => ModuleKey::Insights,

            default => null,
        };
    }

    public function isCore(): bool
    {
        return $this->module() === null;
    }

    /**
     * The module owning a namespace named by string, or null when it is core
     * *or* undeclared.
     *
     * The two answers are deliberately the same one. A caller asking this
     * question wants to know whether to gate, and "no declaration" has to mean
     * "do not gate" for the reason given on the enum itself.
     */
    public static function ownerOf(string $namespace): ?ModuleKey
    {
        return self::tryFrom($namespace)?->module();
    }

    /**
     * The namespaces this module owns.
     *
     * @return list<self>
     */
    public static function ownedBy(ModuleKey $module): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $namespace): bool => $namespace->module() === $module,
        ));
    }

    /**
     * @return list<self>
     */
    public static function core(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $namespace): bool => $namespace->isCore(),
        ));
    }
}
