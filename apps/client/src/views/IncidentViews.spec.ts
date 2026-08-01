// The IMS surfaces against a stubbed node (M16.20; CLIENT-023, CLIENT-024,
// CLIENT-006, CLIENT-015, CLIENT-019; INC-001 through INC-015; data/API 5.1,
// 5.2).
//
// These were fixture tests. They installed a role, mounted the list over three
// compiled-in incidents, saved a note, and asserted that the browser's own copy
// of `IncidentTimelineService` had stored it. Nothing in them reached an
// endpoint, so nothing in them said whether the screen and the server agreed on
// a URL, a request body, or a response shape — and the rules they proved were
// the client's, not the node's.
//
// They now stub `fetch` and answer with the payloads `IncidentReadController`,
// `IncidentCommandController`, `IncidentListPresetController`,
// `FieldReportReadController`, and the short-lived download URL endpoint
// publish. Each test therefore asserts two things: what the screen asked the
// node, and what it did with the answer. The refusals are the node's sentences,
// quoted back.
//
// No server runs for any of this, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import { configureMeridianApi } from "@/api/meridianApi";
import {
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
} from "@/field-reports/localFieldFixture";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import type { SessionDocument, SessionRole } from "@/session/sessionDocument";

const EVENT_ID = LOCAL_FIELD_FIXTURE.eventId;
const RANGERS = LOCAL_FIELD_DEPARTMENT_IDS.rangers;
const INCIDENT_ID = "99999999-9999-4999-8999-999999999901";
const OTHER_INCIDENT_ID = "99999999-9999-4999-8999-999999999902";
const FIELD_REPORT_ID = "99999999-9999-4999-8999-999999999903";
const ATTACHMENT_ID = "99999999-9999-4999-8999-999999999904";
const NOTE_ENTRY_ID = "99999999-9999-4999-8999-999999999905";
const PRESET_ID = "99999999-9999-4999-8999-999999999906";

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
function stubNode(reply: (call: NodeCall) => NodeReply): NodeCall[] {
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

function commandCalls(calls: readonly NodeCall[], command: string): NodeCall[] {
  return calls.filter((call) => call.url.includes(`/api/commands/${command}`));
}

function listCalls(calls: readonly NodeCall[]): NodeCall[] {
  return calls.filter(
    (call) => call.method === "GET" && call.url.includes("/incidents?"),
  );
}

/** One incident, as `IncidentReadController::incidentPayload` publishes it. */
function incidentPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: INCIDENT_ID,
    event_id: EVENT_ID,
    incident_number: "INC-2027-000042",
    status: "on_scene",
    priority_label: "Serious",
    started_at: "2027-07-04T20:15:00+00:00",
    title: "Medical assist near Gate A",
    location_name: "Gate A",
    location_address: "North entry road",
    location_details: "Responder staged near the shade structure. #medical",
    camp_id: null,
    map_location_id: null,
    incident_type_names: ["Medical"],
    responders: [
      {
        staff_id: "88888888-8888-4888-8888-888888888801",
        display_name: "Vera Ranger",
        relationship_label: "Responder",
      },
    ],
    linked_incidents: [],
    attached_field_reports: [],
    attachments: [],
    created_by_user_id: LOCAL_FIELD_FIXTURE.submittedByUserId,
    created_by_name: "Ingrid ICLead",
    created_at: "2027-07-04T20:18:00+00:00",
    updated_at: "2027-07-04T20:32:00+00:00",
    closed_at: null,
    name_reference_chips: [{ token: "Blue-Hat", normalized_token: "blue-hat" }],
    timeline_entries: [
      {
        id: "99999999-9999-4999-8999-999999999910",
        incident_id: INCIDENT_ID,
        actor_user_id: null,
        actor_name: "Ingrid ICLead",
        entry_type: "incident_opened",
        body: "Incident INC-2027-000042 opened.",
        previous_value: null,
        new_value: null,
        reason: null,
        created_at: "2027-07-04T20:18:00+00:00",
        stricken_at: null,
        stricken_reason: null,
      },
    ],
    ...overrides,
  };
}

function otherIncidentPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return incidentPayload({
    id: OTHER_INCIDENT_ID,
    incident_number: "INC-2027-000041",
    title: "Radio relay check",
    status: "monitoring",
    priority_label: "Routine",
    incident_type_names: ["Radio"],
    responders: [],
    location_details: "Monitoring signal reports. #medical",
    created_at: "2027-07-04T19:45:00+00:00",
    updated_at: "2027-07-04T19:56:00+00:00",
    name_reference_chips: [],
    timeline_entries: [],
    ...overrides,
  });
}

function presetPayload(): Record<string, unknown> {
  return {
    id: PRESET_ID,
    event_id: EVENT_ID,
    name: "Critical now",
    filters: {
      search: "",
      state: "all",
      priority: "Critical",
      type: "all",
      responder: "all",
      started_from: null,
      started_to: null,
      sort: "updated",
      direction: "desc",
    },
    query: { state: "all", priority: "Critical" },
    created_at: "2027-07-04T20:00:00+00:00",
    updated_at: "2027-07-04T20:00:00+00:00",
  };
}

/** The list read, as `IncidentReadController::index` publishes it. */
function listPayload(
  overrides: {
    readonly incidents?: Record<string, unknown>[];
    readonly presets?: Record<string, unknown>[];
    readonly filters?: Record<string, unknown>;
    readonly pagination?: Record<string, unknown>;
  } = {},
): Record<string, unknown> {
  return {
    event_id: EVENT_ID,
    filters: {
      search: "",
      state: "active",
      priority: "all",
      type: "all",
      responder: "all",
      started_from: null,
      started_to: null,
      sort: "updated",
      direction: "desc",
      page: 1,
      per_page: 25,
      ...overrides.filters,
    },
    filter_options: {
      states: [
        "active",
        "all",
        "open",
        "on_scene",
        "monitoring",
        "on_hold",
        "closed",
      ],
      priorities: ["all", "Routine", "Important", "Serious", "Critical"],
      sorts: ["updated", "incident", "state", "priority", "started", "location"],
      types: ["Medical", "Radio"],
      responders: [
        {
          staff_id: "88888888-8888-4888-8888-888888888801",
          display_name: "Vera Ranger",
        },
      ],
      max_per_page: 100,
    },
    assignable: {
      statuses: ["open", "on_scene", "monitoring", "on_hold", "closed"],
      priorities: ["Routine", "Important", "Serious", "Critical"],
      types: ["Medical", "Radio", "Weather"],
      responders: [
        {
          staff_id: "88888888-8888-4888-8888-888888888801",
          display_name: "Vera Ranger",
          detail: "Rangers",
        },
        {
          staff_id: "88888888-8888-4888-8888-888888888802",
          display_name: "Omar Operator",
          detail: "Rangers",
        },
      ],
    },
    pagination: {
      page: 1,
      per_page: 25,
      total: overrides.incidents?.length ?? 1,
      total_pages: 1,
      has_more: false,
      ...overrides.pagination,
    },
    presets: overrides.presets ?? [],
    incidents: overrides.incidents ?? [incidentPayload()],
  };
}

/** The Field Report read, as `FieldReportReadController::index` publishes it. */
function fieldReportListPayload(
  related: Record<string, unknown>[] = [],
): Record<string, unknown> {
  return {
    event_id: EVENT_ID,
    field_reports: [
      {
        id: FIELD_REPORT_ID,
        event_id: EVENT_ID,
        display_number: "FRA-2027-000123",
        title: "Medical observation near Gate A",
        author_name: "Vera Ranger",
        body: "Observed medical response near Gate A. #medical",
        created_at: "2027-07-04T20:34:00+00:00",
        related_incidents: related,
      },
    ],
  };
}

/**
 * A node that answers every IMS read the same way for a whole test.
 *
 * Commands answer with an accepted body carrying the incident id, which is
 * enough for the surfaces that re-read afterwards; a test that cares what a
 * command returned answers it itself.
 */
function stubStandardNode(
  options: {
    readonly incident?: Record<string, unknown>;
    readonly list?: Record<string, unknown>;
    readonly fieldReports?: Record<string, unknown>;
  } = {},
): NodeCall[] {
  return stubNode((call) => {
    if (call.url.includes("/field-reports")) {
      return { body: options.fieldReports ?? fieldReportListPayload() };
    }

    if (call.method === "POST") {
      return { body: { id: INCIDENT_ID } };
    }

    if (call.url.includes(`/incidents/${INCIDENT_ID}`)) {
      return { body: { incident: options.incident ?? incidentPayload() } };
    }

    return { body: options.list ?? listPayload() };
  });
}

async function mountAt(path: string) {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push(path);
  await router.isReady();

  const wrapper = mount(App, { global: { plugins: [router] } });

  await flushPromises();

  return { wrapper, router };
}

/**
 * The signed-in session.
 *
 * With no argument it is the seeded IC lead, who holds every incident
 * capability. With one it is a caller holding exactly the codes named, which is
 * how the permission tests below narrow authority without inventing a role.
 */
function installSession(
  capabilityOverrides?: readonly string[],
): SessionDocument {
  const document = installLocalFieldSession(
    capabilityOverrides === undefined
      ? {}
      : { roles: narrowedRoles(capabilityOverrides) },
  );

  selectSessionDepartment(RANGERS);

  return document;
}

function narrowedRoles(capabilities: readonly string[]): SessionRole[] {
  return [
    {
      role_code: "ic_viewer",
      role_name: "Incident Command Viewer",
      scope_type: "event",
      organization_id: null,
      department_id: RANGERS,
      team_id: null,
      team_name: null,
      event_id: EVENT_ID,
      team_grant_id: null,
      reason: null,
      capabilities: [...capabilities],
    },
  ];
}

function findButton(wrapper: VueWrapper, text: string) {
  return wrapper.findAll("button").find((button) => button.text() === text);
}

/**
 * Drive the device-network signal.
 *
 * The event matters as much as the property: `deviceConnectivity` is a module
 * ref that only moves on `online`/`offline`, so a test that drops the network
 * and does not put it back leaves every later test offline.
 */
function setNavigatorOnline(onLine: boolean): void {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value: onLine,
  });

  window.dispatchEvent(new Event(onLine ? "online" : "offline"));
}

beforeEach(() => {
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
  setNavigatorOnline(true);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  configureMeridianApi(null);
  clearClientSession();
  resetSelectedSessionDepartment();
  setNavigatorOnline(true);
});

describe("the incident list", () => {
  it("asks the node for the selection in the URL and renders the page it answered", async () => {
    installSession();
    const calls = stubStandardNode({
      list: listPayload({
        incidents: [incidentPayload(), otherIncidentPayload()],
        filters: { state: "all", priority: "Serious", sort: "priority" },
        pagination: { total: 7, total_pages: 1 },
      }),
    });

    const { wrapper } = await mountAt(
      "/ims/incidents?state=all&priority=Serious&sort=priority",
    );

    const read = listCalls(calls).at(-1);

    expect(read?.url).toContain(`/api/events/${EVENT_ID}/incidents?`);
    expect(read?.url).toContain("state=all");
    expect(read?.url).toContain("priority=Serious");
    expect(read?.url).toContain("sort=priority");

    // The rows are the node's page, and the count is the node's total rather
    // than the number of rows on it.
    expect(wrapper.text()).toContain("INC-2027-000042");
    expect(wrapper.text()).toContain("INC-2027-000041");
    expect(wrapper.get(".ims-list__summary").text()).toContain(
      "7 incidents match the current filters.",
    );
  });

  it("offers the node's filter vocabulary rather than one of its own", async () => {
    installSession();
    stubStandardNode();

    const { wrapper } = await mountAt("/ims/incidents");

    const states = wrapper
      .get("#ims-list-state")
      .findAll("option")
      .map((option) => option.attributes("value"));

    expect(states).toEqual([
      "active",
      "all",
      "open",
      "on_scene",
      "monitoring",
      "on_hold",
      "closed",
    ]);

    // `filter_options.sorts` does not list a types sort, so the heading is not
    // a control that would be refused.
    const headings = wrapper.findAll(".ims-list__table thead th");

    expect(
      headings.find((heading) => heading.text() === "Types")?.find("a").exists(),
    ).toBe(false);
    expect(
      headings
        .find((heading) => heading.text() === "Priority")
        ?.find("a")
        .exists(),
    ).toBe(true);
  });

  it("shows the node's refusal instead of an empty list", async () => {
    installSession();
    stubNode(() => ({
      status: 403,
      body: {
        message:
          "This page requires Incident Command access for the event configured IC department.",
      },
    }));

    const { wrapper } = await mountAt("/ims/incidents");

    expect(wrapper.get(".ims-list__load-error").text()).toBe(
      "This page requires Incident Command access for the event configured IC department.",
    );
    expect(wrapper.text()).not.toContain("INC-2027-000042");
  });

  it("saves a list preset through its command and renders the presets it answered", async () => {
    installSession();
    const calls = stubNode((call) => {
      if (call.url.includes("/api/commands/save-incident-list-preset")) {
        return { status: 201, body: { presets: [presetPayload()] } };
      }

      return { body: listPayload() };
    });

    const { wrapper } = await mountAt(
      "/ims/incidents?state=all&priority=Critical",
    );

    await wrapper.get("#ims-list-preset-name").setValue("Critical now");
    await wrapper
      .get("form[aria-label='Saved incident list presets']")
      .trigger("submit");
    await flushPromises();

    const saved = commandCalls(calls, "save-incident-list-preset").at(0);

    expect(saved?.method).toBe("POST");
    expect(saved?.body).toMatchObject({ event_id: EVENT_ID, name: "Critical now" });

    expect(
      wrapper
        .get("#ims-list-preset")
        .findAll("option")
        .map((option) => option.text()),
    ).toContain("Critical now");
  });

  it("refuses to save a preset the node refuses, in the node's words", async () => {
    installSession();
    stubNode((call) => {
      if (call.url.includes("/api/commands/save-incident-list-preset")) {
        return {
          status: 422,
          body: {
            message: "Preset name may not be greater than 60 characters.",
          },
        };
      }

      return { body: listPayload() };
    });

    const { wrapper } = await mountAt("/ims/incidents");

    await wrapper.get("#ims-list-preset-name").setValue("A name");
    await wrapper
      .get("form[aria-label='Saved incident list presets']")
      .trigger("submit");
    await flushPromises();

    expect(wrapper.get(".ims-list__preset-error").text()).toBe(
      "Preset name may not be greater than 60 characters.",
    );
  });

  it("applies a saved preset by handing the node's own query back to the list", async () => {
    installSession();
    stubNode(() => ({ body: listPayload({ presets: [presetPayload()] }) }));

    const { wrapper, router } = await mountAt("/ims/incidents");

    await wrapper.get("#ims-list-preset").setValue(PRESET_ID);
    await flushPromises();

    expect(router.currentRoute.value.query).toEqual({
      state: "all",
      priority: "Critical",
    });
  });
});

describe("the incident detail", () => {
  it("reads the incident and renders the node's timeline, chips, and attachments", async () => {
    installSession();
    stubStandardNode({
      incident: incidentPayload({
        attachments: [
          {
            id: ATTACHMENT_ID,
            filename: "gate-a.jpg",
            mime_type: "image/jpeg",
            byte_size: 204800,
            created_at: "2027-07-04T20:20:00+00:00",
          },
        ],
      }),
    });

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}`);

    expect(wrapper.text()).toContain("INC-2027-000042");
    expect(wrapper.text()).toContain("Incident INC-2027-000042 opened.");
    // The chip is the node's `name_reference_chips` entry, not one this client
    // re-derived from the text.
    expect(wrapper.text()).toContain("@Blue-Hat");
  });

  it("says what the node said when it refuses the incident", async () => {
    installSession();
    stubNode((call) =>
      call.url.includes(`/incidents/${INCIDENT_ID}`)
        ? {
            status: 403,
            body: {
              message:
                "This page requires Incident Command access for the event configured IC department.",
            },
          }
        : { body: listPayload() },
    );

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}`);

    expect(wrapper.text()).toContain(
      "This page requires Incident Command access for the event configured IC department.",
    );
  });

  it("appends a note through its command and re-reads the incident", async () => {
    installSession();
    let noted = false;
    const calls = stubNode((call) => {
      if (call.url.includes("/api/commands/append-incident-note")) {
        noted = true;

        return { status: 201, body: { id: NOTE_ENTRY_ID } };
      }

      if (call.url.includes("/field-reports")) {
        return { body: fieldReportListPayload() };
      }

      if (call.url.includes(`/incidents/${INCIDENT_ID}`)) {
        return {
          body: {
            incident: incidentPayload({
              timeline_entries: noted
                ? [
                    {
                      id: NOTE_ENTRY_ID,
                      incident_id: INCIDENT_ID,
                      actor_name: "Ingrid ICLead",
                      entry_type: "operational_note",
                      body: "Responder is on scene.",
                      previous_value: null,
                      new_value: null,
                      reason: null,
                      created_at: "2027-07-04T20:40:00+00:00",
                      stricken_at: null,
                      stricken_reason: null,
                    },
                  ]
                : [],
            }),
          },
        };
      }

      return { body: listPayload() };
    });

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    await wrapper.get("#ims-note-body").setValue("Responder is on scene.");
    await wrapper.get("form.ims-edit__note-form").trigger("submit");
    await flushPromises();

    expect(commandCalls(calls, "append-incident-note").at(0)?.body).toEqual({
      event_id: EVENT_ID,
      incident_id: INCIDENT_ID,
      body: "Responder is on scene.",
    });
    expect(wrapper.text()).toContain("Responder is on scene.");
  });

  it("strikes a note with the reason the node requires", async () => {
    installSession();
    vi.spyOn(window, "prompt").mockReturnValue("Recorded on the wrong incident.");

    const calls = stubStandardNode({
      incident: incidentPayload({
        timeline_entries: [
          {
            id: NOTE_ENTRY_ID,
            incident_id: INCIDENT_ID,
            actor_name: "Ingrid ICLead",
            entry_type: "operational_note",
            body: "Responder is on scene.",
            previous_value: null,
            new_value: null,
            reason: null,
            created_at: "2027-07-04T20:40:00+00:00",
            stricken_at: null,
            stricken_reason: null,
          },
        ],
      }),
    });

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    await findButton(wrapper, "Strike note")?.trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "strike-incident-note").at(0)?.body).toEqual({
      event_id: EVENT_ID,
      incident_id: INCIDENT_ID,
      timeline_entry_id: NOTE_ENTRY_ID,
      reason: "Recorded on the wrong incident.",
    });
  });

  it("strikes an attachment with its reason rather than deleting it", async () => {
    installSession();
    vi.spyOn(window, "prompt").mockReturnValue("Wrong incident.");

    const calls = stubStandardNode({
      incident: incidentPayload({
        attachments: [
          {
            id: ATTACHMENT_ID,
            filename: "gate-a.jpg",
            mime_type: "image/jpeg",
            byte_size: 204800,
            created_at: "2027-07-04T20:20:00+00:00",
          },
        ],
      }),
    });

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    await wrapper
      .get("button[aria-label='Strike attachment gate-a.jpg']")
      .trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "strike-incident-attachment").at(0)?.body).toEqual(
      {
        event_id: EVENT_ID,
        incident_id: INCIDENT_ID,
        attachment_id: ATTACHMENT_ID,
        reason: "Wrong incident.",
      },
    );
  });
});

describe("incident editing", () => {
  it("autosaves an edit through the update command and re-reads the record", async () => {
    installSession();
    const calls = stubStandardNode();

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    const readsBefore = calls.filter((call) =>
      call.url.includes(`/incidents/${INCIDENT_ID}`),
    ).length;

    await wrapper.get("#ims-edit-title").setValue("Medical assist, Gate A");
    await wrapper.get("#ims-edit-title").trigger("blur");
    await flushPromises();

    expect(commandCalls(calls, "update-incident").at(0)?.body).toMatchObject({
      event_id: EVENT_ID,
      incident_id: INCIDENT_ID,
      title: "Medical assist, Gate A",
      status: "on_scene",
      priority_label: "Serious",
    });
    expect(
      calls.filter((call) => call.url.includes(`/incidents/${INCIDENT_ID}`))
        .length,
    ).toBeGreaterThan(readsBefore);
  });

  it("creates an incident and names the fields the author set before the first save", async () => {
    installSession();
    const calls = stubStandardNode();

    const { wrapper } = await mountAt("/ims/incidents/create");

    await wrapper.get("#ims-edit-title").setValue("New incident");
    await wrapper.get("#ims-edit-title").trigger("blur");
    await flushPromises();

    const created = commandCalls(calls, "create-incident").at(0);

    expect(created?.body).toMatchObject({
      event_id: EVENT_ID,
      title: "New incident",
    });
    expect(created?.body?.initial_field_update_fields).toEqual(["title"]);
  });

  it("offers the states and priorities the node says it will accept", async () => {
    installSession();
    stubStandardNode();

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    expect(
      wrapper
        .get("#ims-edit-status")
        .findAll("option")
        .map((option) => option.attributes("value")),
    ).toEqual(["open", "on_scene", "monitoring", "on_hold", "closed"]);
    expect(
      wrapper
        .get("#ims-edit-priority")
        .findAll("option")
        .map((option) => option.attributes("value")),
    ).toEqual(["Routine", "Important", "Serious", "Critical"]);
  });

  it("offers the organization's configured types and adds one to the incident", async () => {
    installSession();
    const calls = stubStandardNode();

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    await wrapper.get("#ims-edit-type-add").trigger("focus");
    await flushPromises();

    // Medical is already on the incident, so the remaining configured types are
    // what the picker offers.
    expect(
      wrapper
        .findAll("[aria-label='Incident type matches'] button")
        .map((button) => button.text()),
    ).toEqual(["Radio", "Weather"]);

    await wrapper
      .findAll("[aria-label='Incident type matches'] button")
      .find((button) => button.text() === "Weather")
      ?.trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "update-incident").at(0)?.body).toMatchObject({
      incident_type_names: ["Medical", "Weather"],
    });
  });

  it("never offers to create a type the organization has not configured", async () => {
    installSession();
    const calls = stubStandardNode();

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    await wrapper.get("#ims-edit-type-add").trigger("focus");
    await wrapper.get("#ims-edit-type-add").setValue("Avalanche");
    await flushPromises();

    // Nothing to click, and Enter invents nothing: incident types are the
    // organization's to configure, not this form's to create.
    expect(
      wrapper.findAll("[aria-label='Incident type matches'] button"),
    ).toHaveLength(0);
    expect(wrapper.get("[aria-label='Incident type matches']").text()).toBe(
      "No configured incident type matches that.",
    );

    await wrapper.get("#ims-edit-type-add").trigger("keydown.enter");
    await flushPromises();

    expect(commandCalls(calls, "update-incident")).toHaveLength(0);
    expect(wrapper.get("#ims-edit-types").text()).not.toContain("Avalanche");
  });

  it("says so when the organization has configured no incident types", async () => {
    installSession();
    const list = listPayload();
    (list.assignable as Record<string, unknown>).types = [];

    stubStandardNode({ list });

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    await wrapper.get("#ims-edit-type-add").trigger("focus");
    await flushPromises();

    // An organizer's problem, named as one, rather than a control that looks
    // broken.
    expect(wrapper.get("[aria-label='Incident type matches']").text()).toBe(
      "No incident types are configured for this organization.",
    );
  });

  it("shows the node's refusal when an edit is refused", async () => {
    installSession();
    stubNode((call) => {
      if (call.url.includes("/api/commands/update-incident")) {
        return {
          status: 403,
          body: {
            message:
              "Only IC operators and IC leads for this event may edit incidents.",
          },
        };
      }

      if (call.url.includes("/field-reports")) {
        return { body: fieldReportListPayload() };
      }

      if (call.url.includes(`/incidents/${INCIDENT_ID}`)) {
        return { body: { incident: incidentPayload() } };
      }

      return { body: listPayload() };
    });

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    await wrapper.get("#ims-edit-title").setValue("Refused edit");
    await wrapper.get("#ims-edit-title").trigger("blur");
    await flushPromises();

    expect(wrapper.text()).toContain(
      "Only IC operators and IC leads for this event may edit incidents.",
    );
  });

  it("refuses an edit offline in the command catalog's words and sends nothing", async () => {
    installSession();
    const calls = stubStandardNode();

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    setNavigatorOnline(false);
    await flushPromises();

    await wrapper.get("#ims-edit-title").setValue("Offline edit");
    await wrapper.get("#ims-edit-title").trigger("blur");
    await flushPromises();

    expect(commandCalls(calls, "update-incident")).toHaveLength(0);
    expect(wrapper.text()).toContain(
      "Incident create/edit requires server connection.",
    );
  });
});

describe("incident links", () => {
  it("links another incident through its command and re-reads the record", async () => {
    installSession();
    let linked = false;
    const calls = stubNode((call) => {
      if (call.url.includes("/api/commands/link-incident")) {
        linked = true;

        return { status: 201, body: { id: "link-1" } };
      }

      if (call.url.includes("/field-reports")) {
        return { body: fieldReportListPayload() };
      }

      if (call.url.includes(`/incidents/${INCIDENT_ID}`)) {
        return {
          body: {
            incident: incidentPayload({
              linked_incidents: linked
                ? [
                    {
                      id: OTHER_INCIDENT_ID,
                      incident_number: "INC-2027-000041",
                      title: "Radio relay check",
                      status: "monitoring",
                    },
                  ]
                : [],
            }),
          },
        };
      }

      return {
        body: listPayload({
          incidents: [incidentPayload(), otherIncidentPayload()],
        }),
      };
    });

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    await wrapper.get("#ims-edit-linked-add").trigger("focus");
    await flushPromises();

    const option = wrapper
      .findAll("[aria-label='Linked incident matches'] button")
      .find((button) => button.text().includes("INC-2027-000041"));

    expect(option).toBeDefined();
    await option?.trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "link-incident").at(0)?.body).toEqual({
      event_id: EVENT_ID,
      incident_id: INCIDENT_ID,
      target_incident_id: OTHER_INCIDENT_ID,
    });
    expect(wrapper.get("#ims-edit-linked").text()).toContain("INC-2027-000041");
  });

  it("never offers an incident that is already linked, or the incident itself", async () => {
    installSession();
    stubStandardNode({
      incident: incidentPayload({
        linked_incidents: [
          {
            id: OTHER_INCIDENT_ID,
            incident_number: "INC-2027-000041",
            title: "Radio relay check",
            status: "monitoring",
          },
        ],
      }),
      list: listPayload({
        incidents: [incidentPayload(), otherIncidentPayload()],
      }),
    });

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    await wrapper.get("#ims-edit-linked-add").trigger("focus");
    await flushPromises();

    expect(
      wrapper.findAll("[aria-label='Linked incident matches'] button"),
    ).toHaveLength(0);
  });

  it("attaches a Field Report from the event read and re-reads the incident", async () => {
    installSession();
    let attached = false;
    const calls = stubNode((call) => {
      if (call.url.includes("/api/commands/link-field-report")) {
        attached = true;

        return { status: 201, body: { id: "fr-link-1" } };
      }

      if (call.url.includes("/field-reports")) {
        return { body: fieldReportListPayload() };
      }

      if (call.url.includes(`/incidents/${INCIDENT_ID}`)) {
        return {
          body: {
            incident: incidentPayload({
              attached_field_reports: attached
                ? [
                    {
                      id: FIELD_REPORT_ID,
                      field_report_id: FIELD_REPORT_ID,
                      incident_field_report_id: "fr-link-1",
                      display_number: "FRA-2027-000123",
                      title: "Medical observation near Gate A",
                      author_name: "Vera Ranger",
                      body: "Observed medical response near Gate A.",
                      linked_at: "2027-07-04T20:45:00+00:00",
                    },
                  ]
                : [],
            }),
          },
        };
      }

      return { body: listPayload() };
    });

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    await wrapper.get("#ims-edit-field-report-add").trigger("focus");
    await flushPromises();

    const option = wrapper
      .findAll("[aria-label='Field Report matches'] button")
      .find((button) => button.text().includes("FRA-2027-000123"));

    expect(option).toBeDefined();
    await option?.trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "link-field-report").at(0)?.body).toEqual({
      event_id: EVENT_ID,
      incident_id: INCIDENT_ID,
      field_report_id: FIELD_REPORT_ID,
    });
    expect(wrapper.get("#ims-edit-field-reports").text()).toContain(
      "FRA-2027-000123",
    );
  });
});

describe("the incident PDF", () => {
  it("asks the node for a short-lived URL and navigates to it", async () => {
    installSession();
    const clicked: string[] = [];

    vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(
      function click(this: HTMLAnchorElement) {
        clicked.push(this.href);
      },
    );

    const calls = stubNode((call) => {
      if (call.url.includes("/pdf/download-url")) {
        return {
          body: {
            url: "http://node.test/downloads/incident.pdf?signature=abc",
            expires_at: "2027-07-04T21:00:00+00:00",
          },
        };
      }

      if (call.url.includes(`/incidents/${INCIDENT_ID}`)) {
        return { body: { incident: incidentPayload() } };
      }

      return { body: listPayload() };
    });

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}`);

    await findButton(wrapper, "Print PDF")?.trigger("click");
    await flushPromises();

    const issued = calls.find((call) => call.url.includes("/pdf/download-url"));

    expect(issued?.method).toBe("POST");
    expect(issued?.url).toBe(
      `http://node.test/api/events/${EVENT_ID}/incidents/${INCIDENT_ID}/pdf/download-url`,
    );
    expect(clicked).toEqual([
      "http://node.test/downloads/incident.pdf?signature=abc",
    ]);
  });

  it("shows the node's refusal instead of opening anything", async () => {
    installSession();
    const clicked: string[] = [];

    vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(
      function click(this: HTMLAnchorElement) {
        clicked.push(this.href);
      },
    );

    stubNode((call) => {
      if (call.url.includes("/pdf/download-url")) {
        return {
          status: 403,
          body: {
            message:
              "Only Incident Command leads for this event may print incidents to PDF.",
          },
        };
      }

      if (call.url.includes(`/incidents/${INCIDENT_ID}`)) {
        return { body: { incident: incidentPayload() } };
      }

      return { body: listPayload() };
    });

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}`);

    await findButton(wrapper, "Print PDF")?.trigger("click");
    await flushPromises();

    expect(clicked).toEqual([]);
    expect(wrapper.get(".ims-edit__print-error").text()).toBe(
      "Only Incident Command leads for this event may print incidents to PDF.",
    );
  });
});

describe("what the session permits", () => {
  it("offers no create and no note form to a viewer", async () => {
    installSession(["incidents.view", "field_reports.view_event"]);
    stubStandardNode();

    const list = await mountAt("/ims/incidents");

    expect(findButton(list.wrapper, "Create incident")).toBeUndefined();
    expect(list.wrapper.find("#ims-list-open-mode").exists()).toBe(false);

    const detail = await mountAt(`/ims/incidents/${INCIDENT_ID}`);

    expect(findButton(detail.wrapper, "Print PDF")).toBeUndefined();
    expect(detail.wrapper.find("form.ims-edit__note-form").exists()).toBe(false);
  });

  it("refuses the edit route to a caller without the update capability", async () => {
    installSession(["incidents.view"]);
    stubStandardNode();

    const { wrapper } = await mountAt(`/ims/incidents/${INCIDENT_ID}/edit`);

    expect(wrapper.text()).toContain("IC operator or lead access required");
  });

  it("shows the restricted notice and reads nothing without incidents.view", async () => {
    installSession([]);
    const calls = stubStandardNode();

    const { wrapper } = await mountAt("/ims/incidents");

    expect(wrapper.text()).toContain("Incident Command access required");
    expect(listCalls(calls)).toHaveLength(0);
  });
});

describe("the IC Field Report list", () => {
  it("reads the event's Field Reports and names the incidents they are linked to", async () => {
    installSession();
    stubStandardNode({
      fieldReports: fieldReportListPayload([
        {
          id: INCIDENT_ID,
          incident_number: "INC-2027-000042",
          title: "Medical assist near Gate A",
          status: "on_scene",
          priority_label: "Serious",
        },
      ]),
    });

    const { wrapper } = await mountAt("/ims/field-reports");

    expect(wrapper.text()).toContain("FRA-2027-000123");
    expect(wrapper.text()).toContain("INC-2027-000042");
  });

  it("narrows the delivered list to unlinked reports without asking again", async () => {
    installSession();
    const calls = stubStandardNode({
      fieldReports: fieldReportListPayload([
        {
          id: INCIDENT_ID,
          incident_number: "INC-2027-000042",
          title: "Medical assist near Gate A",
          status: "on_scene",
          priority_label: "Serious",
        },
      ]),
    });

    const { wrapper } = await mountAt("/ims/field-reports");

    expect(wrapper.text()).toContain("FRA-2027-000123");

    const readsBefore = calls.filter((call) =>
      call.url.includes("/field-reports"),
    ).length;

    // Link status is a question about the incidents each report already
    // carries, so narrowing by it is a view of the answer rather than a new
    // one to ask for.
    await wrapper.get("#ims-fr-list-link").setValue("not_linked");
    await flushPromises();

    expect(wrapper.text()).toContain("No Field Reports match these filters.");
    expect(
      calls.filter((call) => call.url.includes("/field-reports")),
    ).toHaveLength(readsBefore);
  });

  it("shows the node's refusal", async () => {
    installSession();
    stubNode(() => ({
      status: 403,
      body: {
        message:
          "This page requires event-wide Field Report access for the event configured IC department.",
      },
    }));

    const { wrapper } = await mountAt("/ims/field-reports");

    expect(wrapper.get(".ims-fr-list__load-error").text()).toBe(
      "This page requires event-wide Field Report access for the event configured IC department.",
    );
  });
});

describe("the IMS routes", () => {
  it("registers the UI contract route names", () => {
    const names = routes.map((route) => route.name);

    expect(names).toContain("ims.incidents.index");
    expect(names).toContain("ims.incidents.create");
    expect(names).toContain("ims.incidents.edit");
    expect(names).toContain("ims.incidents.show");
    expect(names).toContain("ims.field-reports.index");
    expect(names).toContain("ims.field-reports.show");
    expect(names).toContain("ims.restricted");
  });
});
