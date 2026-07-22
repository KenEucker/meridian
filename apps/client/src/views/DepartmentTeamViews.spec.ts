import { afterEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import {
  clearDepartmentSelfAdminSession,
  installDevelopmentDepartmentSelfAdminSession,
  resetDepartmentSelfAdminFixtures,
} from "@/department-teams/teamAdminModel";
import {
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";
import { routes } from "@/router";
import DepartmentTeamEditView from "@/views/DepartmentTeamEditView.vue";
import DepartmentTeamsListView from "@/views/DepartmentTeamsListView.vue";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

function adminPath(
  departmentId = LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId,
): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${departmentId}/admin`;
}

function teamsCreatePath(): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId}/teams/create`;
}

afterEach(() => {
  clearDepartmentSelfAdminSession();
  resetDepartmentSelfAdminFixtures();
  resetSelectedFixtureDepartment();
});

describe("department self-administration", () => {
  it("lists teams and supports archive/restore for non-default teams", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(adminPath());
    await router.isReady();

    const wrapper = mount(DepartmentTeamsListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.get("#dept-teams-heading").text()).toBe("Admin");
    expect(wrapper.text()).toContain("Rangers Default");
    expect(wrapper.text()).toContain("Dirt");
    expect(wrapper.text()).toContain("Department details");
    expect(wrapper.text()).toContain("Team details");
    expect(wrapper.text()).toContain("Local Field Author");

    const archiveButtons = wrapper
      .findAll("button")
      .filter((button) => button.text() === "Archive");
    expect(archiveButtons.length).toBe(1);
    await archiveButtons[0]!.trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain("Archived");

    const restoreButton = wrapper
      .findAll("button")
      .find((button) => button.text() === "Restore");
    expect(restoreButton).toBeTruthy();
    await restoreButton!.trigger("click");
    await flushPromises();

    expect(
      wrapper.findAll("button").some((button) => button.text() === "Restore"),
    ).toBe(false);
  });

  it("updates department details from the teams surface", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(adminPath());
    await router.isReady();

    const wrapper = mount(DepartmentTeamsListView, {
      global: { plugins: [router] },
    });

    const inputs = wrapper.findAll('input[type="text"]');
    await inputs[0]!.setValue("Rangers QA");
    await wrapper.get('button[type="submit"]').trigger("submit");
    await flushPromises();

    expect(
      (
        wrapper.findAll('input[type="text"]')[0]!
          .element as HTMLInputElement
      ).value,
    ).toBe("Rangers QA");
  });

  it("creates a team from the create form", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(teamsCreatePath());
    await router.isReady();

    const wrapper = mount(DepartmentTeamEditView, {
      global: { plugins: [router] },
    });

    const inputs = wrapper.findAll('input[type="text"]');
    await inputs[0]!.setValue("Radio Operators");
    await flushPromises();

    expect((inputs[1]!.element as HTMLInputElement).value).toBe(
      "RADIO_OPERATORS",
    );

    await wrapper.get("textarea").setValue("Radio operators.");
    await wrapper.get('button[type="submit"]').trigger("submit");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe(
      "events.departments.teams.edit",
    );
  });

  it("shows a restricted state for non-admin roles", async () => {
    installDevelopmentDepartmentSelfAdminSession({
      role: "staff",
      roleLabel: "Staff",
    });
    const router = buildRouter();
    await router.push(adminPath());
    await router.isReady();

    const wrapper = mount(DepartmentTeamsListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain(
      "Admin access requires department lead or team lead authority",
    );
  });

  it("shows only team details and team staff for a team lead", async () => {
    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(adminPath(FIXTURE_DPW_DEPARTMENT_ID));
    await router.isReady();

    const wrapper = mount(DepartmentTeamsListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.get("#dept-teams-heading").text()).toBe("Admin");
    expect(wrapper.text()).toContain("DPW");
    expect(wrapper.text()).toContain("Team details");
    expect(wrapper.text()).toContain("Bikes");
    expect(wrapper.text()).toContain("Bea Bikes");
    expect(wrapper.text()).toContain("Devon DPW");
    expect(wrapper.text()).not.toContain("Department details");
    expect(wrapper.text()).not.toContain("Create team");
    expect(wrapper.text()).not.toContain("Gate Default");
    expect(wrapper.text()).not.toContain("Rangers Default");
  });

  it("does not expose Admin content for a staff-only department membership", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(adminPath(FIXTURE_GATE_DEPARTMENT_ID));
    await router.isReady();

    const wrapper = mount(DepartmentTeamsListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.get("#dept-teams-heading").text()).toBe("Admin");
    expect(wrapper.text()).toContain(
      "Admin access requires department lead or team lead authority",
    );
    expect(wrapper.text()).not.toContain("Department details");
    expect(wrapper.text()).not.toContain("Team details");
    expect(wrapper.text()).not.toContain("Create team");
  });
});
