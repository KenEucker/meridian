import { afterEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";
import {
  FIXTURE_RANGERS_DEPARTMENT_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import {
  clearDepartmentSelfAdminSession,
  installDevelopmentDepartmentSelfAdminSession,
  resetDepartmentSelfAdminFixtures,
} from "@/department-teams/teamAdminModel";
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

afterEach(() => {
  clearDepartmentSelfAdminSession();
  clearIncidentSession();
  resetDepartmentSelfAdminFixtures();
  resetDocumentAuthoringFixtures();
  resetSelectedFixtureDepartment();
});

describe("workflow control bands", () => {
  it("gathers incident search, filters, and presets into one band", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentIncidentSession();

    const wrapper = await mountAt(IncidentListView, "/ims/incidents");

    // One surface, not three stacked bordered forms.
    const bars = wrapper.findAll(".control-bar");
    expect(bars).toHaveLength(1);

    // The forms stay separate, because they submit separately.
    const forms = bars[0]!.findAll("form");
    expect(forms.map((form) => form.attributes("aria-label"))).toEqual([
      "Search incidents",
      "Filter incidents",
      "Saved incident list presets",
    ]);

    // Only the search group absorbs leftover width.
    expect(forms[0]!.attributes("data-control-group")).toBe("grow");
    expect(forms[1]!.attributes("data-control-group")).toBe("");
  });

  it("sizes each filter by what it holds rather than by sibling count", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentIncidentSession();

    const wrapper = await mountAt(IncidentListView, "/ims/incidents");
    const widths = wrapper
      .findAll(".control-field")
      .map((field) => field.attributes("data-width"));

    expect(widths).toContain("grow");
    expect(widths).toContain("sm");
    expect(widths).toContain("md");
    expect(new Set(widths).size).toBeGreaterThan(1);
  });

  it("puts department filter toolbars on the shared band", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

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

    const wrapper = await mountAt(DepartmentTeamsListView, departmentPath("admin"));
    const grid = wrapper.get(".content-grid--region");

    expect(grid.find(".dept-teams__details").exists()).toBe(true);
    expect(grid.find(".dept-teams__management").exists()).toBe(true);
    // The document library keeps the full width its table needs.
    expect(grid.find(".documents__table").exists()).toBe(false);
  });
});
