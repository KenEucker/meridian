import { afterEach, describe, expect, it } from "vitest";

import {
  useCombinedNavigation,
  useNavigationSections,
  useStaffLinks,
  useStaffMenuLinks,
  useWorkflowLinks,
  useWorkflowMenuLinks,
} from "@/components/workflowLinks";
import { resetMenuPageAnswers } from "@/session/menuPages";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import {
  LOCAL_FIELD_DEPARTMENT_IDS,
  localFieldOrganizationsWithout,
  localFieldSessionDocument,
} from "@/session/localFieldSessionFixture";
import {
  MODULE_DOCUMENTS,
  MODULE_EQUIPMENT,
  MODULE_EVENT_GEOGRAPHY,
  MODULE_INCIDENT_MANAGEMENT,
  MODULE_KEYS,
  MODULE_QUALIFICATIONS,
  MODULE_SCHEDULING,
  type ModuleKey,
} from "@/session/sessionModules";
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
  resetMenuPageAnswers();
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
      "Exports",
      // M18.30's three.
      "Roster",
      "Deployments",
      "Credits",
    ]) {
      expect(labels).not.toContain(surface);
    }

    // What is left is the user's own pages and their department's member pages,
    // which are association-permitted rather than capability-permitted, plus the
    // device diagnostics the catalog registers nothing for.
    expect(useStaffLinks().value.map((link) => link.label)).toEqual([
      "Me",
      "Event Info",
      // M18.28: the staff dashboard is personal too, and gated by no capability
      // — every widget on it is about the reader's own record.
      "Dashboard",
      "Shifts",
      "My Field Reports",
      "Trainings",
    ]);

    /*
     * M18.69: Acknowledgments and the document library are personal pages and
     * gated by no capability — being asked to read a document is a fact about
     * somebody rather than a permission granted to them — but neither is a
     * place anybody works out of, so they are listed on Home and kept out of
     * the menu.
     */
    expect(sectionLabels("You")).toContain("Acknowledgments");
    expect(sectionLabels("You")).toContain("Documents");
    expect(useStaffLinks().value.map((link) => link.label)).not.toContain(
      "Acknowledgments",
    );

    expect(sectionLabels("Device")).toEqual(["Readiness", "Health"]);
  });

  it("sends the personal Documents entry to the staff library rather than to a department", () => {
    // M18.7: which documents reach somebody is answered from their own
    // memberships, so the entry is neither department-scoped nor conditional on
    // holding a department with teams — the same reason the shift board is not.
    install(sessionWith({ capabilities: ["department.administer"] }));

    const documents = useNavigationSections()
      .value.find((section) => section.title === "You")
      ?.links.find((link) => link.label === "Documents");

    expect(documents?.to.name).toBe("staff.documents.index");
    expect(documents?.to.params).toBeUndefined();
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

  it.each([
    ["department_lead", "Department pages", "Organization pages"],
    ["organizer", "Organization pages", "Department pages"],
    ["lead_organizer", "Organization pages", "Department pages"],
  ])(
    "offers a %s the export surface written for their reach and not the other one",
    (roleCode, listed, absent) => {
      /*
       * M18.26, REPORT-014. The two export surfaces are permitted by the same
       * five codes, so the capability cannot tell them apart — an organizer
       * exports the whole event and a department role exports its own
       * department, and each entry leads to the surface that states that scope.
       */
      install(
        sessionWith({
          roleCode,
          capabilities: ["reports.hours_worked.export"],
        }),
      );

      expect(sectionLabels(listed)).toContain("Exports");
      expect(sectionLabels(absent)).not.toContain("Exports");
    },
  );

  it("sends each Exports entry to its own surface", () => {
    install(
      sessionWith({
        roleCode: "department_lead",
        capabilities: ["reports.hours_worked.export"],
      }),
    );

    const departmentExports = useNavigationSections()
      .value.find((section) => section.title === "Department pages")
      ?.links.find((link) => link.label === "Exports");

    expect(departmentExports?.to.name).toBe("events.departments.exports.index");
    // Department-scoped in its route, because it is department-scoped in its
    // requests.
    expect(departmentExports?.to.params?.departmentId).toBe(DEPARTMENT_ID);

    install(
      sessionWith({
        roleCode: "organizer",
        capabilities: ["reports.hours_worked.export"],
      }),
    );

    const organizerExports = useNavigationSections()
      .value.find((section) => section.title === "Organization pages")
      ?.links.find((link) => link.label === "Exports");

    expect(organizerExports?.to.name).toBe("organizer.exports.index");
    expect(organizerExports?.to.params).toBeUndefined();
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

describe("the M18.29 context and organizer surfaces", () => {
  it("offers event administration and audit review on their own capabilities", () => {
    // UI contract 12.6: both are organizer surfaces, and each answers to its own
    // code rather than to "being an organizer" — reading who changed something
    // is a different job from changing it.
    install(sessionWith({ roleCode: "organizer", capabilities: [] }));

    expect(sectionLabels("Organization pages")).not.toContain("Events");
    expect(sectionLabels("Organization pages")).not.toContain("Audit");

    install(
      sessionWith({
        roleCode: "organizer",
        capabilities: ["organization.events.manage"],
      }),
    );

    expect(sectionLabels("Organization pages")).toContain("Events");
    expect(sectionLabels("Organization pages")).not.toContain("Audit");

    install(
      sessionWith({
        roleCode: "organizer",
        capabilities: ["organization.audit.review"],
      }),
    );

    expect(sectionLabels("Organization pages")).toContain("Audit");
    expect(sectionLabels("Organization pages")).not.toContain("Events");
  });

  it("sends each organizer entry to its own surface", () => {
    install(
      sessionWith({
        roleCode: "organizer",
        capabilities: ["organization.events.manage", "organization.audit.review"],
      }),
    );

    const organizationPages = useNavigationSections().value.find(
      (section) => section.title === "Organization pages",
    );

    expect(
      organizationPages?.links.find((link) => link.label === "Events")?.to.name,
    ).toBe("organizer.events.index");
    expect(
      organizationPages?.links.find((link) => link.label === "Audit")?.to.name,
    ).toBe("organizer.audit.index");
  });

  it("offers the department context screen on the session alone", () => {
    /*
     * UI contract 12.2: entering a department space needs no capability, unlike
     * the two switchers above it — the departments in scope are already part of
     * the session, which is why this entry survives a locked node.
     */
    install(sessionWith({}));

    const context = useNavigationSections().value.find(
      (section) => section.title === "Context",
    );
    const departments = context?.links.find(
      (link) => link.label === "Departments",
    );

    expect(departments?.to.name).toBe("events.departments.index");
    expect(departments?.to.params?.eventId).toBe(EVENT_ID);
    // And the two connected-only switchers are absent on a locked node, which
    // is what makes this a different gate rather than the same one.
    expect(context?.links.map((link) => link.label)).toEqual(["Departments"]);
  });

  it("offers no department context screen to a session carrying no departments", () => {
    install(
      localFieldSessionDocument({
        departments: [],
        teams: [],
        roles: [],
        capabilities: [],
      }),
    );

    expect(
      useNavigationSections()
        .value.find((section) => section.title === "Context")
        ?.links.map((link) => link.label) ?? [],
    ).not.toContain("Departments");
  });
});

describe("the M18.30 department surfaces", () => {
  it("offers the roster to administration, to planning, and to a team lead", () => {
    // UI contract 12.4: "department administration/planning or permitted lead".
    // Three standings, three checks — a team lead holds no capability at all
    // and reaches it through the role, the way Team Overview beside it does.
    install(sessionWith({ capabilities: ["department.administer"] }));
    expect(sectionLabels("Department pages")).toContain("Roster");

    install(sessionWith({ capabilities: ["department.schedule.manage"] }));
    expect(sectionLabels("Department pages")).toContain("Roster");

    install(sessionWith({ roleCode: "shift_lead", isTeamLead: true }));
    expect(sectionLabels("Department pages")).toContain("Roster");

    // And not to a department member holding neither.
    install(sessionWith({ capabilities: ["department.equipment.manage"] }));
    expect(sectionLabels("Department pages")).not.toContain("Roster");
  });

  it("offers deployments on either the assign capability or the admin one", () => {
    install(sessionWith({ capabilities: ["department.deployments.assign"] }));
    expect(sectionLabels("Department pages")).toContain("Deployments");

    install(sessionWith({ capabilities: ["department.administer"] }));
    expect(sectionLabels("Department pages")).toContain("Deployments");

    install(sessionWith({ capabilities: ["department.presence.manage"] }));
    expect(sectionLabels("Department pages")).not.toContain("Deployments");
  });

  it("offers credit review on the credits export capability and on no other", () => {
    // Reading the ledger and downloading it are the same rows and the same
    // disclosure, so they answer to one code rather than two.
    install(sessionWith({ capabilities: ["reports.credits_earned.export"] }));
    expect(sectionLabels("Department pages")).toContain("Credits");

    install(sessionWith({ capabilities: ["reports.hours_worked.export"] }));
    expect(sectionLabels("Department pages")).not.toContain("Credits");
  });

  it("sends each department entry to its own surface", () => {
    install(
      sessionWith({
        capabilities: [
          "department.administer",
          "department.deployments.assign",
          "reports.credits_earned.export",
        ],
      }),
    );

    const departmentPages = useNavigationSections().value.find(
      (section) => section.title === "Department pages",
    );

    expect(
      departmentPages?.links.find((link) => link.label === "Roster")?.to.name,
    ).toBe("events.departments.roster");
    expect(
      departmentPages?.links.find((link) => link.label === "Deployments")?.to.name,
    ).toBe("events.departments.deployments.index");
    expect(
      departmentPages?.links.find((link) => link.label === "Credits")?.to.name,
    ).toBe("events.departments.credits.index");
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
      // M18.28: the department's own dashboard, ahead of the workflows in it.
      "Dashboard",
      "Overview",
      "Planning",
      "Logistics",
      "Operations",
      "Incidents",
      "Admin",
    ]);
  });
});

/**
 * Pages the reader has put away (M18.69).
 *
 * The cases above install a document that hides nothing, so they read the
 * capability derivation on its own. These read the other half: the same
 * derivation with a preference applied on top, which is the order the module
 * works in — permitted first, wanted second.
 *
 * The dashboards are the interesting entry because one key covers four routes
 * across three sections, which is the case where a filter that ran per-list
 * instead of per-route would let one of them through.
 */
describe("navigation the reader has hidden", () => {
  it("hides the dashboards from every surface at once", () => {
    installClientSession(
      localFieldSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
    );
    selectSessionDepartment("66666666-6666-4666-8666-666666666666");

    expect(useWorkflowLinks().value.map((link) => link.label)).toEqual([
      "Overview",
      "Planning",
      "Logistics",
      "Operations",
      "Incidents",
      "Admin",
    ]);
    expect(useStaffLinks().value.map((link) => link.label)).not.toContain(
      "Dashboard",
    );
    expect(everyNavigationLabel()).not.toContain("Incident Command dashboard");
    expect(everyNavigationLabel()).not.toContain("Organizer dashboard");
  });

  /*
   * The whole point of the preference. A page that is hidden is still a page
   * the session permits, so nothing about authority may move — otherwise a
   * reader tidying their menu would be quietly narrowing what they can do.
   */
  it("takes nothing else away with them", () => {
    installClientSession(localFieldSessionDocument(), "network");
    selectSessionDepartment("66666666-6666-4666-8666-666666666666");

    const shown = everyNavigationLabel();

    clearClientSession();
    installClientSession(
      localFieldSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
    );
    selectSessionDepartment("66666666-6666-4666-8666-666666666666");

    const hidden = everyNavigationLabel();

    // Compared as sets, because `everyNavigationLabel` reads the menus and the
    // Home directory and a page appears in both.
    const lost = [...new Set(shown.filter((label) => !hidden.includes(label)))];

    expect(lost.sort()).toEqual(["Dashboard", "Incident Command dashboard"]);
    expect(hidden.filter((label) => !shown.includes(label))).toEqual([]);
  });

  /*
   * A session document from a node that predates the field, or a cached one
   * written by an older build. The reader gets the defaults rather than an
   * empty set, so the menu does not change shape depending on which build last
   * wrote the cache.
   */
  it("falls back to the catalog defaults when the document carries no preferences", () => {
    installClientSession(
      localFieldSessionDocument({ preferences: undefined }),
      "network",
    );
    selectSessionDepartment("66666666-6666-4666-8666-666666666666");

    expect(useWorkflowLinks().value.map((link) => link.label)).not.toContain(
      "Dashboard",
    );
  });

  /* A section emptied by the preference goes rather than standing as a heading
     over nothing. */
  it("drops a section the preference has emptied", () => {
    installClientSession(
      localFieldSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
    );
    selectSessionDepartment("66666666-6666-4666-8666-666666666666");

    for (const section of useNavigationSections().value) {
      expect(section.links.length).toBeGreaterThan(0);
    }
  });
});

/*
 * Modules the organization does not run (M19.16; MOD-015; technical spec
 * 15A.5, 15A.8).
 *
 * A third filter over the same lists, and the one that is not about the reader.
 * The cases above vary a capability and a preference for one person; these vary
 * nothing about the person at all — the session carries every capability the
 * fixture grants, and the entries go anyway, because the organization does not
 * run the product they belong to.
 *
 * Each case names the entries that must go and then checks that the core ones
 * beside them stayed. That second half is the requirement doing the work: the
 * QA gate for this milestone is an organization with three modules off that
 * still intakes staff, runs status, checks people in and out, and records hours,
 * so a filter that took a neighbour with it would be the failure.
 */
describe("navigation for modules the organization does not run", () => {
  const DEPARTMENT = "66666666-6666-4666-8666-666666666666";

  function installWithout(...inactive: readonly ModuleKey[]): void {
    installClientSession(
      localFieldSessionDocument({
        organizations: localFieldOrganizationsWithout(...inactive),
      }),
      "network",
    );
    selectSessionDepartment(DEPARTMENT);
  }

  /**
   * The same, read from the Organizer department.
   *
   * The organizer capabilities in this fixture are granted there, so the
   * Organization pages section is empty from Rangers — a "not offered"
   * assertion made from the wrong department would pass without gating
   * anything.
   */
  function installOrganizerWithout(...inactive: readonly ModuleKey[]): void {
    installWithout(...inactive);
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);
  }

  /*
   * The control every case below is read against. Without it a filter that
   * removed an entry for some unrelated reason — a capability the fixture
   * stopped granting, a label somebody renamed — would read as a passing module
   * test, and the whole file would go green while gating nothing.
   */
  it("offers all of it to a reader whose organization runs everything", () => {
    installWithout();

    const labels = everyNavigationLabel();

    for (const offered of [
      "Shift Board",
      "My Field Reports",
      "Field Reports",
      "Incident Command dashboard",
      "Policies & Procedures",
      "Acknowledgments",
      "Waivers",
      "Trainings",
      "Credentials",
      "Equipment",
      "Deployments",
    ]) {
      expect(labels).toContain(offered);
    }

    expect(useWorkflowLinks().value.map((link) => link.label)).toContain(
      "Incidents",
    );
    expect(sectionLabels("Department pages")).toContain("Shifts");
    expect(sectionLabels("Department pages")).toContain("Documents");

    installOrganizerWithout();

    for (const offered of ["Documents", "Acknowledgments", "Waivers", "Credentials"]) {
      expect(sectionLabels("Organization pages")).toContain(offered);
    }
  });

  it("takes the shift board and shift administration away with Scheduling", () => {
    installWithout(MODULE_SCHEDULING);

    const labels = everyNavigationLabel();

    expect(labels).not.toContain("Shift Board");
    expect(sectionLabels("Department pages")).not.toContain("Shifts");

    /*
     * Planning stays. The Planning Table composes several modules' records and
     * MOD-019 keeps it rendering with any of them off — omitting the shift
     * sections is M19.18's work on the surface itself, not a reason to remove
     * the door.
     */
    expect(useWorkflowLinks().value.map((link) => link.label)).toContain(
      "Planning",
    );
    expect(labels).toContain("Logistics");
  });

  it("takes the Incidents workspace and every Field Report page away with Incident Management", () => {
    installWithout(MODULE_INCIDENT_MANAGEMENT);

    const labels = everyNavigationLabel();

    expect(useWorkflowLinks().value.map((link) => link.label)).not.toContain(
      "Incidents",
    );
    expect(labels).not.toContain("My Field Reports");
    expect(labels).not.toContain("Field Reports");
    expect(labels).not.toContain("Incident Command dashboard");
  });

  it("takes documents, acknowledgments, and waivers away with Documents", () => {
    installWithout(MODULE_DOCUMENTS);

    const labels = everyNavigationLabel();

    expect(labels).not.toContain("Policies & Procedures");
    expect(labels).not.toContain("Acknowledgments");
    expect(labels).not.toContain("Waivers");
    expect(sectionLabels("Department pages")).not.toContain("Documents");

    installOrganizerWithout(MODULE_DOCUMENTS);

    for (const gone of ["Documents", "Acknowledgments", "Waivers"]) {
      expect(sectionLabels("Organization pages")).not.toContain(gone);
    }

    // The organizer's own surfaces that read no document stand.
    expect(sectionLabels("Organization pages")).toContain("Staff");
    expect(sectionLabels("Organization pages")).toContain("Configuration");
  });

  it("takes trainings and credentials away with Qualifications", () => {
    installWithout(MODULE_QUALIFICATIONS);
    expect(everyNavigationLabel()).not.toContain("Trainings");

    installOrganizerWithout(MODULE_QUALIFICATIONS);
    expect(sectionLabels("Organization pages")).not.toContain("Credentials");
  });

  it("takes inventory away with Equipment and deployment options away with Event Geography", () => {
    installWithout(MODULE_EQUIPMENT);
    expect(sectionLabels("Department pages")).not.toContain("Equipment");
    // The Logistics desk is where equipment is handed over, and it reads three
    // capabilities of which equipment is one. It stays (MOD-019).
    expect(useWorkflowLinks().value.map((link) => link.label)).toContain(
      "Logistics",
    );

    installWithout(MODULE_EVENT_GEOGRAPHY);
    expect(sectionLabels("Department pages")).not.toContain("Deployments");
  });

  it("leaves the core product standing with three modules off at once", () => {
    // The milestone QA gate, as navigation: Scheduling, Incident Management,
    // and Documents all inactive, and the organization still runs.
    installWithout(MODULE_SCHEDULING, MODULE_INCIDENT_MANAGEMENT, MODULE_DOCUMENTS);

    const labels = everyNavigationLabel();

    for (const kept of [
      "Me",
      "Event Info",
      "Logistics",
      "Operations",
      "Roster",
      "Credits",
      "Exports",
      "Admin",
      "Readiness",
    ]) {
      expect(labels).toContain(kept);
    }
  });

  it("says nothing about a module when the node did not state the set", () => {
    /*
     * A document written by a build from before MOD-015, or one whose
     * organization this client was never told about. Not knowing is not the
     * same as running nothing: the entry stands, the address stands, and the
     * node refuses the read if it should. Hiding on an unstated set would make
     * an older cached document look like an organization that runs no product
     * at all.
     */
    installClientSession(
      localFieldSessionDocument({
        organizations: localFieldSessionDocument().organizations.map(
          ({ modules: _modules, ...organization }) => organization,
        ),
      }),
      "network",
    );
    selectSessionDepartment(DEPARTMENT);

    expect(everyNavigationLabel()).toContain("Shift Board");
    expect(useWorkflowLinks().value.map((link) => link.label)).toContain(
      "Incidents",
    );
  });

  it("answers for the organization the selected department belongs to", () => {
    /*
     * Module state is per organization (MOD-005), and somebody working across
     * two of them is owed each one's answer where it applies. One shared set
     * would let an organization that turned Scheduling off take the shift board
     * away from the other's departments.
     */
    const document = localFieldSessionDocument();
    const other = "org-cascadia-collective";

    installClientSession(
      localFieldSessionDocument({
        organizations: [
          ...localFieldOrganizationsWithout(MODULE_EQUIPMENT),
          {
            id: other,
            name: "Cascadia Collective",
            slug: "cascadia-collective",
            status: "approved",
            archived_at: null,
            modules: [...MODULE_KEYS],
          },
        ],
        departments: [
          ...document.departments,
          {
            id: "dept-cascadia",
            organization_id: other,
            name: "Cascadia Rangers",
            code: "CASC",
            membership_status: "active",
            archived_at: null,
          },
        ],
        roles: document.roles.map((role) => ({
          ...role,
          department_id: "dept-cascadia",
          organization_id: other,
        })),
      }),
      "network",
    );

    selectSessionDepartment("dept-cascadia");

    expect(sectionLabels("Department pages")).toContain("Equipment");
  });
});

/*
 * Pages the reader has kept out of their menus (M18.69).
 *
 * The milder of the two preferences, and the whole of what separates it from
 * the one above is where it stops. These cases are about that boundary: the
 * menus get shorter, Home does not, and nothing about authority moves.
 */
describe("the pages a reader keeps in their menus", () => {
  const DEPARTMENT = "66666666-6666-4666-8666-666666666666";

  function installTrimmed(pages: readonly string[]): void {
    installClientSession(
      localFieldSessionDocument({
        preferences: { hidden_pages: [], menu_hidden_pages: pages },
      }),
      "network",
    );
    selectSessionDepartment(DEPARTMENT);
  }

  it("takes a workflow out of the menu and leaves it on Home", () => {
    installTrimmed(["logistics"]);

    expect(useWorkflowMenuLinks().value.map((link) => link.label)).not.toContain(
      "Logistics",
    );
    // Still on the map. This is the assertion the second preference exists for:
    // a reader who trims a menu has said they do not work out of that page, not
    // that they are done with it.
    expect(sectionLabels("Workflows")).toContain("Logistics");
  });

  it("takes a personal page out of the menu and leaves it on Home", () => {
    installTrimmed(["shift-board"]);

    expect(useStaffMenuLinks().value.map((link) => link.label)).not.toContain(
      "Shifts",
    );
    expect(sectionLabels("You")).toContain("Shifts");
  });

  /*
   * The two preferences answer two questions about one page, and answering one
   * does not answer the other. Somebody who wants a dashboard but does not want
   * it in the menu they read twenty times a day is making an ordinary request.
   */
  it("keeps a page the reader restored out of the menu when they asked for that", () => {
    installClientSession(
      localFieldSessionDocument({
        preferences: { hidden_pages: [], menu_hidden_pages: ["dashboard"] },
      }),
      "network",
    );
    selectSessionDepartment(DEPARTMENT);

    expect(useStaffMenuLinks().value.map((link) => link.label)).not.toContain(
      "Dashboard",
    );
    expect(useWorkflowMenuLinks().value.map((link) => link.label)).not.toContain(
      "Dashboard",
    );
    expect(sectionLabels("You")).toContain("Dashboard");
    expect(sectionLabels("Workflows")).toContain("Dashboard");
  });

  /*
   * The threshold is about how long a list somebody has to read, so it counts
   * the list they actually read. A reader who has trimmed their menus is
   * reading the shorter one.
   */
  it("counts the trimmed menus when deciding whether to combine them", () => {
    installTrimmed([]);

    const full = useCombinedNavigation().value.links.length;

    clearClientSession();
    installTrimmed(["logistics", "planning"]);

    const trimmed = useCombinedNavigation().value;

    expect(trimmed.links.length).toBe(full - 2);
    expect(trimmed.links.map((link) => link.label)).not.toContain("Logistics");
  });

  /*
   * The four Home-only pages a reader may promote (M18.69).
   *
   * The preference runs both ways for these: they are on Home and out of the
   * menus until somebody asks, and asking puts them in. Everything else in this
   * describe block is about subtraction, and this is the one case that adds.
   */
  it("puts a Home-only page in the menu when the reader asks for it", () => {
    installTrimmed([]);

    expect(useStaffMenuLinks().value.map((link) => link.label)).toContain(
      "Documents",
    );
    expect(useStaffMenuLinks().value.map((link) => link.label)).toContain(
      "Acknowledgments",
    );
    expect(useWorkflowMenuLinks().value.map((link) => link.label)).toContain(
      "Field Reports",
    );
  });

  it("leaves them on Home and out of the menus by default", () => {
    installClientSession(localFieldSessionDocument(), "network");
    selectSessionDepartment(DEPARTMENT);

    expect(useStaffMenuLinks().value.map((link) => link.label)).not.toContain(
      "Documents",
    );
    // Still where they have always been, which is the point: promoting one is
    // an addition to a menu rather than a move off Home.
    expect(sectionLabels("You")).toContain("Documents");
  });

  /* Promoted once, not once per list it appears in. */
  it("does not list a promoted page twice", () => {
    installTrimmed([]);

    const labels = useStaffMenuLinks().value.map((link) => link.label);

    expect(labels.filter((label) => label === "Documents")).toHaveLength(1);
    expect(sectionLabels("You").filter((label) => label === "Documents")).toHaveLength(
      1,
    );
  });

  /*
   * Nothing about authority moves. The lists the capability checks build are
   * untouched — the filter runs on the way to a menu and nowhere else — so a
   * reader tidying their menu is not quietly narrowing what they can do.
   */
  it("takes nothing away from what the session permits", () => {
    installTrimmed([]);

    const permitted = everyNavigationLabel();

    clearClientSession();
    installTrimmed(["logistics", "planning", "shift-board"]);

    expect(everyNavigationLabel()).toEqual(permitted);
    expect(useWorkflowLinks().value.map((link) => link.label)).toContain(
      "Logistics",
    );
  });
});
