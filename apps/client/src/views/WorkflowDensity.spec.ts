import { afterEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  LOCAL_FIELD_DEPARTMENT_IDS,
} from "@/field-reports/localFieldFixture";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import DepartmentOverviewView from "@/views/DepartmentOverviewView.vue";
import DepartmentTeamsListView from "@/views/DepartmentTeamsListView.vue";
import IncidentListView from "@/views/IncidentListView.vue";
import PlanningTableView from "@/views/PlanningTableView.vue";

/*
 * The event and department these tests work in.
 *
 * Declared here rather than imported from a fixture module (M18.9). They are the
 * ids the local development session document carries, which is what the client
 * under test is holding; a shared constants module would make them look like
 * product data rather than what one test file is standing on.
 */
const LOCAL_EVENT_ID = "11111111-1111-4111-8111-111111111111";


async function mountAt(component: unknown, path: string) {
  const router = createRouter({ history: createWebHistory(), routes });
  await router.push(path);
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  });
  await flushPromises();

  return wrapper;
}

function departmentPath(suffix: string): string {
  return `/events/${LOCAL_EVENT_ID}/departments/${LOCAL_FIELD_DEPARTMENT_IDS.rangers}/${suffix}`;
}

/**
 * A node that answers the Admin surface's one read (M16.15).
 *
 * This file is about where controls sit on a page, not about what the page
 * asked for, so the answer is the smallest one that fills every panel: a
 * department, an administering caller, and one team.
 */
function stubTeamAdminNode(): void {
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });

  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL) => {
      // Admin embeds the document library beside its team panels, and that
      // featureset makes its own read (M16.19). This test is about the panel
      // layout, so the library is answered as a reader with nothing published.
      if (String(input).includes("/documents")) {
        return new Response(
          JSON.stringify({
            organization_id: "11111111-1111-4111-8111-111111111111",
            access: { can_maintain: false, scopes: [] },
            event_info_sections: [],
            documents: [],
            fragments: [],
          }),
          { status: 200, headers: { "content-type": "application/json" } },
        );
      }

      return new Response(
          JSON.stringify({
            department: {
              id: LOCAL_FIELD_DEPARTMENT_IDS.rangers,
              organization_id: "11111111-1111-4111-8111-111111111111",
              name: "Rangers",
              code: "RANGERS",
              description: null,
              default_team_id: null,
              archived_at: null,
            },
            access: {
              can_administer: true,
              can_view_led_teams: false,
              led_team_ids: [],
            },
            teams: [
              {
                id: "77777777-7777-4777-8777-777777777771",
                department_id: LOCAL_FIELD_DEPARTMENT_IDS.rangers,
                name: "Dirt",
                code: "DIRT",
                description: null,
                is_default: false,
                archived_at: null,
                created_at: "2026-07-01T00:00:00+00:00",
                updated_at: "2026-07-01T00:00:00+00:00",
              },
            ],
            team_staff: [],
            department_staff: [],
          }),
          { status: 200, headers: { "content-type": "application/json" } },
      );
    }),
  );
}

/**
 * A node that answers the incident list read (M16.20).
 *
 * This file is about where controls sit on a page, so the answer carries the
 * filter vocabularies the controls render from and no incidents at all.
 */
function stubIncidentListNode(): void {
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });

  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL) => {
      // The node echoes the selection it applied, which is what the controls
      // render from.
      const query = new URL(String(input), "http://node.test").searchParams;

      return new Response(
          JSON.stringify({
            event_id: "11111111-1111-4111-8111-111111111111",
            filters: {
              search: query.get("search") ?? "",
              state: query.get("state") ?? "active",
              priority: query.get("priority") ?? "all",
              type: "all",
              responder: "all",
              started_from: null,
              started_to: null,
              sort: "updated",
              direction: "desc",
            },
            filter_options: {
              states: ["active", "all", "closed"],
              priorities: ["all", "Critical"],
              sorts: ["updated", "priority"],
              types: [],
              responders: [],
              max_per_page: 100,
            },
            assignable: {
              statuses: ["open", "closed"],
              priorities: ["Routine", "Critical"],
              types: [],
              responders: [],
            },
            pagination: { page: 1, per_page: 25, total: 0, total_pages: 1 },
            presets: [],
            incidents: [],
          }),
          { status: 200, headers: { "content-type": "application/json" } },
      );
    }),
  );
}

/**
 * A node that answers the Department Overview read (M16.21).
 *
 * This file is about where sections sit on a page, so the answer is the
 * smallest one that renders all four of them: a context, an authority, and one
 * shift with nobody on it.
 */
function stubDepartmentOverviewNode(): void {
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });

  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(
          JSON.stringify({
            context: {
              event_id: LOCAL_EVENT_ID,
              event_label: "Emberfall",
              department_id: LOCAL_FIELD_DEPARTMENT_IDS.rangers,
              department_label: "Rangers",
              time_zone: "America/Los_Angeles",
              as_of: "2027-07-04T18:00:00+00:00",
            },
            access: { can_manage_attendance: true },
            shifts: [
              {
                shift_id: "99999999-9999-4999-8999-999999999999",
                title: "Ranger Dirt Day Shift",
                team_id: "77777777-7777-4777-8777-777777777771",
                team_label: "Dirt",
                starts_at: "2027-07-04T16:00:00+00:00",
                ends_at: "2027-07-04T22:00:00+00:00",
                lifecycle: "active",
                capacity: 4,
              },
            ],
            selected_shift_id: "99999999-9999-4999-8999-999999999999",
            exceptions: [],
            assignments: [],
            equipment_out: [],
            deployments: [],
            on_site_count: 0,
          }),
          { status: 200, headers: { "content-type": "application/json" } },
        ),
    ),
  );
}

/** The signed-in IC session the incident list renders under. */
function installIncidentSession(): void {
  installLocalFieldSession();
  selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
  stubIncidentListNode();
}

afterEach(() => {
  clearClientSession();
  resetSelectedSessionDepartment();
  configureMeridianApi(null);
  resetSelectedSessionDepartment();
  vi.unstubAllGlobals();
});

describe("workflow control bands", () => {
  it("keeps incident search on the band and collapses filters and presets", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    installIncidentSession();

    const wrapper = await mountAt(IncidentListView, "/ims/incidents");

    // Search is the control someone reaches for first, so it stays visible.
    const bars = wrapper.findAll(".control-bar");
    expect(bars).toHaveLength(1);
    expect(bars[0]!.findAll("form").map((f) => f.attributes("aria-label"))).toEqual([
      "Search incidents",
    ]);
    expect(bars[0]!.get("form").attributes("data-control-group")).toBe("grow");

    // Filters and presets move behind one accordion, closed by default.
    const panel = wrapper.get(".ims-list__filter-panel");
    expect(panel.attributes("open")).toBeUndefined();
    expect(panel.get("summary").text()).toContain("Filters and presets");
    expect(
      panel.findAll("form").map((form) => form.attributes("aria-label")),
    ).toEqual(["Filter incidents", "Saved incident list presets"]);
  });

  it("opens the filter panel and counts the filters when the list arrives narrowed", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    installIncidentSession();

    const wrapper = await mountAt(
      IncidentListView,
      "/ims/incidents?priority=Critical&state=closed",
    );

    const panel = wrapper.get(".ims-list__filter-panel");
    expect(panel.attributes("open")).toBeDefined();
    expect(panel.get(".ims-list__filter-count").text()).toBe("2 active");
  });

  it("gives incident filters the roomier label-above-control shape", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    installIncidentSession();

    const wrapper = await mountAt(IncidentListView, "/ims/incidents");

    // Same idiom as the Field Reports list: a label wrapping its own text and
    // control, rather than the dense inline ControlField used for search.
    const filters = wrapper.get(".ims-list__filters");
    const labels = filters.findAll("label");
    expect(labels.length).toBeGreaterThan(3);
    for (const label of labels) {
      expect(label.find("span").exists()).toBe(true);
      expect(label.find("select, input").exists()).toBe(true);
    }
    expect(filters.findAll(".control-field")).toHaveLength(0);

    // Search keeps its inline sizing hint, and so does the open-mode control
    // that sits beside it on the always-visible band rather than in the panel.
    expect(
      wrapper.findAll(".control-field").map((f) => f.attributes("data-width")),
    ).toEqual(["grow", "md"]);
  });

  it("puts department filter toolbars on the shared band", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    stubTeamAdminNode();

    const admin = await mountAt(DepartmentTeamsListView, departmentPath("admin"));
    expect(admin.find(".control-bar").exists()).toBe(true);

    const planning = await mountAt(PlanningTableView, departmentPath("planning"));
    expect(planning.find(".control-bar").exists()).toBe(true);
  });
});

describe("workflow page regions", () => {
  it("pairs Overview sections while keeping the documented content order", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    stubDepartmentOverviewNode();

    const wrapper = await mountAt(
      DepartmentOverviewView,
      departmentPath("overview"),
    );

    const grid = wrapper.get(".content-grid--region");
    expect(
      grid.findAll(".overview__section").map((s) => s.attributes("aria-labelledby")),
    ).toEqual([
      "exceptions-heading",
      "checked-in-heading",
      "assignments-heading",
      "equipment-heading",
    ]);
  });

  it("pairs the Planning chart with the detail it drives", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);

    const wrapper = await mountAt(PlanningTableView, departmentPath("planning"));
    const grids = wrapper.findAll(".content-grid--region");

    expect(grids.length).toBeGreaterThanOrEqual(1);
    expect(grids[0]!.find(".planning__gantt").exists()).toBe(true);
    expect(grids[0]!.find(".planning__drilldown").exists()).toBe(true);
  });

  it("pairs the Admin setup panels", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    stubTeamAdminNode();

    const wrapper = await mountAt(DepartmentTeamsListView, departmentPath("admin"));
    const grid = wrapper.get(".content-grid--region");

    expect(grid.find(".dept-teams__details").exists()).toBe(true);
    expect(grid.find(".dept-teams__management").exists()).toBe(true);
    // The document library keeps the full width its table needs.
    expect(grid.find(".documents__table").exists()).toBe(false);
  });
});
