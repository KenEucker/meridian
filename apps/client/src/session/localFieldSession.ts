// A session document standing in for `GET /api/me` in local development
// (M16.6; CLIENT-001, CLIENT-024).
//
// Navigation now follows the session response, and the client holds no bearer
// token until login is wired into it, so a developer running `npm run dev`
// against a seeded node would otherwise be shown a shell with nothing in it. The
// same compromise the Field Report, IMS, organizer, and department-admin
// surfaces already make applies here: a clearly labeled development session,
// installed only behind an explicit environment flag.
//
// Two rules keep it honest, and they are the whole reason this is not the
// fixture-driven navigation CLIENT-001 forbids:
//
//  1. **The node always wins.** This is installed only after a refresh has
//     failed to produce a document. A node that answers replaces it, and every
//     later refresh replaces it again.
//  2. **It is a document, not a decision.** It carries the same role codes and
//     capability codes the permission catalog publishes, and navigation derives
//     from it by exactly the path a real response takes. Nothing reads it
//     directly, and there is no branch anywhere that behaves differently because
//     the document came from here.
//
// It goes when a login surface lands and a developer can sign in for real.

import {
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
  LOCAL_FIELD_TEAM_IDS,
} from "@/field-reports/localFieldFixture";
import {
  clientSessionState,
  installClientSession,
} from "@/session/clientSession";
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

/** Shared with the server fixture `meridian:seed-local-field-fixture` seeds. */
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
 * The roles the local fixture's staff member holds, one department at a time.
 *
 * Rangers is the fullest role in the seed — department lead who also runs
 * logistics, operations, planning, and Incident Command, and leads the Dirt team
 * — because that is the account a developer wants to land in. The other three
 * exist so the department switcher has something to switch between and so each
 * of the narrower shapes is reachable without editing anything.
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
        // development node is not running to a schedule.
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
      // Rangers, so a developer lands in the fullest of the four rather than in
      // whichever one happens to be first.
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
 * The seeded node is locked to one event, which is the right shape for the
 * development fixture — it stands for an on-site node — and the wrong shape for
 * exercising a switcher, which by definition only exists where a node is not
 * locked. These overrides give the same user two organizations, two events, and
 * switching offered.
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
 * Establish the local development session.
 *
 * Also what the specs establish a session through, which is the point of
 * CLIENT-024: the client's tests exercise the real capability-to-navigation path
 * against a real document, with no server running.
 */
export function installLocalFieldSession(
  overrides: Partial<SessionDocument> = {},
): SessionDocument {
  const document = localFieldSessionDocument(overrides);

  installClientSession(document, "network");

  return document;
}

/**
 * Install the local development session when the environment asks for it, the
 * client has no session of its own, and the node has not just refused it.
 *
 * Shares `VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION` with the Field Report
 * development session: they stand for the same seeded staff member on the same
 * seeded node, and two switches for one fixture is one switch too many.
 *
 * Two states it stays out of, and they are different states (M16.11):
 *
 *  1. **A session is held.** A developer can now sign in for real, and a real
 *     session resolved yesterday and booted from cache today must not be
 *     replaced by a fixture because the node happened to be unreachable this
 *     morning.
 *  2. **A credential this client held was refused.** A revoked token or a
 *     revoked device arrives as an unauthenticated refresh (AUTH-023), and the
 *     honest state after one is signed out. Filling the shell back in with a
 *     fixture would show a developer a populated session at the exact moment the
 *     node stopped accepting them — the one moment the screen has to be
 *     believed.
 *
 *     Deliberately narrower than "the refresh was unauthenticated". A client
 *     that has never signed in is refused too, and that is the case this fixture
 *     exists for: a developer who has not signed in should still see a populated
 *     shell. Only a credential that was held and then refused means somebody was
 *     signed out.
 */
export function installLocalFieldSessionFromEnv(
  options: {
    readonly credentialRefused?: boolean;
    readonly env?: Pick<
      ImportMetaEnv,
      "VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION"
    >;
  } = {},
): boolean {
  const env = options.env ?? import.meta.env;

  if (
    env.VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION !== "true" ||
    clientSessionState.document !== null ||
    options.credentialRefused === true
  ) {
    return false;
  }

  installLocalFieldSession();

  return true;
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
