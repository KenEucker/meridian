import { afterEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import {
  clearOrganizerDepartmentSession,
  installDevelopmentOrganizerDepartmentSession,
  resetOrganizerDepartmentFixtures,
} from "@/organizer-departments/departmentAdminModel";
import {
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_ORGANIZER_DEPARTMENT_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import { routes } from "@/router";
import HomeView from "@/views/HomeView.vue";
import OrganizerDepartmentEditView from "@/views/OrganizerDepartmentEditView.vue";
import OrganizerDepartmentListView from "@/views/OrganizerDepartmentListView.vue";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

afterEach(() => {
  clearOrganizerDepartmentSession();
  resetOrganizerDepartmentFixtures();
  resetSelectedFixtureDepartment();
});

describe("organizer department administration", () => {
  it("lists departments for an organizer and supports archive/restore", async () => {
    selectFixtureDepartment(FIXTURE_ORGANIZER_DEPARTMENT_ID);
    installDevelopmentOrganizerDepartmentSession();
    const router = buildRouter();
    await router.push({ name: "organizer.departments.index" });
    await router.isReady();

    const wrapper = mount(OrganizerDepartmentListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.get("#org-dept-heading").text()).toBe("Departments");
    expect(wrapper.text()).toContain("Organizer");
    expect(wrapper.text()).toContain("DPW");
    expect(wrapper.text()).toContain("Rangers");
    expect(wrapper.text()).toContain("Gate");

    const archiveButton = wrapper
      .findAll("button")
      .find((button) => button.text() === "Archive");
    expect(archiveButton).toBeTruthy();
    await archiveButton!.trigger("click");
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
    expect(
      wrapper.findAll("button").filter((button) => button.text() === "Archive")
        .length,
    ).toBe(4);
  });

  it("creates a department from the create form", async () => {
    selectFixtureDepartment(FIXTURE_ORGANIZER_DEPARTMENT_ID);
    installDevelopmentOrganizerDepartmentSession();
    const router = buildRouter();
    await router.push({ name: "organizer.departments.create" });
    await router.isReady();

    const wrapper = mount(OrganizerDepartmentEditView, {
      global: { plugins: [router] },
    });

    await wrapper.get('input[type="text"]').setValue("Placement");
    const inputs = wrapper.findAll('input[type="text"]');
    await inputs[1]!.setValue("PLACEMENT");
    await wrapper.get("textarea").setValue("Camp placement.");
    await wrapper.get('button[type="submit"]').trigger("submit");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("organizer.departments.edit");
  });

  it("shows a restricted state for non-organizer roles", async () => {
    selectFixtureDepartment(FIXTURE_ORGANIZER_DEPARTMENT_ID);
    installDevelopmentOrganizerDepartmentSession({
      role: "staff",
      roleLabel: "Staff",
    });
    const router = buildRouter();
    await router.push({ name: "organizer.departments.index" });
    await router.isReady();

    const wrapper = mount(OrganizerDepartmentListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain(
      "Department administration requires organizer or lead organizer authority",
    );
    expect(wrapper.find("table").exists()).toBe(false);
  });

  it("does not expose organizer department administration to a DPW team lead", async () => {
    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);
    installDevelopmentOrganizerDepartmentSession();
    let router = buildRouter();
    await router.push({ name: "home" });
    await router.isReady();

    const homeWrapper = mount(HomeView, {
      global: { plugins: [router] },
    });
    const homeCardLabels = homeWrapper
      .findAll(".home__card h3")
      .map((heading) => heading.text());

    expect(homeWrapper.text()).toContain("DPW operations workspace");
    expect(homeCardLabels).toContain("Admin");
    expect(homeCardLabels).not.toContain("Departments");

    router = buildRouter();
    await router.push({ name: "organizer.departments.index" });
    await router.isReady();
    const listWrapper = mount(OrganizerDepartmentListView, {
      global: { plugins: [router] },
    });

    expect(listWrapper.text()).toContain(
      "Department administration requires organizer or lead organizer authority",
    );
    expect(listWrapper.find("table").exists()).toBe(false);
    expect(listWrapper.text()).not.toContain("Archive");

    router = buildRouter();
    await router.push({
      name: "organizer.departments.edit",
      params: { departmentId: FIXTURE_DPW_DEPARTMENT_ID },
    });
    await router.isReady();
    const editWrapper = mount(OrganizerDepartmentEditView, {
      global: { plugins: [router] },
    });

    expect(editWrapper.text()).toContain(
      "Department administration requires organizer or lead organizer authority",
    );
    expect(editWrapper.find("form").exists()).toBe(false);
    expect(editWrapper.text()).not.toContain("Archive");
  });

  it("does not expose organizer department administration to Gate staff", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    installDevelopmentOrganizerDepartmentSession();
    const router = buildRouter();
    await router.push({ name: "organizer.departments.index" });
    await router.isReady();

    const wrapper = mount(OrganizerDepartmentListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain(
      "Department administration requires organizer or lead organizer authority",
    );
    expect(wrapper.find("table").exists()).toBe(false);
    expect(wrapper.text()).not.toContain("Archive");
  });
});
