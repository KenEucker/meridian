import { afterEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import {
  FIXTURE_GATE_DEPARTMENT_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import {
  clearDepartmentSelfAdminSession,
  installDevelopmentDepartmentSelfAdminSession,
  resetDepartmentSelfAdminFixtures,
} from "@/department-teams/teamAdminModel";
import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";
import {
  listDepartmentEquipment,
  resetEquipmentInventoryFixtures,
  updateEquipmentItem,
} from "@/equipment/equipmentInventoryModel";
import { routes } from "@/router";
import DepartmentEquipmentView from "@/views/DepartmentEquipmentView.vue";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

function equipmentPath(
  departmentId = LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId,
): string {
  return `/events/${LOCAL_DEPARTMENT_OPS_CONTEXT.eventId}/departments/${departmentId}/equipment`;
}

async function mountEquipmentPage(
  departmentId = LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId,
) {
  const router = buildRouter();
  await router.push(equipmentPath(departmentId));
  await router.isReady();

  return mount(DepartmentEquipmentView, {
    global: { plugins: [router] },
  });
}

function inputByLabel(wrapper: ReturnType<typeof mount>, label: string) {
  const found = wrapper
    .findAll("label")
    .find((candidate) => candidate.text().startsWith(label));

  if (!found) {
    throw new Error(`No form field labelled "${label}".`);
  }

  return found;
}

afterEach(() => {
  clearDepartmentSelfAdminSession();
  resetDepartmentSelfAdminFixtures();
  resetEquipmentInventoryFixtures();
  resetSelectedFixtureDepartment();
});

describe("department equipment inventory", () => {
  it("lists department inventory with state and checkout context", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const wrapper = await mountEquipmentPage();

    expect(wrapper.get("#dept-equipment-heading").text()).toBe("Equipment");
    expect(wrapper.text()).toContain("Radio 12");
    expect(wrapper.text()).toContain("Radio 13");
    expect(wrapper.text()).toContain("Checked out");
    expect(wrapper.text()).toContain("Damaged");
    expect(wrapper.text()).toContain("Return it in Logistics to change this.");

    // The active filter hides archived equipment without deleting it.
    expect(wrapper.text()).not.toContain("Retired Vest");
  });

  it("adds equipment that starts Available", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const wrapper = await mountEquipmentPage();

    await inputByLabel(wrapper, "Name").get("input").setValue("Radio 20");
    await inputByLabel(wrapper, "Asset tag").get("input").setValue("RAD-020");
    await wrapper.get('form[aria-label="Add equipment"]').trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain(
      "Radio 20 added to the inventory as Available.",
    );
    expect(wrapper.text()).toContain("RAD-020");
  });

  it("rejects a duplicate asset tag in the same department", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const wrapper = await mountEquipmentPage();

    await inputByLabel(wrapper, "Name").get("input").setValue("Radio 12 Copy");
    await inputByLabel(wrapper, "Asset tag").get("input").setValue("RAD-012");
    await wrapper.get('form[aria-label="Add equipment"]').trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain(
      'Asset tag "RAD-012" already exists in this department.',
    );
  });

  it("requires a name", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const wrapper = await mountEquipmentPage();

    await inputByLabel(wrapper, "Asset tag").get("input").setValue("RAD-099");
    await wrapper.get('form[aria-label="Add equipment"]').trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Equipment name is required.");
  });

  it("archives and restores equipment instead of deleting it", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const wrapper = await mountEquipmentPage();

    const archiveButtons = wrapper
      .findAll("button")
      .filter((button) => button.text() === "Archive");
    expect(archiveButtons.length).toBeGreaterThan(0);
    await archiveButtons[0]!.trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain("Its checkout history is preserved.");

    await wrapper
      .get('select[aria-label="Filter equipment by status"]')
      .setValue("archived");
    await flushPromises();

    const restoreButtons = wrapper
      .findAll("button")
      .filter((button) => button.text() === "Restore");
    expect(restoreButtons.length).toBeGreaterThan(0);
    await restoreButtons[0]!.trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain("restored to the active inventory.");
  });

  it("never offers Archive or a state change for checked-out equipment", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const wrapper = await mountEquipmentPage();

    // Radio 12 is checked out in the fixture, so its row exposes Edit but not
    // Archive; checkout state stays with the Logistics workflow.
    const checkedOutRow = wrapper
      .findAll("tbody tr")
      .find((row) => row.text().includes("Radio 12"));
    expect(checkedOutRow).toBeDefined();
    expect(
      checkedOutRow!.findAll("button").map((button) => button.text()),
    ).toEqual(["Edit"]);

    await checkedOutRow!.get("button").trigger("click");
    await flushPromises();

    // The state control is locked to the item's Logistics-owned state.
    const stateSelect = wrapper.get('select[aria-label="Equipment state"]');
    expect(stateSelect.attributes("disabled")).toBeDefined();
    expect(stateSelect.findAll("option").map((option) => option.text())).toEqual(
      ["Checked out", "Available", "Missing", "Damaged"],
    );
    expect(wrapper.text()).toContain(
      "return it from the Logistics Window to change its state",
    );

    // Details stay editable while the item is out, matching the server.
    await inputByLabel(wrapper, "Name").get("input").setValue("Radio 12 (UHF)");
    await wrapper.get('form[aria-label="Edit equipment"]').trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Radio 12 (UHF) updated.");
    expect(wrapper.text()).toContain("Checked out");
  });

  it("refuses a state change while equipment is checked out", () => {
    const session = installDevelopmentDepartmentSelfAdminSession();
    const checkedOut = listDepartmentEquipment(session).find(
      (item) => item.hasOpenCheckout,
    );
    expect(checkedOut).toBeDefined();

    // The form disables the state control for a checked-out item, so this
    // asserts the underlying invariant a stale client could still reach.
    expect(() =>
      updateEquipmentItem(session, checkedOut!.id, {
        name: checkedOut!.name,
        assetTag: checkedOut!.assetTag ?? "",
        serialNumber: checkedOut!.serialNumber ?? "",
        eventId: checkedOut!.eventId,
        status: "missing",
      }),
    ).toThrowError(
      "This equipment is checked out. Return it from the Logistics Window to change its state.",
    );
  });

  it("keeps inventory setup inside the department (EQUIP-006)", () => {
    const session = installDevelopmentDepartmentSelfAdminSession();

    // Every read and write is scoped to the session department; no surface
    // accepts another department, so allotments cannot be expressed.
    const names = listDepartmentEquipment(session, "all").map(
      (item) => item.name,
    );
    expect(names).toContain("Radio 12");
    expect(names).not.toContain("Bike Repair Stand");
    expect(names).not.toContain("Gate Scanner");
  });

  it("imports a CSV and reports skipped rows", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const wrapper = await mountEquipmentPage();

    await wrapper.get('textarea[aria-label="Equipment CSV"]').setValue(
      [
        "name,asset_tag,serial_number,notes",
        "Radio 30,RAD-030,SN-0030,ignored",
        "Radio 31,RAD-031,,",
        ",RAD-032,,",
        "Radio 33,RAD-012,,",
      ].join("\n"),
    );
    await wrapper
      .get('form[aria-label="Import equipment inventory"]')
      .trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Imported 2 item(s); skipped 2.");
    expect(wrapper.text()).toContain("Missing name.");
    expect(wrapper.text()).toContain(
      'Asset tag "RAD-012" already exists in this department.',
    );
    expect(wrapper.text()).toContain("Radio 30");
    expect(wrapper.text()).toContain("Radio 31");
  });

  it("rejects a CSV without a name column", async () => {
    installDevelopmentDepartmentSelfAdminSession();
    const wrapper = await mountEquipmentPage();

    await wrapper
      .get('textarea[aria-label="Equipment CSV"]')
      .setValue("asset_tag,serial_number\nRAD-040,SN-0040");
    await wrapper
      .get('form[aria-label="Import equipment inventory"]')
      .trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain(
      'The CSV must include a "name" header column.',
    );
  });

  it("fails closed for a department member without logistics or lead authority", async () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    installDevelopmentDepartmentSelfAdminSession();
    const wrapper = await mountEquipmentPage(FIXTURE_GATE_DEPARTMENT_ID);

    expect(wrapper.text()).toContain(
      "Equipment inventory is available to department logistics and department administration",
    );
    expect(wrapper.text()).not.toContain("Gate Scanner");
    expect(wrapper.find('form[aria-label="Add equipment"]').exists()).toBe(
      false,
    );
    expect(
      wrapper.find('form[aria-label="Import equipment inventory"]').exists(),
    ).toBe(false);
  });
});
