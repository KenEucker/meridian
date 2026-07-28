import { describe, expect, it } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
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

function homeCardByHeading(wrapper: VueWrapper, heading: string) {
  return wrapper
    .findAll(".home__card")
    .find((item) => item.find("h3").text() === heading);
}

describe("department operations surfaces", () => {
  it("registers workflow routes and home links", async () => {
    const names = routes.map((route) => route.name);
    expect(names).toContain("events.departments.overview");
    expect(names).toContain("events.departments.logistics");
    expect(names).toContain("events.departments.operations");
    expect(names).toContain("events.departments.planning");
    expect(names).toContain("staff.me");
    expect(names).toContain("events.info");
    expect(names).toContain("ims.incidents.index");
    expect(names).toContain("ims.field-reports.index");

    const { wrapper } = await mountAt("/");
    expect(wrapper.get("#home-heading").text()).toBe(
      LOCAL_DEPARTMENT_OPS_CONTEXT.eventLabel,
    );
    const eventCard = wrapper.get(".home__event-card");
    expect(eventCard.find(".home__eyebrow").exists()).toBe(false);
    expect(eventCard.text()).not.toContain("Admin");
    expect(eventCard.get(".home__status").text()).toBe("ongoing");
    expect(eventCard.text()).toContain("Operations");
    expect(eventCard.text()).toContain("Location");
    expect(eventCard.text()).toContain("Description");
    expect(eventCard.text()).not.toContain("active");
    expect(wrapper.text()).toContain("Overview");
    expect(wrapper.text()).toContain("Logistics");
    expect(wrapper.text()).toContain("Operations Center");
    expect(wrapper.text()).toContain("Incidents");
    expect(wrapper.text()).toContain("My Field Reports");
    expect(wrapper.text()).toContain("Readiness");
    expect(homeCardByHeading(wrapper, "Incidents")?.attributes("href")).toBe(
      "/ims/incidents",
    );
    expect(
      homeCardByHeading(wrapper, "Field Reports")?.attributes("href"),
    ).toBe("/ims/field-reports");
    expect(homeCardByHeading(wrapper, "Health")?.attributes("href")).toBe(
      "/settings/about",
    );
  });

  it("renders the staff Me page with profile links and current schedule", async () => {
    const { wrapper } = await mountAt("/staff/me");

    expect(wrapper.get("#me-heading").text()).toBe("Local Field Author");
    expect(wrapper.get(".me__nav a").attributes("href")).toBe("/");
    expect(wrapper.get(".me__photo").attributes("aria-label")).toContain(
      "Local Field Author profile photo",
    );
    expect(wrapper.text()).toContain("Years of service");
    expect(wrapper.text()).toContain("Events worked");
    expect(wrapper.text()).toContain("My Field Reports");
    expect(
      wrapper
        .findAll(".me__links a")
        .some((link) => link.attributes("href") === "/staff/field-reports"),
    ).toBe(true);
    expect(wrapper.text()).toContain("Schedule for ongoing event");
    expect(wrapper.text()).toContain("Ranger Dirt Day Shift");
    expect(wrapper.text()).toContain("Ranger Dirt Swing Shift");
    expect(wrapper.get(".me__event").attributes("href")).toBe(
      `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId}/overview`,
    );
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

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Logistics Window");
    expect(wrapper.get("#current-shifts-heading").text()).toBe(
      "Current shifts",
    );
    expect(wrapper.text()).toContain("Ranger Dirt Day Shift");
    expect(wrapper.get("#search-cache-heading").text()).toBe(
      "Offline search cache",
    );
    expect(wrapper.text()).toContain("Offline usable");
    expect(wrapper.text()).toContain("Find staff");
    expect(wrapper.text().toLowerCase()).not.toContain("agent");

    await wrapper.get('input[type="search"]').setValue("swing");
    const shiftButton = wrapper
      .findAll("button")
      .find((button) => button.text().includes("Ranger Dirt Swing Shift"));
    expect(shiftButton).toBeTruthy();
    await shiftButton!.trigger("click");

    expect(wrapper.get("#search-context-heading").text()).toBe(
      "Ranger Dirt Swing Shift",
    );
    expect(wrapper.get("#selected-shift-staff-heading").text()).toBe(
      "Scheduled staff",
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
    // Scoped to the open workspace: the on-shift roster above the search offers
    // its own "Check out equipment" button for a different staff member.
    await wrapper
      .get(".logistics__workspace")
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
    const { wrapper, router } = await mountAt(operationsPath());

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
    const metricCards = wrapper.findAll(".ops__metric-card");
    const incidentCards = metricCards.slice(0, 4);
    expect(incidentCards).toHaveLength(4);
    expect(
      incidentCards.find((card) => card.text().includes("Event total"))?.text(),
    ).toContain("3");
    expect(
      incidentCards
        .find((card) => card.text().includes("Current shift"))
        ?.text(),
    ).toContain("3");
    expect(
      incidentCards.find((card) => card.text().includes("Active"))?.text(),
    ).toContain("2");
    expect(
      incidentCards
        .find((card) => card.text().includes("Critical priority"))
        ?.text(),
    ).toContain("0");
    const fieldReportCards = metricCards.slice(4);
    expect(fieldReportCards).toHaveLength(4);
    expect(
      fieldReportCards.find((card) => card.text().includes("This shift"))?.text(),
    ).toContain("3");
    expect(
      fieldReportCards.find((card) => card.text().includes("Linked"))?.text(),
    ).toContain("0");
    expect(
      fieldReportCards.find((card) => card.text().includes("Unlinked"))?.text(),
    ).toContain("3");
    expect(
      fieldReportCards.find((card) => card.text().includes("Event total"))?.text(),
    ).toContain("3");
    // The page composes shortcuts from capability, so it does not restate the
    // author's personal workspace. That link lives in the shell's staff menu,
    // which is why this assertion reads the page rather than the whole app.
    expect(wrapper.get(".dept-ops").text()).not.toContain("My Field Reports");
    expect(wrapper.text()).not.toContain(
      "Incident overview requires event-scoped Incident Command capability.",
    );
    expect(wrapper.find("#deployments-heading").exists()).toBe(true);

    await incidentCards
      .find((card) => card.text().includes("Current shift"))!
      .trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("ims.incidents.index");
    expect(router.currentRoute.value.query).toMatchObject({
      state: "all",
      shift: "current",
    });
  });

  it("renders an identity-free planning table", async () => {
    const { wrapper } = await mountAt(planningPath());

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Planning Table");
    expect(wrapper.text()).toContain("Plan versus actual");
    expect(wrapper.text()).toContain("Signed up / assigned");
    expect(wrapper.text()).toContain("No target");
    expect(wrapper.text()).toContain("Completed");
    expect(wrapper.text()).toContain("Actual hours");
    expect(wrapper.text()).toContain("Variance");
    expect(wrapper.get("#planning-gantt-heading").text()).toBe(
      "Scheduled shifts",
    );
    expect(wrapper.findAll(".planning__gantt-row")).toHaveLength(3);
    expect(wrapper.get("#planning-shift-detail-heading").text()).toBe(
      "Shift detail",
    );
    expect(wrapper.get(".planning__drilldown").text()).toContain(
      "Ranger Dirt Day Shift",
    );
    expect(wrapper.get(".planning__drilldown").text()).toContain(
      "Local Field Author",
    );
    expect(wrapper.get(".planning__drilldown").text()).toContain("Vera Staff");
    expect(wrapper.get(".planning__drilldown").text()).toContain(
      "Sam Shiftlead",
    );
    expect(wrapper.text()).toContain(
      "Aggregate rows remain identity-free",
    );

    await wrapper
      .findAll(".planning__gantt-row")
      .find((button) => button.text().includes("Ranger Dirt Swing Shift"))!
      .trigger("click");

    expect(wrapper.get(".planning__drilldown").text()).toContain(
      "Ranger Dirt Swing Shift",
    );
    expect(wrapper.get(".planning__drilldown").text()).toContain(
      "Ari Ranger",
    );
    expect(wrapper.get(".planning__drilldown").text()).not.toContain(
      "Vera Staff",
    );

    expect(wrapper.findAll(".planning__table-frame tbody tr")).toHaveLength(3);
    await wrapper.get("select").setValue(
      "77777777-7777-4777-8777-777777777772",
    );
    expect(wrapper.findAll(".planning__table-frame tbody tr")).toHaveLength(1);
    expect(wrapper.text()).toContain("Ranger Command Overnight");
    expect(wrapper.text()).not.toContain("Ranger Dirt Day Shift");

    await wrapper.get("select").setValue("");
    await wrapper.get('input[type="date"]').setValue("2027-07-04");
    expect(wrapper.findAll(".planning__table-frame tbody tr")).toHaveLength(2);
    expect(wrapper.text()).toContain("Ranger Dirt Day Shift");
    expect(wrapper.text()).toContain("Ranger Dirt Swing Shift");
    expect(wrapper.text()).not.toContain("Ranger Command Overnight");
  });
  it('lists staff on shift above the search with the cache notice beside it', async () => {
    const { wrapper } = await mountAt(logisticsPath());

    const roster = wrapper.get('.logistics__on-shift');
    expect(roster.get('#on-shift-heading').text()).toBe('On shift now');
    // Checked in on the day shift in the fixture; Vera Staff is only scheduled.
    expect(roster.text()).toContain('Local Field Author');
    expect(roster.text()).not.toContain('Vera Staff');
    expect(roster.text()).toContain('Ranger Dirt Day Shift');

    const html = wrapper.html();
    expect(html.indexOf('logistics__on-shift')).toBeLessThan(
      html.indexOf('entity-search'),
    );
    expect(
      wrapper.get('.logistics__find').find('.logistics__cache').exists(),
    ).toBe(true);
    expect(wrapper.find('.logistics__find .entity-search').exists()).toBe(true);
  });

  it('checks a staff member out straight from the on-shift roster', async () => {
    const { wrapper } = await mountAt(logisticsPath());

    const row = wrapper
      .findAll('.logistics__on-shift-list li')
      .find((item) => item.text().includes('Local Field Author'));
    expect(row).toBeTruthy();

    await row!
      .findAll('button')
      .find((button) => button.text() === 'Check out')!
      .trigger('click');

    const dialog = wrapper.get('[role="dialog"]');
    expect(dialog.get('#attendance-dialog-heading').text()).toBe('Check out');
    // Opening from the roster selects that staff member, so the dialog and the
    // workspace act on the same person.
    expect(wrapper.get('#staff-workspace-heading').text()).toBe(
      'Local Field Author',
    );

    await dialog
      .findAll('button')
      .find((button) => button.text() === 'Confirm')!
      .trigger('click');

    expect(wrapper.text()).toContain('Local Field Author checked out.');
    expect(wrapper.get('.logistics__on-shift').text()).toContain(
      'No staff are checked in',
    );
  });

  it('opens equipment checkout for a roster member without searching first', async () => {
    const { wrapper } = await mountAt(logisticsPath());

    const row = wrapper
      .findAll('.logistics__on-shift-list li')
      .find((item) => item.text().includes('Local Field Author'));

    await row!
      .findAll('button')
      .find((button) => button.text() === 'Check out equipment')!
      .trigger('click');

    const dialog = wrapper.get('[role="dialog"]');
    expect(dialog.get('#attendance-dialog-heading').text()).toBe(
      'Check out equipment',
    );
    expect(wrapper.get('#staff-workspace-heading').text()).toBe(
      'Local Field Author',
    );
  });
});
