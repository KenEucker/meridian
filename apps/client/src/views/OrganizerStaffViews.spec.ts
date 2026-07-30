import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import {
  clearOrganizerDepartmentSession,
  installDevelopmentOrganizerDepartmentSession,
} from "@/organizer-departments/departmentAdminModel";
import { resetOrganizerStaffFixtures } from "@/organizer-staff/staffAdminModel";
import {
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_ORGANIZER_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";
import HomeView from "@/views/HomeView.vue";
import OrganizerStaffView from "@/views/OrganizerStaffView.vue";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

// The home directory follows the session response (M16.6).
beforeEach(() => {
  installLocalFieldSession();
});

afterEach(() => {
  clearOrganizerDepartmentSession();
  resetOrganizerStaffFixtures();
  resetSelectedFixtureDepartment();
  clearClientSession();
});

describe("organizer staff administration", () => {
  it("appears from organizer Home and adds invited staff", async () => {
    selectFixtureDepartment(FIXTURE_ORGANIZER_DEPARTMENT_ID);
    installDevelopmentOrganizerDepartmentSession();
    const router = buildRouter();
    await router.push({ name: "home" });
    await router.isReady();

    const homeWrapper = mount(HomeView, {
      global: { plugins: [router] },
    });
    const cardLabels = homeWrapper
      .findAll(".home__card h3")
      .map((heading) => heading.text());

    expect(cardLabels).toContain("Staff");

    await router.push({ name: "organizer.staff.index" });
    await router.isReady();
    const wrapper = mount(OrganizerStaffView, {
      global: { plugins: [router] },
    });

    expect(wrapper.get("#org-staff-heading").text()).toBe("Staff");
    expect(wrapper.text()).toContain("Local Field Author");

    const inputs = wrapper.findAll("input");
    await inputs[0]!.setValue("Jordan Intake");
    await inputs[1]!.setValue("Jordan");
    await inputs[2]!.setValue("jordan-radio");
    await inputs[3]!.setValue("JORDAN@example.test");
    await wrapper.find("select").setValue(FIXTURE_DPW_DEPARTMENT_ID);
    await wrapper.get('form button[type="submit"]').trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Jordan added to staff.");
    expect(wrapper.text()).toContain("jordan@example.test");
    expect(wrapper.text()).toContain("DPW");
    expect(wrapper.text()).toContain("active");
    expect(wrapper.text()).toContain("invited");
  });

  it("selects and removes a department lead from existing staff", async () => {
    selectFixtureDepartment(FIXTURE_ORGANIZER_DEPARTMENT_ID);
    installDevelopmentOrganizerDepartmentSession();
    const router = buildRouter();
    await router.push({ name: "organizer.staff.index" });
    await router.isReady();

    const wrapper = mount(OrganizerStaffView, {
      global: { plugins: [router] },
    });

    const selects = wrapper.findAll("select");
    await selects[1]!.setValue("33333333-3333-4333-8333-333333333334");
    await selects[2]!.setValue(FIXTURE_RANGERS_DEPARTMENT_ID);
    await wrapper.findAll('button[type="submit"]')[1]!.trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Vera selected as Rangers lead.");
    expect(wrapper.text()).toContain("Rangers lead");

    const removeButton = wrapper
      .findAll("button")
      .find((button) => button.text() === "Remove lead");
    expect(removeButton).toBeTruthy();
    await removeButton!.trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain("removed from lead selection");
  });

  it("shows a restricted state for non-organizer roles", async () => {
    selectFixtureDepartment(FIXTURE_ORGANIZER_DEPARTMENT_ID);
    installDevelopmentOrganizerDepartmentSession({
      role: "staff",
      roleLabel: "Staff",
    });
    const router = buildRouter();
    await router.push({ name: "organizer.staff.index" });
    await router.isReady();

    const wrapper = mount(OrganizerStaffView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain(
      "Staff administration requires organizer or lead organizer authority",
    );
    expect(wrapper.find("table").exists()).toBe(false);
  });

  it("does not expose organizer staff administration to a department team lead", async () => {
    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);
    installDevelopmentOrganizerDepartmentSession();
    const router = buildRouter();
    await router.push({ name: "organizer.staff.index" });
    await router.isReady();

    const wrapper = mount(OrganizerStaffView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain(
      "Staff administration requires organizer or lead organizer authority",
    );
    expect(wrapper.text()).not.toContain("Select lead");
  });
});
