import { afterEach, describe, expect, it } from "vitest";

import {
  useNavigationSections,
  useStaffLinks,
  useWorkflowLinks,
} from "@/components/workflowLinks";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import { localFieldSessionDocument } from "@/session/localFieldSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import type { SessionDocument, SessionRole } from "@/session/sessionDocument";

/**
 * Navigation derives from the capability codes the session response carries and
 * from nothing else (M16.6; CLIENT-004, CLIENT-005; UI contract 19A.1).
 *
 * No server is involved anywhere in this file, which is the other half of what
 * this task owes: removing fixture-driven behavior must not cost the client the
 * ability to run its tests without one (CLIENT-024). Every case here installs a
 * document of exactly the shape `GET /api/me` returns and reads the navigation
 * back out.
 */

const ORGANIZATION_ID = "org-1";
const EVENT_ID = "event-1";
const DEPARTMENT_ID = "dept-1";
const TEAM_ID = "team-1";

/**
 * A session for one user, in one department, holding exactly what is asked for.
 *
 * Deliberately minimal: a document carrying one capability and one role is the
 * only way to say "this entry appears because of this code" without a second
 * grant quietly keeping it on screen.
 */
function sessionWith(options: {
  readonly capabilities?: readonly string[];
  readonly roleCode?: string;
  readonly isTeamLead?: boolean;
  readonly departments?: readonly { readonly id: string; readonly name: string }[];
}): SessionDocument {
  const role: SessionRole = {
    role_code: options.roleCode ?? "staff",
    role_name: "Test Role",
    scope_type: "department",
    organization_id: ORGANIZATION_ID,
    department_id: DEPARTMENT_ID,
    team_id: TEAM_ID,
    team_name: "Team One",
    event_id: EVENT_ID,
    team_grant_id: "grant-1",
    reason: "Test grant.",
    capabilities: options.capabilities ?? [],
  };

  return localFieldSessionDocument({
    roles: [role],
    capabilities: [...(options.capabilities ?? [])],
    events: [
      {
        id: EVENT_ID,
        organization_id: ORGANIZATION_ID,
        name: "Test Event",
        slug: "test-event",
        status: "published",
        timezone: "UTC",
        starts_at: null,
        ends_at: null,
        active_event_window_starts_at: null,
        active_event_window_ends_at: null,
        is_node_locked: true,
      },
    ],
    departments: [
      {
        id: DEPARTMENT_ID,
        organization_id: ORGANIZATION_ID,
        name: "Test Department",
        code: "TEST",
        membership_status: "active",
        archived_at: null,
      },
    ],
    teams: [
      {
        id: TEAM_ID,
        department_id: DEPARTMENT_ID,
        organization_id: ORGANIZATION_ID,
        name: "Team One",
        code: "ONE",
        is_default: false,
        is_lead: options.isTeamLead ?? false,
        archived_at: null,
      },
    ],
    context: {
      organization_id: ORGANIZATION_ID,
      event_id: EVENT_ID,
      department_id: DEPARTMENT_ID,
      node_locked: true,
      node_locked_event_id: EVENT_ID,
      switching_available: false,
    },
  });
}

function install(document: SessionDocument): void {
  installClientSession(document, "network");
  selectSessionDepartment(DEPARTMENT_ID);
}

/** Every label the client would render anywhere in navigation. */
function everyNavigationLabel(): string[] {
  return [
    ...useStaffLinks().value.map((link) => link.label),
    ...useWorkflowLinks().value.map((link) => link.label),
    ...useNavigationSections().value.flatMap((section) =>
      section.links.map((link) => link.pageLabel ?? link.label),
    ),
  ];
}

function sectionLabels(title: string): string[] {
  return (
    useNavigationSections()
      .value.find((section) => section.title === title)
      ?.links.map((link) => link.label) ?? []
  );
}

afterEach(() => {
  clearClientSession();
  resetSelectedSessionDepartment();
});

describe("navigation without a permitting capability", () => {
  it("renders nothing at all for a client holding no session", () => {
    // CLIENT-005 at its limit: not a reduced menu, no menu. A client that has
    // not resolved a session has no user to build one for.
    expect(useStaffLinks().value).toEqual([]);
    expect(useWorkflowLinks().value).toEqual([]);
    expect(useNavigationSections().value).toEqual([]);
  });

  it("renders no department or organization surface for a user holding no capability", () => {
    install(sessionWith({}));

    const labels = everyNavigationLabel();

    for (const surface of [
      "Overview",
      "Team Overview",
      "Planning",
      "Logistics",
      "Operations",
      "Incidents",
      "Admin",
      "Equipment",
      "Branding",
      "Departments",
      "Staff",
      "Field Reports",
      "Credentials",
    ]) {
      expect(labels).not.toContain(surface);
    }

    // What is left is the user's own pages and their department's member pages,
    // which are association-permitted rather than capability-permitted, plus the
    // device diagnostics the catalog registers nothing for.
    expect(useStaffLinks().value.map((link) => link.label)).toEqual([
      "Me",
      "Event Info",
      "Shifts",
      "My Field Reports",
      "Documents",
      "Trainings",
    ]);
    expect(sectionLabels("Device")).toEqual(["Readiness", "Health"]);
  });

  it.each([
    ["department.schedule.manage", "Planning"],
    ["department.attendance.manage", "Logistics"],
    ["department.presence.manage", "Logistics"],
    ["department.deployments.assign", "Operations"],
    ["incidents.view", "Incidents"],
    ["department.administer", "Admin"],
  ])("renders the %s workflow only for a holder of its capability", (
    capability,
    label,
  ) => {
    install(sessionWith({}));
    expect(useWorkflowLinks().value.map((link) => link.label)).not.toContain(
      label,
    );

    install(sessionWith({ capabilities: [capability] }));
    expect(useWorkflowLinks().value.map((link) => link.label)).toContain(label);
  });

  it.each([
    ["department.equipment.manage", "Equipment", "Department pages"],
    ["department.branding.manage", "Branding", "Department pages"],
    ["organization.departments.manage", "Departments", "Organization pages"],
    ["organization.staff.manage", "Staff", "Organization pages"],
    ["organization.branding.manage", "Branding", "Organization pages"],
    ["policies.view_published", "Documents", "Organization pages"],
    [
      "reports.credential_eligibility.export",
      "Credentials",
      "Organization pages",
    ],
  ])("lists %s in the home directory only for a holder of its capability", (
    capability,
    label,
    section,
  ) => {
    install(sessionWith({}));
    expect(sectionLabels(section)).not.toContain(label);

    install(sessionWith({ capabilities: [capability] }));
    expect(sectionLabels(section)).toContain(label);
  });

  it("offers Department Overview to a department lead and to nobody else", () => {
    // Role-permitted: the catalog registers no capability for a department
    // lead's own overview, so the effective role code the same response carries
    // is what permits it.
    install(sessionWith({ capabilities: ["department.administer"] }));
    expect(useWorkflowLinks().value.map((link) => link.label)).not.toContain(
      "Overview",
    );

    install(
      sessionWith({
        roleCode: "department_lead",
        capabilities: ["department.administer"],
      }),
    );
    expect(useWorkflowLinks().value.map((link) => link.label)).toContain(
      "Overview",
    );
  });

  it("offers Team Overview only where the designation and the grant agree", () => {
    // A lead designation with no shift lead grant is a title. The server would
    // refuse the surface it opens, so the client does not offer it.
    install(sessionWith({ isTeamLead: true }));
    expect(useWorkflowLinks().value.map((link) => link.label)).not.toContain(
      "Team",
    );

    install(sessionWith({ roleCode: "shift_lead" }));
    expect(useWorkflowLinks().value.map((link) => link.label)).not.toContain(
      "Team",
    );

    install(sessionWith({ roleCode: "shift_lead", isTeamLead: true }));
    const team = useWorkflowLinks().value.find((link) => link.label === "Team");
    expect(team?.to.name).toBe("events.departments.teams.show");
    expect(team?.to.params?.teamId).toBe(TEAM_ID);
  });
});

describe("navigation and the session verdict", () => {
  it("drops every entry when a cached session outlives its event window", () => {
    // CLIENT-008: past the locked event's window a cached document grants
    // nothing, and navigation is the first place that has to be true.
    const document = sessionWith({ capabilities: ["department.administer"] });
    const expired: SessionDocument = {
      ...document,
      events: [
        {
          ...document.events[0]!,
          active_event_window_ends_at: "2026-01-01T00:00:00+00:00",
        },
      ],
    };

    installClientSession(expired, "cache", new Date("2026-06-01T00:00:00+00:00"));
    selectSessionDepartment(DEPARTMENT_ID);

    expect(useNavigationSections().value).toEqual([]);
    expect(useWorkflowLinks().value).toEqual([]);
  });

  it("removes an entry as soon as a refresh takes its capability away", () => {
    // CLIENT-010: a reduction applies on refresh, not on next login. The
    // document replaces rather than merges, so there is nowhere for a withdrawn
    // capability to survive.
    install(sessionWith({ capabilities: ["department.administer"] }));
    expect(useWorkflowLinks().value.map((link) => link.label)).toContain("Admin");

    installClientSession(sessionWith({}), "network");

    expect(useWorkflowLinks().value.map((link) => link.label)).not.toContain(
      "Admin",
    );
  });
});

describe("navigation across the user's departments", () => {
  it("answers each department from the codes scoped to it", () => {
    // Authority is scoped: running logistics in one department says nothing
    // about the next one, and a flat capability list cannot express that.
    const document = localFieldSessionDocument();

    installClientSession(document, "network");

    selectSessionDepartment("22222222-2222-4222-8222-222222222202");
    expect(useWorkflowLinks().value.map((link) => link.label)).toEqual([]);

    selectSessionDepartment("66666666-6666-4666-8666-666666666666");
    expect(useWorkflowLinks().value.map((link) => link.label)).toEqual([
      "Overview",
      "Planning",
      "Logistics",
      "Operations",
      "Incidents",
      "Admin",
    ]);
  });
});
