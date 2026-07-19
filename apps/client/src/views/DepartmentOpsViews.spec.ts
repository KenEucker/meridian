import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";
import { routes } from "@/router";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

async function mountAt(path: string) {
  const router = buildRouter();
  await router.push(path);
  await router.isReady();

  const wrapper = mount(App, {
    global: {
      plugins: [router],
    },
  });

  return { wrapper, router };
}

function overviewPath(): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId}/overview`;
}

function logisticsPath(): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId}/logistics`;
}

function operationsPath(): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId}/operations`;
}

function planningPath(): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId}/planning`;
}

describe("department operations surfaces", () => {
  it("registers workflow routes and home links", async () => {
    const names = routes.map((route) => route.name);
    expect(names).toContain("events.departments.overview");
    expect(names).toContain("events.departments.logistics");
    expect(names).toContain("events.departments.operations");
    expect(names).toContain("events.departments.planning");

    const { wrapper } = await mountAt("/");
    expect(wrapper.text()).toContain("Department overview");
    expect(wrapper.text()).toContain("Logistics desk");
    expect(wrapper.text()).toContain("Operations center");
    expect(wrapper.text()).toContain("Planning table");
  });

  it("redirects legacy shift-board routes to the new surfaces", async () => {
    const { router } = await mountAt(
      `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId}/shift-board/planning`,
    );

    expect(router.currentRoute.value.name).toBe("events.departments.planning");
  });

  it("orders overview content around lead situational awareness", async () => {
    const { wrapper } = await mountAt(overviewPath());

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Department Overview");
    expect(wrapper.get("#exceptions-heading").text()).toBe(
      "Exceptions needing attention",
    );
    expect(wrapper.get("#checked-in-heading").text()).toBe(
      "Checked-in staff currently working",
    );
    expect(wrapper.get("#assignments-heading").text()).toBe("Shift assignments");
    expect(wrapper.get("#equipment-heading").text()).toBe("Equipment out");

    const headingOrder = [
      wrapper.get("#exceptions-heading").element,
      wrapper.get("#checked-in-heading").element,
      wrapper.get("#assignments-heading").element,
      wrapper.get("#equipment-heading").element,
    ];
    const positions = headingOrder.map((element) =>
      element.compareDocumentPosition(headingOrder[0]!),
    );
    expect(
      headingOrder[0]!.compareDocumentPosition(headingOrder[1]!) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(
      headingOrder[1]!.compareDocumentPosition(headingOrder[2]!) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(
      headingOrder[2]!.compareDocumentPosition(headingOrder[3]!) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(positions[0]).toBe(0);
    expect(wrapper.find("label").text()).toContain("Selected shift");
  });

  it("opens a staff-first logistics workspace from search", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Logistics Desk");
    await wrapper.get('input[type="search"]').setValue("Vera");
    const resultButton = wrapper
      .findAll("button")
      .find((button) => button.text().includes("Vera Staff"));
    expect(resultButton).toBeTruthy();
    await resultButton!.trigger("click");

    expect(wrapper.get("#staff-workspace-heading").text()).toBe("Vera Staff");
    expect(wrapper.text()).toContain("Mark on-site");
    expect(wrapper.text()).toContain("Provisions");
    expect(wrapper.text()).toContain(
      "Provisions will appear here once that domain is specified.",
    );
  });

  it("keeps operations center modules capability-composed", async () => {
    const { wrapper } = await mountAt(operationsPath());

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Operations Center");
    expect(wrapper.text()).toContain("Deployments");
    expect(wrapper.text()).toContain(
      "Incident overview requires event-scoped Incident Command capability.",
    );
    expect(wrapper.find("#incidents-heading").exists()).toBe(false);
    expect(wrapper.find("#deployments-heading").exists()).toBe(true);
  });

  it("renders an identity-free planning table", async () => {
    const { wrapper } = await mountAt(planningPath());

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Planning Table");
    expect(wrapper.text()).toContain("Plan versus actual");
    expect(wrapper.text()).toContain("Signed up / assigned");
    expect(wrapper.text()).toContain("Actual hours");
    expect(wrapper.text()).toContain("Variance");
    expect(wrapper.text()).not.toContain("Local Field Author");
    expect(wrapper.text()).not.toContain("Vera Staff");
    expect(wrapper.text()).not.toContain("Team members");
    expect(wrapper.text()).toContain(
      "does not show individual staff identities",
    );
  });
});
