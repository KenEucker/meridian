// The department equipment inventory surface against a stubbed node (M16.17;
// CLIENT-023, CLIENT-024, EQUIP-001 through EQUIP-005, EQUIP-007;
// data/API 10.13).
//
// These were fixture tests. They installed a role, mounted the page over six
// compiled-in items, typed a duplicate asset tag, and asserted that the
// browser's own copy of `EquipmentInventoryService` had refused it. Nothing in
// them reached an endpoint, so nothing in them said whether the screen and the
// server agreed on a URL, a request body, or a response shape — and the rule
// they proved was the client's, not the node's.
//
// They now stub `fetch` and answer with the payloads
// `EquipmentInventoryReadController` and the equipment command endpoints
// publish. Each test therefore asserts two things: what the screen asked the
// node, and what it did with the answer. The refusals are the node's sentences,
// quoted back.
//
// No server runs for any of this, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  LOCAL_FIELD_DEPARTMENT_IDS,
} from "@/field-reports/localFieldFixture";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";
import {
  selectSessionDepartment,
} from "@/session/sessionAccess";
import { routes } from "@/router";
import DepartmentEquipmentView from "@/views/DepartmentEquipmentView.vue";

const EVENT_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
const ORGANIZATION_ID = "11111111-1111-4111-8111-111111111111";
const DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;
const RADIO_12_ID = "88888888-8888-4888-8888-888888888801";
const RADIO_13_ID = "88888888-8888-4888-8888-888888888802";
const RETIRED_VEST_ID = "88888888-8888-4888-8888-888888888803";
const CHECKOUT_ID = "66666666-6666-4666-8666-666666666601";
const SHIFT_ID = "55555555-5555-4555-8555-555555555551";
const VERA_STAFF_ID = "33333333-3333-4333-8333-333333333331";

/** One request this client made, as the assertions read it. */
interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
}

interface NodeReply {
  readonly status?: number;
  readonly body: unknown;
}

/**
 * Answer as the node would, and record what was asked.
 *
 * `reply` sees the URL and the parsed body so a test can vary its answer over
 * the run. Several of these need the read after a write to differ from the read
 * before it, which is exactly the behavior they are there to prove.
 */
function stubNode(reply: (call: NodeCall) => NodeReply): readonly NodeCall[] {
  const calls: NodeCall[] = [];

  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const call: NodeCall = {
        url: String(input),
        method: init?.method ?? "GET",
        body:
          typeof init?.body === "string"
            ? (JSON.parse(init.body) as Record<string, unknown>)
            : null,
      };

      calls.push(call);

      const answer = reply(call);

      return new Response(JSON.stringify(answer.body), {
        status: answer.status ?? 200,
        headers: { "content-type": "application/json" },
      });
    }),
  );

  return calls;
}

/** A node that cannot be reached at all, as `fetch` reports it. */
function stubUnreachableNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => {
      throw new TypeError("Failed to fetch");
    }),
  );
}

/** One item, as `EquipmentInventoryReadController::payload` publishes it. */
function itemPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: RADIO_13_ID,
    organization_id: ORGANIZATION_ID,
    department_id: DEPARTMENT_ID,
    event_id: null,
    name: "Radio 13",
    asset_tag: "RAD-013",
    serial_number: "SN-0013",
    status: "damaged",
    status_label: "Damaged",
    open_checkout: null,
    archived_at: null,
    created_at: "2026-07-01T12:00:00+00:00",
    updated_at: "2026-07-01T12:00:00+00:00",
    ...overrides,
  };
}

/** The item Logistics is holding, checkout and all. */
function checkedOutPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return itemPayload({
    id: RADIO_12_ID,
    name: "Radio 12",
    asset_tag: "RAD-012",
    serial_number: "SN-0012",
    status: "checked_out",
    status_label: "Checked out",
    open_checkout: {
      id: CHECKOUT_ID,
      staff_id: VERA_STAFF_ID,
      staff_name: "Vera Ranger",
      checked_out_at: "2026-07-03T18:00:00+00:00",
      shift_id: SHIFT_ID,
      shift_title: "Dirt patrol (Friday night)",
    },
    ...overrides,
  });
}

function archivedPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return itemPayload({
    id: RETIRED_VEST_ID,
    name: "Retired Vest",
    asset_tag: "VST-001",
    serial_number: null,
    status: "available",
    status_label: "Available",
    archived_at: "2026-06-01T12:00:00+00:00",
    ...overrides,
  });
}

/**
 * The `GET /api/departments/{id}/equipment` envelope, as the index publishes it.
 *
 * `maintainable_statuses` is the subset setup may set; Checked out and Returned
 * are Logistics states and are never in it (EQUIP-005).
 */
function inventoryPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    department: {
      id: DEPARTMENT_ID,
      organization_id: ORGANIZATION_ID,
      name: "Rangers",
      code: "RANGERS",
      archived_at: null,
    },
    access: { can_manage: true },
    events: [{ id: EVENT_ID, name: "Signal Camp 2026" }],
    maintainable_statuses: ["available", "missing", "damaged"],
    status_labels: {
      available: "Available",
      checked_out: "Checked out",
      returned: "Returned",
      missing: "Missing",
      damaged: "Damaged",
    },
    equipment: [checkedOutPayload(), itemPayload(), archivedPayload()],
    ...overrides,
  };
}

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

/*
 * Every wrapper this file mounts, so `afterEach` can take them down. The page
 * watches the route and re-reads when it changes; one left mounted keeps
 * reacting into the next test's stub and the next test's recorded calls.
 */
const mounted: VueWrapper[] = [];

beforeEach(() => {
  installLocalFieldSession();
  selectSessionDepartment(DEPARTMENT_ID);
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  configureMeridianApi(null);
  clearClientSession();
  vi.unstubAllGlobals();
});

async function mountEquipmentPage(): Promise<VueWrapper> {
  const router = buildRouter();

  await router.push({
    name: "events.departments.equipment.index",
    params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID },
  });
  await router.isReady();

  const wrapper = mount(DepartmentEquipmentView, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return wrapper;
}

function inventoryReads(calls: readonly NodeCall[]): readonly NodeCall[] {
  return calls.filter(
    (call) => call.method === "GET" && call.url.endsWith("/equipment"),
  );
}

function commandCalls(
  calls: readonly NodeCall[],
  command: string,
): readonly NodeCall[] {
  return calls.filter((call) => call.url.endsWith(`/commands/${command}`));
}

function inputByLabel(wrapper: VueWrapper, label: string) {
  const found = wrapper
    .findAll("label")
    .find((candidate) => candidate.text().startsWith(label));

  if (!found) {
    throw new Error(`No form field labelled "${label}".`);
  }

  return found;
}

function rowFor(wrapper: VueWrapper, name: string) {
  return wrapper.findAll("tbody tr").find((row) => row.text().includes(name));
}

describe("department equipment inventory", () => {
  it("renders the whole page from one read of the department", async () => {
    const calls = stubNode(() => ({ body: inventoryPayload() }));

    const wrapper = await mountEquipmentPage();

    expect(inventoryReads(calls)).toHaveLength(1);
    expect(inventoryReads(calls)[0]?.url).toBe(
      `http://node.test/api/departments/${DEPARTMENT_ID}/equipment`,
    );

    // The heading names the department the node answered with, not the one the
    // session guessed.
    expect(wrapper.get("#dept-equipment-heading").text()).toBe("Equipment");
    expect(wrapper.text()).toContain("Rangers");
    expect(wrapper.text()).toContain("Radio 12");
    expect(wrapper.text()).toContain("Radio 13");

    // The state column is the node's label for the state, not a table the
    // client keeps (UI contract 9.6).
    expect(wrapper.text()).toContain("Checked out");
    expect(wrapper.text()).toContain("Damaged");

    // The Active filter is a view of that one response, not a second request.
    expect(wrapper.text()).not.toContain("Retired Vest");

    await wrapper
      .get('select[aria-label="Filter equipment by status"]')
      .setValue("archived");
    await flushPromises();

    expect(wrapper.text()).toContain("Retired Vest");
    expect(inventoryReads(calls)).toHaveLength(1);
  });

  it("names who is holding a checked-out item instead of only locking the row", async () => {
    stubNode(() => ({ body: inventoryPayload() }));

    const wrapper = await mountEquipmentPage();
    const row = rowFor(wrapper, "Radio 12");

    expect(row).toBeDefined();
    expect(row!.text()).toContain(
      "Out with Vera Ranger for Dirt patrol (Friday night).",
    );

    // Archiving an item that is out is the server's refusal, so the row does
    // not offer it at all.
    expect(row!.findAll("button").map((button) => button.text())).toEqual([
      "Edit",
    ]);
  });

  it("offers only the states the node said setup may set", async () => {
    stubNode(() => ({ body: inventoryPayload() }));

    const wrapper = await mountEquipmentPage();
    await rowFor(wrapper, "Radio 13")!.get("button").trigger("click");

    const stateSelect = wrapper.get('select[aria-label="Equipment state"]');

    expect(stateSelect.attributes("disabled")).toBeUndefined();
    expect(stateSelect.findAll("option").map((option) => option.text())).toEqual(
      ["Available", "Missing", "Damaged"],
    );
  });

  it("adds equipment through the command and re-reads", async () => {
    let added = false;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/create-equipment-item")) {
        added = true;

        return { status: 201, body: { id: "created" } };
      }

      return {
        body: inventoryPayload({
          equipment: added
            ? [
                checkedOutPayload(),
                itemPayload(),
                itemPayload({
                  id: "created",
                  name: "Radio 20",
                  asset_tag: "RAD-020",
                  serial_number: null,
                  status: "available",
                  status_label: "Available",
                }),
              ]
            : [checkedOutPayload(), itemPayload()],
        }),
      };
    });

    const wrapper = await mountEquipmentPage();

    await inputByLabel(wrapper, "Name").get("input").setValue("Radio 20");
    await inputByLabel(wrapper, "Asset tag").get("input").setValue("RAD-020");
    await wrapper.get('form[aria-label="Add equipment"]').trigger("submit");
    await flushPromises();

    // No state is sent. "New equipment starts Available" is the server's rule
    // (EQUIP-005), not a default this form fills in.
    expect(commandCalls(calls, "create-equipment-item")[0]?.body).toEqual({
      department_id: DEPARTMENT_ID,
      name: "Radio 20",
      asset_tag: "RAD-020",
      serial_number: null,
      event_id: null,
    });

    // The row is the node's answer on the second read, not one patched in.
    expect(inventoryReads(calls)).toHaveLength(2);
    expect(wrapper.text()).toContain(
      "Radio 20 added to the inventory as Available.",
    );
    expect(wrapper.text()).toContain("RAD-020");
  });

  it("reports a duplicate asset tag in the node's words and adds no row", async () => {
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/create-equipment-item")) {
        return {
          status: 422,
          body: {
            message: 'Asset tag "RAD-012" already exists in this department.',
          },
        };
      }

      return { body: inventoryPayload() };
    });

    const wrapper = await mountEquipmentPage();

    await inputByLabel(wrapper, "Name").get("input").setValue("Radio 12 Copy");
    await inputByLabel(wrapper, "Asset tag").get("input").setValue("RAD-012");
    await wrapper.get('form[aria-label="Add equipment"]').trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain(
      'Asset tag "RAD-012" already exists in this department.',
    );
    // Nothing on the page moved: the refusal did not trigger a re-read, and the
    // typed name is still in the form to correct.
    expect(inventoryReads(calls)).toHaveLength(1);
    expect(wrapper.text()).not.toContain("Radio 12 Copy added");
  });

  it("edits a checked-out item's details without sending its state", async () => {
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/update-equipment-item")) {
        return { body: { id: RADIO_12_ID } };
      }

      return { body: inventoryPayload() };
    });

    const wrapper = await mountEquipmentPage();
    await rowFor(wrapper, "Radio 12")!.get("button").trigger("click");

    // The state control is locked to the Logistics-owned state it is in.
    const stateSelect = wrapper.get('select[aria-label="Equipment state"]');
    expect(stateSelect.attributes("disabled")).toBeDefined();
    expect(stateSelect.findAll("option").map((option) => option.text())).toEqual(
      ["Checked out", "Available", "Missing", "Damaged"],
    );

    await inputByLabel(wrapper, "Name").get("input").setValue("Radio 12 (UHF)");
    await wrapper.get('form[aria-label="Edit equipment"]').trigger("submit");
    await flushPromises();

    // `status` is left out entirely, which is what lets a name-only edit through
    // while the checkout is open.
    expect(commandCalls(calls, "update-equipment-item")[0]?.body).toEqual({
      equipment_item_id: RADIO_12_ID,
      name: "Radio 12 (UHF)",
      asset_tag: "RAD-012",
      serial_number: "SN-0012",
      event_id: null,
    });
    expect(wrapper.text()).toContain("Radio 12 (UHF) updated.");
  });

  it("archives and restores through the commands instead of deleting", async () => {
    let archived = false;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/archive-equipment-item")) {
        archived = true;

        return { body: { id: RADIO_13_ID } };
      }

      if (call.url.endsWith("/commands/restore-equipment-item")) {
        archived = false;

        return { body: { id: RADIO_13_ID } };
      }

      return {
        body: inventoryPayload({
          equipment: [
            checkedOutPayload(),
            itemPayload({
              archived_at: archived ? "2026-07-04T12:00:00+00:00" : null,
            }),
          ],
        }),
      };
    });

    const wrapper = await mountEquipmentPage();

    await rowFor(wrapper, "Radio 13")!
      .findAll("button")
      .find((button) => button.text() === "Archive")!
      .trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "archive-equipment-item")[0]?.body).toEqual({
      equipment_item_id: RADIO_13_ID,
    });
    expect(wrapper.text()).toContain("Its checkout history is preserved.");
    // The row left the Active view because the re-read said it was archived,
    // not because the click removed it.
    expect(rowFor(wrapper, "Radio 13")).toBeUndefined();

    await wrapper
      .get('select[aria-label="Filter equipment by status"]')
      .setValue("archived");
    await flushPromises();

    await rowFor(wrapper, "Radio 13")!
      .findAll("button")
      .find((button) => button.text() === "Restore")!
      .trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "restore-equipment-item")[0]?.body).toEqual({
      equipment_item_id: RADIO_13_ID,
    });
    expect(wrapper.text()).toContain("restored to the active inventory.");
  });

  it("hands the CSV to the node and reports the rows it answered with", async () => {
    const csv = [
      "name,asset_tag,serial_number,notes",
      "Radio 30,RAD-030,SN-0030,ignored",
      "Radio 31,RAD-031,,",
      ",RAD-032,,",
      "Radio 33,RAD-012,,",
    ].join("\n");

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/import-equipment-inventory")) {
        return {
          body: {
            imported: 2,
            skipped: 2,
            rows: [
              {
                line: 2,
                name: "Radio 30",
                asset_tag: "RAD-030",
                status: "imported",
                reason: null,
              },
              {
                line: 3,
                name: "Radio 31",
                asset_tag: "RAD-031",
                status: "imported",
                reason: null,
              },
              {
                line: 4,
                name: "",
                asset_tag: "RAD-032",
                status: "skipped",
                reason: "Missing name.",
              },
              {
                line: 5,
                name: "Radio 33",
                asset_tag: "RAD-012",
                status: "skipped",
                reason:
                  'Asset tag "RAD-012" already exists in this department.',
              },
            ],
          },
        };
      }

      return { body: inventoryPayload() };
    });

    const wrapper = await mountEquipmentPage();

    await wrapper.get('textarea[aria-label="Equipment CSV"]').setValue(csv);
    await wrapper
      .get('form[aria-label="Import equipment inventory"]')
      .trigger("submit");
    await flushPromises();

    // The file goes over as typed: which column is the name and which row named
    // nothing are the server's findings, not the client's.
    expect(commandCalls(calls, "import-equipment-inventory")[0]?.body).toEqual({
      department_id: DEPARTMENT_ID,
      event_id: null,
      csv,
    });

    expect(wrapper.text()).toContain("Imported 2 item(s); skipped 2.");
    expect(wrapper.text()).toContain("Missing name.");
    expect(wrapper.text()).toContain(
      'Asset tag "RAD-012" already exists in this department.',
    );
    expect(inventoryReads(calls)).toHaveLength(2);
  });

  it("reports a rejected CSV in the node's words", async () => {
    stubNode((call) => {
      if (call.url.endsWith("/commands/import-equipment-inventory")) {
        return {
          status: 422,
          body: { message: 'The CSV must include a "name" header column.' },
        };
      }

      return { body: inventoryPayload() };
    });

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

  it("fails closed on the node's refusal rather than on a role the client decided", async () => {
    stubNode(() => ({
      status: 403,
      body: {
        message:
          'You do not have permission to manage equipment inventory for this department.',
      },
    }));

    const wrapper = await mountEquipmentPage();

    expect(wrapper.text()).toContain(
      "You do not have permission to manage equipment inventory for this department.",
    );
    expect(wrapper.find('form[aria-label="Add equipment"]').exists()).toBe(
      false,
    );
    expect(
      wrapper.find('form[aria-label="Import equipment inventory"]').exists(),
    ).toBe(false);
  });

  it("says the equipment could not be read when the node is unreachable", async () => {
    stubUnreachableNode();

    const wrapper = await mountEquipmentPage();

    // Inventory setup is not offline-writable work (data/API 7.2), so the read
    // is reported rather than answered from a cache — and a department with no
    // equipment must not look like one that could not be read (CLIENT-023).
    expect(wrapper.text()).toContain("Unable to load equipment");
    expect(wrapper.text()).toContain("Try again");
    expect(wrapper.find('form[aria-label="Add equipment"]').exists()).toBe(
      false,
    );
  });
});
