import { afterEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";
import {
  FIXTURE_DPW_BIKES_TEAM_ID,
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEFAULT_TEAM_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  FIXTURE_RANGERS_DIRT_TEAM_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import {
  clearDepartmentSelfAdminSession,
  installDevelopmentDepartmentSelfAdminSession,
} from "@/department-teams/fixtureDepartmentSession";
import { resetDocumentAuthoringFixtures } from "@/documents/documentAuthoringModel";
import { resolveEventInfo } from "@/event-info/eventInfoModel";
import { routes } from "@/router";
import DocumentEditView from "@/views/DocumentEditView.vue";
import DocumentLibraryView from "@/views/DocumentLibraryView.vue";
import EventInfoView from "@/views/EventInfoView.vue";
import MeView from "@/views/MeView.vue";
import TeamOverviewView from "@/views/TeamOverviewView.vue";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

function eventInfoPath(): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/info`;
}

function teamOverviewPath(departmentId: string, teamId: string): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${departmentId}/teams/${teamId}`;
}

async function mountAt(component: unknown, path: string) {
  const router = buildRouter();
  await router.push(path);
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  });
  await flushPromises();

  return { router, wrapper };
}

afterEach(() => {
  clearDepartmentSelfAdminSession();
  resetDocumentAuthoringFixtures();
  resetSelectedFixtureDepartment();
});

describe("Staff Me role-aware event routing", () => {
  it("registers the team overview route", () => {
    expect(routes.map((route) => route.name)).toContain(
      "events.departments.teams.show",
    );
  });

  it("sends a department lead to Department Overview", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const { router, wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.text()).toContain("Opens Department Overview");

    await wrapper.get(".me__event").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("events.departments.overview");
  });

  it("sends a team lead to the team overview for a team they lead", async () => {
    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);
    const { router, wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.text()).toContain("Opens Team Overview");

    await wrapper.get(".me__event").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("events.departments.teams.show");
    expect(router.currentRoute.value.params.teamId).toBe(
      FIXTURE_DPW_BIKES_TEAM_ID,
    );
  });

  it("sends a staff member without lead authority to Event Info", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    const { router, wrapper } = await mountAt(MeView, "/staff/me");

    expect(wrapper.text()).toContain("Opens Event Info");

    await wrapper.get(".me__event").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("events.info");
  });
});

describe("team overview handoff", () => {
  it("shows the led team's roster, shifts, and current staffing", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

    const { wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(FIXTURE_RANGERS_DEPARTMENT_ID, FIXTURE_RANGERS_DIRT_TEAM_ID),
    );

    expect(wrapper.get("#team-overview-heading").text()).toBe("Dirt");
    expect(wrapper.text()).toContain("Ranger Dirt Day Shift");
    expect(wrapper.text()).toContain("Ranger Dirt Swing Shift");
    expect(wrapper.text()).toContain("Local Field Author");
    expect(wrapper.text()).toContain("Vera Staff");
    expect(wrapper.text()).toContain("Checked in");
    // The Command team's overnight shift belongs to another team.
    expect(wrapper.text()).not.toContain("Ranger Command Overnight");
  });

  it("fails closed for a staff member without department or team lead authority", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

    const { wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(FIXTURE_GATE_DEPARTMENT_ID, FIXTURE_RANGERS_DIRT_TEAM_ID),
    );

    expect(wrapper.text()).toContain(
      "Team Overview requires department lead or team lead authority",
    );
    expect(wrapper.text()).not.toContain("Team roster");
  });

  it("refuses a team the session does not lead instead of swapping in one it does", async () => {
    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

    const { wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(FIXTURE_DPW_DEPARTMENT_ID, FIXTURE_RANGERS_DIRT_TEAM_ID),
    );

    expect(wrapper.text()).toContain(
      "Team Overview requires department lead or team lead authority",
    );
    expect(wrapper.text()).not.toContain("Bikes");
  });

  it("lets a department lead switch between the department's teams", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

    const { router, wrapper } = await mountAt(
      TeamOverviewView,
      teamOverviewPath(FIXTURE_RANGERS_DEPARTMENT_ID, FIXTURE_RANGERS_DIRT_TEAM_ID),
    );

    await wrapper.get("select").setValue(FIXTURE_RANGERS_DEFAULT_TEAM_ID);
    await flushPromises();

    expect(router.currentRoute.value.params.teamId).toBe(
      FIXTURE_RANGERS_DEFAULT_TEAM_ID,
    );
  });
});

describe("event info document resolution", () => {
  it("renders published assigned documents instead of placeholder prose", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const { wrapper } = await mountAt(EventInfoView, eventInfoPath());

    expect(wrapper.text()).toContain("How to get to the event");
    expect(wrapper.text()).toContain("Getting To Signal Camp");
    expect(wrapper.text()).toContain("Take the north access road to Gate 1.");
    expect(wrapper.text()).toContain("Ranger Packing List");
    expect(wrapper.text()).toContain("Dirt Team Housing");
    expect(wrapper.text()).not.toContain("Placeholder:");
  });

  it("names the gap in a section with no visible published document", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    const { wrapper } = await mountAt(EventInfoView, eventInfoPath());

    const housing = wrapper.get('[data-section="housing"]');
    expect(housing.attributes("data-empty")).toBe("true");
    expect(housing.text()).toContain(
      "No published document covers housing, camping, or shelter for this event yet.",
    );
  });

  it("does not widen document visibility", () => {
    const rangers = resolveEventInfo(null, FIXTURE_RANGERS_DEPARTMENT_ID);
    const gate = resolveEventInfo(null, FIXTURE_GATE_DEPARTMENT_ID);

    const rangerHousing = rangers.sections.find(
      (section) => section.section === "housing",
    );
    const gateHousing = gate.sections.find(
      (section) => section.section === "housing",
    );

    expect(rangerHousing?.documents.map((document) => document.title)).toEqual([
      "Dirt Team Housing",
    ]);
    expect(gateHousing?.documents).toEqual([]);
    expect(gateHousing?.emptyDescription).not.toBeNull();
  });

  it("orders a section from the broadest scope to the narrowest", () => {
    const rangers = resolveEventInfo(null, FIXTURE_RANGERS_DEPARTMENT_ID);
    const packing = rangers.sections.find(
      (section) => section.section === "packing",
    );

    expect(packing?.documents.map((document) => document.scopeType)).toEqual([
      "department",
    ]);

    const sectionOrder = rangers.sections.map((section) => section.section);
    expect(sectionOrder).toEqual([
      "directions",
      "arrival",
      "packing",
      "food",
      "housing",
      "requirements",
    ]);
  });

  it("shows each document's Event Info placement in the maintainer library", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

    const { wrapper } = await mountAt(
      DocumentLibraryView,
      `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${FIXTURE_RANGERS_DEPARTMENT_ID}/documents`,
    );

    const packingRow = wrapper
      .findAll("tbody tr")
      .find((row) => row.text().includes("Ranger Packing List"));

    expect(packingRow?.text()).toContain("What to bring");

    const radioRow = wrapper
      .findAll("tbody tr")
      .find((row) => row.text().includes("Radio Checkout"));

    expect(radioRow?.text()).toContain("Not shown");
  });

  it("keeps unpublished documents off Event Info even for their maintainer", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);

    const { wrapper: editor } = await mountAt(
      DocumentEditView,
      `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${FIXTURE_RANGERS_DEPARTMENT_ID}/documents/procedure/create`,
    );

    const inputs = editor.findAll("input");
    await inputs[0]!.setValue("Ranger Arrival Notes");
    await inputs[1]!.setValue("ranger-arrival-notes");
    await editor
      .get("select[value], select")
      .setValue(`department:${FIXTURE_RANGERS_DEPARTMENT_ID}`);
    await editor.findAll("select")[1]!.setValue("arrival");
    await editor.get("textarea").setValue("Report to the Ranger HQ shade.");
    await editor.get("form").trigger("submit");
    await flushPromises();

    const draftOnly = resolveEventInfo(null, FIXTURE_RANGERS_DEPARTMENT_ID);
    expect(
      draftOnly.sections
        .find((section) => section.section === "arrival")
        ?.documents.map((document) => document.title),
    ).not.toContain("Ranger Arrival Notes");

    const publishButton = editor
      .findAll("button")
      .find((button) => button.text() === "Publish");
    await publishButton!.trigger("click");
    await flushPromises();

    const published = resolveEventInfo(null, FIXTURE_RANGERS_DEPARTMENT_ID);
    expect(
      published.sections
        .find((section) => section.section === "arrival")
        ?.documents.map((document) => document.title),
    ).toContain("Ranger Arrival Notes");
  });
});
