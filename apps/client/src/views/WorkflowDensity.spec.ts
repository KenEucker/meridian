import { afterEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";
import {
  FIXTURE_RANGERS_DEPARTMENT_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import {
  clearDepartmentSelfAdminSession,
  installDevelopmentDepartmentSelfAdminSession,
} from "@/department-teams/fixtureDepartmentSession";
import { resetDocumentAuthoringFixtures } from "@/documents/documentAuthoringModel";
import {
  installDevelopmentIncidentSession,
  clearIncidentSession,
} from "@/ims/incidentReadModel";
import { routes } from "@/router";
import DepartmentOverviewView from "@/views/DepartmentOverviewView.vue";
import DepartmentTeamsListView from "@/views/DepartmentTeamsListView.vue";
import IncidentListView from "@/views/IncidentListView.vue";
import PlanningTableView from "@/views/PlanningTableView.vue";

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
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${FIXTURE_RANGERS_DEPARTMENT_ID}/${suffix}`;
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
    vi.fn(
      async () =>
        new Response(
          JSON.stringify({
            department: {
              id: FIXTURE_RANGERS_DEPARTMENT_ID,
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
                department_id: FIXTURE_RANGERS_DEPARTMENT_ID,
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
        ),
    ),
  );
}

afterEach(() => {
  clearDepartmentSelfAdminSession();
  clearIncidentSession();
  configureMeridianApi(null);
  resetDocumentAuthoringFixtures();
  resetSelectedFixtureDepartment();
  vi.unstubAllGlobals();
});

describe("workflow control bands", () => {
  it("keeps incident search on the band and collapses filters and presets", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentIncidentSession();

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
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentIncidentSession();

    const wrapper = await mountAt(
      IncidentListView,
      "/ims/incidents?priority=Critical&shift=current",
    );

    const panel = wrapper.get(".ims-list__filter-panel");
    expect(panel.attributes("open")).toBeDefined();
    expect(panel.get(".ims-list__filter-count").text()).toBe("2 active");
  });

  it("gives incident filters the roomier label-above-control shape", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentIncidentSession();

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

    // Search keeps its inline sizing hint.
    expect(
      wrapper.findAll(".control-field").map((f) => f.attributes("data-width")),
    ).toEqual(["grow"]);
  });

  it("puts department filter toolbars on the shared band", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    stubTeamAdminNode();

    const admin = await mountAt(DepartmentTeamsListView, departmentPath("admin"));
    expect(admin.find(".control-bar").exists()).toBe(true);

    const planning = await mountAt(PlanningTableView, departmentPath("planning"));
    expect(planning.find(".control-bar").exists()).toBe(true);
  });
});

describe("workflow page regions", () => {
  it("pairs Overview sections while keeping the documented content order", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
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
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

    const wrapper = await mountAt(PlanningTableView, departmentPath("planning"));
    const grids = wrapper.findAll(".content-grid--region");

    expect(grids.length).toBeGreaterThanOrEqual(1);
    expect(grids[0]!.find(".planning__gantt").exists()).toBe(true);
    expect(grids[0]!.find(".planning__drilldown").exists()).toBe(true);
  });

  it("pairs the Admin setup panels", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    stubTeamAdminNode();

    const wrapper = await mountAt(DepartmentTeamsListView, departmentPath("admin"));
    const grid = wrapper.get(".content-grid--region");

    expect(grid.find(".dept-teams__details").exists()).toBe(true);
    expect(grid.find(".dept-teams__management").exists()).toBe(true);
    // The document library keeps the full width its table needs.
    expect(grid.find(".documents__table").exists()).toBe(false);
  });
});
