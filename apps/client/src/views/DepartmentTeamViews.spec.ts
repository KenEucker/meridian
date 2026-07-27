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

    // Scoped to the team management section: Admin also embeds the document
    // library, which has its own Archive controls.
    const archiveButtons = wrapper
      .findAll(".dept-teams__management button")
      .filter((button) => button.text() === "Archive");
    expect(archiveButtons.length).toBe(1);
    await archiveButtons[0]!.trigger("click");
    await flushPromises();

    expect(wrapper.get(".dept-teams__management").text()).toContain("Archived");

    const restoreButton = wrapper
      .findAll(".dept-teams__management button")
      .find((button) => button.text() === "Restore");
    expect(restoreButton).toBeTruthy();
    await restoreButton!.trigger("click");
    await flushPromises();

    expect(
      wrapper
        .findAll(".dept-teams__management button")
        .some((button) => button.text() === "Restore"),
    ).toBe(false);
  });

  it("embeds the document library as an Admin featureset", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(adminPath());
    await router.isReady();

    const wrapper = mount(DepartmentTeamsListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.get("#documents-section-heading").text()).toBe("Documents");
    expect(wrapper.text()).toContain("Policies and procedures");
    expect(wrapper.text()).toContain("Fragments");
  });

  it("opens department details read-only and edits behind a toggle", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(adminPath());
    await router.isReady();

    const wrapper = mount(DepartmentTeamsListView, {
      global: { plugins: [router] },
    });

    // Default is the read-out, like the team details panel beside it.
    expect(wrapper.find(".dept-teams__readout").exists()).toBe(true);
    expect(wrapper.find(".dept-teams__form").exists()).toBe(false);
    expect(wrapper.get(".dept-teams__readout").text()).toContain("Rangers");

    await wrapper.get(".dept-teams__edit").trigger("click");
    expect(wrapper.find(".dept-teams__form").exists()).toBe(true);

    const inputs = wrapper.findAll('input[type="text"]');
    await inputs[0]!.setValue("Rangers QA");
    await wrapper.get('button[type="submit"]').trigger("submit");
    await flushPromises();

    // Saving returns to the read-out and shows the saved value.
    expect(wrapper.find(".dept-teams__form").exists()).toBe(false);
    expect(wrapper.get(".dept-teams__readout").text()).toContain("Rangers QA");
  });

  it("discards an unsaved department details edit on cancel", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(adminPath());
    await router.isReady();

    const wrapper = mount(DepartmentTeamsListView, {
      global: { plugins: [router] },
    });

    await wrapper.get(".dept-teams__edit").trigger("click");
    await wrapper.findAll('input[type="text"]')[0]!.setValue("Discard me");
    await wrapper
      .findAll(".dept-teams__form-actions button")
      .find((button) => button.text() === "Cancel")!
      .trigger("click");
    await flushPromises();

    expect(wrapper.find(".dept-teams__form").exists()).toBe(false);
    expect(wrapper.get(".dept-teams__readout").text()).not.toContain("Discard me");
    expect(wrapper.get(".dept-teams__readout").text()).toContain("Rangers");

    await wrapper.get(".dept-teams__edit").trigger("click");
    expect(
      (wrapper.findAll('input[type="text"]')[0]!.element as HTMLInputElement)
        .value,
    ).toBe("Rangers");
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

  it("designates and removes a team lead as department lead", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(adminPath());
    await router.isReady();

    const wrapper = mount(DepartmentTeamsListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain("Team staff");

    const makeLeadButtons = wrapper
      .findAll("button")
      .filter((button) => button.text() === "Make team lead");
    expect(makeLeadButtons.length).toBeGreaterThan(0);
    await makeLeadButtons[0]!.trigger("click");
    await flushPromises();

    const removeLeadButtons = wrapper
      .findAll("button")
      .filter((button) => button.text() === "Remove lead");
    expect(removeLeadButtons.length).toBeGreaterThan(0);
    await removeLeadButtons[0]!.trigger("click");
    await flushPromises();
  });

  it("assigns and removes staff on a managed team", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(adminPath());
    await router.isReady();

    const wrapper = mount(DepartmentTeamsListView, {
      global: { plugins: [router] },
    });

    const selects = wrapper
      .find('form[class*="assign"]')
      .findAll("select");
    expect(selects.length).toBe(2);

    // Riley Reserve is an unassigned department roster member in the fixture.
    await selects[0]!.setValue("33333333-3333-4333-8333-333333333361");
    const teamSelect = selects[1]!;
    const dirtOption = teamSelect
      .findAll("option")
      .find((option) => option.text() === "Dirt");
    expect(dirtOption).toBeTruthy();
    await teamSelect.setValue(dirtOption!.attributes("value"));
    await wrapper
      .findAll('button[type="submit"]')
      .find((button) => button.text() === "Assign to team")!
      .trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Riley Reserve");

    const removeButtons = wrapper
      .findAll("button")
      .filter((button) => button.text() === "Remove");
    expect(removeButtons.length).toBeGreaterThan(0);
    await removeButtons[removeButtons.length - 1]!.trigger("click");
    await flushPromises();
  });

  it("lets a team lead assign staff only to led teams", async () => {
    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    const router = buildRouter();
    await router.push(adminPath(FIXTURE_DPW_DEPARTMENT_ID));
    await router.isReady();

    const wrapper = mount(DepartmentTeamsListView, {
      global: { plugins: [router] },
    });

    const selects = wrapper.find('form[class*="assign"]').findAll("select");
    const teamOptions = selects[1]!
      .findAll("option")
      .map((option) => option.text());

    // Only the led Bikes team is assignable; lead designation stays admin-only.
    expect(teamOptions).toContain("Bikes");
    expect(teamOptions).not.toContain("DPW Default");
    expect(wrapper.text()).not.toContain("Make team lead");
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
