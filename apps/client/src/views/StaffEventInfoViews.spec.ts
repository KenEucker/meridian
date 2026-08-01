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
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";
import {
  FIXTURE_DPW_BIKES_TEAM_ID,
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEFAULT_TEAM_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  FIXTURE_RANGERS_DIRT_TEAM_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import {
  clearDepartmentSelfAdminSession,
  installDevelopmentDepartmentSelfAdminSession,
} from "@/department-teams/fixtureDepartmentSession";
import { getEventInfo } from "@/event-info/eventInfoModel";
import { routes } from "@/router";
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
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/info`;
}

function teamOverviewPath(departmentId: string, teamId: string): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${departmentId}/teams/${teamId}`;
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
    scope_label: "Organization: Idaho Burners",
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
      id: LOCAL_DEPARTMENT_OPS_CONTEXT.eventId,
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

beforeEach(() => {
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  clearDepartmentSelfAdminSession();
  resetSelectedFixtureDepartment();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

describe("Staff Me role-aware event routing", () => {
  it("registers the team overview route", () => {
    expect(routes.map((route) => route.name)).toContain(
      "events.departments.teams.show",
    );
  });

  it("sends a department lead to Department Overview", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const { router, wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.text()).toContain("Opens Department Overview");

    await wrapper.get(".me__event").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("events.departments.overview");
  });

  it("sends a team lead to the team overview for a team they lead", async () => {
    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);
    const { router, wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.text()).toContain("Opens Team Overview");

    await wrapper.get(".me__event").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("events.departments.teams.show");
    expect(router.currentRoute.value.params.teamId).toBe(
      FIXTURE_DPW_BIKES_TEAM_ID,
    );
  });

  it("sends a staff member without lead authority to Event Info", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    const { router, wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.text()).toContain("Opens Event Info");

    await wrapper.get(".me__event").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("events.info");
  });
});

describe("team overview handoff", () => {
  it("shows the led team's roster, shifts, and current staffing", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

    const { wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(FIXTURE_RANGERS_DEPARTMENT_ID, FIXTURE_RANGERS_DIRT_TEAM_ID),
    );

    expect(wrapper.get("#team-overview-heading").text()).toBe("Dirt");
    expect(wrapper.text()).toContain("Ranger Dirt Day Shift");
    expect(wrapper.text()).toContain("Ranger Dirt Swing Shift");
    expect(wrapper.text()).toContain("Local Field Author");
    expect(wrapper.text()).toContain("Vera Staff");
    expect(wrapper.text()).toContain("Checked in");
    // The Command team's overnight shift belongs to another team.
    expect(wrapper.text()).not.toContain("Ranger Command Overnight");
  });

  it("fails closed for a staff member without department or team lead authority", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

    const { wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(FIXTURE_GATE_DEPARTMENT_ID, FIXTURE_RANGERS_DIRT_TEAM_ID),
    );

    expect(wrapper.text()).toContain(
      "Team Overview requires department lead or team lead authority",
    );
    expect(wrapper.text()).not.toContain("Team roster");
  });

  it("refuses a team the session does not lead instead of swapping in one it does", async () => {
    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

    const { wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(FIXTURE_DPW_DEPARTMENT_ID, FIXTURE_RANGERS_DIRT_TEAM_ID),
    );

    expect(wrapper.text()).toContain(
      "Team Overview requires department lead or team lead authority",
    );
    expect(wrapper.text()).not.toContain("Bikes");
  });

  it("lets a department lead switch between the department's teams", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

    const { router, wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(FIXTURE_RANGERS_DEPARTMENT_ID, FIXTURE_RANGERS_DIRT_TEAM_ID),
    );

    await wrapper.get("select").setValue(FIXTURE_RANGERS_DEFAULT_TEAM_ID);
    await flushPromises();

    expect(router.currentRoute.value.params.teamId).toBe(
      FIXTURE_RANGERS_DEFAULT_TEAM_ID,
    );
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
      `http://node.test/api/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/info`,
    );
    expect(wrapper.text()).toContain("How to get to the event");
    expect(wrapper.text()).toContain("Getting To Signal Camp");
    expect(wrapper.text()).toContain("Take the north access road to Gate 1.");
    expect(wrapper.text()).toContain("Ranger Packing List");
    // Scope, type, and version are the node's words rather than a client lookup.
    expect(wrapper.text()).toContain("Organization: Idaho Burners");
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

    const view = await getEventInfo(LOCAL_DEPARTMENT_OPS_CONTEXT.eventId);

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

    const view = await getEventInfo(LOCAL_DEPARTMENT_OPS_CONTEXT.eventId);
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
