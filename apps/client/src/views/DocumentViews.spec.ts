import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import {
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_ORGANIZER_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import { resetDocumentAuthoringFixtures } from "@/documents/documentAuthoringModel";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";
import { routes } from "@/router";
import DocumentEditView from "@/views/DocumentEditView.vue";
import DocumentLibraryView from "@/views/DocumentLibraryView.vue";
import HomeView from "@/views/HomeView.vue";

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
  resetDocumentAuthoringFixtures();
  resetSelectedFixtureDepartment();
  clearClientSession();
});

describe("product document authoring", () => {
  it("registers organizer and department document routes", () => {
    const names = routes.map((route) => route.name);

    expect(names).toContain("organizer.documents.index");
    expect(names).toContain("organizer.documents.create");
    expect(names).toContain("organizer.documents.edit");
    expect(names).toContain("events.departments.documents.index");
    expect(names).toContain("events.departments.documents.create");
    expect(names).toContain("events.departments.documents.edit");
  });

  it("exposes document entry points from organizer and department home contexts", async () => {
    selectFixtureDepartment(FIXTURE_ORGANIZER_DEPARTMENT_ID);
    const organizerRouter = buildRouter();
    await organizerRouter.push({ name: "home" });
    await organizerRouter.isReady();

    const organizerHome = mount(HomeView, {
      global: { plugins: [organizerRouter] },
    });
    expect(organizerHome.text()).toContain("Organization policies, procedures");

    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const departmentRouter = buildRouter();
    await departmentRouter.push({ name: "home" });
    await departmentRouter.isReady();

    const departmentHome = mount(HomeView, {
      global: { plugins: [departmentRouter] },
    });
    expect(departmentHome.text()).toContain("Department and team policies");
  });

  it("lets an organizer create a policy and shows export/share entry points", async () => {
    selectFixtureDepartment(FIXTURE_ORGANIZER_DEPARTMENT_ID);
    const router = buildRouter();
    await router.push({
      name: "organizer.documents.create",
      params: { artifactKind: "policy" },
    });
    await router.isReady();

    const wrapper = mount(DocumentEditView, {
      global: { plugins: [router] },
    });

    const inputs = wrapper.findAll("input");
    await inputs[0]!.setValue("Arrival Policy");
    await inputs[1]!.setValue("arrival-policy");
    await wrapper.get("textarea").setValue("# Arrival Policy\n\nArrive ready.");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("organizer.documents.edit");
    expect(wrapper.text()).toContain("Arrival Policy saved.");
    expect(wrapper.text()).toContain("Export Markdown");
    expect(wrapper.text()).toContain("Export PDF");
    expect(wrapper.text()).toContain("Share");
  });

  it("shows department and team maintainable documents plus fragment impact review", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const router = buildRouter();
    await router.push({
      name: "events.departments.documents.index",
      params: {
        eventId: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
        departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
      },
    });
    await router.isReady();

    const list = mount(DocumentLibraryView, {
      global: { plugins: [router] },
    });

    expect(list.text()).toContain("Radio Checkout");
    expect(list.text()).toContain("Dirt Team Radio Policy");
    expect(list.text()).toContain("Published reference impact: 1");

    const fragmentLink = list
      .findAll("a")
      .find((link) => link.text() === "Edit" && link.attributes("href")?.includes("fragment"));
    expect(fragmentLink).toBeTruthy();
  });

  it("shows staff only published visible documents without fragment maintenance", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    const router = buildRouter();
    await router.push({
      name: "events.departments.documents.index",
      params: {
        eventId: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
        departmentId: FIXTURE_GATE_DEPARTMENT_ID,
      },
    });
    await router.isReady();

    const wrapper = mount(DocumentLibraryView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain("Volunteer Conduct");
    expect(wrapper.text()).not.toContain("Radio Checkout");
    // Readers get the mobile-first card list, not the maintainer table or the
    // fragment workspace.
    expect(wrapper.find(".staff-page").exists()).toBe(true);
    expect(wrapper.findAll(".staff-card").length).toBeGreaterThan(0);
    expect(wrapper.find("table").exists()).toBe(false);
    expect(wrapper.text()).not.toContain("Fragments");
    expect(wrapper.text()).not.toContain("New fragment");
  });
});
