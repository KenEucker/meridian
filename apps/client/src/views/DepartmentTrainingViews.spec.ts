import { afterEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import {
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import {
  FIXTURE_TRAINING_IDS,
  resetTrainingFixtures,
} from "@/trainings/trainingAdminModel";
import { routes } from "@/router";
import DepartmentTrainingDetailView from "@/views/DepartmentTrainingDetailView.vue";
import DepartmentTrainingEditView from "@/views/DepartmentTrainingEditView.vue";
import DepartmentTrainingListView from "@/views/DepartmentTrainingListView.vue";
import HomeView from "@/views/HomeView.vue";

const EVENT_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

afterEach(() => {
  resetTrainingFixtures();
  resetSelectedFixtureDepartment();
});

describe("product training management", () => {
  it("registers department training routes", () => {
    const names = routes.map((route) => route.name);

    expect(names).toContain("events.departments.trainings.index");
    expect(names).toContain("events.departments.trainings.create");
    expect(names).toContain("events.departments.trainings.edit");
    expect(names).toContain("events.departments.trainings.show");
  });

  it("exposes a trainings entry point from the department home context", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const router = buildRouter();
    await router.push({ name: "home" });
    await router.isReady();

    const home = mount(HomeView, { global: { plugins: [router] } });
    expect(home.text()).toContain("Department training schedule, signup");
  });

  it("shows the department lead trainings with schedule, expiration, and prerequisites", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const router = buildRouter();
    await router.push({
      name: "events.departments.trainings.index",
      params: { eventId: EVENT_ID, departmentId: FIXTURE_RANGERS_DEPARTMENT_ID },
    });
    await router.isReady();

    const wrapper = mount(DepartmentTrainingListView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain("Ranger Orientation");
    expect(wrapper.text()).toContain("Radio Certification");
    expect(wrapper.text()).toContain("Annual (365 days)");
    expect(wrapper.text()).toContain("1 of 2 signed up");
    expect(wrapper.text()).toContain("New training");
  });

  it("lets a department lead create a training with expiration setup", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const router = buildRouter();
    await router.push({
      name: "events.departments.trainings.create",
      params: { eventId: EVENT_ID, departmentId: FIXTURE_RANGERS_DEPARTMENT_ID },
    });
    await router.isReady();

    const wrapper = mount(DepartmentTrainingEditView, {
      global: { plugins: [router] },
    });

    await wrapper.get("input[type='text']").setValue("Vehicle Certification");
    const numberInputs = wrapper.findAll("input[type='number']");
    await numberInputs[0]!.setValue(180);
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Vehicle Certification saved.");
    expect(router.currentRoute.value.name).toBe(
      "events.departments.trainings.edit",
    );
  });

  it("lets staff sign up for a scheduled training and cancel it", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    const router = buildRouter();
    await router.push({
      name: "events.departments.trainings.index",
      params: { eventId: EVENT_ID, departmentId: FIXTURE_GATE_DEPARTMENT_ID },
    });
    await router.isReady();

    const wrapper = mount(DepartmentTrainingListView, {
      global: { plugins: [router] },
    });

    // Gate staff cannot create trainings.
    expect(wrapper.text()).not.toContain("New training");
    expect(wrapper.text()).toContain("Gate Shift Briefing");

    const signUp = wrapper
      .findAll("button")
      .find((button) => button.text() === "Sign up");
    expect(signUp).toBeTruthy();
    await signUp!.trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain("Signed up for Gate Shift Briefing.");
    expect(wrapper.text()).toContain("Signed up");

    const cancel = wrapper
      .findAll("button")
      .find((button) => button.text() === "Cancel signup");
    expect(cancel).toBeTruthy();
    await cancel!.trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain("Signup cancelled for Gate Shift Briefing.");
  });

  it("shows the roster to authorized leads and records completions", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const router = buildRouter();
    await router.push({
      name: "events.departments.trainings.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
        trainingId: FIXTURE_TRAINING_IDS.radioCertification,
      },
    });
    await router.isReady();

    const wrapper = mount(DepartmentTrainingEditView, {
      global: { plugins: [router] },
    });

    // Sam Shiftlead is on the fixture roster.
    expect(wrapper.text()).toContain("Roster");
    expect(wrapper.text()).toContain("Sam Shiftlead");

    const record = wrapper
      .findAll("button")
      .find((button) => button.text() === "Record completion");
    expect(record).toBeTruthy();
    await record!.trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain("Completion recorded for Sam Shiftlead.");
  });

  it("shows the training webpage with schedule, commitment, and after-training info", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const router = buildRouter();
    await router.push({
      name: "events.departments.trainings.show",
      params: {
        eventId: EVENT_ID,
        departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
        trainingId: FIXTURE_TRAINING_IDS.radioCertification,
      },
    });
    await router.isReady();

    const wrapper = mount(DepartmentTrainingDetailView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain("Radio Certification");
    expect(wrapper.text()).toContain("In person");
    expect(wrapper.text()).toContain("HQ Tent");
    expect(wrapper.text()).toContain("One three-hour session, renewed annually.");
    expect(wrapper.text()).toContain("After this training");
    expect(wrapper.text()).toContain("radio-equipped patrol shifts");
    expect(wrapper.text()).toContain("Shifts this training unlocks");
    expect(wrapper.text()).toContain("Dirt patrol (Friday night)");
    expect(wrapper.text()).toContain("listed as a shift signup");
    expect(wrapper.text()).toContain("Ranger Orientation — not completed yet");
  });

  it("shows online trainings with a URL and no signup", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const router = buildRouter();
    await router.push({
      name: "events.departments.trainings.show",
      params: {
        eventId: EVENT_ID,
        departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
        trainingId: FIXTURE_TRAINING_IDS.radioTheoryOnline,
      },
    });
    await router.isReady();

    const wrapper = mount(DepartmentTrainingDetailView, {
      global: { plugins: [router] },
    });

    expect(wrapper.text()).toContain("Online");
    expect(wrapper.text()).toContain(
      "https://training.signalcamp.dev/radio-theory",
    );
    expect(wrapper.text()).toContain("No signup is needed");
    const signUp = wrapper
      .findAll("button")
      .find((button) => button.text() === "Sign up");
    expect(signUp).toBeFalsy();
  });

  it("imports completions from CSV with per-row results", async () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const router = buildRouter();
    await router.push({
      name: "events.departments.trainings.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
        trainingId: FIXTURE_TRAINING_IDS.rangerOrientation,
      },
    });
    await router.isReady();

    const wrapper = mount(DepartmentTrainingEditView, {
      global: { plugins: [router] },
    });

    await wrapper
      .get("textarea[aria-label='Completion CSV']")
      .setValue(
        "email,completed_at\nvera@signalcamp.dev,2026-07-01\nmissing@nowhere.dev,2026-07-01",
      );

    const importButton = wrapper
      .findAll("button")
      .find((button) => button.text() === "Import completions");
    expect(importButton).toBeTruthy();
    await importButton!.trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain("Imported 1 completion(s); skipped 1.");
    expect(wrapper.text()).toContain("No staff record with this email.");
  });
});
