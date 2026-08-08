// The three department screens M18.30 builds, against a stubbed node
// (UI contract 12.4; VOL-011, VOL-012; SLB-009, SLB-010; CREDIT-004,
// CREDIT-005).
//
// Each case asserts two things: what the screen asked the node, and what it did
// with the answer. The refusals and the omissions are the node's, quoted back —
// in particular the one that matters most here, which is that emergency
// contacts are missing from the payload for a reader who may not have them
// rather than present and empty.
//
// No server runs for any of this (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { routes } from "@/router";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { clearClientSession } from "@/session/clientSession";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
} from "@/session/localFieldSessionFixture";
import { selectSessionDepartment } from "@/session/sessionAccess";
import DepartmentCreditsView from "@/views/DepartmentCreditsView.vue";
import DepartmentDeploymentsView from "@/views/DepartmentDeploymentsView.vue";
import DepartmentRosterView from "@/views/DepartmentRosterView.vue";

const EVENT_ID = LOCAL_FIELD_FIXTURE.eventId;
const DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;

interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
}

interface NodeReply {
  readonly status?: number;
  readonly body: unknown;
}

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

const mounted: VueWrapper[] = [];

beforeEach(() => {
  clearOfflineReadSet();
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

async function mountDepartmentPage(
  component: unknown,
  routeName: string,
): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({
    name: routeName,
    params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID },
  });
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return wrapper;
}

function commandCalls(
  calls: readonly NodeCall[],
  command: string,
): readonly NodeCall[] {
  return calls.filter((call) => call.url.endsWith(`/commands/${command}`));
}

/* -------------------------------------------------------------------------- */

/** One roster row, as `DepartmentRosterReadController::member` publishes it. */
function memberPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    membership_id: "aaaa0001-0000-4000-8000-000000000001",
    staff_id: "bbbb0001-0000-4000-8000-000000000001",
    display_name: "Vera Ranger",
    legal_name: "Vera Ranger",
    preferred_name: null,
    handle: "vera",
    email: "vera@example.test",
    phone: "555-0100",
    city: "Boise",
    state: "ID",
    membership_status: "active",
    organization_status: "active",
    teams: [{ id: "cccc0001-0000-4000-8000-000000000001", name: "Dirt", membership_role: "lead" }],
    ...overrides,
  };
}

function rosterPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    context: {
      event_id: EVENT_ID,
      event_label: "Signal Camp 2026",
      department_id: DEPARTMENT_ID,
      department_label: "Rangers",
      participates_in_event: true,
    },
    access: {
      whole_department: true,
      led_team_ids: [],
      emergency_contacts: true,
    },
    teams: [
      {
        id: "cccc0001-0000-4000-8000-000000000001",
        name: "Dirt",
        code: "DIRT",
        is_default: true,
      },
      {
        id: "cccc0001-0000-4000-8000-000000000002",
        name: "Command",
        code: "CMD",
        is_default: false,
      },
    ],
    members: [
      memberPayload({
        emergency_contact_name: "Casey Ranger",
        emergency_contact_phone: "555-0199",
      }),
      memberPayload({
        membership_id: "aaaa0001-0000-4000-8000-000000000002",
        staff_id: "bbbb0001-0000-4000-8000-000000000002",
        display_name: "Sam Command",
        legal_name: "Sam Command",
        handle: "sam",
        email: "sam@example.test",
        phone: "555-0200",
        teams: [
          {
            id: "cccc0001-0000-4000-8000-000000000002",
            name: "Command",
            membership_role: "member",
          },
        ],
        emergency_contact_name: null,
        emergency_contact_phone: null,
      }),
    ],
    ...overrides,
  };
}

describe("department roster", () => {
  it("reads one event-and-department-scoped roster and renders it", async () => {
    const calls = stubNode(() => ({ body: rosterPayload() }));

    const wrapper = await mountDepartmentPage(
      DepartmentRosterView,
      "events.departments.roster",
    );

    expect(calls).toHaveLength(1);
    expect(calls[0]?.url).toBe(
      `http://node.test/api/events/${EVENT_ID}/departments/${DEPARTMENT_ID}/roster`,
    );

    expect(wrapper.get("#department-roster-heading").text()).toBe("Roster");
    expect(wrapper.text()).toContain("Rangers");
    expect(wrapper.text()).toContain("Vera Ranger");
    expect(wrapper.text()).toContain("555-0100");
    expect(wrapper.text()).toContain("Dirt (lead)");
  });

  /**
   * VOL-012. The column exists because the node sent the fields, and the row
   * that recorded nobody says so rather than looking withheld.
   */
  it("shows emergency contacts when the node sent them", async () => {
    stubNode(() => ({ body: rosterPayload() }));

    const wrapper = await mountDepartmentPage(
      DepartmentRosterView,
      "events.departments.roster",
    );

    expect(wrapper.text()).toContain("Emergency contact");
    expect(wrapper.text()).toContain("Casey Ranger");
    expect(wrapper.text()).toContain("555-0199");
    expect(wrapper.text()).toContain("None recorded");
    expect(wrapper.text()).toContain(
      "Emergency contacts are shown because you lead this department.",
    );
  });

  /**
   * VOL-011 and the other half of VOL-012: no column at all, rather than a
   * column of blanks that would read as "none recorded".
   */
  it("renders no emergency contact column when the node withheld them", async () => {
    stubNode(() => ({
      body: rosterPayload({
        access: {
          whole_department: true,
          led_team_ids: [],
          emergency_contacts: false,
        },
        members: [memberPayload(), memberPayload({
          membership_id: "aaaa0001-0000-4000-8000-000000000002",
          staff_id: "bbbb0001-0000-4000-8000-000000000002",
          display_name: "Sam Command",
          legal_name: "Sam Command",
          handle: "sam",
        })],
      }),
    }));

    const wrapper = await mountDepartmentPage(
      DepartmentRosterView,
      "events.departments.roster",
    );

    expect(wrapper.text()).toContain("Vera Ranger");
    expect(wrapper.findAll("th[scope='col']").map((cell) => cell.text())).not.toContain(
      "Emergency contact",
    );
    expect(wrapper.text()).toContain(
      "Emergency contacts are not shown: they belong to department leads for their own department.",
    );
  });

  it("says the list is narrowed when a team lead reads it", async () => {
    stubNode(() => ({
      body: rosterPayload({
        access: {
          whole_department: false,
          led_team_ids: ["cccc0001-0000-4000-8000-000000000001"],
          emergency_contacts: false,
        },
      }),
    }));

    const wrapper = await mountDepartmentPage(
      DepartmentRosterView,
      "events.departments.roster",
    );

    expect(wrapper.text()).toContain("This list covers only the teams you lead.");
  });

  /**
   * Searching runs over the roster already held, so it keeps working with the
   * node unreachable — the same reasoning SLB-021 applies to the Logistics
   * Desk.
   */
  it("filters locally without asking the node again", async () => {
    const calls = stubNode(() => ({ body: rosterPayload() }));

    const wrapper = await mountDepartmentPage(
      DepartmentRosterView,
      "events.departments.roster",
    );

    await wrapper.get("input[type='search']").setValue("sam");
    await flushPromises();

    expect(calls).toHaveLength(1);
    expect(wrapper.text()).toContain("Sam Command");
    expect(wrapper.text()).not.toContain("Vera Ranger");
  });

  it("shows nothing rather than an empty department when the read fails", async () => {
    stubNode(() => ({
      status: 403,
      body: {
        message:
          "You do not have permission to view the staff list for this department.",
      },
    }));

    const wrapper = await mountDepartmentPage(
      DepartmentRosterView,
      "events.departments.roster",
    );

    expect(wrapper.text()).toContain(
      "You do not have permission to view the staff list for this department.",
    );
    expect(wrapper.findAll("tbody tr")).toHaveLength(0);
  });
});

/* -------------------------------------------------------------------------- */

function deploymentsPayload(
  deployments: readonly Record<string, unknown>[],
): Record<string, unknown> {
  return {
    context: {
      event_id: EVENT_ID,
      event_label: "Signal Camp 2026",
      department_id: DEPARTMENT_ID,
      department_label: "Rangers",
    },
    deployments,
  };
}

function deploymentPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: "dddd0001-0000-4000-8000-000000000001",
    name: "Gate 1",
    description: "Main entrance",
    location_details: "Past the sign",
    archived_at: null,
    assigned_staff_count: 0,
    ...overrides,
  };
}

describe("department deployments", () => {
  it("renders the options with how many staff are at each", async () => {
    stubNode(() => ({
      body: deploymentsPayload([
        deploymentPayload({ assigned_staff_count: 2 }),
        deploymentPayload({
          id: "dddd0001-0000-4000-8000-000000000002",
          name: "Retired Post",
          description: null,
          location_details: null,
          archived_at: "2026-06-01T12:00:00+00:00",
        }),
      ]),
    }));

    const wrapper = await mountDepartmentPage(
      DepartmentDeploymentsView,
      "events.departments.deployments.index",
    );

    expect(wrapper.get("#department-deployments-heading").text()).toBe(
      "Deployments",
    );
    expect(wrapper.text()).toContain("Gate 1");
    expect(wrapper.text()).toContain("2 staff members here now");
    // Archived options stay on the page, because restoring one is half of why
    // somebody opens it.
    expect(wrapper.text()).toContain("Archived");
    expect(wrapper.text()).toContain("Retired Post");
  });

  it("says the module has nowhere to stand anybody when the list is empty", async () => {
    stubNode(() => ({ body: deploymentsPayload([]) }));

    const wrapper = await mountDepartmentPage(
      DepartmentDeploymentsView,
      "events.departments.deployments.index",
    );

    expect(wrapper.text()).toContain(
      "The Operations Center has nowhere to stand anybody until one is added here.",
    );
  });

  it("creates a deployment and re-reads the list", async () => {
    let created = false;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/create-deployment")) {
        created = true;

        return { status: 201, body: { id: "dddd0001-0000-4000-8000-000000000009" } };
      }

      return {
        body: deploymentsPayload(
          created ? [deploymentPayload({ name: "Gate 2" })] : [],
        ),
      };
    });

    const wrapper = await mountDepartmentPage(
      DepartmentDeploymentsView,
      "events.departments.deployments.index",
    );

    await wrapper.get("form input[type='text']").setValue("Gate 2");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    const posted = commandCalls(calls, "create-deployment");
    expect(posted).toHaveLength(1);
    expect(posted[0]?.body).toMatchObject({
      event_id: EVENT_ID,
      department_id: DEPARTMENT_ID,
      name: "Gate 2",
    });

    // The list is the node's answer after the write, never a locally patched
    // copy of the one before it.
    expect(
      calls.filter((call) => call.method === "GET" && call.url.endsWith("/deployments")),
    ).toHaveLength(2);
    expect(wrapper.text()).toContain("Gate 2");
  });

  /**
   * The refusal is the node's sentence, shown as it worded it. The button is
   * offered rather than disabled, because a disabled control explains nothing
   * and this one explains exactly what has to happen first (CLIENT-006).
   */
  it("shows the node's refusal when a deployment still holds staff", async () => {
    stubNode((call) => {
      if (call.url.endsWith("/commands/archive-deployment")) {
        return {
          status: 422,
          body: {
            message:
              "One staff member is currently deployed here. Move them to another deployment before archiving this one.",
          },
        };
      }

      return {
        body: deploymentsPayload([deploymentPayload({ assigned_staff_count: 1 })]),
      };
    });

    const wrapper = await mountDepartmentPage(
      DepartmentDeploymentsView,
      "events.departments.deployments.index",
    );

    const archive = wrapper
      .findAll("button")
      .find((button) => button.text() === "Archive");

    expect(archive).toBeDefined();
    expect(archive?.attributes("disabled")).toBeUndefined();

    await archive?.trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain(
      "One staff member is currently deployed here. Move them to another deployment before archiving this one.",
    );
  });

  it("restores an archived deployment", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/restore-deployment")
        ? { body: { id: "dddd0001-0000-4000-8000-000000000002", archived_at: null } }
        : {
            body: deploymentsPayload([
              deploymentPayload({
                id: "dddd0001-0000-4000-8000-000000000002",
                name: "Retired Post",
                archived_at: "2026-06-01T12:00:00+00:00",
              }),
            ]),
          },
    );

    const wrapper = await mountDepartmentPage(
      DepartmentDeploymentsView,
      "events.departments.deployments.index",
    );

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Restore")
      ?.trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "restore-deployment")[0]?.body).toMatchObject({
      deployment_id: "dddd0001-0000-4000-8000-000000000002",
    });
  });
});

/* -------------------------------------------------------------------------- */

function creditsPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    context: {
      event_id: EVENT_ID,
      event_label: "Signal Camp 2026",
      department_id: DEPARTMENT_ID,
      department_label: "Rangers",
    },
    access: { organization_wide: false, can_export: true },
    totals: {
      entry_count: 2,
      staff_count: 2,
      hours: "17.50",
      credits: "20.50",
    },
    outstanding: {
      open_hours_count: 0,
      uncredited_hours_count: 1,
      grace_closes_at: "2026-07-14T00:00:00+00:00",
      grace_closed: true,
    },
    staff: [
      {
        staff_id: "bbbb0001-0000-4000-8000-000000000001",
        staff_name: "Vera",
        staff_handle: "vera",
        entry_count: 1,
        hours: "7.50",
        credits: "7.50",
      },
      {
        staff_id: "bbbb0001-0000-4000-8000-000000000002",
        staff_name: "Alma",
        staff_handle: "alma",
        entry_count: 1,
        hours: "10.00",
        credits: "13.00",
      },
    ],
    entries: [
      {
        id: "eeee0001-0000-4000-8000-000000000001",
        staff_id: "bbbb0001-0000-4000-8000-000000000001",
        staff_name: "Vera",
        staff_handle: "vera",
        shift_title: "Rangers Dirt Day",
        shift_starts_at: "2026-06-19T19:00:00+00:00",
        shift_ends_at: "2026-06-20T03:00:00+00:00",
        team: "Dirt",
        entry_type: "calculated",
        status: "frozen",
        hours: "7.50",
        credits: "7.50",
        credit_policy_name: "Standard Credit",
        credit_multiplier: "1.000",
        policy_source: "organization",
        minutes_worked: "450",
        calculated_at: "2026-07-15T17:00:00+00:00",
        hours_corrected_at: null,
        frozen_at: "2026-07-15T17:00:00+00:00",
      },
      {
        id: "eeee0001-0000-4000-8000-000000000002",
        staff_id: "bbbb0001-0000-4000-8000-000000000002",
        staff_name: "Alma",
        staff_handle: "alma",
        shift_title: "Gate Opening",
        shift_starts_at: "2026-06-18T19:00:00+00:00",
        shift_ends_at: "2026-06-19T03:00:00+00:00",
        team: "Greeters",
        entry_type: "calculated",
        status: "frozen",
        hours: "10.00",
        credits: "13.00",
        credit_policy_name: "Overnight Gate",
        credit_multiplier: "1.300",
        policy_source: "shift",
        minutes_worked: "600",
        calculated_at: "2026-07-15T17:00:00+00:00",
        hours_corrected_at: "2026-06-20T18:00:00+00:00",
        frozen_at: "2026-07-15T17:00:00+00:00",
      },
    ],
    ...overrides,
  };
}

describe("department credits", () => {
  it("reads the department's ledger and shows the basis behind every number", async () => {
    const calls = stubNode(() => ({ body: creditsPayload() }));

    const wrapper = await mountDepartmentPage(
      DepartmentCreditsView,
      "events.departments.credits.index",
    );

    expect(
      calls.filter((call) => call.url.endsWith("/credits")),
    ).toHaveLength(1);

    expect(wrapper.get("#department-credits-heading").text()).toBe("Credits");
    expect(wrapper.text()).toContain("20.50");

    // CREDIT-005: hours, rate, credits, and the policy that set the rate are
    // all on the row, so the arithmetic is re-checkable without opening a
    // policy record.
    expect(wrapper.text()).toContain("Standard Credit");
    expect(wrapper.text()).toContain("1.000");
    expect(wrapper.text()).toContain("Overnight Gate");
    expect(wrapper.text()).toContain("1.300");
    expect(wrapper.text()).toContain("shift");
  });

  it("names what is worked and not yet credited", async () => {
    stubNode(() => ({ body: creditsPayload() }));

    const wrapper = await mountDepartmentPage(
      DepartmentCreditsView,
      "events.departments.credits.index",
    );

    expect(wrapper.text()).toContain(
      "1 frozen hours records carry no credit entry",
    );
  });

  it("offers the credits export narrowed to this department", async () => {
    stubNode(() => ({ body: creditsPayload() }));

    const wrapper = await mountDepartmentPage(
      DepartmentCreditsView,
      "events.departments.credits.index",
    );

    expect(wrapper.text()).toContain("Credits earned");
    expect(wrapper.text()).toContain("Rangers only, within Signal Camp 2026.");
  });

  it("says nothing has been credited rather than showing an empty table", async () => {
    stubNode(() => ({
      body: creditsPayload({
        totals: { entry_count: 0, staff_count: 0, hours: "0.00", credits: "0.00" },
        outstanding: {
          open_hours_count: 0,
          uncredited_hours_count: 0,
          grace_closes_at: null,
          grace_closed: false,
        },
        staff: [],
        entries: [],
      }),
    }));

    const wrapper = await mountDepartmentPage(
      DepartmentCreditsView,
      "events.departments.credits.index",
    );

    expect(wrapper.text()).toContain(
      "Nothing has been credited to this department for this event yet.",
    );
    expect(wrapper.findAll("tbody tr")).toHaveLength(0);
  });

  it("shows nothing rather than a department that earned nothing when the read fails", async () => {
    stubNode(() => ({
      status: 403,
      body: {
        message: "You do not have permission to review credits for this department.",
      },
    }));

    const wrapper = await mountDepartmentPage(
      DepartmentCreditsView,
      "events.departments.credits.index",
    );

    expect(wrapper.text()).toContain(
      "You do not have permission to review credits for this department.",
    );
    expect(wrapper.findAll("tbody tr")).toHaveLength(0);
  });
});
