<?php

declare(strict_types=1);

namespace App\Services\Console;

/**
 * The orientation summary on the God Mode landing screen (GOD-002, GOD-003,
 * GOD-004; technical spec 22.5.1).
 *
 * The console is where somebody lands who has to repair Meridian without
 * necessarily having run an event on it. What they need first is not a feature
 * tour but the shape of the domain: what owns what, what is authoritative when,
 * and which records refuse to be edited.
 *
 * The content lives here rather than in the Blade template so the boundary
 * statement and the topic coverage are assertable, and so the landing screen
 * stays a screen rather than a document.
 */
final class ConsoleOrientation
{
    public const BOUNDARY = 'God Mode is repair and break-glass tooling. Normal organizer, department, and staff workflows belong in Meridian Admin, not here.';

    public function summary(): string
    {
        return 'Meridian runs volunteer operations for events. This console is the repair surface beneath that product.';
    }

    public function boundary(): string
    {
        return self::BOUNDARY;
    }

    /**
     * How Meridian works end to end (GOD-003). One bullet per concept, ordered
     * the way the data depends on itself: organizations own departments, events
     * borrow departments, staff join teams, teams take shifts, shifts produce
     * hours.
     *
     * @return list<array{heading: string, body: string}>
     */
    public function topics(): array
    {
        return [
            [
                'heading' => 'Organizations and departments',
                'body' => 'An organization owns its departments, staff records, policies, trainings, and waivers. A department owns teams, and every department membership exists through a team membership. One department is designated the Organizers Department, which is how organization-level authority is granted; another is the default Incident Command Department.',
            ],
            [
                'heading' => 'Events and the active event window',
                'body' => 'An event belongs to one organization and borrows departments through event department assignments. The active event window is the period an event is being run, and it changes who may write what: governance data such as policies, procedures, and branding freezes, and the on-site node becomes authoritative for event-scoped operations.',
            ],
            [
                'heading' => 'Staff, teams, and roles',
                'body' => 'A person is a user; a staff record is that person inside one organization, with its own status. Roles are not held directly — they are granted to teams, and a staff member holds a role because they are a member of the granted team. Some roles are further narrowed, such as shift lead applying only to designated team leads.',
            ],
            [
                'heading' => 'Shifts and eligibility',
                'body' => 'Shifts belong to a department and event and are staffed from eligible teams. Eligibility checks training completion, waiver completion, team membership, capacity, and the schedule lock. Event credential eligibility follows from having signed-up shifts, so removing the last future shift blocks the credential.',
            ],
            [
                'heading' => 'Operations, attendance, and hours',
                'body' => 'During an event, staff are checked in and out against a shift. Scheduled shifts and recorded hours are different records: check-out creates an actual hours record, and hours never exist without a shift and department. Corrections happen inside a grace period, after which credit calculation freezes.',
            ],
            [
                'heading' => 'Policies, procedures, and acknowledgments',
                'body' => 'Policy and procedure documents are versioned and composed from reusable fragments. Acknowledgment requirements are scoped to an organization, department, or event, and an acknowledgment is recorded against the exact document version that was accepted.',
            ],
            [
                'heading' => 'Field reports and incidents',
                'body' => 'Field reports are created in the field, may be created offline, and are immutable once submitted — corrections are appended, never edited in place. Incidents are online-only and visible to Incident Command roles, which resolve against the effective Incident Command Department. Field reports are linked to incidents rather than absorbed by them.',
            ],
            [
                'heading' => 'Nodes, sync, and authority',
                'body' => 'A central node holds configuration and organization governance. An on-site node runs the event and pairs with central using a one-time token. Sync is append-only signed operations, and it is idempotent: an operation delivered twice applies once. During the active event window the on-site node is authoritative for event-scoped writes and central refuses them. Operations that cannot be applied become sync conflicts, which wait here and do not block unrelated sync.',
            ],
        ];
    }
}
