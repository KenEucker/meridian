import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import { LOCAL_CURRENT_SHIFT_BOARD } from "@/shift-board/currentShiftBoard";
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

function shiftBoardPath(): string {
  return `/events/${LOCAL_CURRENT_SHIFT_BOARD.eventId}/departments/${LOCAL_CURRENT_SHIFT_BOARD.departmentId}/shift-board/current`;
}

describe("Current Shift Board roster surface (M10.1)", () => {
  it("registers the UI contract route name", () => {
    const names = routes.map((route) => route.name);

    expect(names).toContain("events.departments.shift-board.current");
  });

  it("links to the current shift board from the home surface", async () => {
    const { wrapper } = await mountAt("/");

    const link = wrapper
      .findAll(".home__links a")
      .find((item) => item.text() === "Current shift board");

    expect(link?.attributes("href")).toBe(shiftBoardPath());
  });

  it("shows current shift context, roster count, and checked-in count", async () => {
    const { wrapper } = await mountAt(shiftBoardPath());

    expect(wrapper.get("#shift-board-heading").text()).toBe(
      "Current Shift Board",
    );
    expect(wrapper.text()).toContain("Local Field Event");
    expect(wrapper.text()).toContain("Rangers");
    expect(wrapper.text()).toContain("Dirt");
    expect(wrapper.text()).toContain("Ranger Dirt Day Shift");

    const summary = wrapper.get('[aria-label="Roster summary"]').text();
    expect(summary).toContain("Roster");
    expect(summary).toContain("3");
    expect(summary).toContain("Checked in");
    expect(summary).toContain("2");
  });

  it("adds an eligible unscheduled staff member to the roster", async () => {
    const { wrapper } = await mountAt(shiftBoardPath());

    const addSection = wrapper.get('[aria-labelledby="unscheduled-heading"]');
    expect(addSection.text()).toContain("Ari Ranger");

    await addSection.get("form").trigger("submit");

    expect(wrapper.get('[aria-label="Roster summary"]').text()).toContain("4");
    expect(wrapper.get('[aria-labelledby="roster-heading"]').text()).toContain(
      "Ari Ranger",
    );
    expect(wrapper.get('[role="status"]').text()).toBe(
      "Ari Ranger added to roster.",
    );
    expect(
      wrapper
        .findAll('[aria-label="Add eligible unscheduled staff"] option')
        .map((option) => option.text()),
    ).toEqual([]);
  });

  it("moves a roster member to a current deployment", async () => {
    const { wrapper } = await mountAt(shiftBoardPath());

    const deploymentSection = wrapper.get(
      '[aria-labelledby="deployments-heading"]',
    );
    expect(deploymentSection.text()).toContain("Local Field Author - Gate 1");
    expect(deploymentSection.text()).toContain("Vera Staff - Unassigned");

    await deploymentSection
      .findAll("select")[0]
      .setValue("aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2");
    await deploymentSection
      .findAll("select")[1]
      .setValue("deployment-hq-runner");
    await deploymentSection.get("form").trigger("submit");

    expect(
      wrapper.get('[aria-label="Deployment assignment status"]').text(),
    ).toBe("Vera Staff moved to HQ Runner.");
    expect(wrapper.get('[aria-labelledby="roster-heading"]').text()).toContain(
      "HQ Runner",
    );
  });

  it("lists the current roster and labels checked-in state with text", async () => {
    const { wrapper } = await mountAt(shiftBoardPath());

    const roster = wrapper.get('[aria-labelledby="roster-heading"]');
    expect(roster.text()).toContain("Local Field Author");
    expect(roster.text()).toContain("Vera Staff");
    expect(roster.text()).toContain("Sam Shiftlead");
    expect(roster.text()).toContain("Checked in");
    expect(roster.text()).toContain("Scheduled");
    expect(roster.text()).toContain("Gate 1");
    expect(roster.text()).toContain("Perimeter North");
    expect(roster.text()).toContain("Unassigned");
    expect(roster.text()).toContain("Not checked in");
  });

  it("surfaces checked-in staff separately from scheduled roster members", async () => {
    const { wrapper } = await mountAt(shiftBoardPath());

    const checkedIn = wrapper.get('[aria-labelledby="checked-in-heading"]');
    expect(checkedIn.text()).toContain("Local Field Author");
    expect(checkedIn.text()).toContain("Sam Shiftlead");
    expect(checkedIn.text()).not.toContain("Vera Staff");
  });

  it("does not expose deferred attendance operation or adjacent workflow controls", async () => {
    const { wrapper } = await mountAt(shiftBoardPath());

    expect(wrapper.findAll("button").map((button) => button.text())).toEqual([
      "Add to roster",
      "Move to deployment",
    ]);
    expect(wrapper.text()).not.toContain("Check in");
    expect(wrapper.text()).not.toContain("Check out");
    expect(wrapper.text()).not.toContain("No-show");
    expect(wrapper.text()).not.toContain("Hours");
    expect(wrapper.text()).not.toContain("Equipment");
    expect(wrapper.text()).not.toContain("Incident");
  });
});
