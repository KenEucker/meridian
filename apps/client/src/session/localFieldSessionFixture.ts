// The session document the client's specs run on (M18.9; CLIENT-024).
//
// Nothing in the application imports this. It is the last of the fixtures, and
// what is left of it after M18.9 is scaffolding for tests rather than data
// behind a screen: no route installs it, no environment flag installs it, and
// `app/fixtureIsolation.spec.ts` asserts that no module reachable from `main.ts`
// or `App.vue` can reach it. The `Fixture` in the filename is load-bearing —
// that guard matches on it — so a production import of this module fails the
// suite rather than shipping.
//
// It exists because CLIENT-024 requires the client's tests to run without a live
// server, and because the path they have to exercise starts at a real session
// document. The specs establish a session by installing one of these and let
// capabilities, navigation, branding, and context resolve from it by exactly the
// path `GET /api/me` takes. A test that stubbed navigation directly would prove
// nothing about the rule under test.
//
// It was two modules until M18.9. `field-reports/localFieldFixture.ts` held the
// identifiers and `session/localFieldSession.ts` built the document and
// installed it into the running client behind
// `VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION`. The installer is gone — a
// developer signs in against a seeded node now — and with it the reason the ids
// lived apart from the document that is their only remaining reader.
//
// The identifiers stay aligned with the server's
// `php artisan meridian:seed-local-field-fixture`, which is a real seeder for
// human QA and is not in scope here. Keeping them equal costs nothing and means
// a spec and a QA script can talk about the same event.

import { installClientSession } from "@/session/clientSession";
import {
  CAPABILITY_DEPARTMENT_ADMINISTER,
  CAPABILITY_DEPARTMENT_ATTENDANCE_MANAGE,
  CAPABILITY_DEPARTMENT_BRANDING_MANAGE,
  CAPABILITY_DEPARTMENT_DEPLOYMENTS_ASSIGN,
  CAPABILITY_DEPARTMENT_EQUIPMENT_MANAGE,
  CAPABILITY_DEPARTMENT_PRESENCE_MANAGE,
  CAPABILITY_DEPARTMENT_SCHEDULE_MANAGE,
  CAPABILITY_DOCUMENT_ACKNOWLEDGMENTS_REVIEW,
  CAPABILITY_EVENT_CREDENTIALS_REVOKE,
  CAPABILITY_FIELD_REPORTS_VIEW_EVENT,
  CAPABILITY_INCIDENTS_VIEW,
  CAPABILITY_ORGANIZATION_BRANDING_MANAGE,
  CAPABILITY_ORGANIZATION_DEPARTMENTS_MANAGE,
  CAPABILITY_ORGANIZATION_INCIDENT_TYPES_MANAGE,
  CAPABILITY_ORGANIZATION_STAFF_MANAGE,
  CAPABILITY_POLICIES_VIEW_PUBLISHED,
  ROLE_DEPARTMENT_LEAD,
  ROLE_SHIFT_LEAD,
} from "@/session/permissionCodes";
import type {
  SessionDocument,
  SessionRole,
} from "@/session/sessionDocument";

/** Identities shared with the server's `meridian:seed-local-field-fixture`. */
export const LOCAL_FIELD_FIXTURE = {
  eventId: "11111111-1111-4111-8111-111111111111",
  eventLabel: "Local Field Event",
  submittedByUserId: "22222222-2222-4222-8222-222222222222",
  staffId: "33333333-3333-4333-8333-333333333333",
  originDeviceId: "44444444-4444-4444-8444-444444444444",
  originNodeId: "55555555-5555-4555-8555-555555555555",
  departmentId: "66666666-6666-4666-8666-666666666666",
  departmentLabel: "Rangers",
  teamId: "77777777-7777-4777-8777-777777777777",
  teamLabel: "Command",
} as const;

/** The departments this session's staff member belongs to. */
export const LOCAL_FIELD_DEPARTMENT_IDS = {
  organizer: "22222222-2222-4222-8222-222222222201",
  rangers: LOCAL_FIELD_FIXTURE.departmentId,
  gate: "22222222-2222-4222-8222-222222222202",
  dpw: "22222222-2222-4222-8222-222222222203",
} as const;

export const LOCAL_FIELD_TEAM_IDS = {
  organizerDefault: "77777777-7777-4777-8777-777777777760",
  rangersDefault: "77777777-7777-4777-8777-777777777770",
  rangersDirt: "77777777-7777-4777-8777-777777777771",
  gateDefault: "77777777-7777-4777-8777-777777777780",
  gateCredentials: "77777777-7777-4777-8777-777777777781",
  dpwDefault: "77777777-7777-4777-8777-777777777790",
  dpwBikes: "77777777-7777-4777-8777-777777777791",
} as const;

export const LOCAL_FIELD_ORGANIZATION_ID = "88888888-8888-4888-8888-888888888888";

const ORGANIZATION_ID = LOCAL_FIELD_ORGANIZATION_ID;

const DEPARTMENT_TRAININGS_MANAGE = "department.trainings.manage";

/** Capability sets exactly as `PermissionCatalog::rolePermissions()` maps them. */
const REPORT_CAPABILITIES = [
  "reports.credential_eligibility.export",
  "reports.shift_roster.export",
  "reports.staff_contact.export",
  "reports.hours_worked.export",
  "reports.credits_earned.export",
];

function role(
  overrides: Pick<SessionRole, "role_code" | "role_name" | "team_id" | "team_name" | "capabilities"> &
    Partial<SessionRole> & { readonly department_id: string },
): SessionRole {
  return {
    scope_type: "department",
    organization_id: ORGANIZATION_ID,
    event_id: LOCAL_FIELD_FIXTURE.eventId,
    team_grant_id: null,
    reason: `You have the ${overrides.role_name} role because you are a member of the ${overrides.team_name} team.`,
    ...overrides,
  };
}

/**
 * The roles this session's staff member holds, one department at a time.
 *
 * Rangers is the fullest — department lead who also runs logistics, operations,
 * planning, and Incident Command, and leads the Dirt team — because a spec that
 * wants an authority usually wants that one. The other three exist so the
 * department switcher has something to switch between and so each of the
 * narrower shapes is reachable without building a document by hand.
 */
function localFieldRoles(): SessionRole[] {
  return [
    role({
      role_code: "organizer",
      role_name: "Organizer",
      scope_type: "organization",
      department_id: LOCAL_FIELD_DEPARTMENT_IDS.organizer,
      team_id: LOCAL_FIELD_TEAM_IDS.organizerDefault,
      team_name: "Organizer Default",
      capabilities: [
        CAPABILITY_POLICIES_VIEW_PUBLISHED,
        CAPABILITY_ORGANIZATION_DEPARTMENTS_MANAGE,
        CAPABILITY_ORGANIZATION_STAFF_MANAGE,
        CAPABILITY_ORGANIZATION_INCIDENT_TYPES_MANAGE,
        DEPARTMENT_TRAININGS_MANAGE,
        CAPABILITY_ORGANIZATION_BRANDING_MANAGE,
        CAPABILITY_EVENT_CREDENTIALS_REVOKE,
        CAPABILITY_DOCUMENT_ACKNOWLEDGMENTS_REVIEW,
        ...REPORT_CAPABILITIES,
      ],
    }),
    role({
      role_code: ROLE_DEPARTMENT_LEAD,
      role_name: "Department Lead",
      department_id: LOCAL_FIELD_DEPARTMENT_IDS.rangers,
      team_id: LOCAL_FIELD_TEAM_IDS.rangersDirt,
      team_name: "Dirt",
      capabilities: [
        CAPABILITY_DEPARTMENT_ADMINISTER,
        DEPARTMENT_TRAININGS_MANAGE,
        CAPABILITY_DEPARTMENT_BRANDING_MANAGE,
        ...REPORT_CAPABILITIES,
      ],
    }),
    role({
      role_code: "department_logistics",
      role_name: "Department Logistics",
      department_id: LOCAL_FIELD_DEPARTMENT_IDS.rangers,
      team_id: LOCAL_FIELD_TEAM_IDS.rangersDirt,
      team_name: "Dirt",
      capabilities: [
        CAPABILITY_DEPARTMENT_PRESENCE_MANAGE,
        CAPABILITY_DEPARTMENT_ATTENDANCE_MANAGE,
        CAPABILITY_DEPARTMENT_EQUIPMENT_MANAGE,
      ],
    }),
    role({
      role_code: "department_operations",
      role_name: "Department Operations",
      department_id: LOCAL_FIELD_DEPARTMENT_IDS.rangers,
      team_id: LOCAL_FIELD_TEAM_IDS.rangersDirt,
      team_name: "Dirt",
      capabilities: [CAPABILITY_DEPARTMENT_DEPLOYMENTS_ASSIGN],
    }),
    role({
      role_code: "department_planning",
      role_name: "Department Planning",
      department_id: LOCAL_FIELD_DEPARTMENT_IDS.rangers,
      team_id: LOCAL_FIELD_TEAM_IDS.rangersDirt,
      team_name: "Dirt",
      capabilities: [CAPABILITY_DEPARTMENT_SCHEDULE_MANAGE],
    }),
    role({
      role_code: "ic_lead",
      role_name: "Incident Command Lead",
      scope_type: "event",
      department_id: LOCAL_FIELD_DEPARTMENT_IDS.rangers,
      team_id: LOCAL_FIELD_TEAM_IDS.rangersDirt,
      team_name: "Dirt",
      capabilities: [
        CAPABILITY_INCIDENTS_VIEW,
        "incidents.create",
        "incidents.update",
        "incidents.add_note",
        "incidents.close",
        "incidents.reopen",
        "incidents.link_field_report",
        "incidents.print",
        CAPABILITY_FIELD_REPORTS_VIEW_EVENT,
        "field_reports.download_photo",
        CAPABILITY_EVENT_CREDENTIALS_REVOKE,
      ],
    }),
    role({
      role_code: ROLE_SHIFT_LEAD,
      role_name: "Shift Lead",
      scope_type: "team",
      department_id: LOCAL_FIELD_DEPARTMENT_IDS.rangers,
      team_id: LOCAL_FIELD_TEAM_IDS.rangersDirt,
      team_name: "Dirt",
      reason:
        "You have the Shift Lead role because you are a designated lead of the Dirt team.",
      capabilities: [],
    }),
    role({
      role_code: ROLE_SHIFT_LEAD,
      role_name: "Shift Lead",
      scope_type: "team",
      department_id: LOCAL_FIELD_DEPARTMENT_IDS.dpw,
      team_id: LOCAL_FIELD_TEAM_IDS.dpwBikes,
      team_name: "Bikes",
      reason:
        "You have the Shift Lead role because you are a designated lead of the Bikes team.",
      capabilities: [],
    }),
    role({
      role_code: "staff",
      role_name: "Staff",
      scope_type: "organization",
      department_id: LOCAL_FIELD_DEPARTMENT_IDS.gate,
      team_id: LOCAL_FIELD_TEAM_IDS.gateCredentials,
      team_name: "Credentials",
      capabilities: [],
    }),
  ];
}

/**
 * The document itself.
 *
 * `overrides` exists for the specs, which build the shapes this file does not
 * seed — a user with no capabilities anywhere, a session whose event window has
 * ended — without restating nine top-level keys to change one of them.
 */
export function localFieldSessionDocument(
  overrides: Partial<SessionDocument> = {},
): SessionDocument {
  const roles = localFieldRoles();

  return {
    user: {
      id: LOCAL_FIELD_FIXTURE.submittedByUserId,
      name: "Local Field Author",
      email: "local-field-author@example.test",
      staff_ids: [LOCAL_FIELD_FIXTURE.staffId],
    },
    roles,
    capabilities: [
      ...new Set(roles.flatMap((entry) => entry.capabilities)),
    ].sort(),
    organizations: [
      {
        id: ORGANIZATION_ID,
        name: "Northwood Collective",
        slug: "northwood-collective",
        status: "approved",
        archived_at: null,
      },
    ],
    events: [
      {
        id: LOCAL_FIELD_FIXTURE.eventId,
        organization_id: ORGANIZATION_ID,
        name: LOCAL_FIELD_FIXTURE.eventLabel,
        slug: "local-field-event",
        status: "published",
        timezone: "UTC",
        starts_at: null,
        ends_at: null,
        // No window bound, so a cached copy of this document stays usable. A
        // spec that is about the window sets one.
        active_event_window_starts_at: null,
        active_event_window_ends_at: null,
        is_node_locked: true,
      },
    ],
    departments: [
      department(LOCAL_FIELD_DEPARTMENT_IDS.organizer, "Organizer", "ORG"),
      department(LOCAL_FIELD_DEPARTMENT_IDS.rangers, "Rangers", "RANGERS"),
      department(LOCAL_FIELD_DEPARTMENT_IDS.gate, "Gate", "GATE"),
      department(LOCAL_FIELD_DEPARTMENT_IDS.dpw, "DPW", "DPW"),
    ],
    teams: [
      team(
        LOCAL_FIELD_TEAM_IDS.organizerDefault,
        LOCAL_FIELD_DEPARTMENT_IDS.organizer,
        "Organizer Default",
        "DEFAULT",
        { isDefault: true },
      ),
      team(
        LOCAL_FIELD_TEAM_IDS.rangersDirt,
        LOCAL_FIELD_DEPARTMENT_IDS.rangers,
        "Dirt",
        "DIRT",
        { isLead: true },
      ),
      team(
        LOCAL_FIELD_TEAM_IDS.gateCredentials,
        LOCAL_FIELD_DEPARTMENT_IDS.gate,
        "Credentials",
        "CRED",
      ),
      team(
        LOCAL_FIELD_TEAM_IDS.dpwBikes,
        LOCAL_FIELD_DEPARTMENT_IDS.dpw,
        "Bikes",
        "BIKES",
        { isLead: true },
      ),
    ],
    context: {
      organization_id: ORGANIZATION_ID,
      event_id: LOCAL_FIELD_FIXTURE.eventId,
      // Rangers, so a spec that does not select one lands in the fullest of the
      // four rather than in whichever happens to be first.
      department_id: LOCAL_FIELD_DEPARTMENT_IDS.rangers,
      node_locked: true,
      node_locked_event_id: LOCAL_FIELD_FIXTURE.eventId,
      switching_available: false,
    },
    refreshed_at: new Date(0).toISOString(),
    ...overrides,
  };
}

/** A second association, so a context switcher has somewhere to go (M16.7). */
export const LOCAL_FIELD_OTHER_ORGANIZATION_ID = "org-cascadia-collective";
export const LOCAL_FIELD_OTHER_EVENT_ID = "event-cascadia-thaw-2027";

/**
 * The same session as resolved by a node with no event lock (M16.7).
 *
 * The seeded node is locked to one event, which is the right shape for an
 * on-site node and the wrong shape for exercising a switcher, which by
 * definition only exists where a node is not locked. These overrides give the
 * same user two organizations, two events, and switching offered.
 *
 * The seeded organization stays first and stays the context, so departments,
 * teams, and roles all still resolve against the organization they belong to and
 * only the context questions change.
 */
export function switchableLocalFieldContext(): Pick<
  SessionDocument,
  "organizations" | "events" | "context"
> {
  const seeded = localFieldSessionDocument();

  return {
    organizations: [
      ...seeded.organizations,
      {
        id: LOCAL_FIELD_OTHER_ORGANIZATION_ID,
        name: "Cascadia Collective",
        slug: "cascadia-collective",
        status: "approved",
        archived_at: null,
      },
    ],
    events: [
      ...seeded.events.map((event) => ({ ...event, is_node_locked: false })),
      {
        id: LOCAL_FIELD_OTHER_EVENT_ID,
        organization_id: LOCAL_FIELD_OTHER_ORGANIZATION_ID,
        name: "Cascadia Thaw 2027",
        slug: "cascadia-thaw-2027",
        status: "published",
        timezone: "UTC",
        starts_at: "2027-03-12T16:00:00+00:00",
        ends_at: "2027-03-15T16:00:00+00:00",
        active_event_window_starts_at: null,
        active_event_window_ends_at: null,
        is_node_locked: false,
      },
    ],
    context: {
      ...seeded.context,
      node_locked: false,
      node_locked_event_id: null,
      switching_available: true,
    },
  };
}

/**
 * Establish this session on the client under test.
 *
 * `installClientSession` is the same seam a cached document is restored
 * through, so what a spec gets afterwards is a client in the state a real
 * session leaves it in — capabilities, context, branding, and navigation all
 * derived by the production path (CLIENT-024).
 */
export function installLocalFieldSession(
  overrides: Partial<SessionDocument> = {},
): SessionDocument {
  const document = localFieldSessionDocument(overrides);

  installClientSession(document, "network");

  return document;
}

function department(id: string, name: string, code: string) {
  return {
    id,
    organization_id: ORGANIZATION_ID,
    name,
    code,
    membership_status: "active",
    archived_at: null,
  };
}

function team(
  id: string,
  departmentId: string,
  name: string,
  code: string,
  options: { readonly isDefault?: boolean; readonly isLead?: boolean } = {},
) {
  return {
    id,
    department_id: departmentId,
    organization_id: ORGANIZATION_ID,
    name,
    code,
    is_default: options.isDefault ?? false,
    is_lead: options.isLead ?? false,
    archived_at: null,
  };
}
