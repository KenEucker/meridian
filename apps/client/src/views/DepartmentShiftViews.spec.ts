// The department shift surfaces against a stubbed node (M16.18; CLIENT-023,
// CLIENT-024; SHIFT-001 through SHIFT-010).
//
// These were fixture tests. They installed a role, mounted the list over five
// compiled-in shifts, submitted a backwards schedule, and asserted that the
// browser's own copy of `ShiftAdminService` had refused it. Nothing in them
// reached an endpoint, so nothing in them said whether the screen and the server
// agreed on a URL, a request body, or a response shape — and the rule they
// proved was the client's, not the node's.
//
// They now stub `fetch` and answer with the payloads `ShiftAdminReadController`
// and the four shift commands publish. Each test therefore asserts two things:
// what the screen asked the node, and what it did with the answer. The refusals
// are the node's sentences, quoted back.
//
// No server runs for any of this, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearReadCache } from "@/offline/readCache";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
  LOCAL_FIELD_ORGANIZATION_ID,
} from "@/session/localFieldSessionFixture";
import { clearClientSession } from "@/session/clientSession";
import {
  selectSessionDepartment,
} from "@/session/sessionAccess";
import { routes } from "@/router";
import DepartmentShiftEditView from "@/views/DepartmentShiftEditView.vue";
import DepartmentShiftListView from "@/views/DepartmentShiftListView.vue";
import PlanningTableView from "@/views/PlanningTableView.vue";

const EVENT_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
const DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;
const DIRT_TEAM_ID = "77777777-7777-4777-8777-777777777771";
const OPERATORS_TEAM_ID = "77777777-7777-4777-8777-777777777772";
const DAY_SHIFT_ID = "55555555-5555-4555-8555-555555555551";
const STARTED_SHIFT_ID = "55555555-5555-4555-8555-555555555552";
const PEER_SHIFT_ID = "55555555-5555-4555-8555-555555555553";
const TRAINING_ID = "88888888-8888-4888-8888-888888888801";
const WAIVER_ID = "99999999-9999-4999-8999-999999999901";

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

/** One shift, as `ShiftAdminReadController::payload` publishes it. */
function shiftPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: DAY_SHIFT_ID,
    event_id: EVENT_ID,
    event_name: "Signal Camp 2026",
    department_id: DEPARTMENT_ID,
    eligible_team_id: DIRT_TEAM_ID,
    eligible_team_name: "Dirt",
    title: "Dirt Patrol (Day)",
    starts_at: "2027-07-01T15:00:00+00:00",
    ends_at: "2027-07-01T23:00:00+00:00",
    capacity: 6,
    active_assignment_count: 2,
    signup_opens_at: null,
    signup_closes_at: null,
    schedule_lock_at: null,
    cancelled_at: null,
    has_started: false,
    can_manage: true,
    required_training_ids: [],
    required_waiver_ids: [],
    created_at: "2026-07-01T12:00:00+00:00",
    updated_at: "2026-07-01T12:00:00+00:00",
    ...overrides,
  };
}

function startedShiftPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return shiftPayload({
    id: STARTED_SHIFT_ID,
    title: "Dirt Patrol (Started)",
    starts_at: "2026-07-01T15:00:00+00:00",
    ends_at: "2026-07-01T23:00:00+00:00",
    capacity: 4,
    active_assignment_count: 4,
    has_started: true,
    ...overrides,
  });
}

/**
 * The `GET /api/departments/{id}/shifts` envelope.
 *
 * `teams` is what the eligible-team field may offer — the active teams this
 * caller may schedule for — rather than every team in the department.
 */
function workspacePayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    department_id: DEPARTMENT_ID,
    department: {
      id: DEPARTMENT_ID,
      organization_id: "11111111-1111-4111-8111-111111111111",
      name: "Rangers",
      code: "RANGERS",
      archived_at: null,
    },
    access: {
      can_administer: true,
      can_manage: true,
      manageable_team_ids: [DIRT_TEAM_ID, OPERATORS_TEAM_ID],
    },
    teams: [
      { id: DIRT_TEAM_ID, name: "Dirt", code: "DIRT", is_default: false },
      {
        id: OPERATORS_TEAM_ID,
        name: "Operators",
        code: "OPERATORS",
        is_default: true,
      },
    ],
    events: [{ id: EVENT_ID, name: "Signal Camp 2026" }],
    training_options: [{ id: TRAINING_ID, name: "Ranger Basic Training" }],
    waiver_options: [{ id: WAIVER_ID, name: "Event Liability Waiver" }],
    shifts: [shiftPayload(), startedShiftPayload()],
    ...overrides,
  };
}

/** The same envelope as a department member reads it: no forms, no authority. */
function memberWorkspacePayload(): Record<string, unknown> {
  return workspacePayload({
    access: {
      can_administer: false,
      can_manage: false,
      manageable_team_ids: [],
    },
    teams: [],
    events: [],
    training_options: [],
    waiver_options: [],
    shifts: [shiftPayload({ can_manage: false })],
  });
}

/** Enough of the trainings read for the Planning hub's other featureset. */
function trainingsPayload(): Record<string, unknown> {
  return {
    department_id: DEPARTMENT_ID,
    organization_id: "11111111-1111-4111-8111-111111111111",
    access: { can_manage: true, can_record_completions: true },
    teams: [],
    department_staff: [],
    trainings: [],
  };
}

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

/** An ISO instant as the `datetime-local` control holds it. */
function localInput(iso: string): string {
  const date = new Date(iso);
  const pad = (value: number): string => String(value).padStart(2, "0");

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/*
 * Every wrapper this file mounts, so `afterEach` can take them down. These pages
 * watch the route and re-read when it changes; one left mounted keeps reacting
 * into the next test's stub and the next test's recorded calls.
 */
const mounted: VueWrapper[] = [];

beforeEach(() => {
  /*
   * Reads are durable from M18.9 (technical spec 9.3), so a successful read in
   * one case would be served to the next one from the store. Cleared between
   * cases, and the unreachable-node cases below are about a device that is
   * holding nothing.
   */
  clearReadCache();
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

async function mountAt(
  component: unknown,
  location: Parameters<ReturnType<typeof buildRouter>["push"]>[0],
): Promise<{ wrapper: VueWrapper; router: ReturnType<typeof buildRouter> }> {
  const router = buildRouter();

  await router.push(location);
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return { wrapper, router };
}

function mountShiftList(query: Record<string, string> = {}) {
  return mountAt(DepartmentShiftListView, {
    name: "events.departments.shifts.index",
    params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID },
    query,
  });
}

function mountShiftCreate() {
  return mountAt(DepartmentShiftEditView, {
    name: "events.departments.shifts.create",
    params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID },
  });
}

function mountShiftEdit(shiftId: string) {
  return mountAt(DepartmentShiftEditView, {
    name: "events.departments.shifts.edit",
    params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID, shiftId },
  });
}

function shiftReads(calls: readonly NodeCall[]): readonly NodeCall[] {
  return calls.filter(
    (call) => call.method === "GET" && call.url.includes("/shifts"),
  );
}

function commandCalls(
  calls: readonly NodeCall[],
  command: string,
): readonly NodeCall[] {
  return calls.filter((call) => call.url.endsWith(`/commands/${command}`));
}

function rowFor(wrapper: VueWrapper, title: string) {
  return wrapper.findAll("tbody tr").find((row) => row.text().includes(title));
}

describe("department shift administration", () => {
  it("renders the whole page from one read of the department", async () => {
    const calls = stubNode(() => ({ body: workspacePayload() }));

    const { wrapper } = await mountShiftList();

    expect(shiftReads(calls)).toHaveLength(1);
    expect(shiftReads(calls)[0]?.url).toBe(
      `http://node.test/api/departments/${DEPARTMENT_ID}/shifts`,
    );

    // The heading names the department the node answered with, not the one the
    // session guessed.
    expect(wrapper.get("#dept-shifts-heading").text()).toBe("Shifts");
    expect(wrapper.text()).toContain("Rangers");
    expect(wrapper.text()).toContain("Dirt Patrol (Day)");
    expect(wrapper.text()).toContain("Dirt");
    expect(wrapper.text()).toContain("2 assigned / 6 cap");
    expect(wrapper.text()).toContain("Create shift");

    // Status is the node's two facts about the shift — cancelled, and started —
    // rather than the client's own reading of the clock.
    expect(rowFor(wrapper, "Dirt Patrol (Day)")!.text()).toContain("Scheduled");
    expect(rowFor(wrapper, "Dirt Patrol (Started)")!.text()).toContain(
      "Started",
    );
  });

  it("narrows the list through the endpoint rather than in the browser", async () => {
    const calls = stubNode((call) => ({
      body: workspacePayload(
        call.url.includes("status=cancelled")
          ? {
              shifts: [
                shiftPayload({
                  id: PEER_SHIFT_ID,
                  title: "Dirt Patrol (Cancelled)",
                  cancelled_at: "2026-07-02T12:00:00+00:00",
                }),
              ],
            }
          : {},
      ),
    }));

    const { wrapper } = await mountShiftList();

    await wrapper
      .get('select[aria-label="Filter shifts by status"]')
      .setValue("cancelled");
    await flushPromises();

    expect(shiftReads(calls)).toHaveLength(2);
    expect(shiftReads(calls)[1]?.url).toBe(
      `http://node.test/api/departments/${DEPARTMENT_ID}/shifts?status=cancelled`,
    );
    expect(wrapper.text()).toContain("Dirt Patrol (Cancelled)");
    expect(wrapper.text()).not.toContain("Dirt Patrol (Day)");
  });

  it("cancels and restores through the commands and re-reads", async () => {
    let cancelled = false;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/cancel-shift")) {
        cancelled = true;

        return { body: { id: DAY_SHIFT_ID } };
      }

      if (call.url.endsWith("/commands/restore-shift")) {
        cancelled = false;

        return { body: { id: DAY_SHIFT_ID } };
      }

      return {
        body: workspacePayload({
          shifts: [
            shiftPayload({
              cancelled_at: cancelled ? "2026-07-02T12:00:00+00:00" : null,
            }),
          ],
        }),
      };
    });

    const { wrapper } = await mountShiftList();

    await rowFor(wrapper, "Dirt Patrol (Day)")!
      .findAll("button")
      .find((button) => button.text() === "Cancel shift")!
      .trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "cancel-shift")[0]?.body).toEqual({
      shift_id: DAY_SHIFT_ID,
    });

    // The row now offers Restore because the re-read said it was cancelled, not
    // because the click changed anything on screen.
    expect(shiftReads(calls)).toHaveLength(2);
    expect(rowFor(wrapper, "Dirt Patrol (Day)")!.text()).toContain("Cancelled");

    await rowFor(wrapper, "Dirt Patrol (Day)")!
      .findAll("button")
      .find((button) => button.text() === "Restore")!
      .trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "restore-shift")[0]?.body).toEqual({
      shift_id: DAY_SHIFT_ID,
    });
    expect(shiftReads(calls)).toHaveLength(3);
    expect(rowFor(wrapper, "Dirt Patrol (Day)")!.text()).toContain("Scheduled");
  });

  it("reports a refused cancellation in the node's words and moves nothing", async () => {
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/cancel-shift")) {
        return {
          status: 422,
          body: { message: 'Shifts cannot be cancelled once they have started.' },
        };
      }

      return { body: workspacePayload() };
    });

    const { wrapper } = await mountShiftList();

    await rowFor(wrapper, "Dirt Patrol (Day)")!
      .findAll("button")
      .find((button) => button.text() === "Cancel shift")!
      .trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain(
      "Shifts cannot be cancelled once they have started.",
    );
    // A refusal changed nothing, so there was nothing to re-read.
    expect(shiftReads(calls)).toHaveLength(1);
    expect(rowFor(wrapper, "Dirt Patrol (Day)")!.text()).toContain("Scheduled");
  });

  it("offers no actions on a shift the node said this caller may not manage", async () => {
    stubNode(() => ({
      body: workspacePayload({
        access: {
          can_administer: false,
          can_manage: true,
          manageable_team_ids: [DIRT_TEAM_ID],
        },
        teams: [
          { id: DIRT_TEAM_ID, name: "Dirt", code: "DIRT", is_default: false },
        ],
        shifts: [
          shiftPayload(),
          shiftPayload({
            id: PEER_SHIFT_ID,
            title: "Operators Sweep",
            eligible_team_id: OPERATORS_TEAM_ID,
            eligible_team_name: "Operators",
            can_manage: false,
          }),
        ],
      }),
    }));

    const { wrapper } = await mountShiftList();

    expect(rowFor(wrapper, "Dirt Patrol (Day)")!.text()).toContain("Edit");
    const peer = rowFor(wrapper, "Operators Sweep")!;
    expect(peer.findAll("a")).toHaveLength(0);
    expect(peer.findAll("button")).toHaveLength(0);
  });

  it("gives a department member a read-only list of their team shifts", async () => {
    const calls = stubNode(() => ({ body: memberWorkspacePayload() }));

    const { wrapper } = await mountShiftList();

    // The member shell, chosen from the node's answer about authority.
    expect(wrapper.text()).toContain("Shifts your teams are eligible for.");
    expect(wrapper.text()).toContain("Dirt Patrol (Day)");
    expect(wrapper.text()).not.toContain("Create shift");
    expect(wrapper.findAll("table")).toHaveLength(0);
    expect(wrapper.findAll('a[href*="/edit"]')).toHaveLength(0);
    expect(
      wrapper.findAll("button").some((button) => button.text() === "Cancel shift"),
    ).toBe(false);
    expect(shiftReads(calls)).toHaveLength(1);
  });

  it("states a refused read instead of showing a department with no shifts", async () => {
    stubNode(() => ({
      status: 403,
      body: {
        message:
          "You do not have permission to view shifts for this department.",
      },
    }));

    const { wrapper } = await mountShiftList();

    expect(wrapper.text()).toContain(
      "You do not have permission to view shifts for this department.",
    );
    expect(wrapper.text()).not.toContain("Dirt Patrol (Day)");
  });

  it("states an unreachable node rather than an empty schedule", async () => {
    stubUnreachableNode();

    const { wrapper } = await mountShiftList();

    expect(wrapper.text()).toContain("Unable to load shifts.");
    expect(wrapper.get("button").text()).toBe("Try again");
  });

  it("embeds shifts and trainings as Planning featuresets", async () => {
    stubNode((call) =>
      call.url.includes("/trainings")
        ? { body: trainingsPayload() }
        : { body: workspacePayload() },
    );

    const { wrapper } = await mountAt(PlanningTableView, {
      name: "events.departments.planning",
      params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID },
    });

    // Planning is the forward-looking hub: coverage, the shifts it is built
    // from, and the trainings that gate eligibility for them. Embedded with no
    // page above it, the shift featureset makes its own read.
    expect(wrapper.get("#planning-gantt-heading").text()).toBe(
      "Scheduled shifts",
    );
    expect(wrapper.get("#shifts-section-heading").text()).toBe("Shifts");
    expect(wrapper.get("#trainings-section-heading").text()).toBe("Trainings");
    expect(wrapper.text()).toContain("Dirt Patrol (Day)");
  });
});

describe("shift create and edit", () => {
  it("creates a shift through the command and opens what the node created", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/create-shift")
        ? { status: 201, body: { id: PEER_SHIFT_ID } }
        : { body: workspacePayload() },
    );

    const { wrapper, router } = await mountShiftCreate();

    await wrapper.get('input[type="text"]').setValue("Night Perimeter");
    await wrapper.get("select").setValue(DIRT_TEAM_ID);
    const dateInputs = wrapper.findAll('input[type="datetime-local"]');
    await dateInputs[0]!.setValue("2027-07-01T20:00");
    await dateInputs[1]!.setValue("2027-07-02T04:00");
    await wrapper.get('input[type="checkbox"]').setValue(true);
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    const created = commandCalls(calls, "create-shift")[0]?.body;
    expect(created).toMatchObject({
      department_id: DEPARTMENT_ID,
      // The event comes from the client's event context; whether it belongs to
      // the department's organization is the node's check (SHIFT-001).
      event_id: EVENT_ID,
      eligible_team_id: DIRT_TEAM_ID,
      title: "Night Perimeter",
      capacity: null,
      required_training_ids: [TRAINING_ID],
      required_waiver_ids: [],
    });
    expect(new Date(created?.starts_at as string).toISOString()).toBe(
      new Date("2027-07-01T20:00").toISOString(),
    );

    expect(router.currentRoute.value.name).toBe(
      "events.departments.shifts.edit",
    );
    expect(router.currentRoute.value.params.shiftId).toBe(PEER_SHIFT_ID);
  });

  it("names a credit policy on the shift and sends it with the save (SHIFT-010)", async () => {
    // The override the credit resolver prefers over the organization default
    // (CREDIT-002; M18.16). The options are the node's offer, and blank means
    // the organization default rather than a policy of the department's own —
    // ORG-010 rules that out.
    const policyId = "cccccccc-cccc-4ccc-8ccc-cccccccccc01";
    const policyOptions = [
      {
        id: policyId,
        name: "Overnight Gate",
        credit_multiplier: "2.000",
        archived: false,
      },
    ];
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/update-shift")) {
        return { body: shiftPayload({ credit_policy_id: policyId }) };
      }

      if (call.url.includes(`/shifts/${DAY_SHIFT_ID}`)) {
        return { body: shiftPayload() };
      }

      return {
        body: workspacePayload({ credit_policy_options: policyOptions }),
      };
    });

    const { wrapper } = await mountShiftEdit(DAY_SHIFT_ID);

    const policySelect = wrapper
      .findAll("select")
      .find((candidate) => candidate.text().includes("Organization default"));
    expect(policySelect).toBeDefined();
    expect(policySelect!.text()).toContain("Overnight Gate — 2.000 credits/hour");

    await policySelect!.setValue(policyId);
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(commandCalls(calls, "update-shift")[0]?.body).toMatchObject({
      shift_id: DAY_SHIFT_ID,
      credit_policy_id: policyId,
    });
  });

  it("gives a shift a custom rate between 0 and 2 instead of a named policy (M18.16)", async () => {
    // Pre- and post-event work is typically priced below the standard hour,
    // so the select offers a custom rate beside the named policies. The two
    // are mutually exclusive on the wire: a custom rate sends no policy id.
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/update-shift")) {
        return { body: shiftPayload({ custom_credit_multiplier: "0.500" }) };
      }

      if (call.url.includes(`/shifts/${DAY_SHIFT_ID}`)) {
        return { body: shiftPayload() };
      }

      return { body: workspacePayload() };
    });

    const { wrapper } = await mountShiftEdit(DAY_SHIFT_ID);

    const policySelect = wrapper
      .findAll("select")
      .find((candidate) => candidate.text().includes("Organization default"));
    expect(policySelect!.text()).toContain("Custom rate for this shift…");

    await policySelect!.setValue("__custom__");
    await wrapper
      .get("input[aria-label='Custom credits per hour']")
      .setValue("0.5");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(commandCalls(calls, "update-shift")[0]?.body).toMatchObject({
      shift_id: DAY_SHIFT_ID,
      credit_policy_id: null,
      custom_credit_multiplier: "0.5",
    });
  });

  /*
   * Five fields that were blank and were being typed to the same values every
   * time. The window is the operational one, which is what setup and teardown
   * happen inside, and the two roster deadlines land where the roster stops
   * being changeable: the event starting.
   */
  it("opens a new shift on the event window with signups already open", async () => {
    clearClientSession();
    installLocalFieldSession({
      events: [
        {
          // The event the session's context resolved to, which is the one a
          // window is read off.
          id: LOCAL_FIELD_FIXTURE.eventId,
          organization_id: LOCAL_FIELD_ORGANIZATION_ID,
          name: "Local Field Event",
          slug: "local-field-event",
          status: "published",
          timezone: "UTC",
          starts_at: "2027-07-01T00:00:00.000Z",
          ends_at: "2027-07-08T00:00:00.000Z",
          active_event_window_starts_at: "2027-06-28T00:00:00.000Z",
          active_event_window_ends_at: "2027-07-11T00:00:00.000Z",
          is_node_locked: true,
        },
      ],
    });
    selectSessionDepartment(DEPARTMENT_ID);
    stubNode(() => ({ body: workspacePayload() }));

    const { wrapper } = await mountShiftCreate();

    const [startsAt, endsAt, signupOpensAt, signupClosesAt, scheduleLockAt] =
      wrapper
        .findAll('input[type="datetime-local"]')
        .map((input) => (input.element as HTMLInputElement).value);

    // The active window wins over the published dates: authority is handed over
    // on it, and a shift covering teardown is inside it and outside them.
    expect(localInput("2027-06-28T00:00:00.000Z")).toBe(startsAt);
    expect(localInput("2027-07-11T00:00:00.000Z")).toBe(endsAt);
    expect(signupOpensAt).not.toBe("");
    expect(localInput("2027-06-28T00:00:00.000Z")).toBe(signupClosesAt);
    expect(localInput("2027-06-28T00:00:00.000Z")).toBe(scheduleLockAt);
  });

  it("leaves the schedule blank for an event with no recorded window", async () => {
    // A default nobody set is worse than an empty field: it would put a made-up
    // window on a record the node is about to be asked to accept.
    stubNode(() => ({ body: workspacePayload() }));

    const { wrapper } = await mountShiftCreate();

    const values = wrapper
      .findAll('input[type="datetime-local"]')
      .map((input) => (input.element as HTMLInputElement).value);

    expect([values[0], values[1], values[3], values[4]]).toEqual([
      "",
      "",
      "",
      "",
    ]);
  });

  it("quotes the node's refusal of a backwards schedule", async () => {
    stubNode((call) =>
      call.url.endsWith("/commands/create-shift")
        ? {
            status: 422,
            body: { message: "Shift end must be after shift start." },
          }
        : { body: workspacePayload() },
    );

    const { wrapper, router } = await mountShiftCreate();

    await wrapper.get('input[type="text"]').setValue("Backwards Shift");
    const dateInputs = wrapper.findAll('input[type="datetime-local"]');
    await dateInputs[0]!.setValue("2027-07-02T04:00");
    await dateInputs[1]!.setValue("2027-07-01T20:00");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Shift end must be after shift start.");
    // The refusal left the form where it was, with the typed title to correct.
    expect(router.currentRoute.value.name).toBe(
      "events.departments.shifts.create",
    );
  });

  it("offers only the teams the node said this caller may schedule for", async () => {
    stubNode(() => ({
      body: workspacePayload({
        access: {
          can_administer: false,
          can_manage: true,
          manageable_team_ids: [DIRT_TEAM_ID],
        },
        teams: [
          { id: DIRT_TEAM_ID, name: "Dirt", code: "DIRT", is_default: false },
        ],
      }),
    }));

    const { wrapper } = await mountShiftCreate();

    expect(
      wrapper
        .get("select")
        .findAll("option")
        .map((option) => option.text()),
    ).toEqual(["Select team", "Dirt"]);
  });

  it("locks the schedule and team the node reported started, and sends them back unchanged", async () => {
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/update-shift")) {
        return { body: { id: STARTED_SHIFT_ID } };
      }

      if (call.url.includes(`/shifts/${STARTED_SHIFT_ID}`)) {
        return { body: startedShiftPayload() };
      }

      return { body: workspacePayload() };
    });

    const { wrapper } = await mountShiftEdit(STARTED_SHIFT_ID);

    expect(wrapper.text()).toContain("This shift has started");
    const dateInputs = wrapper.findAll('input[type="datetime-local"]');
    expect(dateInputs[0]!.attributes("disabled")).toBeDefined();
    expect(dateInputs[1]!.attributes("disabled")).toBeDefined();
    expect(wrapper.get("select").attributes("disabled")).toBeDefined();

    await wrapper.get('input[type="text"]').setValue("Dirt Patrol (Renamed)");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    // Title and capacity stay editable on a started shift; the schedule goes
    // back exactly as the node holds it, which is how "unchanged" is expressed.
    expect(commandCalls(calls, "update-shift")[0]?.body).toMatchObject({
      shift_id: STARTED_SHIFT_ID,
      title: "Dirt Patrol (Renamed)",
      eligible_team_id: DIRT_TEAM_ID,
      starts_at: "2026-07-01T15:00:00+00:00",
      ends_at: "2026-07-01T23:00:00+00:00",
    });
    expect(wrapper.text()).toContain("Shift saved.");
  });

  it("shows the node's refusal for a shift this caller may not manage", async () => {
    stubNode((call) =>
      call.url.includes(`/shifts/${PEER_SHIFT_ID}`)
        ? {
            status: 403,
            body: {
              message: "You do not have permission to manage this shift.",
            },
          }
        : { body: workspacePayload() },
    );

    const { wrapper } = await mountShiftEdit(PEER_SHIFT_ID);

    expect(wrapper.text()).toContain(
      "You do not have permission to manage this shift.",
    );
    expect(wrapper.findAll("form")).toHaveLength(0);
  });

  it("keeps a member off the create form the commands would refuse", async () => {
    stubNode(() => ({ body: memberWorkspacePayload() }));

    const { wrapper } = await mountShiftCreate();

    expect(wrapper.text()).toContain(
      "Creating and maintaining shifts requires department lead or team lead authority",
    );
    expect(wrapper.findAll("form")).toHaveLength(0);
  });
});
