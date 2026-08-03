// Staff Me routing, Team Overview handoff, and Event Info against a stubbed
// node (M16.19; CLIENT-023, CLIENT-024; data/API 11.4A).
//
// The Event Info tests were fixture tests. They asserted that the browser's own
// copy of `EventInfoService` — six section keys, six empty descriptions, a
// published-only filter, and a scope-breadth sort — had assembled the client's
// compiled-in documents correctly. None of it reached an endpoint, so a section
// that looked empty was empty of fixtures rather than empty of guidance.
//
// They now stub `fetch` and answer with the payload `EventInfoReadController`
// publishes, so what they prove is that the page asks the right event and
// renders the node's assembly: its section order, its labels, its rendered
// document HTML, and its named gaps.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearReadCache } from "@/offline/readCache";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { getEventInfo } from "@/event-info/eventInfoModel";
import { routes } from "@/router";
import {
  bootClientSessionFromCache,
  clearClientSession,
} from "@/session/clientSession";
import { writeCachedSession } from "@/session/sessionCache";
import type { SessionDocument } from "@/session/sessionDocument";
import { fixtureSessionDocument } from "@/session/sessionDocumentFixture";
import { resetSelectedSessionDepartment } from "@/session/sessionAccess";
import EventInfoView from "@/views/EventInfoView.vue";
import MeView from "@/views/MeView.vue";
import TeamOverviewView from "@/views/TeamOverviewView.vue";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

function eventInfoPath(): string {
  return `/events/${SESSION_EVENT_ID}/info`;
}

function teamOverviewPath(departmentId: string, teamId: string): string {
  return `/events/${SESSION_EVENT_ID}/departments/${departmentId}/teams/${teamId}`;
}

async function mountAt(component: unknown, path: string) {
  const router = buildRouter();
  await router.push(path);
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  });
  await flushPromises();

  return { router, wrapper };
}

/** One Event Info document, as `EventInfoService::documentPayload` publishes it. */
function documentPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: "44444444-4444-4444-8444-444444444404",
    document_type: "policy",
    title: "Getting To Signal Camp",
    slug: "getting-to-signal-camp",
    scope_type: "organization",
    scope_label: "Organization: Northwood Collective",
    version: "1.00",
    rendered_html:
      "<p>Take the north access road to Gate 1. The last fuel stop is 40 miles out.</p>",
    published_at: "2026-07-04T17:00:00+00:00",
    updated_at: "2026-07-04T17:00:00+00:00",
    ...overrides,
  };
}

/**
 * The `GET /api/events/{event}/info` envelope.
 *
 * `documentsBySection` names only the sections that have something in them; the
 * rest come back with the node's `empty_description`, which is the behavior the
 * empty-state test is about (11.4A).
 */
function eventInfoPayload(
  documentsBySection: Record<string, Record<string, unknown>[]> = {},
): Record<string, unknown> {
  const sections = [
    ["directions", "How to get to the event"],
    ["arrival", "Arrival requirements"],
    ["packing", "What to bring"],
    ["food", "Food"],
    ["housing", "Housing"],
    ["requirements", "Event requirements"],
  ] as const;

  const emptyDescriptions: Record<string, string> = {
    directions:
      "No published document covers travel, gate access, or arrival checkpoints for this event yet.",
    arrival:
      "No published document covers arrival requirements, credentials, or check-in for this event yet.",
    packing:
      "No published document covers what staff should bring to this event yet.",
    food: "No published document covers meals or food availability for this event yet.",
    housing:
      "No published document covers housing, camping, or shelter for this event yet.",
    requirements:
      "No published document covers what this event requires of staff yet.",
  };

  return {
    event: {
      id: SESSION_EVENT_ID,
      organization_id: "88888888-8888-4888-8888-888888888888",
      name: "Signal Camp 2026",
      slug: "signal-camp-2026",
      timezone: "UTC",
      starts_at: "2026-08-20T15:00:00+00:00",
      ends_at: "2026-08-24T15:00:00+00:00",
      status: "published",
    },
    section_order: sections.map(([key]) => key),
    sections: sections.map(([key, label]) => {
      const documents = documentsBySection[key] ?? [];

      return {
        section: key,
        label,
        documents,
        empty_description: documents.length === 0 ? emptyDescriptions[key] : null,
      };
    }),
  };
}

function stubEventInfoNode(body: unknown, status = 200): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(JSON.stringify(body), {
          status,
          headers: { "content-type": "application/json" },
        }),
    ),
  );
}

const stubJson = stubEventInfoNode;

/*
 * The session documents Me now reads its identity, department, and standing
 * from (M18.9; CLIENT-001 through CLIENT-004).
 *
 * Installed through the durable cache rather than assigned, so what the page
 * renders is a document the client accepted through its own validation — a
 * shape that would be rejected on a real boot is rejected here too.
 */
const SESSION_EVENT_ID = "019fc2e7-fc38-7030-8562-eeec2d4fd020";
const SESSION_DEPARTMENT_ID = "019fc2e7-fbed-7126-bf72-27ff6dbd3fe4";
const SESSION_TEAM_ID = "019fc2e7-fbee-7328-a30e-354e37d15edd";

function meSessionDocument(
  roles: SessionDocument["roles"],
  teamIsLead: boolean,
): SessionDocument {
  const base = fixtureSessionDocument();
  const event = { ...base.events[0]!, id: SESSION_EVENT_ID, name: "Emberfall 2026" };

  return {
    ...base,
    user: {
      id: "user-dana",
      name: "Dana Departmentlead",
      email: "dana@example.test",
      staff_ids: ["staff-dana"],
    },
    roles,
    capabilities: roles.flatMap((role) => role.capabilities),
    events: [event],
    departments: [
      { ...base.departments[0]!, id: SESSION_DEPARTMENT_ID, name: "Rangers" },
    ],
    teams: [
      {
        ...base.teams[0]!,
        id: SESSION_TEAM_ID,
        department_id: SESSION_DEPARTMENT_ID,
        name: "Dirt",
        is_lead: teamIsLead,
      },
    ],
    context: {
      ...base.context,
      event_id: SESSION_EVENT_ID,
      department_id: SESSION_DEPARTMENT_ID,
      node_locked_event_id: SESSION_EVENT_ID,
    },
  };
}

function meRole(
  roleCode: string,
  roleName: string,
  teamId: string | null,
): SessionDocument["roles"][number] {
  return {
    role_code: roleCode,
    role_name: roleName,
    scope_type: teamId === null ? "department" : "team",
    organization_id: "org-northwood-collective",
    department_id: SESSION_DEPARTMENT_ID,
    team_id: teamId,
    team_name: teamId === null ? null : "Dirt",
    event_id: SESSION_EVENT_ID,
    team_grant_id: "grant-1",
    reason: roleName,
    capabilities: [],
  };
}

function departmentLeadDocument(): SessionDocument {
  return meSessionDocument(
    [meRole("department_lead", "Department Lead", null)],
    false,
  );
}

function teamLeadDocument(): SessionDocument {
  return meSessionDocument(
    [meRole("shift_lead", "Shift Lead", SESSION_TEAM_ID)],
    true,
  );
}

function ordinaryStaffDocument(): SessionDocument {
  return meSessionDocument([], false);
}

function installSession(document: SessionDocument): void {
  writeCachedSession(document, "2026-09-11T18:30:00+00:00");
  bootClientSessionFromCache(new Date("2026-09-11T18:30:00+00:00"));
}

beforeEach(() => {
  /*
   * Reads are durable from M18.9 (technical spec 9.3), so a successful read in
   * one case would be served to the next one from the store. Cleared between
   * cases, and the unreachable-node cases below are about a device that is
   * holding nothing.
   */
  clearReadCache();
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  clearClientSession();
  resetSelectedSessionDepartment();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

/*
 * Me's routing follows the session document now rather than a fixture department
 * selection (M18.9). The standing under test is the standing the node reported,
 * which is the same one it enforces on the surface each of these opens.
 */
describe("Staff Me role-aware event routing", () => {
  beforeEach(() => {
    stubJson({ event: { id: SESSION_EVENT_ID, name: "Emberfall 2026" }, shifts: [] });
  });

  it("registers the team overview route", () => {
    expect(routes.map((route) => route.name)).toContain(
      "events.departments.teams.show",
    );
  });

  it("sends a department lead to Department Overview", async () => {
    installSession(departmentLeadDocument());
    const { router, wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.text()).toContain("Opens Department Overview");

    await wrapper.get(".me__event").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("events.departments.overview");
  });

  it("sends a team lead to the team overview for a team they lead", async () => {
    installSession(teamLeadDocument());
    const { router, wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.text()).toContain("Opens Team Overview");

    await wrapper.get(".me__event").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("events.departments.teams.show");
    expect(router.currentRoute.value.params.teamId).toBe(SESSION_TEAM_ID);
  });

  it("sends a staff member without lead authority to Event Info", async () => {
    installSession(ordinaryStaffDocument());
    const { router, wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.text()).toContain("Opens Event Info");

    await wrapper.get(".me__event").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("events.info");
  });

  /*
   * The page used to name "Local Field Author" at "Local Field Event" whoever
   * was signed in, because both came out of the fixture (M18.9).
   */
  it("names the signed-in staff member and their own event", async () => {
    installSession(departmentLeadDocument());
    const { wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.get("#me-heading").text()).toBe("Dana Departmentlead");
    expect(wrapper.text()).toContain("Emberfall 2026 - Rangers");
    expect(wrapper.text()).not.toContain("Local Field");
  });

  it("lists the shifts the board says this person holds", async () => {
    installSession(departmentLeadDocument());
    stubJson({
      event: { id: SESSION_EVENT_ID, name: "Emberfall 2026" },
      shifts: [
        {
          id: "shift-day",
          department_id: SESSION_DEPARTMENT_ID,
          department_name: "Rangers",
          eligible_team_id: SESSION_TEAM_ID,
          eligible_team_name: "Dirt",
          title: "Day Patrol",
          starts_at: "2026-09-11T16:00:00+00:00",
          ends_at: "2026-09-11T22:00:00+00:00",
          capacity: 4,
          signup_opens_at: null,
          signup_closes_at: null,
          schedule_lock_at: null,
          cancelled_at: null,
          signed_up: true,
          assignment_status: "signed_up",
        },
        {
          id: "shift-not-mine",
          department_id: SESSION_DEPARTMENT_ID,
          department_name: "Rangers",
          eligible_team_id: SESSION_TEAM_ID,
          eligible_team_name: "Dirt",
          title: "Swing Patrol",
          starts_at: "2026-09-11T22:00:00+00:00",
          ends_at: "2026-09-12T04:00:00+00:00",
          capacity: 4,
          signup_opens_at: null,
          signup_closes_at: null,
          schedule_lock_at: null,
          cancelled_at: null,
          signed_up: false,
          assignment_status: null,
        },
      ],
    });

    const { wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.text()).toContain("Day Patrol");
    expect(wrapper.text()).not.toContain("Swing Patrol");
  });

  /*
   * A read that failed is not an empty schedule. Reporting one as the other
   * tells somebody they are on no shifts when nobody managed to ask.
   */
  it("states a failed schedule read rather than reporting no shifts", async () => {
    installSession(departmentLeadDocument());
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const { wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.get(".me__schedule-error").text()).toContain(
      "Unable to read your schedule",
    );
    expect(wrapper.text()).not.toContain("not signed up for any shifts");
  });
});

/*
 * Team Overview reads the node now (M18.9; CLIENT-023).
 *
 * The authority under test is the `access` block on the teams read, which is the
 * same answer the Admin surface is shaped by and the same one the commands
 * enforce. It used to be a compiled-in list of who leads what, which is how a
 * real department lead was refused a team in their own department.
 */
describe("team overview handoff", () => {
  const DIRT_TEAM_ID = "019fc2e7-fbee-7328-a30e-354e37d15edd";
  const DEFAULT_TEAM_ID = "019fc2e7-fbfa-71a4-91cb-bf3f9b086bc4";

  function teamPayload(
    id: string,
    name: string,
    overrides: Record<string, unknown> = {},
  ): Record<string, unknown> {
    return {
      id,
      department_id: SESSION_DEPARTMENT_ID,
      name,
      code: name.toUpperCase(),
      description: null,
      is_default: id === DEFAULT_TEAM_ID,
      archived_at: null,
      created_at: "2026-01-01T00:00:00+00:00",
      updated_at: "2026-01-01T00:00:00+00:00",
      ...overrides,
    };
  }

  function planningRow(
    shiftId: string,
    title: string,
    lifecycle: string,
  ): Record<string, unknown> {
    return {
      shift_id: shiftId,
      title,
      team_id: DIRT_TEAM_ID,
      team_label: "Dirt",
      starts_at: "2026-09-11T16:00:00+00:00",
      ends_at: "2026-09-11T22:00:00+00:00",
      lifecycle,
      capacity: 4,
      signed_up_or_assigned_count: 3,
      checked_in_count: 2,
      no_show_count: 0,
      unscheduled_count: 0,
      planned_hours: 24,
      actual_hours: 4,
      variance_hours: -20,
      status_label: "Under target",
    };
  }

  /**
   * A node answering the two reads Team Overview makes.
   *
   * `access` is the whole subject here, so it is a parameter rather than a
   * constant: each test is one shape of authority.
   */
  function stubTeamNode(access: Record<string, unknown>): void {
    vi.stubGlobal(
      "fetch",
      vi.fn(async (input: RequestInfo | URL) => {
        const url = new URL(String(input), "http://node.test");
        const body = url.pathname.includes("/teams")
          ? {
              department: {
                id: SESSION_DEPARTMENT_ID,
                organization_id: "org-northwood-collective",
                name: "Rangers",
                code: "RANGERS",
                description: null,
                default_team_id: DEFAULT_TEAM_ID,
                archived_at: null,
              },
              access,
              teams: [
                teamPayload(DIRT_TEAM_ID, "Dirt"),
                teamPayload(DEFAULT_TEAM_ID, "Rangers"),
              ],
              team_staff: [
                {
                  staff_id: "staff-vera",
                  display_name: "Vera Staff",
                  handle: "vera",
                  team_id: DIRT_TEAM_ID,
                  team_label: "Dirt",
                  membership_role: "member",
                  role_label: "Member",
                },
                {
                  staff_id: "staff-other",
                  display_name: "Other Team Member",
                  handle: "other",
                  team_id: DEFAULT_TEAM_ID,
                  team_label: "Rangers",
                  membership_role: "member",
                  role_label: "Member",
                },
              ],
              department_staff: [],
            }
          : {
              context: {
                event_id: SESSION_EVENT_ID,
                event_label: "Emberfall 2026",
                department_id: SESSION_DEPARTMENT_ID,
                department_label: "Rangers",
                time_zone: "America/Los_Angeles",
                as_of: "2026-09-11T18:00:00+00:00",
              },
              access: {},
              teams: [],
              filters: { team_id: url.searchParams.get("team_id"), date: null },
              rows: [
                planningRow("shift-day", "Dirt Day Patrol", "active"),
                planningRow("shift-swing", "Dirt Swing Patrol", "upcoming"),
              ],
            };

        return new Response(JSON.stringify(body), {
          status: 200,
          headers: { "content-type": "application/json" },
        });
      }),
    );
  }

  it("shows the led team's roster, shifts, and current staffing", async () => {
    stubTeamNode({ can_administer: true, can_view_led_teams: false, led_team_ids: [] });

    const { wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(SESSION_DEPARTMENT_ID, DIRT_TEAM_ID),
    );

    expect(wrapper.get("#team-overview-heading").text()).toBe("Dirt");
    expect(wrapper.text()).toContain("Dirt Day Patrol");
    expect(wrapper.text()).toContain("Dirt Swing Patrol");
    expect(wrapper.text()).toContain("Vera Staff");
    // The roster is this team's, not the department's.
    expect(wrapper.text()).not.toContain("Other Team Member");
    expect(wrapper.text()).toContain("Checked in");
  });

  it("fails closed for a staff member without department or team lead authority", async () => {
    stubTeamNode({ can_administer: false, can_view_led_teams: false, led_team_ids: [] });

    const { wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(SESSION_DEPARTMENT_ID, DIRT_TEAM_ID),
    );

    expect(wrapper.text()).toContain(
      "Team Overview requires department lead or team lead authority",
    );
    expect(wrapper.text()).not.toContain("Team roster");
  });

  it("refuses a team the session does not lead instead of swapping in one it does", async () => {
    stubTeamNode({
      can_administer: false,
      can_view_led_teams: true,
      led_team_ids: [DEFAULT_TEAM_ID],
    });

    const { wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(SESSION_DEPARTMENT_ID, DIRT_TEAM_ID),
    );

    expect(wrapper.text()).toContain(
      "Team Overview requires department lead or team lead authority",
    );
    expect(wrapper.text()).not.toContain("Vera Staff");
  });

  it("lets a department lead switch between the department's teams", async () => {
    stubTeamNode({ can_administer: true, can_view_led_teams: false, led_team_ids: [] });

    const { router, wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(SESSION_DEPARTMENT_ID, DIRT_TEAM_ID),
    );

    await wrapper.get("select").setValue(DEFAULT_TEAM_ID);
    await flushPromises();

    expect(router.currentRoute.value.params.teamId).toBe(DEFAULT_TEAM_ID);
  });

  /*
   * An unreachable node is not a refusal. The page had only the refusal state,
   * so a lead whose node was down was told they lacked authority they hold.
   */
  it("states an unreachable node rather than a missing permission", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const { wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(SESSION_DEPARTMENT_ID, DIRT_TEAM_ID),
    );

    expect(wrapper.get(".team-overview__error").text()).toContain(
      "Unable to read this team",
    );
    expect(wrapper.text()).not.toContain("requires department lead");
  });
});

describe("event info document resolution", () => {
  it("reads the event and renders the node's assembled sections", async () => {
    const fetchMock = vi.fn(
      async (_input: RequestInfo | URL, _init?: RequestInit) =>
        new Response(
          JSON.stringify(
            eventInfoPayload({
              directions: [documentPayload()],
              packing: [
                documentPayload({
                  id: "44444444-4444-4444-8444-444444444407",
                  document_type: "procedure",
                  title: "Ranger Packing List",
                  scope_type: "department",
                  scope_label: "Department: Rangers",
                  rendered_html:
                    "<p>Dust goggles, a working headlamp, and warm layers for night patrol.</p>",
                }),
              ],
            }),
          ),
          { status: 200, headers: { "content-type": "application/json" } },
        ),
    );
    vi.stubGlobal("fetch", fetchMock);

    const { wrapper } = await mountAt(EventInfoView, eventInfoPath());

    expect(String(fetchMock.mock.calls[0]?.[0])).toBe(
      `http://node.test/api/events/${SESSION_EVENT_ID}/info`,
    );
    expect(wrapper.text()).toContain("How to get to the event");
    expect(wrapper.text()).toContain("Getting To Signal Camp");
    expect(wrapper.text()).toContain("Take the north access road to Gate 1.");
    expect(wrapper.text()).toContain("Ranger Packing List");
    // Scope, type, and version are the node's words rather than a client lookup.
    expect(wrapper.text()).toContain("Organization: Northwood Collective");
    expect(wrapper.text()).toContain("2 visible to you");
    expect(wrapper.text()).not.toContain("Placeholder:");
  });

  it("names the gap the node named in a section with nothing visible", async () => {
    stubEventInfoNode(eventInfoPayload({ directions: [documentPayload()] }));

    const { wrapper } = await mountAt(EventInfoView, eventInfoPath());

    const housing = wrapper.get('[data-section="housing"]');
    expect(housing.attributes("data-empty")).toBe("true");
    expect(housing.text()).toContain(
      "No published document covers housing, camping, or shelter for this event yet.",
    );
  });

  it("keeps the section order the node sent", async () => {
    stubEventInfoNode(eventInfoPayload());

    const view = await getEventInfo(SESSION_EVENT_ID);

    expect(view.sections.map((section) => section.section)).toEqual([
      "directions",
      "arrival",
      "packing",
      "food",
      "housing",
      "requirements",
    ]);
  });

  it("does not widen visibility: a section the node left empty stays empty", async () => {
    stubEventInfoNode(eventInfoPayload());

    const view = await getEventInfo(SESSION_EVENT_ID);
    const housing = view.sections.find(
      (section) => section.section === "housing",
    );

    expect(housing?.documents).toEqual([]);
    expect(housing?.emptyDescription).not.toBeNull();
    expect(view.documentCount).toBe(0);
  });

  it("states an unreachable node rather than an event with no guidance", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const { wrapper } = await mountAt(EventInfoView, eventInfoPath());

    expect(wrapper.text()).toContain("Unable to load event information.");
    expect(wrapper.text()).not.toContain("At a glance");
  });

  it("shows the node's refusal when the caller has no standing in the event", async () => {
    stubEventInfoNode(
      {
        message:
          "Event information requires staff standing in this event organization.",
      },
      403,
    );

    const { wrapper } = await mountAt(EventInfoView, eventInfoPath());

    expect(wrapper.text()).toContain(
      "Event information requires staff standing in this event organization.",
    );
  });
});
