import { afterEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import {
  clearIncidentSession,
  installIncidentSession,
  LOCAL_IMS_EVENT_ID,
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

afterEach(() => {
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
    expect(wrapper.text()).toContain("INC-2027-000041");
    expect(wrapper.text()).toContain("Priority not set");
    expect(wrapper.text()).toContain("07-04-2027");
    expect(wrapper.text()).toMatch(/07-04-2027 \d{2}:\d{2}/u);
    expect(wrapper.get(".ims-list__incident-link").attributes("href")).toBe(
      "/ims/incidents/incident-gate-medical",
    );
    expect(wrapper.text()).not.toContain("Create incident");
  });

  it("shows create entry points only to IC operators and leads", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents");

    expect(wrapper.text()).toContain("Create incident");
    expect(wrapper.get(".ims-list__create").attributes("href")).toBe(
      "/ims/incidents/create",
    );
  });

  it("installs an IC operator development session so create/edit can be exercised locally", async () => {
    const { wrapper } = await mountAt("/ims/incidents");

    expect(wrapper.text()).toContain("Local Field Organization");
    expect(wrapper.text()).toContain("Local Field Event");
    expect(wrapper.text()).toContain("Incident Command Operator");
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

  it("renders read-only incident detail for IC roles", async () => {
    installIncidentSession(IC_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-gate-medical");

    expect(wrapper.get("#ims-detail-heading").text()).toBe(
      "Medical assist near Gate A",
    );
    expect(wrapper.text()).toContain("INC-2027-000042");
    expect(wrapper.text()).toContain("On Scene");
    expect(wrapper.text()).toContain("Serious");
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
    expect(
      wrapper.get(".ims-detail__chip--name-reference").attributes("href"),
    ).toBe("/ims/incidents?search=Blue-Hat");
  });

  it("lets IC operators create an incident from a blank autosave form", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper, router } = await mountAt("/ims/incidents/create");

    expect(wrapper.text()).toContain("Create incident");
    expect(wrapper.text()).toContain("Not assigned yet");

    await wrapper.get("#ims-edit-title").setValue("  Perimeter assist  ");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("ims.incidents.create");
    expect(wrapper.text()).toContain("Not assigned yet");

    await wrapper.get("#ims-edit-title").trigger("blur");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("ims.incidents.edit");
    expect(wrapper.text()).toContain("INC-2027-000043");
    expect(wrapper.text()).not.toContain("Saved INC-2027-000043.");
    expect(wrapper.text()).toContain("Incident INC-2027-000043 opened.");

    await router.push("/ims/incidents");
    await flushPromises();

    expect(wrapper.text()).toContain("Perimeter assist");
    expect(wrapper.text()).toContain("INC-2027-000043");
  });

  it("waits for a title before creating from the autosave form", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/create");

    await wrapper.get("#ims-edit-location-name").setValue("Gate B");
    await flushPromises();

    expect(wrapper.text()).not.toContain("Autosave failed");
    expect(wrapper.text()).not.toContain("Incident title is required.");
    expect(wrapper.text()).toContain("Not assigned yet");
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
    await flushPromises();

    expect(wrapper.text()).not.toContain("Incident field changed");

    await wrapper.get("#ims-edit-title").trigger("blur");
    await flushPromises();

    expect(wrapper.text()).not.toContain("Saved INC-2027-000042.");
    expect(wrapper.text()).toContain(
      "Changed title: Gate A medical follow-up #followup @RangerHQ",
    );
    expect(wrapper.text()).not.toContain("Incident field changed");
    expect(wrapper.text()).not.toContain("field changed from");
    expect(wrapper.text()).toContain("#followup");
    expect(wrapper.text()).toContain("@RangerHQ");

    const titleInput = wrapper.get<HTMLInputElement>("#ims-edit-title");
    expect(titleInput.element.value).toBe("Gate A medical follow-up #followup @RangerHQ");
  });

  it("records a concise location address edit without a phantom started change", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-radio-check/edit");

    await wrapper.get("#ims-edit-location-address").setValue("North entry road");
    await wrapper.get("#ims-edit-location-address").trigger("blur");
    await flushPromises();

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
