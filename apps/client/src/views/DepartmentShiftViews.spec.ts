import { afterEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import {
  clearDepartmentSelfAdminSession,
  installDevelopmentDepartmentSelfAdminSession,
} from "@/department-teams/fixtureDepartmentSession";
import {
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";
import { resetShiftAdminFixtures } from "@/shift-admin/shiftAdminModel";
import { routes } from "@/router";
import DepartmentShiftEditView from "@/views/DepartmentShiftEditView.vue";
import DepartmentShiftListView from "@/views/DepartmentShiftListView.vue";
import PlanningTableView from "@/views/PlanningTableView.vue";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

function shiftsPath(
  departmentId = LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId,
): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${departmentId}/shifts`;
}

function shiftsCreatePath(): string {
  return `${shiftsPath()}/create`;
}

afterEach(() => {
  clearDepartmentSelfAdminSession();
  resetShiftAdminFixtures();
  resetSelectedFixtureDepartment();
});

describe("department shift administration", () => {
  it("lists department shifts with schedule, capacity, and status", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(shiftsPath());
    await router.isReady();

    const wrapper = mount(DepartmentShiftListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.get("#dept-shifts-heading").text()).toBe("Shifts");
    expect(wrapper.text()).toContain("Dirt Patrol (Day)");
    expect(wrapper.text()).toContain("Dirt Patrol (Started)");
    expect(wrapper.text()).toContain("Started");
    expect(wrapper.text()).toContain("Cancelled");
    expect(wrapper.text()).toContain("2 assigned / 6 cap");
    expect(wrapper.text()).toContain("Create shift");
  });

  it("cancels and restores an upcoming shift", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(shiftsPath());
    await router.isReady();

    const wrapper = mount(DepartmentShiftListView, {
      global: { plugins: [router] },
    });

    const cancelButtons = wrapper
      .findAll("button")
      .filter((button) => button.text() === "Cancel shift");
    expect(cancelButtons.length).toBeGreaterThan(0);
    await cancelButtons[0]!.trigger("click");
    await flushPromises();

    const restoreButtons = wrapper
      .findAll("button")
      .filter((button) => button.text() === "Restore");
    expect(restoreButtons.length).toBeGreaterThan(0);
  });

  it("creates a shift from the create form", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(shiftsCreatePath());
    await router.isReady();

    const wrapper = mount(DepartmentShiftEditView, {
      global: { plugins: [router] },
    });

    await wrapper.get('input[type="text"]').setValue("Night Perimeter");
    const dateInputs = wrapper.findAll('input[type="datetime-local"]');
    await dateInputs[0]!.setValue("2027-07-01T20:00");
    await dateInputs[1]!.setValue("2027-07-02T04:00");
    await wrapper.get('button[type="submit"]').trigger("submit");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe(
      "events.departments.shifts.edit",
    );
  });

  it("rejects a shift that ends before it starts", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(shiftsCreatePath());
    await router.isReady();

    const wrapper = mount(DepartmentShiftEditView, {
      global: { plugins: [router] },
    });

    await wrapper.get('input[type="text"]').setValue("Backwards Shift");
    const dateInputs = wrapper.findAll('input[type="datetime-local"]');
    await dateInputs[0]!.setValue("2027-07-02T04:00");
    await dateInputs[1]!.setValue("2027-07-01T20:00");
    await wrapper.get('button[type="submit"]').trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Shift end must be after shift start.");
  });

  it("locks schedule and team inputs once a shift has started", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    const startedShiftId = "aaaaaaa1-0000-4000-8000-000000000002";
    await router.push(`${shiftsPath()}/${startedShiftId}/edit`);
    await router.isReady();

    const wrapper = mount(DepartmentShiftEditView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain("This shift has started");
    const dateInputs = wrapper.findAll('input[type="datetime-local"]');
    expect(dateInputs[0]!.attributes("disabled")).toBeDefined();
    expect(dateInputs[1]!.attributes("disabled")).toBeDefined();
    expect(wrapper.get("select").attributes("disabled")).toBeDefined();
  });

  it("scopes the list to led teams for a team lead", async () => {
    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(shiftsPath(FIXTURE_DPW_DEPARTMENT_ID));
    await router.isReady();

    const wrapper = mount(DepartmentShiftListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain("Bike Repair Bench");
    expect(wrapper.text()).not.toContain("Dirt Patrol (Day)");
    expect(wrapper.text()).toContain("Create shift");
  });

  it("gives staff-only members a read-only list of their team shifts", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(shiftsPath(FIXTURE_GATE_DEPARTMENT_ID));
    await router.isReady();

    const wrapper = mount(DepartmentShiftListView, {
      global: { plugins: [router] },
    });

    // The Gate fixture user is a member of Credentials but leads nothing.
    expect(wrapper.text()).toContain("Shifts your teams are eligible for.");
    expect(wrapper.text()).toContain("Credential Check");
    expect(wrapper.text()).not.toContain("Create shift");
    expect(
      wrapper.findAll("button").some((button) => button.text() === "Cancel shift"),
    ).toBe(false);
    expect(wrapper.findAll('a[href*="/edit"]').length).toBe(0);
  });

  it("embeds shifts and trainings as Planning featuresets", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(
      `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId}/planning`,
    );
    await router.isReady();

    const wrapper = mount(PlanningTableView, {
      global: { plugins: [router] },
    });

    // Planning is the forward-looking hub: coverage, the shifts it is built
    // from, and the trainings that gate eligibility for them.
    expect(wrapper.get("#planning-gantt-heading").text()).toBe(
      "Scheduled shifts",
    );
    expect(wrapper.get("#shifts-section-heading").text()).toBe("Shifts");
    expect(wrapper.get("#trainings-section-heading").text()).toBe("Trainings");
    expect(wrapper.text()).toContain("Dirt Patrol (Day)");
  });

  it("shows a restricted state for someone with no team membership", async () => {
    installDevelopmentDepartmentSelfAdminSession({
      role: "staff",
      roleLabel: "Staff",
      teamLeadTeamIds: [],
      memberTeamIds: [],
    });
    const router = buildRouter();
    await router.push(shiftsPath());
    await router.isReady();

    const wrapper = mount(DepartmentShiftListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain("Shifts require department membership");
    expect(wrapper.text()).not.toContain("Dirt Patrol (Day)");
  });
});
