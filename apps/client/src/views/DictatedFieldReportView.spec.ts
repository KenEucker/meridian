import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import type { VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import { authorFieldReportCatalog, resetFieldReportRuntime } from "@/field-reports/fieldReportRuntime";
import {
  clearFieldSession,
  installFieldSession,
  type FieldSessionContext,
} from "@/field-reports/fieldSession";
import { configureMeridianApi } from "@/api/meridianApi";
import { LOCAL_FIELD_DEPARTMENT_IDS } from "@/field-reports/localFieldFixture";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import type { SessionRole } from "@/session/sessionDocument";

/**
 * An IC operator takes a Field Report by dictation from the Incidents
 * workspace: they name the staff member who gave it to them, submit, and the
 * report files to that staff member with the operator recorded on it.
 *
 * Dictation is gated on `incidents.create`, which since M16.20 comes from the
 * session response rather than an installed IMS role. A viewer holds
 * `incidents.view` and nothing else.
 */
const RANGERS = LOCAL_FIELD_DEPARTMENT_IDS.rangers;

function icRoles(capabilities: readonly string[]): SessionRole[] {
  return [
    {
      role_code: "ic_viewer",
      role_name: "Incident Command Viewer",
      scope_type: "event",
      organization_id: null,
      department_id: RANGERS,
      team_id: null,
      team_name: null,
      event_id: "11111111-1111-4111-8111-111111111111",
      team_grant_id: null,
      reason: null,
      capabilities: [...capabilities],
    },
  ];
}

function installIcSession(capabilities?: readonly string[]): void {
  installLocalFieldSession(
    capabilities === undefined ? {} : { roles: icRoles(capabilities) },
  );
  selectSessionDepartment(RANGERS);
}

/**
 * A node with no incidents, so mounting the Incidents page in this file is
 * about which actions it offers rather than what it lists.
 */
function stubEmptyIncidentNode(): void {
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });

  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(
          JSON.stringify({
            event_id: "11111111-1111-4111-8111-111111111111",
            filters: {},
            filter_options: {},
            assignable: {},
            pagination: { page: 1, per_page: 25, total: 0, total_pages: 1 },
            presets: [],
            incidents: [],
            field_reports: [],
          }),
          { status: 200, headers: { "content-type": "application/json" } },
        ),
    ),
  );
}

// The operator's own staff id is the one the department fixtures know as
// "Local Field Author", so the attribution line can name them.
const OPERATOR_SESSION: FieldSessionContext = {
  eventId: "11111111-1111-4111-8111-111111111111",
  eventLabel: "Local Field Event",
  submittedByUserId: "22222222-2222-4222-8222-222222222222",
  staffId: "33333333-3333-4333-8333-333333333333",
  originDeviceId: "44444444-4444-4444-8444-444444444444",
  originNodeId: "55555555-5555-4555-8555-555555555555",
  departmentId: "66666666-6666-4666-8666-666666666666",
  departmentLabel: "Rangers",
  teamId: "77777777-7777-4777-8777-777777777777",
  teamLabel: "Command",
};

const VERA_STAFF_ID = "33333333-3333-4333-8333-333333333334";

async function mountAt(path: string) {
  const router = createRouter({ history: createWebHistory(), routes });
  await router.push(path);
  await router.isReady();

  const wrapper = mount(App, { global: { plugins: [router] } });
  await flushPromises();

  return { wrapper, router };
}

function buttonByText(wrapper: VueWrapper, text: string) {
  return wrapper.findAll("button").find((button) => button.text() === text);
}

beforeEach(async () => {
  await resetFieldReportRuntime();
  clearFieldSession();
  clearClientSession();
  installFieldSession(OPERATOR_SESSION);
  installIcSession();
  stubEmptyIncidentNode();
});

afterEach(async () => {
  await resetFieldReportRuntime();
  clearFieldSession();
  clearClientSession();
  resetSelectedSessionDepartment();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

describe("dictated Field Report entry point", () => {
  it("offers Take Field Report from Incidents for IC roles that may create incidents", async () => {
    const { wrapper } = await mountAt("/ims/incidents");

    const action = wrapper
      .findAll("a")
      .find((link) => link.text() === "Take Field Report");

    expect(action?.attributes("href")).toBe("/ims/field-reports/create");
  });

  it("hides Take Field Report from an IC viewer", async () => {
    installIcSession(["incidents.view"]);
    const { wrapper } = await mountAt("/ims/incidents");

    expect(
      wrapper.findAll("a").some((link) => link.text() === "Take Field Report"),
    ).toBe(false);
  });

  it("refuses the dictation form to an IC viewer who navigates to it directly", async () => {
    installIcSession(["incidents.view"]);
    const { wrapper } = await mountAt("/ims/field-reports/create");

    expect(wrapper.text()).toContain(
      "requires Incident Command permission to create incidents",
    );
    expect(wrapper.find("#fr-on-behalf-search").exists()).toBe(false);
  });
});

describe("dictated Field Report form", () => {
  it("defaults the report to the signed-in user and adds no attribution line", async () => {
    const { wrapper, router } = await mountAt("/ims/field-reports/create");

    expect(wrapper.get(".fr-create__on-behalf-selected").text()).toContain(
      "Local Field Author",
    );

    await wrapper.get("#fr-title").setValue("Own report");
    await wrapper.get("#fr-body").setValue("I saw this myself.");
    await wrapper.get(".fr-create__form").trigger("submit");
    await flushPromises();

    const reportId = String(router.currentRoute.value.params.fieldReportId);
    const stored = authorFieldReportCatalog.get(reportId);

    expect(stored?.body).toBe("I saw this myself.");
    expect(stored?.dictation).toBeNull();
    expect(stored?.staffId).toBe(OPERATOR_SESSION.staffId);
  });

  it("files a dictated report to the named staff member with the attribution line", async () => {
    const { wrapper, router } = await mountAt("/ims/field-reports/create");

    await wrapper.get("#fr-on-behalf-search").setValue("Vera");
    const veraOption = wrapper
      .findAll(".fr-create__on-behalf-results button")
      .find((button) => button.text().includes("Vera Staff"));
    expect(veraOption).toBeTruthy();
    await veraOption!.trigger("click");

    expect(wrapper.get(".fr-create__on-behalf-preview").text()).toContain(
      "Field Report filled out by Local Field Author on behalf of Vera Staff",
    );

    await wrapper.get("#fr-title").setValue("Dictated report");
    await wrapper.get("#fr-body").setValue("Vera reported a light out.");
    await wrapper.get(".fr-create__form").trigger("submit");
    await flushPromises();

    const reportId = String(router.currentRoute.value.params.fieldReportId);
    const stored = authorFieldReportCatalog.get(reportId);

    expect(stored?.body).toBe(
      "Field Report filled out by Local Field Author on behalf of Vera Staff\n\n" +
        "Vera reported a light out.",
    );
    // The report is Vera's; the operator stays the submitter.
    expect(stored?.staffId).toBe(VERA_STAFF_ID);
    expect(stored?.submittedByUserId).toBe(OPERATOR_SESSION.submittedByUserId);
    expect(stored?.dictation?.recordedByStaffId).toBe(OPERATOR_SESSION.staffId);
  });

  it("lets the operator go back to their own name after picking someone else", async () => {
    const { wrapper } = await mountAt("/ims/field-reports/create");

    await wrapper.get("#fr-on-behalf-search").setValue("Vera");
    await wrapper
      .findAll(".fr-create__on-behalf-results button")
      .find((button) => button.text().includes("Vera Staff"))!
      .trigger("click");
    expect(wrapper.find(".fr-create__on-behalf-preview").exists()).toBe(true);

    await buttonByText(wrapper, "Use my own name")!.trigger("click");

    expect(wrapper.find(".fr-create__on-behalf-preview").exists()).toBe(false);
    expect(wrapper.get(".fr-create__on-behalf-selected").text()).toContain(
      "Local Field Author",
    );
  });

  it("does not offer the staff picker on the ordinary staff create route", async () => {
    const { wrapper } = await mountAt("/staff/field-reports/create");

    expect(wrapper.find("#fr-on-behalf-search").exists()).toBe(false);
    expect(wrapper.get("#fr-create-heading").text()).toBe("Submit Field Report");
  });
});

describe("dictated Field Report visibility for the reporting staff member", () => {
  it("appears in the reporting staff member's own list and is read-only to them", async () => {
    const { wrapper, router } = await mountAt("/ims/field-reports/create");

    await wrapper.get("#fr-on-behalf-search").setValue("Vera");
    await wrapper
      .findAll(".fr-create__on-behalf-results button")
      .find((button) => button.text().includes("Vera Staff"))!
      .trigger("click");
    await wrapper.get("#fr-title").setValue("Dictated report");
    await wrapper.get("#fr-body").setValue("Vera reported a light out.");
    await wrapper.get(".fr-create__form").trigger("submit");
    await flushPromises();

    const reportId = String(router.currentRoute.value.params.fieldReportId);

    // Sign in as Vera: a different user and staff id, same event.
    installFieldSession({
      ...OPERATOR_SESSION,
      submittedByUserId: "vera-user",
      staffId: VERA_STAFF_ID,
    });

    const vera = await mountAt("/staff/field-reports");
    expect(vera.wrapper.text()).toContain("Dictated report");

    const detail = await mountAt(`/staff/field-reports/${reportId}`);
    expect(detail.wrapper.text()).toContain("Vera reported a light out.");
    // Only the original submitter may append (data/API 15.3).
    expect(detail.wrapper.find(".fr-detail__append-form").exists()).toBe(false);
    expect(detail.wrapper.text()).toContain(
      "Only the person who submitted it can append to it",
    );
  });

  it("stays out of an unrelated staff member's list", async () => {
    const { wrapper } = await mountAt("/ims/field-reports/create");

    await wrapper.get("#fr-on-behalf-search").setValue("Vera");
    await wrapper
      .findAll(".fr-create__on-behalf-results button")
      .find((button) => button.text().includes("Vera Staff"))!
      .trigger("click");
    await wrapper.get("#fr-title").setValue("Dictated report");
    await wrapper.get("#fr-body").setValue("Vera reported a light out.");
    await wrapper.get(".fr-create__form").trigger("submit");
    await flushPromises();

    installFieldSession({
      ...OPERATOR_SESSION,
      submittedByUserId: "stranger-user",
      staffId: "33333333-3333-4333-8333-339999999999",
    });

    const stranger = await mountAt("/staff/field-reports");
    expect(stranger.wrapper.text()).not.toContain("Dictated report");
  });
});
