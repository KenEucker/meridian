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
    expect(names).toContain("ims.incidents.index");
    expect(names).toContain("ims.field-reports.index");

    const { wrapper } = await mountAt("/");
    expect(wrapper.text()).toContain("Department overview");
    expect(wrapper.text()).toContain("Logistics desk");
    expect(wrapper.text()).toContain("Operations center");
    expect(wrapper.text()).toContain("Planning table");
    expect(wrapper.text()).toContain("Incident Management");
    expect(wrapper.text()).toContain("Supporting tools");
    expect(
      wrapper
        .findAll(".home__links a")
        .find((item) => item.text() === "Incidents")
        ?.attributes("href"),
    ).toBe("/ims/incidents");
    expect(
      wrapper
        .findAll(".home__links a")
        .find((item) => item.text() === "Field Reports")
        ?.attributes("href"),
    ).toBe("/ims/field-reports");
    expect(
      wrapper
        .findAll(".home__links a")
        .find((item) => item.text() === "Health")
        ?.attributes("href"),
    ).toBe("/settings/about");
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
    expect(wrapper.get("#search-cache-heading").text()).toBe(
      "Offline search cache",
    );
    expect(wrapper.text()).toContain("Offline usable");

    await wrapper.get('input[type="search"]').setValue("swing");
    const shiftButton = wrapper
      .findAll("button")
      .find((button) => button.text().includes("Ranger Dirt Swing Shift"));
    expect(shiftButton).toBeTruthy();
    await shiftButton!.trigger("click");

    expect(wrapper.get("#search-context-heading").text()).toBe(
      "Ranger Dirt Swing Shift",
    );
    expect(wrapper.text()).toContain("Open Local Field Author");
    expect(wrapper.text()).toContain("Open Ari Ranger");

    await wrapper.get('input[type="search"]').setValue("Vera");
    const resultButton = wrapper
      .findAll("button")
      .find((button) => button.text().includes("Vera Staff"));
    expect(resultButton).toBeTruthy();
    await resultButton!.trigger("click");

    expect(wrapper.get("#staff-workspace-heading").text()).toBe("Vera Staff");
    expect(wrapper.text()).toContain("Mark on-site");
    expect(wrapper.get("#active-shifts-heading").text()).toBe("Active shift");
    expect(wrapper.get("#upcoming-shifts-heading").text()).toBe(
      "Upcoming shifts",
    );
    expect(wrapper.get("#outgoing-shifts-heading").text()).toBe(
      "Outgoing shifts",
    );
    expect(wrapper.text()).toContain("Provisions");
    expect(wrapper.text()).toContain(
      "Provisions will appear here once that domain is specified.",
    );
  });

  it("opens check-in and check-out as modal dialogs", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    await wrapper.get('input[type="search"]').setValue("Vera");
    const veraButton = wrapper
      .findAll("button")
      .find((button) => button.text().includes("Vera Staff"));
    expect(veraButton).toBeTruthy();
    await veraButton!.trigger("click");
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Mark on-site")!
      .trigger("click");
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Check in")!
      .trigger("click");

    let dialog = wrapper.get('[role="dialog"]');
    expect(dialog.attributes("aria-modal")).toBe("true");
    expect(wrapper.find(".logistics__modal-backdrop").exists()).toBe(true);
    expect(dialog.get("#attendance-dialog-heading").text()).toBe("Check in");
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Cancel")!
      .trigger("click");
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);

    await wrapper.get('input[type="search"]').setValue("Local Field Author");
    const authorButton = wrapper
      .findAll("button")
      .find((button) => button.text().includes("Local Field Author"));
    expect(authorButton).toBeTruthy();
    await authorButton!.trigger("click");
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Check out")!
      .trigger("click");

    dialog = wrapper.get('[role="dialog"]');
    expect(dialog.attributes("aria-modal")).toBe("true");
    expect(dialog.get("#attendance-dialog-heading").text()).toBe("Check out");
    expect(dialog.text()).toContain("Return equipment");
    expect(dialog.text()).toContain("Radio 12");
    expect(dialog.text()).toContain("Returned");
    expect(dialog.text()).toContain("Missing");
    expect(dialog.text()).toContain("Damaged");

    await dialog
      .findAll("button")
      .find((button) => button.text() === "Confirm")!
      .trigger("click");

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    expect(wrapper.text()).toContain("No open equipment for this staff member.");
  });

  it("checks out equipment from the staff workspace after check-in", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    await wrapper.get('input[type="search"]').setValue("Vera");
    const veraButton = wrapper
      .findAll("button")
      .find((button) => button.text().includes("Vera Staff"));
    expect(veraButton).toBeTruthy();
    await veraButton!.trigger("click");
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Mark on-site")!
      .trigger("click");
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Check in")!
      .trigger("click");
    await wrapper
      .get('[role="dialog"]')
      .findAll("button")
      .find((button) => button.text() === "Confirm")!
      .trigger("click");

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Check out equipment")!
      .trigger("click");

    const dialog = wrapper.get('[role="dialog"]');
    expect(dialog.get("#attendance-dialog-heading").text()).toBe(
      "Check out equipment",
    );
    expect(dialog.text()).toContain("Available equipment");
    expect(dialog.text()).toContain("Radio 13");
    expect(dialog.text()).toContain("Radio 14");
    await dialog.get('input[value="equipment-radio-13"]').setValue(true);
    await dialog.get('input[value="equipment-radio-14"]').setValue(true);
    await dialog
      .findAll("button")
      .find((button) => button.text() === "Confirm")!
      .trigger("click");

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    expect(wrapper.text()).toContain("Vera Staff equipment checked out.");
    expect(wrapper.text()).toContain("Radio 13");
    expect(wrapper.text()).toContain("Radio 14");
    expect(wrapper.text()).toContain("Checked out");
  });

  it("keeps operations center modules capability-composed", async () => {
    const { wrapper } = await mountAt(operationsPath());

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Operations Center");
    expect(wrapper.text()).toContain("Deployments");
    expect(wrapper.text()).toContain("Field Reports");
    expect(wrapper.find("#field-reports-heading").exists()).toBe(true);
    expect(
      wrapper
        .findAll("a")
        .some((link) => link.text() === "Submit Field Report"),
    ).toBe(true);
    expect(wrapper.find("#incidents-heading").exists()).toBe(true);
    expect(
      wrapper
        .findAll("a")
        .some((link) => link.text() === "Open IMS incidents"),
    ).toBe(true);
    expect(wrapper.text()).not.toContain(
      "Incident overview requires event-scoped Incident Command capability.",
    );
    expect(wrapper.find("#deployments-heading").exists()).toBe(true);
  });

  it("renders an identity-free planning table", async () => {
    const { wrapper } = await mountAt(planningPath());

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Planning Table");
    expect(wrapper.text()).toContain("Offline aggregate cache");
    expect(wrapper.text()).toContain("Plan versus actual");
    expect(wrapper.text()).toContain("Signed up / assigned");
    expect(wrapper.text()).toContain("No target");
    expect(wrapper.text()).toContain("Completed");
    expect(wrapper.text()).toContain("Actual hours");
    expect(wrapper.text()).toContain("Variance");
    expect(wrapper.text()).not.toContain("Local Field Author");
    expect(wrapper.text()).not.toContain("Vera Staff");
    expect(wrapper.text()).not.toContain("Team members");
    expect(wrapper.text()).toContain(
      "does not show individual staff identities",
    );

    expect(wrapper.findAll("tbody tr")).toHaveLength(3);
    await wrapper.get("select").setValue(
      "77777777-7777-4777-8777-777777777772",
    );
    expect(wrapper.findAll("tbody tr")).toHaveLength(1);
    expect(wrapper.text()).toContain("Ranger Command Overnight");
    expect(wrapper.text()).not.toContain("Ranger Dirt Day Shift");

    await wrapper.get("select").setValue("");
    await wrapper.get('input[type="date"]').setValue("2027-07-04");
    expect(wrapper.findAll("tbody tr")).toHaveLength(2);
    expect(wrapper.text()).toContain("Ranger Dirt Day Shift");
    expect(wrapper.text()).toContain("Ranger Dirt Swing Shift");
    expect(wrapper.text()).not.toContain("Ranger Command Overnight");
  });
});
