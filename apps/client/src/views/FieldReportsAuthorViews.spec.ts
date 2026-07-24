import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import {
  authorFieldReportCatalog,
  reloadFieldReportRuntimeFromLocalStore,
  resetFieldReportRuntime,
} from "@/field-reports/fieldReportRuntime";
import {
  clearFieldSession,
  installFieldSession,
  type FieldSessionContext,
} from "@/field-reports/fieldSession";
import { applyLocalFieldReportAcceptance } from "@/field-reports/submitFieldReport";
import { routes } from "@/router";

const SESSION: FieldSessionContext = {
  eventId: "event-1",
  eventLabel: "Idaho Decompression 2026",
  submittedByUserId: "user-1",
  staffId: "staff-1",
  originDeviceId: "device-1",
  originNodeId: "node-1",
  departmentId: "department-1",
  departmentLabel: "Rangers",
  teamId: "team-1",
  teamLabel: "Dirt",
};

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

beforeEach(async () => {
  await resetFieldReportRuntime();
  clearFieldSession();
  installFieldSession(SESSION);
});

afterEach(async () => {
  await resetFieldReportRuntime();
  clearFieldSession();
});

describe("Field Report author list/detail surfaces (M9.4)", () => {
  it("registers the UI contract route names for index, create, and show", () => {
    const names = routes.map((route) => route.name);

    expect(names).toContain("staff.field-reports.index");
    expect(names).toContain("staff.field-reports.create");
    expect(names).toContain("staff.field-reports.show");
  });

  it("opens My Field Reports at staff.field-reports.index", async () => {
    const { wrapper } = await mountAt("/staff/field-reports");

    expect(wrapper.get("#field-reports-heading").text()).toBe(
      "My Field Reports",
    );
    expect(wrapper.text()).toContain("Idaho Decompression 2026");
    expect(wrapper.text()).toContain(
      "You have not submitted any Field Reports yet.",
    );
  });

  it("links to the author list from the home surface", async () => {
    const { wrapper } = await mountAt("/");

    const link = wrapper
      .findAll(".home__card")
      .find((item) => item.find("h3").text() === "My Field Reports");

    expect(link?.attributes("href")).toBe("/staff/field-reports");
  });

  it("submits a finalized Field Report with Submit and shows it on detail", async () => {
    const { wrapper, router } = await mountAt("/staff/field-reports/create");

    expect(wrapper.get("#fr-create-heading").text()).toBe(
      "Submit Field Report",
    );
    expect(wrapper.text()).toContain("Submit");
    expect(wrapper.text()).toContain("Cancel");
    expect(wrapper.text()).not.toContain("Save");
    expect(wrapper.text()).toContain("Set by your signed-in session");
    expect(wrapper.get("#fr-title").attributes("type")).toBe("text");
    expect(wrapper.get("#fr-title").attributes("maxlength")).toBe("200");

    await wrapper.get("#fr-title").setValue("Medical assist near Gate A");
    await wrapper
      .get("#fr-body")
      .setValue("Observed a medical assist near Gate A.");
    await wrapper.get(".fr-create__form").trigger("submit");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("staff.field-reports.show");
    expect(wrapper.get("#fr-detail-heading").text()).toBe(
      "Medical assist near Gate A",
    );
    expect(wrapper.get(".fr-detail__number").text()).toMatch(/^LOCAL-/);
    expect(wrapper.get(".fr-detail__body").text()).toBe(
      "Observed a medical assist near Gate A.",
    );
    expect(wrapper.text()).toContain("Pending sync");
    expect(wrapper.get('[aria-label="Field Report sync status"]').text()).toContain(
      "Queued locally",
    );
    expect(wrapper.text()).toContain("Retry sync");
    expect(wrapper.text()).toContain(
      "This original title and body are finalized and cannot be edited. You can append an update below.",
    );
    expect(wrapper.get("#fr-append-heading").text()).toBe("Append update");
    expect(wrapper.get("#fr-append-body").element).toBeTruthy();
    expect(wrapper.get(".fr-detail__append-submit").text()).toBe("Append");
    expect(wrapper.text()).not.toContain("Edit");
    expect(wrapper.text()).not.toContain("Save");
  });

  it("lets the author append without rewriting the original body", async () => {
    const { wrapper, router } = await mountAt("/staff/field-reports/create");

    await wrapper.get("#fr-title").setValue("Gate assist");
    await wrapper.get("#fr-body").setValue("Original report body.");
    await wrapper.get(".fr-create__form").trigger("submit");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("staff.field-reports.show");

    await wrapper.get("#fr-append-body").setValue("Later update from author.");
    await wrapper.get(".fr-detail__append-form").trigger("submit");
    await flushPromises();

    expect(wrapper.get(".fr-detail__body").text()).toBe("Original report body.");
    expect(wrapper.get('[aria-label="Field Report appends"]').text()).toContain(
      "Later update from author.",
    );
    expect(wrapper.get("#fr-append-body").element).toHaveProperty("value", "");

    const reportId = String(router.currentRoute.value.params.fieldReportId);
    const stored = authorFieldReportCatalog.get(reportId);
    expect(stored?.body).toBe("Original report body.");
    expect(stored?.appends).toHaveLength(1);
    expect(stored?.appends[0]?.body).toBe("Later update from author.");
  });

  it("lists only the author's submitted reports on the index", async () => {
    const { wrapper, router } = await mountAt("/staff/field-reports/create");

    await wrapper.get("#fr-title").setValue("First author title");
    await wrapper.get("#fr-body").setValue("First author report");
    await wrapper.get(".fr-create__form").trigger("submit");
    await flushPromises();

    const reportId = String(router.currentRoute.value.params.fieldReportId);
    authorFieldReportCatalog.recordSubmitted(
      // Force a second author's report into the catalog without using the UI.
      Object.freeze({
        id: "other-author-report",
        eventId: "event-1",
        departmentId: null,
        teamId: null,
        submittedByUserId: "user-2",
        staffId: "staff-2",
        fraNumber: null,
        temporaryLocalNumber: "LOCAL-OTHER001",
        title: "Other author title",
        body: "Should not appear for user-1",
        deviceSubmittedAt: "2027-06-01T12:00:00.000Z",
        serverReceivedAt: null,
        originDeviceId: "device-2",
        originNodeId: "node-1",
        syncStatus: "pending_sync" as const,
        createdAt: "2027-06-01T12:00:00.000Z",
        appends: [],
      }),
    );

    await router.push({ name: "staff.field-reports.index" });
    await flushPromises();

    expect(wrapper.text()).toContain("First author title");
    expect(wrapper.text()).toContain("First author report");
    expect(wrapper.text()).not.toContain("Should not appear for user-1");
    expect(wrapper.text()).not.toContain("Other author title");
    expect(wrapper.get(".field-reports__link").attributes("href")).toBe(
      `/staff/field-reports/${reportId}`,
    );
  });

  it("denies detail for a Field Report the current author did not submit", async () => {
    authorFieldReportCatalog.recordSubmitted(
      Object.freeze({
        id: "other-author-report",
        eventId: "event-1",
        departmentId: null,
        teamId: null,
        submittedByUserId: "user-2",
        staffId: "staff-2",
        fraNumber: null,
        temporaryLocalNumber: "LOCAL-OTHER001",
        title: "Other author title",
        body: "Other author body",
        deviceSubmittedAt: "2027-06-01T12:00:00.000Z",
        serverReceivedAt: null,
        originDeviceId: "device-2",
        originNodeId: "node-1",
        syncStatus: "pending_sync" as const,
        createdAt: "2027-06-01T12:00:00.000Z",
        appends: [],
      }),
    );

    const { wrapper } = await mountAt("/staff/field-reports/other-author-report");

    expect(wrapper.text()).toContain(
      "You can only view Field Reports you authored.",
    );
    expect(wrapper.text()).not.toContain("Other author body");
    expect(wrapper.text()).not.toContain("Other author title");
  });

  it("replaces the temporary local number with the FRA number after acceptance", async () => {
    const { wrapper, router } = await mountAt("/staff/field-reports/create");

    await wrapper.get("#fr-title").setValue("Synced report title");
    await wrapper.get("#fr-body").setValue("Synced report body");
    await wrapper.get(".fr-create__form").trigger("submit");
    await flushPromises();

    const reportId = String(router.currentRoute.value.params.fieldReportId);

    applyLocalFieldReportAcceptance(reportId, {
      fraNumber: "FRA-2027-000123",
      serverReceivedAt: "2027-06-01T12:05:00.000Z",
    });
    await flushPromises();

    expect(wrapper.get("#fr-detail-heading").text()).toBe("Synced report title");
    expect(wrapper.get(".fr-detail__number").text()).toBe("FRA-2027-000123");
    expect(wrapper.text()).toContain("Synced");
    expect(wrapper.text()).not.toContain("Temporary local number");
  });

  it("shows Cancel in the create header and as a form action", async () => {
    const { wrapper } = await mountAt("/staff/field-reports/create");

    expect(wrapper.get(".fr-create__cancel-link").text()).toBe("Cancel");
    expect(wrapper.get(".fr-create__cancel").text()).toBe("Cancel");
  });

  it("exposes optional photo capture controls with the Alpha 1 max-2 help text", async () => {
    const { wrapper } = await mountAt("/staff/field-reports/create");

    expect(wrapper.get("#fr-photos").attributes("type")).toBe("file");
    expect(wrapper.get("#fr-photos").attributes("accept")).toBe("image/*");
    expect(wrapper.get(".fr-create__add-photo").text()).toBe("Add photo");
    expect(wrapper.get("#fr-photos-help").text()).toContain("Up to 2 images");
    expect(wrapper.get("#fr-photos-help").text()).toContain("GIFs are not");
    expect(wrapper.get("#fr-photos-help").text()).toContain("2 slots remaining");
  });

  it("cancels create back to the author list without saving a draft", async () => {
    const { wrapper, router } = await mountAt("/staff/field-reports/create");

    await wrapper.get("#fr-title").setValue("Draft title");
    await wrapper.get("#fr-body").setValue("Draft that must not persist");
    await wrapper.get(".fr-create__cancel").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("staff.field-reports.index");
    expect(authorFieldReportCatalog.size).toBe(0);
    expect(wrapper.text()).toContain(
      "You have not submitted any Field Reports yet.",
    );
  });

  it("keeps submitted reports on the list after a simulated page refresh", async () => {
    const { wrapper, router } = await mountAt("/staff/field-reports/create");

    await wrapper.get("#fr-title").setValue("Survives refresh title");
    await wrapper.get("#fr-body").setValue("Survives refresh");
    await wrapper.get(".fr-create__form").trigger("submit");
    await flushPromises();

    // Simulate reload: drop in-memory state, then hydrate from localStorage.
    authorFieldReportCatalog.clear();
    expect(authorFieldReportCatalog.size).toBe(0);
    reloadFieldReportRuntimeFromLocalStore();

    await router.push({ name: "staff.field-reports.index" });
    await flushPromises();

    expect(wrapper.text()).toContain("Survives refresh title");
    expect(wrapper.text()).toContain("Survives refresh");
    expect(wrapper.text()).toContain("Pending sync");
  });
});
