import { afterEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import type { VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import {
  availableFieldReportOptionsForSession,
  availableLinkedIncidentOptionsForSession,
  blankIncidentAutosaveForm,
  clearIncidentSession,
  createIncidentFromAutosaveForm,
  installIncidentSession,
  LOCAL_IMS_EVENT_ID,
  linkFieldReportForSession,
  updateIncidentFromAutosaveForm,
  type IncidentAutosaveForm,
  type IncidentSessionContext,
} from "@/ims/incidentReadModel";
import { routes } from "@/router";

const IC_SESSION: IncidentSessionContext = {
  eventId: LOCAL_IMS_EVENT_ID,
  eventLabel: "Idaho Decompression 2026",
  organizationLabel: "Idaho Burners",
  icDepartmentLabel: "Rangers",
  role: "ic_viewer",
  roleLabel: "Incident Command Viewer",
};

const NON_IC_SESSION: IncidentSessionContext = {
  ...IC_SESSION,
  role: "department_lead",
  roleLabel: "Department Lead",
};

const IC_OPERATOR_SESSION: IncidentSessionContext = {
  ...IC_SESSION,
  role: "ic_operator",
  roleLabel: "Incident Command Operator",
};

const IC_LEAD_SESSION: IncidentSessionContext = {
  ...IC_SESSION,
  role: "ic_lead",
  roleLabel: "Incident Command Lead",
};

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

function findLinkByText(wrapper: VueWrapper, text: string) {
  return wrapper.findAll("a").find((link) => link.text() === text);
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

async function addBySearch(
  wrapper: VueWrapper,
  inputSelector: string,
  query: string,
  optionText: string,
): Promise<void> {
  await wrapper.get(inputSelector).trigger("focus");
  await wrapper.get(inputSelector).setValue(query);
  await flushPromises();

  const option = wrapper
    .findAll(".ims-edit__add-results button")
    .find((button) => button.text() === optionText);

  expect(option).toBeDefined();
  await option?.trigger("click");
  await flushPromises();
}

async function showFullHistory(wrapper: VueWrapper): Promise<void> {
  const button = wrapper
    .findAll("button")
    .find((candidate) => candidate.text() === "Show full history");

  expect(button).toBeDefined();
  await button?.trigger("click");
  await flushPromises();
}

afterEach(() => {
  vi.restoreAllMocks();
  clearIncidentSession();
  setNavigatorOnline(true);
});

function setNavigatorOnline(onLine: boolean): void {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value: onLine,
  });
}

describe("IMS incident list/detail surfaces (M11.5)", () => {
  it("registers the UI contract route names", () => {
    const names = routes.map((route) => route.name);

    expect(names).toContain("ims.incidents.index");
    expect(names).toContain("ims.incidents.create");
    expect(names).toContain("ims.incidents.show");
    expect(names).toContain("ims.incidents.edit");
    expect(names).toContain("ims.field-reports.index");
    expect(names).toContain("ims.field-reports.show");
    expect(names).toContain("ims.restricted");
  });

  it("renders a restricted, scan-friendly incident list for IC roles", async () => {
    installIncidentSession(IC_SESSION);

    const { wrapper } = await mountAt("/ims/incidents");

    expect(wrapper.get("#ims-incidents-heading").text()).toBe("Incidents");
    expect(wrapper.text()).toContain("Idaho Burners");
    expect(wrapper.text()).toContain("Idaho Decompression 2026");
    expect(wrapper.text()).toContain("Rangers");
    expect(wrapper.text()).toContain("Incident Command Viewer");
    expect(wrapper.text()).toContain("INC-2027-000042");
    expect(wrapper.text()).toContain("Medical assist near Gate A");
    expect(wrapper.text()).toContain("On Scene");
    expect(wrapper.text()).toContain("Serious");
    expect(wrapper.text()).toContain("Medical, Safety");
    expect(wrapper.text()).toContain("INC-2027-000041");
    expect(wrapper.text()).toContain("Routine");
    expect(wrapper.text()).toContain("Radio");
    expect(wrapper.text()).not.toContain("Closed supply handoff");
    expect(findLinkByText(wrapper, "Back To Home")?.attributes("href")).toBe(
      "/",
    );
    expect(
      wrapper
        .findAll(".ims-list__secondary-link")
        .some((link) => link.attributes("href") === "/ims/field-reports"),
    ).toBe(true);
    expect(wrapper.text()).toContain("07-04-2027");
    expect(wrapper.text()).toMatch(/07-04-2027 \d{2}:\d{2}/u);
    expect(wrapper.get(".ims-list__incident-link").attributes("href")).toBe(
      "/ims/incidents/incident-gate-medical",
    );
    expect(wrapper.text()).not.toContain("Create incident");
  });

  it("filters incidents by state and priority and sorts by headings", async () => {
    installIncidentSession(IC_SESSION);

    const { wrapper, router } = await mountAt("/ims/incidents");

    expect(wrapper.text()).not.toContain("Closed supply handoff");

    await wrapper.get("#ims-list-state").setValue("all");
    await flushPromises();

    expect(router.currentRoute.value.query.state).toBe("all");
    expect(wrapper.text()).toContain("Closed supply handoff");

    await wrapper.get("#ims-list-shift").setValue("current");
    await flushPromises();

    expect(router.currentRoute.value.query.shift).toBe("current");
    expect(wrapper.text()).toContain("Closed supply handoff");

    await wrapper.get("#ims-list-priority").setValue("Serious");
    await flushPromises();

    expect(wrapper.text()).toContain("Medical assist near Gate A");
    expect(wrapper.text()).not.toContain("Radio relay check");
    expect(wrapper.text()).not.toContain("Closed supply handoff");

    await router.push("/ims/incidents");
    await flushPromises();

    const incidentSort = wrapper
      .findAll("thead a")
      .find((link) => link.text() === "Incident");
    expect(incidentSort).toBeDefined();
    await incidentSort?.trigger("click");
    await flushPromises();

    expect(wrapper.findAll(".ims-list__incident-link")[0]?.text()).toContain(
      "INC-2027-000041",
    );
  });

  it("renders the IC Field Reports list with cross-links, filters, and sorting", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);
    linkFieldReportForSession(
      IC_OPERATOR_SESSION,
      "incident-gate-medical",
      "field-report-medical-gate",
      new Date("2027-07-04T20:50:00.000Z"),
    );

    const { wrapper, router } = await mountAt("/ims/field-reports");

    expect(wrapper.get("#ims-field-reports-heading").text()).toBe(
      "Field Reports",
    );
    expect(wrapper.text()).toContain("FRA-2027-000123");
    expect(wrapper.text()).toContain("Vera Ranger");
    expect(wrapper.text()).toContain("INC-2027-000042");
    expect(wrapper.text()).toContain("FRA-2027-000124");
    expect(findLinkByText(wrapper, "Back To Home")?.attributes("href")).toBe(
      "/",
    );
    expect(
      wrapper
        .findAll(".ims-fr-list__secondary-link")
        .some((link) => link.attributes("href") === "/ims/incidents"),
    ).toBe(true);
    const medicalReportLink = wrapper
      .findAll(".ims-fr-list__report-link")
      .find(
        (link) =>
          link.attributes("href") ===
          "/ims/field-reports/field-report-medical-gate",
      );
    expect(medicalReportLink).toBeDefined();

    await medicalReportLink?.trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("ims.field-reports.show");
    expect(wrapper.get("#ims-fr-detail-heading").text()).toBe(
      "Medical observation near Gate A",
    );
    expect(wrapper.text()).toContain("Vera Ranger");
    expect(wrapper.text()).toContain(
      "Observed medical response near Gate A for @Blue-Hat.",
    );
    expect(wrapper.text()).toContain("INC-2027-000042");

    await router.push("/ims/field-reports");
    await flushPromises();

    await wrapper.get("#ims-fr-list-link").setValue("linked");
    await flushPromises();

    expect(router.currentRoute.value.query.link).toBe("linked");
    expect(wrapper.text()).toContain("FRA-2027-000123");
    expect(wrapper.text()).not.toContain("FRA-2027-000124");

    await wrapper.get("#ims-fr-list-shift").setValue("current");
    await flushPromises();

    expect(router.currentRoute.value.query.shift).toBe("current");

    await wrapper.get("#ims-fr-list-link").setValue("not_linked");
    await flushPromises();

    expect(router.currentRoute.value.query.link).toBe("not_linked");
    expect(wrapper.text()).not.toContain("FRA-2027-000123");
    expect(wrapper.text()).toContain("FRA-2027-000124");

    await wrapper.get("#ims-fr-list-link").setValue("all");
    await flushPromises();

    await wrapper.get("#ims-fr-list-priority").setValue("Serious");
    await flushPromises();

    expect(wrapper.text()).toContain("FRA-2027-000123");
    expect(wrapper.text()).not.toContain("FRA-2027-000124");

    await router.push("/ims/field-reports");
    await flushPromises();

    const authorSort = wrapper
      .findAll("thead a")
      .find((link) => link.text() === "Author");
    expect(authorSort).toBeDefined();
    await authorSort?.trigger("click");
    await flushPromises();

    expect(wrapper.findAll("tbody tr")[0]?.text()).toContain("Ingrid ICLead");
  });

  it("shows create entry points only to IC operators and leads", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents");

    expect(wrapper.text()).toContain("Create incident");
    expect(findLinkByText(wrapper, "Create incident")?.attributes("href")).toBe(
      "/ims/incidents/create",
    );
  });

  it("installs an IC lead development session so create/edit/print can be exercised locally", async () => {
    const { wrapper } = await mountAt("/ims/incidents");

    expect(wrapper.text()).toContain("Local Field Organization");
    expect(wrapper.text()).toContain("Local Field Event");
    expect(wrapper.text()).toContain("Incident Command Lead");
    expect(wrapper.text()).toContain("Create incident");
  });

  it("does not render incident rows for non-IC roles", async () => {
    installIncidentSession(NON_IC_SESSION);

    const { wrapper } = await mountAt("/ims/incidents");

    expect(wrapper.text()).toContain("Incident Command access required");
    expect(wrapper.text()).toContain("Department Lead");
    expect(wrapper.text()).not.toContain("Medical assist near Gate A");
    expect(wrapper.text()).not.toContain("INC-2027-000042");
  });

  it("denies IC Field Report detail to non-IC roles", async () => {
    installIncidentSession(NON_IC_SESSION);

    const { wrapper } = await mountAt(
      "/ims/field-reports/field-report-medical-gate",
    );

    expect(wrapper.text()).toContain("Incident Command access required");
    expect(wrapper.text()).not.toContain("Medical observation near Gate A");
    expect(wrapper.text()).not.toContain("FRA-2027-000123");
  });

  it("renders read-only incident detail for IC roles", async () => {
    installIncidentSession(IC_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-gate-medical");

    expect(wrapper.get("#ims-detail-heading").text()).toBe(
      "Medical assist near Gate A",
    );
    expect(wrapper.text()).toContain("INC-2027-000042");
    expect(wrapper.text()).toContain("On Scene");
    expect(wrapper.text()).toContain("Serious");
    expect(wrapper.text()).toContain("Medical");
    expect(wrapper.text()).toContain("Safety");
    expect(wrapper.text()).toContain("Vera Ranger");
    expect(wrapper.text()).toContain("Linked incidents");
    expect(wrapper.text()).toContain("INC-2027-000041");
    expect(wrapper.text()).toContain("Radio relay check");
    expect(wrapper.text()).toContain("Gate A");
    expect(wrapper.text()).toContain("#medical");
    expect(wrapper.text()).toContain("@Blue-Hat");
    expect(wrapper.text()).toContain("@Gate_A");
    expect(wrapper.text()).toContain("Responder staged near the shade structure.");
    expect(wrapper.text()).toContain("Incident INC-2027-000042 opened.");
    expect(wrapper.text()).toContain("Responder is on scene and monitoring breathing.");
    expect(wrapper.text()).toContain("Ingrid ICLead");
    expect(wrapper.text()).not.toContain("Add note");
    expect(wrapper.text()).not.toContain("Save");
    expect(wrapper.text()).not.toContain("Edit");
    expect(wrapper.text()).not.toContain("Print PDF");
    expect(
      wrapper.get(".ims-detail__chip--name-reference").attributes("href"),
    ).toBe("/ims/incidents?search=Blue-Hat");
  });

  it("shows Print PDF only for IC leads and keeps local fixture print available offline", async () => {
    installIncidentSession(IC_LEAD_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-gate-medical");

    expect(wrapper.text()).toContain("Print PDF");
    expect(wrapper.get(".ims-detail__print-button").attributes("disabled")).toBeUndefined();

    setNavigatorOnline(false);
    window.dispatchEvent(new Event("offline"));
    await flushPromises();

    expect(wrapper.text()).not.toContain(
      "Incident PDF print requires a server connection.",
    );
    expect(wrapper.get(".ims-detail__print-button").attributes("disabled")).toBeUndefined();

    clearIncidentSession();
    installIncidentSession(IC_OPERATOR_SESSION);
    const operatorMount = await mountAt("/ims/incidents/incident-gate-medical");

    expect(operatorMount.wrapper.text()).toContain("Edit incident");
    expect(operatorMount.wrapper.text()).not.toContain("Print PDF");
  });

  it("lets IC operators create an incident from a blank autosave form", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper, router } = await mountAt("/ims/incidents/create");

    expect(wrapper.text()).toContain("Create incident");
    expect(wrapper.text()).toContain("Not assigned yet");
    expect(wrapper.text()).toContain("Responders");
    expect(wrapper.text()).not.toContain("Rangers/responders");

    await wrapper.get("#ims-edit-priority").setValue("Important");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("ims.incidents.edit");
    expect(wrapper.text()).toContain("INC-2027-000043");
    expect(wrapper.text()).toContain("Incident INC-2027-000043 opened.");
    expect(wrapper.text()).not.toContain("Changed priority: Important");

    const openingTimelineEntries = wrapper.findAll(".ims-edit__timeline li");
    expect(openingTimelineEntries[0]?.text()).toContain(
      "Incident INC-2027-000043 opened.",
    );

    await showFullHistory(wrapper);

    expect(wrapper.text()).toContain("Changed priority: Important");
    expect(wrapper.findAll(".ims-edit__timeline li")[1]?.text()).toContain(
      "Changed priority: Important",
    );

    await addBySearch(wrapper, "#ims-edit-type-add", "Log", "Logistics");
    await addBySearch(wrapper, "#ims-edit-type-add", "Rad", "Radio");
    await addBySearch(wrapper, "#ims-edit-responder-add", "Omar", "Omar Operator");
    await wrapper.get("#ims-edit-title").setValue("  Perimeter assist  ");
    await flushPromises();

    await wrapper.get("#ims-edit-title").trigger("blur");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("ims.incidents.edit");
    expect(wrapper.text()).toContain("INC-2027-000043");
    expect(wrapper.text()).not.toContain("Saved INC-2027-000043.");

    await router.push("/ims/incidents");
    await flushPromises();

    expect(wrapper.text()).toContain("Perimeter assist");
    expect(wrapper.text()).toContain("INC-2027-000043");
    expect(wrapper.text()).toContain("Important");
    expect(wrapper.text()).toContain("Logistics, Radio");
  });

  it("waits for a committed field change before creating from the autosave form", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/create");

    await wrapper.get("#ims-edit-location-name").setValue("Gate B");
    await flushPromises();

    expect(wrapper.text()).not.toContain("Autosave failed");
    expect(wrapper.text()).not.toContain("Incident title is required.");
    expect(wrapper.text()).toContain("Not assigned yet");

    await wrapper.get("#ims-edit-location-name").trigger("blur");
    await flushPromises();

    expect(wrapper.text()).toContain("INC-2027-000043");
    expect(wrapper.get<HTMLInputElement>("#ims-edit-title").element.value).toBe(
      "",
    );
  });

  it("shows add choices in a dismissible popup", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-gate-medical/edit");

    await wrapper.get("#ims-edit-type-add").trigger("focus");
    await wrapper.get("#ims-edit-type-add").setValue("Wea");
    await flushPromises();

    expect(wrapper.find(".ims-edit__add-results").exists()).toBe(true);
    expect(wrapper.text()).toContain("Weather");

    document.body.dispatchEvent(new Event("pointerdown", { bubbles: true }));
    await flushPromises();

    expect(wrapper.find(".ims-edit__add-results").exists()).toBe(false);

    await wrapper.get("#ims-edit-responder-add").trigger("focus");
    await wrapper.get("#ims-edit-responder-add").setValue("Ingrid");
    await flushPromises();

    expect(wrapper.find(".ims-edit__add-results").exists()).toBe(true);

    await wrapper.get("#ims-edit-responder-add").trigger("keydown.escape");
    await flushPromises();

    expect(wrapper.find(".ims-edit__add-results").exists()).toBe(false);
  });

  it("lets IC operators autosave current fields for an existing incident", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt(
      "/ims/incidents/incident-gate-medical/edit",
    );

    expect(wrapper.text()).toContain("Edit incident");
    expect(wrapper.text()).toContain("INC-2027-000042");

    await wrapper
      .get("#ims-edit-title")
      .setValue("Gate A medical follow-up #followup @RangerHQ");
    await wrapper.get("#ims-edit-priority").setValue("Critical");
    await wrapper
      .get('button[aria-label="Remove incident type Safety"]')
      .trigger("click");
    await addBySearch(wrapper, "#ims-edit-type-add", "Wea", "Weather");
    await addBySearch(wrapper, "#ims-edit-responder-add", "Ingrid", "Ingrid ICLead");
    await flushPromises();

    expect(wrapper.text()).not.toContain("Incident field changed");

    await wrapper.get("#ims-edit-title").trigger("blur");
    await flushPromises();

    expect(wrapper.text()).not.toContain("Saved INC-2027-000042.");
    expect(wrapper.text()).not.toContain(
      "Changed title: Gate A medical follow-up #followup @RangerHQ",
    );

    await showFullHistory(wrapper);

    expect(wrapper.text()).toContain(
      "Changed title: Gate A medical follow-up #followup @RangerHQ",
    );
    expect(wrapper.text()).toContain("Changed priority: Critical");
    expect(wrapper.text()).toContain("Changed incident types: Medical, Weather");
    expect(wrapper.text()).toContain("Changed responders: Vera Ranger, Ingrid ICLead");
    expect(wrapper.text().match(/Changed priority: Critical/gu)).toHaveLength(1);
    expect(wrapper.text()).not.toContain("Incident field changed");
    expect(wrapper.text()).not.toContain("field changed from");
    expect(wrapper.text()).toContain("#followup");
    expect(wrapper.text()).toContain("@RangerHQ");

    const titleInput = wrapper.get<HTMLInputElement>("#ims-edit-title");
    expect(titleInput.element.value).toBe("Gate A medical follow-up #followup @RangerHQ");
  });

  it("lets IC operators unlink and relink same-event incidents from edit", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt(
      "/ims/incidents/incident-gate-medical/edit",
    );

    expect(wrapper.text()).toContain("Linked incidents");
    expect(wrapper.text()).toContain("INC-2027-000041");
    expect(wrapper.text()).toContain("Radio relay check");

    await wrapper
      .get('button[aria-label="Unlink incident INC-2027-000041"]')
      .trigger("click");
    await flushPromises();

    expect(wrapper.text()).not.toContain(
      "Unlinked related incident INC-2027-000041: Radio relay check.",
    );
    await showFullHistory(wrapper);

    expect(wrapper.text()).toContain(
      "Unlinked related incident INC-2027-000041: Radio relay check.",
    );
    expect(wrapper.text()).toContain("No linked incidents.");

    await addBySearch(
      wrapper,
      "#ims-edit-linked-add",
      "Radio",
      "INC-2027-000041 - Radio relay check",
    );

    expect(wrapper.text()).toContain(
      "Linked related incident INC-2027-000041: Radio relay check.",
    );
    expect(wrapper.text()).toContain("INC-2027-000041");
    expect(wrapper.text()).not.toContain("Incidents are already linked.");
  });

  it("lets IC operators link and unlink Field Reports from edit", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper, router } = await mountAt(
      "/ims/incidents/incident-gate-medical/edit",
    );

    expect(wrapper.text()).toContain("Attached Field Reports");
    expect(wrapper.text()).toContain("No attached Field Reports.");

    await addBySearch(
      wrapper,
      "#ims-edit-field-report-add",
      "medical",
      "FRA-2027-000123 - Medical observation near Gate A",
    );

    expect(wrapper.text()).toContain("FRA-2027-000123");
    expect(wrapper.text()).toContain("Medical observation near Gate A");
    expect(wrapper.text()).toContain(
      "Field Report: Medical observation near Gate A",
    );
    expect(wrapper.text()).toContain("Author: Vera Ranger");
    expect(wrapper.text()).toContain(
      "Observed medical response near Gate A for @Blue-Hat.",
    );
    expect(wrapper.text()).not.toContain("No attached Field Reports.");

    await wrapper.get("#ims-edit-field-report-add").trigger("focus");
    await wrapper.get("#ims-edit-field-report-add").setValue("medical");
    await flushPromises();

    expect(
      wrapper
        .findAll(".ims-edit__add-results button")
        .some(
          (button) =>
            button.text() ===
            "FRA-2027-000123 - Medical observation near Gate A",
        ),
    ).toBe(false);

    await router.push("/ims/incidents/incident-gate-medical");
    await flushPromises();

    expect(wrapper.text()).toContain("Attached Field Reports");
    expect(wrapper.text()).toContain("FRA-2027-000123");
    expect(wrapper.text()).toContain("Medical observation near Gate A");
    expect(
      wrapper.get(".ims-detail__field-report-row").attributes("href"),
    ).toBe("/ims/field-reports/field-report-medical-gate");

    await wrapper.get(".ims-detail__field-report-row").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("ims.field-reports.show");
    expect(wrapper.get("#ims-fr-detail-heading").text()).toBe(
      "Medical observation near Gate A",
    );

    await router.push("/ims/incidents/incident-gate-medical/edit");
    await flushPromises();

    await wrapper
      .get('button[aria-label="Unlink Field Report FRA-2027-000123"]')
      .trigger("click");
    await flushPromises();

    expect(wrapper.text()).not.toContain(
      "Removed Field Report FRA-2027-000123: Medical observation near Gate A.",
    );
    expect(wrapper.text()).not.toContain("Field Report removed from incident.");
    expect(wrapper.text()).toContain("No attached Field Reports.");

    await showFullHistory(wrapper);

    expect(wrapper.text()).toContain(
      "Removed Field Report FRA-2027-000123: Medical observation near Gate A.",
    );
    expect(wrapper.text()).toContain("Field Report removed from incident.");
    expect(wrapper.get(".ims-edit__timeline-body--stricken").text()).toContain(
      "Field Report: Medical observation near Gate A",
    );
  });

  it("hides routine history by default and can show full detail history", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);
    const createdAt = new Date("2027-07-04T21:00:00.000Z");
    const form: IncidentAutosaveForm = {
      ...blankIncidentAutosaveForm(createdAt),
      title: "Timeline visibility check",
      priorityLabel: "Routine",
    };
    const incident = createIncidentFromAutosaveForm(
      IC_OPERATOR_SESSION,
      form,
      null,
      createdAt,
    );
    updateIncidentFromAutosaveForm(
      IC_OPERATOR_SESSION,
      incident.id,
      {
        ...form,
        priorityLabel: "Serious",
      },
      new Date("2027-07-04T21:05:00.000Z"),
    );

    const { wrapper } = await mountAt(`/ims/incidents/${incident.id}`);

    expect(wrapper.text()).toContain(`Incident ${incident.incidentNumber} opened.`);
    expect(wrapper.text()).not.toContain("Changed priority");

    await showFullHistory(wrapper);

    expect(wrapper.text()).toContain("Hide full history");
    expect(wrapper.text()).toContain("Changed priority");
  });

  it("orders linked incident candidates by shared tags first then newest created", () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const sharedTagIncident = createIncidentFromAutosaveForm(
      IC_OPERATOR_SESSION,
      {
        title: "Older matching incident #medical",
        status: "open",
        priorityLabel: "Routine",
        incidentTypeNames: [],
        responderStaffIds: [],
        startedAt: "2027-07-04T20:40",
        locationName: "",
        locationAddress: "",
        locationDetails: "",
      },
      null,
      new Date("2027-07-04T20:40:00.000Z"),
    );
    const unrelatedIncident = createIncidentFromAutosaveForm(
      IC_OPERATOR_SESSION,
      {
        title: "Old unrelated incident #logistics",
        status: "open",
        priorityLabel: "Routine",
        incidentTypeNames: [],
        responderStaffIds: [],
        startedAt: "2027-07-04T20:45",
        locationName: "",
        locationAddress: "",
        locationDetails: "",
      },
      null,
      new Date("2027-07-04T20:45:00.000Z"),
    );
    const locationOnlyIncident = createIncidentFromAutosaveForm(
      IC_OPERATOR_SESSION,
      {
        title: "Newest location-only candidate",
        status: "open",
        priorityLabel: "Routine",
        incidentTypeNames: [],
        responderStaffIds: [],
        startedAt: "2027-07-04T20:55",
        locationName: "#medical",
        locationAddress: "",
        locationDetails: "",
      },
      null,
      new Date("2027-07-04T20:55:00.000Z"),
    );

    const options = availableLinkedIncidentOptionsForSession(
      IC_OPERATOR_SESSION,
      "incident-gate-medical",
    );

    expect(options.map((incident) => incident.id).slice(0, 3)).toEqual([
      sharedTagIncident.id,
      locationOnlyIncident.id,
      unrelatedIncident.id,
    ]);
    expect(options.map((incident) => incident.id)).not.toContain(
      "incident-radio-check",
    );
  });

  it("orders Field Report candidates by shared tags first then newest created", () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const options = availableFieldReportOptionsForSession(
      IC_OPERATOR_SESSION,
      "incident-gate-medical",
    );

    expect(options.map((report) => report.id)).toEqual([
      "field-report-medical-gate",
      "field-report-newer-logistics",
      "field-report-radio-relay",
    ]);
  });

  it("records a concise location address edit without a phantom started change", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-radio-check/edit");

    await wrapper.get("#ims-edit-location-address").setValue("North entry road");
    await wrapper.get("#ims-edit-location-address").trigger("blur");
    await flushPromises();

    expect(wrapper.text()).not.toContain("Changed location address: North entry road");
    await showFullHistory(wrapper);

    expect(wrapper.text()).toContain("Changed location address: North entry road");
    expect(wrapper.text()).not.toContain("Started field changed");
    expect(wrapper.text()).not.toContain("Changed started:");
  });

  it("blocks incident create/edit while offline without queue language", async () => {
    setNavigatorOnline(false);
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/create");

    expect(wrapper.text()).toContain("Offline");
    expect(wrapper.text()).toContain("Incident create/edit requires server connection.");
    expect(wrapper.text()).toContain("Not assigned yet");
    expect(wrapper.text()).not.toContain("queued");
    expect(wrapper.get<HTMLInputElement>("#ims-edit-title").element.disabled).toBe(
      true,
    );
  });

  it("fails closed when direct edit access lacks operator or lead authority", async () => {
    installIncidentSession(IC_SESSION);

    const { wrapper } = await mountAt(
      "/ims/incidents/incident-gate-medical/edit",
    );

    expect(wrapper.text()).toContain("IC operator or lead access required");
    expect(wrapper.text()).not.toContain("Medical assist near Gate A");
    expect(wrapper.find("#ims-edit-title").exists()).toBe(false);
  });

  it("filters the incident list from a Name Reference chip search", async () => {
    installIncidentSession(IC_SESSION);

    const { wrapper } = await mountAt("/ims/incidents?search=Blue-Hat");

    expect(wrapper.text()).toContain("Search: Blue-Hat");
    expect(wrapper.text()).toContain("INC-2027-000042");
    expect(wrapper.text()).toContain("Medical assist near Gate A");
    expect(wrapper.text()).not.toContain("INC-2027-000041");
    expect(wrapper.text()).not.toContain("Radio relay check");
  });

  it("searches incidents from the list page control", async () => {
    installIncidentSession(IC_SESSION);

    const { wrapper, router } = await mountAt("/ims/incidents");

    expect(wrapper.text()).toContain("INC-2027-000042");
    expect(wrapper.text()).toContain("INC-2027-000041");

    await wrapper.get("#ims-list-search").setValue("#medical");
    await wrapper.get("form.ims-list__search-form").trigger("submit");
    await flushPromises();

    expect(router.currentRoute.value.query.search).toBe("#medical");
    expect(wrapper.text()).toContain("Search: #medical");
    expect(wrapper.text()).toContain("INC-2027-000042");
    expect(wrapper.text()).not.toContain("INC-2027-000041");
  });

  it("lets IC operators append a plain-text operational timeline note", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-gate-medical");

    await wrapper.get("#ims-note-body").setValue("  Radio relay confirmed.  ");
    await wrapper.get("form.ims-detail__note-form").trigger("submit");

    expect(wrapper.text()).toContain("Radio relay confirmed.");
    expect(wrapper.text()).toContain("Incident Command Operator");
    expect(wrapper.text()).not.toContain("Search: Radio relay confirmed.");
    expect(wrapper.get<HTMLTextAreaElement>("#ims-note-body").element.value).toBe(
      "",
    );
  });

  it("lets IC operators strike operational timeline notes", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);
    vi.spyOn(window, "prompt").mockReturnValue("Wrong incident note.");

    const { wrapper } = await mountAt("/ims/incidents/incident-gate-medical");

    await wrapper.get("#ims-note-body").setValue("  Radio relay confirmed.  ");
    await wrapper.get("form.ims-detail__note-form").trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Radio relay confirmed.");

    const appendedNoteRow = wrapper
      .findAll(".ims-detail__timeline li")
      .find((row) => row.text().includes("Radio relay confirmed."));
    const strikeButton = appendedNoteRow
      ?.findAll("button")
      .find((button) => button.text() === "Strike note");

    expect(strikeButton).toBeDefined();
    await strikeButton?.trigger("click");
    await flushPromises();

    expect(wrapper.text()).not.toContain("Radio relay confirmed.");

    await showFullHistory(wrapper);

    expect(wrapper.text()).toContain("Radio relay confirmed.");
    expect(wrapper.text()).toContain("Stricken: Wrong incident note.");
    expect(wrapper.get(".ims-detail__timeline-body--stricken").text()).toContain(
      "Radio relay confirmed.",
    );
  });

  it("adds Name Reference chips for locally appended timeline notes", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-radio-check");

    expect(wrapper.text()).not.toContain("@RadioLead");

    await wrapper.get("#ims-note-body").setValue("Follow up with @RadioLead.");
    await wrapper.get("form.ims-detail__note-form").trigger("submit");

    expect(wrapper.text()).toContain("@RadioLead");
    expect(
      wrapper.get(".ims-detail__chip--name-reference").attributes("href"),
    ).toBe("/ims/incidents?search=RadioLead");
  });

  it("adds tag chips for locally appended timeline notes", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-radio-check");

    expect(wrapper.text()).not.toContain("#radio");

    await wrapper
      .get("#ims-note-body")
      .setValue("Follow up at @RadioHQ for #radio.");
    await wrapper.get("form.ims-detail__note-form").trigger("submit");

    expect(wrapper.text()).toContain("@RadioHQ");
    expect(wrapper.text()).toContain("#radio");
    expect(wrapper.get(".ims-detail__chip--tag").attributes("href")).toBe(
      "/ims/incidents?search=%23radio",
    );
  });

  it("keeps blank timeline notes from being appended in the operator surface", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-gate-medical");

    await wrapper.get("#ims-note-body").setValue("   ");
    await wrapper.get("form.ims-detail__note-form").trigger("submit");

    expect(wrapper.text()).toContain("Incident note body is required.");
  });

  it("fails closed when direct detail access lacks IC authority", async () => {
    installIncidentSession(NON_IC_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-gate-medical");

    expect(wrapper.text()).toContain("Incident Command access required");
    expect(wrapper.text()).not.toContain("Medical assist near Gate A");
    expect(wrapper.text()).not.toContain("INC-2027-000042");
    expect(wrapper.text()).not.toContain("@Blue-Hat");
  });

  it("shows an event-scoped missing state for unknown detail IDs", async () => {
    installIncidentSession(IC_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/not-this-event");

    expect(wrapper.text()).toContain("Incident not found for this event.");
  });
});
