import { afterEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import {
  COMBINED_NAVIGATION_MAX_ITEMS,
  useCombinedNavigation,
  useStaffLinks,
  useWorkflowLinks,
} from "@/components/workflowLinks";
import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";
import {
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import {
  clearDepartmentSelfAdminSession,
  installDevelopmentDepartmentSelfAdminSession,
  resetDepartmentSelfAdminFixtures,
} from "@/department-teams/teamAdminModel";
import { resetDocumentAuthoringFixtures } from "@/documents/documentAuthoringModel";
import { routes } from "@/router";
import DepartmentShiftListView from "@/views/DepartmentShiftListView.vue";
import DocumentLibraryView from "@/views/DocumentLibraryView.vue";
import DepartmentTrainingListView from "@/views/DepartmentTrainingListView.vue";
import EventInfoView from "@/views/EventInfoView.vue";
import FieldReportsIndexView from "@/views/FieldReportsIndexView.vue";

async function mountAt(component: unknown, path: string) {
  const router = createRouter({ history: createWebHistory(), routes });
  await router.push(path);
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  });
  await flushPromises();

  return wrapper;
}

function departmentPath(departmentId: string, suffix: string): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${departmentId}/${suffix}`;
}

afterEach(() => {
  clearDepartmentSelfAdminSession();
  resetDepartmentSelfAdminFixtures();
  resetDocumentAuthoringFixtures();
  resetSelectedFixtureDepartment();
});

describe("staff page template", () => {
  it("renders My Field Reports on the narrow touch-first shell", async () => {
    const wrapper = await mountAt(FieldReportsIndexView, "/staff/field-reports");

    expect(wrapper.find(".staff-page").exists()).toBe(true);
    expect(wrapper.find(".workflow-page").exists()).toBe(false);
    expect(wrapper.find("table").exists()).toBe(false);
  });

  it("renders Event Info on the narrow touch-first shell", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const wrapper = await mountAt(
      EventInfoView,
      `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/info`,
    );

    expect(wrapper.find(".staff-page").exists()).toBe(true);
    expect(wrapper.find(".workflow-page").exists()).toBe(false);
  });

  it("gives a member the shift card list and a lead the shift table", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    const member = await mountAt(
      DepartmentShiftListView,
      departmentPath(FIXTURE_GATE_DEPARTMENT_ID, "shifts"),
    );

    expect(member.find(".staff-page").exists()).toBe(true);
    expect(member.find("table").exists()).toBe(false);
    expect(member.text()).toContain("Shifts your teams are eligible for.");

    clearDepartmentSelfAdminSession();
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    const lead = await mountAt(
      DepartmentShiftListView,
      departmentPath(FIXTURE_RANGERS_DEPARTMENT_ID, "shifts"),
    );

    expect(lead.find(".workflow-page").exists()).toBe(true);
    expect(lead.find(".staff-page").exists()).toBe(false);
    expect(lead.find("table").exists()).toBe(true);
    expect(lead.text()).toContain("Create shift");
  });

  it("gives a member the training card list and a manager the training table", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    const member = await mountAt(
      DepartmentTrainingListView,
      departmentPath(FIXTURE_GATE_DEPARTMENT_ID, "trainings"),
    );

    expect(member.find(".staff-page").exists()).toBe(true);
    expect(member.find("table").exists()).toBe(false);
    expect(member.text()).not.toContain("New training");

    clearDepartmentSelfAdminSession();
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    const manager = await mountAt(
      DepartmentTrainingListView,
      departmentPath(FIXTURE_RANGERS_DEPARTMENT_ID, "trainings"),
    );

    expect(manager.find(".workflow-page").exists()).toBe(true);
    expect(manager.find(".staff-page").exists()).toBe(false);
    expect(manager.find("table").exists()).toBe(true);
    expect(manager.text()).toContain("New training");
  });

  it("surrounds the Event Info summary with its section cards", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const wrapper = await mountAt(
      EventInfoView,
      `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/info`,
    );

    const layout = wrapper.get(".hero-center");
    // The summary is a peer of the cards, not a header above them, so it can
    // take the middle column once there is room either side of it.
    expect(layout.get(".hero-center__hero .event-info__hero").text()).toContain(
      "At a glance",
    );
    expect(layout.findAll(".event-info__section").length).toBe(6);
  });

  it("tiles reader lists on the department staff surfaces", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();

    const documents = await mountAt(
      DocumentLibraryView,
      departmentPath(FIXTURE_GATE_DEPARTMENT_ID, "documents"),
    );
    expect(documents.get(".content-grid").classes()).toContain(
      "content-grid--wide",
    );

    const shifts = await mountAt(
      DepartmentShiftListView,
      departmentPath(FIXTURE_GATE_DEPARTMENT_ID, "shifts"),
    );
    expect(shifts.get(".content-grid").classes()).toContain(
      "content-grid--tile",
    );

    const trainings = await mountAt(
      DepartmentTrainingListView,
      departmentPath(FIXTURE_GATE_DEPARTMENT_ID, "trainings"),
    );
    expect(trainings.get(".content-grid").classes()).toContain(
      "content-grid--wide",
    );
  });

  it("keeps staff card actions at a thumb-sized target", async () => {
    const wrapper = await mountAt(FieldReportsIndexView, "/staff/field-reports");

    // The whole card title block is the tap target when the card links out.
    expect(wrapper.find(".staff-page__actions").exists()).toBe(true);
    expect(
      wrapper.get(".staff-page__actions a").attributes("data-variant"),
    ).toBe("primary");
  });
});

describe("combined staff and workflow navigation", () => {
  it("combines the menus exactly while the list stays under the threshold", () => {
    // No fixture role reaches the threshold today: the fullest, a Rangers
    // department lead, is nine items against a limit of ten. The split path is
    // therefore asserted as a rule rather than driven through a fixture, so
    // raising or lowering the limit keeps this honest.
    for (const departmentId of [
      FIXTURE_GATE_DEPARTMENT_ID,
      FIXTURE_DPW_DEPARTMENT_ID,
      FIXTURE_RANGERS_DEPARTMENT_ID,
    ]) {
      selectFixtureDepartment(departmentId);
      const navigation = useCombinedNavigation();

      expect(navigation.value.combined).toBe(
        navigation.value.links.length < COMBINED_NAVIGATION_MAX_ITEMS,
      );
      expect(navigation.value.combined).toBe(true);
    }
  });

  it("puts Event Info next to Me whenever the interface is locked to an event", () => {
    for (const departmentId of [
      FIXTURE_GATE_DEPARTMENT_ID,
      FIXTURE_DPW_DEPARTMENT_ID,
      FIXTURE_RANGERS_DEPARTMENT_ID,
    ]) {
      selectFixtureDepartment(departmentId);
      const staffLinks = useStaffLinks();

      expect(staffLinks.value.slice(0, 2).map((link) => link.label)).toEqual([
        "Me",
        "Event Info",
      ]);
      expect(staffLinks.value[1]!.to.name).toBe("events.info");
    }
  });

  it("keeps Me out of the workflow hubs", () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const workflowLinks = useWorkflowLinks();

    expect(workflowLinks.value.map((link) => link.label)).not.toContain("Me");
    expect(workflowLinks.value.map((link) => link.label)).not.toContain(
      "Event Info",
    );
  });
});
