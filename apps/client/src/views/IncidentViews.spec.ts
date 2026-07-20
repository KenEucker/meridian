import { afterEach, describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
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
});

describe("IMS incident list/detail surfaces (M11.5)", () => {
  it("registers the UI contract route names", () => {
    const names = routes.map((route) => route.name);

    expect(names).toContain("ims.incidents.index");
    expect(names).toContain("ims.incidents.show");
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
    expect(wrapper.get(".ims-list__incident-link").attributes("href")).toBe(
      "/ims/incidents/incident-gate-medical",
    );
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
    expect(wrapper.text()).toContain("Responder staged near the shade structure.");
    expect(wrapper.text()).toContain("Incident opened");
    expect(wrapper.text()).toContain("Responder is on scene and monitoring breathing.");
    expect(wrapper.text()).toContain("Ingrid ICLead");
    expect(wrapper.text()).not.toContain("Add note");
    expect(wrapper.text()).not.toContain("Save");
    expect(wrapper.text()).not.toContain("Edit");
  });

  it("lets IC operators append a plain-text operational timeline note", async () => {
    installIncidentSession(IC_OPERATOR_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/incident-gate-medical");

    await wrapper.get("#ims-note-body").setValue("  Radio relay confirmed.  ");
    await wrapper.get("form.ims-detail__note-form").trigger("submit");

    expect(wrapper.text()).toContain("Radio relay confirmed.");
    expect(wrapper.text()).toContain("Incident Command Operator");
    expect(wrapper.get<HTMLTextAreaElement>("#ims-note-body").element.value).toBe(
      "",
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
  });

  it("shows an event-scoped missing state for unknown detail IDs", async () => {
    installIncidentSession(IC_SESSION);

    const { wrapper } = await mountAt("/ims/incidents/not-this-event");

    expect(wrapper.text()).toContain("Incident not found for this event.");
  });
});
