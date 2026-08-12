<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Which audit actions are written at which verbosity, and which are written
 * whatever an organization asks for (requirements 2.4; data/API sections 8 and
 * 14.1).
 *
 * Two lists, and the first one governs:
 *
 *  - {@see REQUIRED} is the floor. Requirements 2.4 names the changes that must
 *    preserve history and data/API section 8 names the events audit applies to,
 *    and between them they decide these. No verbosity level reaches below the
 *    floor and no per-action override switches one off — {@see AuditPolicy}
 *    enforces that in the write path, not in the surface that sets the
 *    configuration, so a value written straight into the column by a repair
 *    tool cannot get around it either.
 *  - {@see levels()} catalogues everything else against the level it first
 *    appears at. An action is written when the organization's level is at or
 *    above it.
 *
 * **An uncatalogued action is written.** Actions are composed at runtime in
 * several places — branding decides `added` versus `replaced`, credit policies
 * and device trust build theirs from a variable — so this list cannot be
 * complete by construction, and a catalogue that silently dropped what it did
 * not recognise would turn a gap here into missing history there. Failing open
 * costs rows; failing closed costs the record.
 *
 * **On the volume this can actually save.** The largest single writer is
 * `incident.viewed`, one row per opened incident per reader, and data/API
 * section 8 requires auditing sensitive incident reads — so it sits in the
 * floor and no verbosity setting removes it. Verbosity meaningfully reduces
 * routine operational noise; it is not the lever for the biggest contributor,
 * and retention and partitioning are.
 */
final class AuditActionCatalog
{
    /**
     * Actions that are always recorded, whatever an organization configures.
     *
     * Each entry traces to a named obligation. Requirements 2.4 lists incident
     * updates, incident notes, stricken incident content, Field Report
     * attachment and removal, staff status changes, organizer removals,
     * credential revocations, hour corrections, and credit calculations.
     * Data/API section 8 adds permission changes, auth and device trust,
     * shared-workstation login codes, node pairing and configuration,
     * attendance direct edits, dangerous God Mode actions, sync conflict
     * resolution, node operation acceptance and rejection, policy and procedure
     * version changes, fragment edits, acknowledgments, export and print, and
     * sensitive read and view events.
     *
     * @var list<string>
     */
    public const REQUIRED = [
        // Requirements 2.4: staff status changes and organizer removals.
        'staff_organization_status.changed',
        'staff_organization_status.created',
        'department_lead.removed',
        // Data/API section 8: permission changes.
        'team_lead.selected',
        'team_lead.removed',
        'team_designation.created',
        'team_designation.changed',
        'team_designation.removed',
        // Requirements 2.4: credential revocations, hour corrections, credits.
        'event_credential.revoked',
        'hours.corrected',
        'hours.frozen',
        'event_credits.calculated',
        // Requirements 2.4: incident updates, notes, and stricken content;
        // Field Report attachment and removal.
        'incident.created',
        'incident.updated',
        'incident.note_appended',
        'incident.note_stricken',
        'incident.attachment_stricken',
        'incident.field_report_linked',
        'incident.field_report_unlinked',
        'incident.linked',
        'incident.unlinked',
        // Data/API section 8: sensitive read/view events, export and print.
        'incident.viewed',
        'incident.exported',
        'event_credential_eligibility.exported',
        'event_shift_roster.exported',
        'event_staff_contact.exported',
        'event_hours_worked.exported',
        'event_credits_earned.exported',
        // Data/API section 8: node pairing and configuration, device trust.
        'node.paired',
        'node.pairing_completed',
        'node_pairing_token.issued',
        'node_pairing_token.revoked',
        // Technical spec 13.1: "Context changes are audited." A shared
        // workstation's pinned context decides which organization's and which
        // event's operational scope appears on a machine strangers stand in
        // front of, which puts it with device trust rather than below it.
        'shared_workstation.context_pinned',
        // Data/API section 8: policy/procedure acknowledgments.
        'document_acknowledgment.accepted',
        // Organization governance: the values every other rule is measured
        // from (ORG-020 requires configuration changes to be audited).
        'organization.configuration_updated',
        // Which modules an organization is entitled to run. MOD-011 audits
        // every transition without qualification, and the transition decides
        // whether a whole capability exists for everyone in the organization —
        // so it sits at the floor rather than at a level an organization could
        // configure its way below.
        'organization_module.entitlement_changed',
        // And which of those the organization has chosen to run (MOD-008). The
        // same reasoning: it is the transition that most often explains why a
        // capability an organization had yesterday is absent today, and MOD-011
        // audits it without qualification.
        'organization_module.enablement_changed',
        // The archival that enforces a retention limit. Recorded at the floor
        // for the obvious reason: an act that removes audit history is the last
        // one that should be omissible from it.
        'audit.archived',
    ];

    /**
     * Everything else, against the level it is first written at.
     *
     * @return array<string, AuditVerbosity>
     */
    public static function levels(): array
    {
        return [
            // Low: decisions that change what somebody may do.
            'event_application.approved' => AuditVerbosity::Low,
            'event_application.rescinded' => AuditVerbosity::Low,
            'event_application.withdrawn' => AuditVerbosity::Low,
            'department_membership.assigned_from_application' => AuditVerbosity::Low,
            'department_membership.inactivated_from_application_rescind' => AuditVerbosity::Low,
            'team_membership.removed' => AuditVerbosity::Low,
            'staff.profile.handle_changed' => AuditVerbosity::Low,
            'staff.profile.picture_changed' => AuditVerbosity::Low,
            'staff.profile.picture_removed' => AuditVerbosity::Low,
            'staff.profile_change_request.withdrawn' => AuditVerbosity::Low,
            'user.updated' => AuditVerbosity::Low,

            // Standard: governance and administration.
            'organization.default_ic_department_changed' => AuditVerbosity::Standard,
            'department.created' => AuditVerbosity::Standard,
            'department.updated' => AuditVerbosity::Standard,
            'department.archived' => AuditVerbosity::Standard,
            'department.restored' => AuditVerbosity::Standard,
            'team.created' => AuditVerbosity::Standard,
            'team.updated' => AuditVerbosity::Standard,
            'team.archived' => AuditVerbosity::Standard,
            'team.restored' => AuditVerbosity::Standard,
            'event.created' => AuditVerbosity::Standard,
            'event.updated' => AuditVerbosity::Standard,
            'event.ic_department_changed' => AuditVerbosity::Standard,
            'event.department_assigned' => AuditVerbosity::Standard,
            'event.department_removed' => AuditVerbosity::Standard,
            'shift.created' => AuditVerbosity::Standard,
            'shift.updated' => AuditVerbosity::Standard,
            'training.created' => AuditVerbosity::Standard,
            'training.updated' => AuditVerbosity::Standard,
            'training.prerequisite_added' => AuditVerbosity::Standard,
            'training.prerequisite_removed' => AuditVerbosity::Standard,
            'credit_policy.created' => AuditVerbosity::Standard,
            'credit_policy.updated' => AuditVerbosity::Standard,
            'incident_type.created' => AuditVerbosity::Standard,
            'incident_type.renamed' => AuditVerbosity::Standard,
            'branding.updated' => AuditVerbosity::Standard,
            'branding.asset_removed' => AuditVerbosity::Standard,
            'document_acknowledgment_requirement.created' => AuditVerbosity::Standard,
            'equipment_item.created' => AuditVerbosity::Standard,
            'equipment_item.updated' => AuditVerbosity::Standard,
            'users.imported' => AuditVerbosity::Standard,
            'teams.imported' => AuditVerbosity::Standard,
            'shifts.imported' => AuditVerbosity::Standard,
            'shift_assignments.imported' => AuditVerbosity::Standard,
            'equipment_inventory.imported' => AuditVerbosity::Standard,
            'training.completions_imported' => AuditVerbosity::Standard,
            'user.imported' => AuditVerbosity::Standard,

            // Detailed: routine operational work during an event.
            'attendance.checked_in' => AuditVerbosity::Detailed,
            'attendance.checked_out' => AuditVerbosity::Detailed,
            'attendance.marked_no_show' => AuditVerbosity::Detailed,
            'equipment.checked_out' => AuditVerbosity::Detailed,
            'equipment_pool.adjusted' => AuditVerbosity::Detailed,
            'deployment.current_set' => AuditVerbosity::Detailed,
            'shift_assignment.assigned' => AuditVerbosity::Detailed,
            'shift_assignment.signed_up' => AuditVerbosity::Detailed,
            'shift_assignment.withdrawn' => AuditVerbosity::Detailed,
            'shift_assignment.removed' => AuditVerbosity::Detailed,
            'shift_assignment.unscheduled_added' => AuditVerbosity::Detailed,
            'shift_assignment.removed_by_credential_revocation' => AuditVerbosity::Detailed,
            'training.signed_up' => AuditVerbosity::Detailed,
            'training.signup_cancelled' => AuditVerbosity::Detailed,
            'training.completion_recorded' => AuditVerbosity::Detailed,
            'waiver_completion.recorded' => AuditVerbosity::Detailed,

            // Complete: everything else the application happens to record.
            'applicant_portal.link_issued' => AuditVerbosity::Complete,
            'organization_inquiry.submitted' => AuditVerbosity::Complete,
            'organization_inquiry.reviewed' => AuditVerbosity::Complete,
            'organization_inquiry.discarded' => AuditVerbosity::Complete,
            'staff.profile.self_updated' => AuditVerbosity::Complete,
        ];
    }

    public static function isRequired(string $action): bool
    {
        return in_array($action, self::REQUIRED, true);
    }

    /**
     * The level an action is first written at, or null when it is not
     * catalogued — in which case it is written at every level.
     */
    public static function levelFor(string $action): ?AuditVerbosity
    {
        return self::levels()[$action] ?? null;
    }

    /**
     * Every action this build knows about, for the configuration surface that
     * offers per-action overrides.
     *
     * @return list<array{action: string, level: string|null, required: bool}>
     */
    public static function inventory(): array
    {
        $inventory = [];

        foreach (self::REQUIRED as $action) {
            $inventory[$action] = [
                'action' => $action,
                'level' => null,
                'required' => true,
            ];
        }

        foreach (self::levels() as $action => $level) {
            $inventory[$action] = [
                'action' => $action,
                'level' => $level->value,
                'required' => false,
            ];
        }

        ksort($inventory);

        return array_values($inventory);
    }
}
