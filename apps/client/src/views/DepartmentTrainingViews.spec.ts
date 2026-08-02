// The department training surfaces against a stubbed node (M16.16; CLIENT-023,
// CLIENT-024, TRAIN requirements; data/API 10.8).
//
// These were fixture tests. They installed a role, mounted a page over five
// compiled-in trainings, clicked Sign up, and asserted that a module array had
// changed. Nothing in them reached an endpoint, so nothing in them said whether
// the screen and the server agreed on a URL, a request body, or a response
// shape — and the authority they exercised was a role string the client had
// decided for itself.
//
// They now stub `fetch` and answer with the payloads `TrainingReadController`
// and the training command endpoints publish. Each test therefore asserts two
// things: what the screen asked the node, and what it did with the answer.
// Authority is no longer set up by the test at all — it is a field on the
// response, which is the same answer the commands enforce.
//
// No server runs for any of this, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearReadCache } from "@/offline/readCache";
import {
  LOCAL_FIELD_DEPARTMENT_IDS,
} from "@/field-reports/localFieldFixture";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory, type RouteLocationRaw } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";
import {
  selectSessionDepartment,
} from "@/session/sessionAccess";
import { routes } from "@/router";
import DepartmentTrainingDetailView from "@/views/DepartmentTrainingDetailView.vue";
import DepartmentTrainingEditView from "@/views/DepartmentTrainingEditView.vue";
import DepartmentTrainingListView from "@/views/DepartmentTrainingListView.vue";
import HomeView from "@/views/HomeView.vue";

const EVENT_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
const ORGANIZATION_ID = "11111111-1111-4111-8111-111111111111";
const DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;
const TEAM_ID = "77777777-7777-4777-8777-777777777771";
const ORIENTATION_ID = "99999999-9999-4999-8999-999999999901";
const RADIO_ID = "99999999-9999-4999-8999-999999999902";
const ONLINE_ID = "99999999-9999-4999-8999-999999999903";
const CREATED_ID = "99999999-9999-4999-8999-999999999999";
const SHIFT_ID = "55555555-5555-4555-8555-555555555551";
const VERA_STAFF_ID = "33333333-3333-4333-8333-333333333331";
const SAM_STAFF_ID = "33333333-3333-4333-8333-333333333332";

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

/** One training, as `TrainingPayload::training` publishes it. */
function trainingPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: RADIO_ID,
    organization_id: ORGANIZATION_ID,
    department_id: DEPARTMENT_ID,
    team_id: TEAM_ID,
    team_name: "Dirt",
    event_id: null,
    event_name: null,
    name: "Radio Certification",
    description: "Radio discipline and traffic handling.",
    expires_after_days: 365,
    delivery: "in_person",
    online_url: null,
    requires_scheduled_attendance: true,
    scheduled_start_at: "2026-08-20T17:00:00+00:00",
    scheduled_end_at: "2026-08-20T20:00:00+00:00",
    location: "HQ Tent",
    capacity: 2,
    time_commitment: "One three-hour session, renewed annually.",
    after_training:
      "You are cleared for radio-equipped patrol shifts for the season.",
    provisions: "A loaner radio for the session.",
    archived_at: null,
    active_signup_count: 1,
    linked_shift: null,
    unlocked_shifts: [
      {
        id: SHIFT_ID,
        title: "Dirt patrol (Friday night)",
        starts_at: "2026-08-21T22:00:00+00:00",
      },
    ],
    prerequisites: [
      { id: ORIENTATION_ID, name: "Ranger Orientation", viewer_completed: false },
    ],
    viewer: {
      can_manage: true,
      can_record_completions: true,
      is_signed_up: false,
      completion: null,
    },
    ...overrides,
  };
}

function orientationPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return trainingPayload({
    id: ORIENTATION_ID,
    name: "Ranger Orientation",
    team_id: null,
    team_name: null,
    expires_after_days: null,
    capacity: null,
    active_signup_count: 0,
    unlocked_shifts: [],
    prerequisites: [],
    ...overrides,
  });
}

function onlinePayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return trainingPayload({
    id: ONLINE_ID,
    name: "Radio Theory (online)",
    delivery: "online",
    online_url: "https://training.signalcamp.dev/radio-theory",
    requires_scheduled_attendance: false,
    scheduled_start_at: null,
    scheduled_end_at: null,
    location: null,
    capacity: null,
    active_signup_count: 0,
    unlocked_shifts: [],
    prerequisites: [],
    ...overrides,
  });
}

/** The `GET /api/departments/{id}/trainings` envelope, as the index publishes it. */
function workspacePayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    department_id: DEPARTMENT_ID,
    organization_id: ORGANIZATION_ID,
    access: { can_manage: true, can_record_completions: true },
    teams: [
      {
        id: TEAM_ID,
        name: "Dirt",
        is_default: false,
        archived_at: null,
      },
    ],
    department_staff: [
      {
        staff_id: VERA_STAFF_ID,
        display_name: "Vera Staff",
        email: "vera@signalcamp.dev",
      },
      {
        staff_id: SAM_STAFF_ID,
        display_name: "Sam Shiftlead",
        email: "sam@signalcamp.dev",
      },
    ],
    trainings: [orientationPayload(), trainingPayload()],
    ...overrides,
  };
}

/** The `roster` and `completions` a `show` read adds for an authorized trainer. */
function detailPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return trainingPayload({
    roster: [
      {
        staff_id: SAM_STAFF_ID,
        display_name: "Sam Shiftlead",
        email: "sam@signalcamp.dev",
        signed_up_at: "2026-07-05T12:00:00+00:00",
        completed: false,
      },
    ],
    completions: [],
    ...overrides,
  });
}

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

/*
 * Every wrapper this file mounts, so `afterEach` can take them down. These
 * screens watch the route and re-read when it changes; one left mounted keeps
 * reacting into the next test's stub and the next test's recorded calls.
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
  // The home directory and the department label follow the session (M16.6).
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
  to: RouteLocationRaw,
): Promise<{ wrapper: VueWrapper; router: ReturnType<typeof buildRouter> }> {
  const router = buildRouter();

  await router.push(to);
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return { wrapper, router };
}

async function mountList(): Promise<{
  wrapper: VueWrapper;
  router: ReturnType<typeof buildRouter>;
}> {
  return mountAt(DepartmentTrainingListView, {
    name: "events.departments.trainings.index",
    params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID },
  });
}

async function mountEdit(trainingId: string): Promise<VueWrapper> {
  const { wrapper } = await mountAt(DepartmentTrainingEditView, {
    name: "events.departments.trainings.edit",
    params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID, trainingId },
  });

  return wrapper;
}

async function mountDetail(trainingId: string): Promise<VueWrapper> {
  const { wrapper } = await mountAt(DepartmentTrainingDetailView, {
    name: "events.departments.trainings.show",
    params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID, trainingId },
  });

  return wrapper;
}

/** The department training index reads, ignoring the per-training reads. */
function workspaceReads(calls: readonly NodeCall[]): readonly NodeCall[] {
  return calls.filter(
    (call) => call.method === "GET" && call.url.endsWith("/trainings"),
  );
}

function commandCalls(
  calls: readonly NodeCall[],
  command: string,
): readonly NodeCall[] {
  return calls.filter((call) => call.url.endsWith(`/commands/${command}`));
}

describe("product training management", () => {
  it("registers department training routes", () => {
    const names = routes.map((route) => route.name);

    expect(names).toContain("events.departments.trainings.index");
    expect(names).toContain("events.departments.trainings.create");
    expect(names).toContain("events.departments.trainings.edit");
    expect(names).toContain("events.departments.trainings.show");
  });

  it("exposes a trainings entry point from the department home context", async () => {
    stubNode(() => ({ body: workspacePayload() }));
    const { wrapper } = await mountAt(HomeView, { name: "home" });

    expect(wrapper.text()).toContain("Department training schedule, signup");
  });

  it("renders the whole trainings page from one read of the department", async () => {
    const calls = stubNode(() => ({ body: workspacePayload() }));

    const { wrapper } = await mountList();

    // The heading cards and the list are the same answer. Asking twice would let
    // the counts and the rows disagree about which trainings exist.
    expect(workspaceReads(calls)).toHaveLength(1);
    expect(workspaceReads(calls)[0]?.url).toBe(
      `http://node.test/api/departments/${DEPARTMENT_ID}/trainings`,
    );

    expect(wrapper.text()).toContain("Ranger Orientation");
    expect(wrapper.text()).toContain("Radio Certification");
    expect(wrapper.text()).toContain("Annual (365 days)");
    expect(wrapper.text()).toContain("1 of 2 signed up");
    expect(wrapper.text()).toContain("New training");
  });

  it("shows a staff member the trainings the node answered with and no manage actions", async () => {
    stubNode(() => ({
      body: workspacePayload({
        access: { can_manage: false, can_record_completions: false },
        teams: [],
        department_staff: [],
        trainings: [
          trainingPayload({
            viewer: {
              can_manage: false,
              can_record_completions: false,
              is_signed_up: false,
              completion: null,
            },
          }),
        ],
      }),
    }));

    const { wrapper } = await mountList();

    expect(wrapper.text()).toContain("Radio Certification");
    expect(wrapper.text()).not.toContain("New training");
    expect(
      wrapper.findAll("button").some((button) => button.text() === "Archive"),
    ).toBe(false);
  });

  it("signs a staff member up through the command and re-reads", async () => {
    let signedUp = false;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/sign-up-for-training")) {
        signedUp = true;

        return {
          status: 201,
          body: {
            training_id: RADIO_ID,
            staff_id: VERA_STAFF_ID,
            signed_up_at: "2026-07-06T12:00:00+00:00",
            cancelled_at: null,
            active_signup_count: 2,
          },
        };
      }

      if (call.url.endsWith("/commands/cancel-training-signup")) {
        signedUp = false;

        return {
          body: {
            training_id: RADIO_ID,
            staff_id: VERA_STAFF_ID,
            signed_up_at: "2026-07-06T12:00:00+00:00",
            cancelled_at: "2026-07-06T13:00:00+00:00",
            active_signup_count: 1,
          },
        };
      }

      return {
        body: workspacePayload({
          access: { can_manage: false, can_record_completions: false },
          teams: [],
          department_staff: [],
          trainings: [
            trainingPayload({
              active_signup_count: signedUp ? 2 : 1,
              viewer: {
                can_manage: false,
                can_record_completions: false,
                is_signed_up: signedUp,
                completion: null,
              },
            }),
          ],
        }),
      };
    });

    const { wrapper } = await mountList();

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Sign up")!
      .trigger("click");
    await flushPromises();

    // The caller signs themselves up: the node reads the staff profile off the
    // session rather than being told which one to use.
    expect(commandCalls(calls, "sign-up-for-training")[0]?.body).toEqual({
      training_id: RADIO_ID,
    });

    // The row shows the node's answer to a second question, not a local edit.
    expect(workspaceReads(calls)).toHaveLength(2);
    expect(wrapper.text()).toContain("Signed up for Radio Certification.");
    expect(wrapper.text()).toContain("2 of 2 signed up");

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Cancel signup")!
      .trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "cancel-training-signup")[0]?.body).toEqual({
      training_id: RADIO_ID,
    });
    expect(workspaceReads(calls)).toHaveLength(3);
    expect(wrapper.text()).toContain("Signup cancelled for Radio Certification.");
  });

  it("reports a refused signup in the node's words and leaves the row alone", async () => {
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/sign-up-for-training")) {
        return {
          status: 422,
          body: {
            message: "Complete Ranger Orientation before signing up.",
          },
        };
      }

      return {
        body: workspacePayload({
          access: { can_manage: false, can_record_completions: false },
          teams: [],
          department_staff: [],
          trainings: [
            trainingPayload({
              viewer: {
                can_manage: false,
                can_record_completions: false,
                is_signed_up: false,
                completion: null,
              },
            }),
          ],
        }),
      };
    });

    const { wrapper } = await mountList();

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Sign up")!
      .trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain(
      "Complete Ranger Orientation before signing up.",
    );

    // A refusal is not a change, so nothing was re-read and the row still offers
    // to sign up.
    expect(workspaceReads(calls)).toHaveLength(1);
    expect(
      wrapper.findAll("button").some((button) => button.text() === "Sign up"),
    ).toBe(true);
  });

  it("archives and restores a training through the commands and re-reads", async () => {
    let archivedAt: string | null = null;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/archive-training")) {
        archivedAt = "2026-07-07T00:00:00+00:00";

        return { body: trainingPayload({ archived_at: archivedAt }) };
      }

      if (call.url.endsWith("/commands/restore-training")) {
        archivedAt = null;

        return { body: trainingPayload() };
      }

      return {
        body: workspacePayload({
          trainings: [trainingPayload({ archived_at: archivedAt })],
        }),
      };
    });

    const { wrapper } = await mountList();

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Archive")!
      .trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "archive-training")[0]?.body).toEqual({
      training_id: RADIO_ID,
    });
    expect(wrapper.text()).toContain("Radio Certification archived.");
    expect(wrapper.text()).toContain("Archived");

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Restore")!
      .trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "restore-training")[0]?.body).toEqual({
      training_id: RADIO_ID,
    });
    expect(wrapper.text()).toContain("Radio Certification restored.");
  });

  it("says the trainings could not be read when the node is unreachable", async () => {
    stubUnreachableNode();

    const { wrapper } = await mountList();

    expect(wrapper.text()).toContain("Unable to load trainings");
    // A department with no trainings and one that could not be read must not
    // look the same (CLIENT-023).
    expect(wrapper.text()).not.toContain("No trainings yet");
  });

  it("creates a training through the command and follows the node to its edit route", async () => {
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/create-training")) {
        return {
          status: 201,
          body: trainingPayload({
            id: CREATED_ID,
            name: "Vehicle Certification",
            expires_after_days: 180,
            prerequisites: [],
          }),
        };
      }

      if (call.url.includes(`/trainings/${CREATED_ID}`)) {
        return {
          body: detailPayload({
            id: CREATED_ID,
            name: "Vehicle Certification",
            expires_after_days: 180,
            prerequisites: [],
            roster: [],
          }),
        };
      }

      return { body: workspacePayload() };
    });

    const { wrapper, router } = await mountAt(DepartmentTrainingEditView, {
      name: "events.departments.trainings.create",
      params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID },
    });

    await wrapper.get("input[type='text']").setValue("Vehicle Certification");
    await wrapper.findAll("input[type='number']")[0]!.setValue(180);
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    const create = commandCalls(calls, "create-training")[0];

    expect(create?.method).toBe("POST");
    expect(create?.body).toMatchObject({
      department_id: DEPARTMENT_ID,
      name: "Vehicle Certification",
      expires_after_days: 180,
      delivery: "in_person",
    });

    expect(wrapper.text()).toContain("Vehicle Certification saved.");
    expect(router.currentRoute.value.name).toBe(
      "events.departments.trainings.edit",
    );
    expect(router.currentRoute.value.params.trainingId).toBe(CREATED_ID);
  });

  it("brings prerequisites to what the form asked for, one command per change", async () => {
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/update-training")) {
        return { body: trainingPayload() };
      }

      if (call.url.endsWith("/commands/remove-training-prerequisite")) {
        return { body: trainingPayload({ prerequisites: [] }) };
      }

      if (call.url.includes(`/trainings/${RADIO_ID}`)) {
        return { body: detailPayload() };
      }

      return { body: workspacePayload() };
    });

    const wrapper = await mountEdit(RADIO_ID);

    // The form opens on the prerequisite the node answered with.
    const checkbox = wrapper.get(".training-edit__prereq-option input");
    expect((checkbox.element as HTMLInputElement).checked).toBe(true);

    await checkbox.setValue(false);
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(commandCalls(calls, "remove-training-prerequisite")[0]?.body).toEqual({
      training_id: RADIO_ID,
      prerequisite_training_id: ORIENTATION_ID,
    });
    expect(commandCalls(calls, "add-training-prerequisite")).toHaveLength(0);
  });

  it("shows the roster to an authorized trainer and records a completion", async () => {
    let completed = false;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/record-training-completion")) {
        completed = true;

        return {
          status: 201,
          body: {
            training_id: RADIO_ID,
            staff_id: SAM_STAFF_ID,
            completed_at: "2026-07-08T00:00:00+00:00",
            expires_at: "2027-07-08T00:00:00+00:00",
          },
        };
      }

      if (call.url.includes(`/trainings/${RADIO_ID}`)) {
        return {
          body: detailPayload({
            roster: [
              {
                staff_id: SAM_STAFF_ID,
                display_name: "Sam Shiftlead",
                email: "sam@signalcamp.dev",
                signed_up_at: "2026-07-05T12:00:00+00:00",
                completed,
              },
            ],
            completions: completed
              ? [
                  {
                    id: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1",
                    staff_id: SAM_STAFF_ID,
                    display_name: "Sam Shiftlead",
                    email: "sam@signalcamp.dev",
                    completed_at: "2026-07-08T00:00:00+00:00",
                    expires_at: "2027-07-08T00:00:00+00:00",
                    expired: false,
                    recorded_by: "Dana Lead",
                  },
                ]
              : [],
          }),
        };
      }

      return { body: workspacePayload() };
    });

    const wrapper = await mountEdit(RADIO_ID);

    expect(wrapper.text()).toContain("Roster");
    expect(wrapper.text()).toContain("Sam Shiftlead");
    expect(wrapper.text()).toContain("No completions recorded yet.");

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Record completion")!
      .trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "record-training-completion")[0]?.body).toEqual({
      training_id: RADIO_ID,
      staff_id: SAM_STAFF_ID,
    });

    expect(wrapper.text()).toContain("Completion recorded for Sam Shiftlead.");
    // The completion table is the node's answer to a second read.
    expect(wrapper.text()).toContain("Dana Lead");
  });

  it("adds and removes a roster member through the signup commands", async () => {
    const calls = stubNode((call) => {
      if (
        call.url.endsWith("/commands/sign-up-for-training") ||
        call.url.endsWith("/commands/cancel-training-signup")
      ) {
        return {
          body: {
            training_id: RADIO_ID,
            staff_id: VERA_STAFF_ID,
            signed_up_at: "2026-07-09T00:00:00+00:00",
            cancelled_at: null,
            active_signup_count: 2,
          },
        };
      }

      if (call.url.includes(`/trainings/${RADIO_ID}`)) {
        return { body: detailPayload() };
      }

      return { body: workspacePayload() };
    });

    const wrapper = await mountEdit(RADIO_ID);

    await wrapper.get(".training-edit__inline-form select").setValue(VERA_STAFF_ID);
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Add to roster")!
      .trigger("click");
    await flushPromises();

    // Naming a staff member is roster maintenance, which the node allows only to
    // an authorized trainer or lead.
    expect(commandCalls(calls, "sign-up-for-training")[0]?.body).toEqual({
      training_id: RADIO_ID,
      staff_id: VERA_STAFF_ID,
    });
    expect(wrapper.text()).toContain("Vera Staff added to the roster.");

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Remove")!
      .trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "cancel-training-signup")[0]?.body).toEqual({
      training_id: RADIO_ID,
      staff_id: SAM_STAFF_ID,
    });
    expect(wrapper.text()).toContain("Sam Shiftlead removed from the roster.");
  });

  it("hands the completion spreadsheet to the node and shows its per-row findings", async () => {
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/import-training-completions")) {
        return {
          body: {
            imported: 1,
            skipped: 1,
            rows: [
              {
                line: 2,
                email: "vera@signalcamp.dev",
                status: "imported",
                reason: null,
              },
              {
                line: 3,
                email: "missing@nowhere.dev",
                status: "skipped",
                reason: "No staff record with this email.",
              },
            ],
          },
        };
      }

      if (call.url.includes(`/trainings/${ORIENTATION_ID}`)) {
        return { body: detailPayload({ id: ORIENTATION_ID, roster: [] }) };
      }

      return { body: workspacePayload() };
    });

    const wrapper = await mountEdit(ORIENTATION_ID);

    const csv =
      "email,completed_at\nvera@signalcamp.dev,2026-07-01\nmissing@nowhere.dev,2026-07-01";

    await wrapper.get("textarea[aria-label='Completion CSV']").setValue(csv);
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Import completions")!
      .trigger("click");
    await flushPromises();

    // The CSV is parsed by the node, not the client: which column is the email
    // and which row named nobody are its findings (TRAIN-006).
    expect(commandCalls(calls, "import-training-completions")[0]?.body).toEqual({
      training_id: ORIENTATION_ID,
      csv,
    });

    expect(wrapper.text()).toContain("Imported 1 completion(s); skipped 1.");
    expect(wrapper.text()).toContain("No staff record with this email.");
  });

  it("says a training's details are not editable without department authority", async () => {
    stubNode((call) => {
      if (call.url.includes(`/trainings/${RADIO_ID}`)) {
        return {
          body: detailPayload({
            viewer: {
              can_manage: false,
              can_record_completions: true,
              is_signed_up: false,
              completion: null,
            },
          }),
        };
      }

      return {
        body: workspacePayload({
          access: { can_manage: false, can_record_completions: true },
          teams: [],
        }),
      };
    });

    const wrapper = await mountEdit(RADIO_ID);

    expect(wrapper.text()).toContain(
      "not editable without department authority",
    );
    expect(wrapper.find("form").exists()).toBe(false);
    // A team lead is still an authorized trainer for their own team's trainings.
    expect(wrapper.text()).toContain("Sam Shiftlead");
  });

  it("shows the training webpage from one read, including what completion unlocks", async () => {
    const calls = stubNode(() => ({ body: detailPayload() }));

    const wrapper = await mountDetail(RADIO_ID);

    expect(calls).toHaveLength(1);
    expect(calls[0]?.url).toBe(
      `http://node.test/api/departments/${DEPARTMENT_ID}/trainings/${RADIO_ID}`,
    );

    expect(wrapper.text()).toContain("Radio Certification");
    expect(wrapper.text()).toContain("In person");
    expect(wrapper.text()).toContain("HQ Tent");
    expect(wrapper.text()).toContain("One three-hour session, renewed annually.");
    expect(wrapper.text()).toContain("After this training");
    expect(wrapper.text()).toContain("radio-equipped patrol shifts");
    expect(wrapper.text()).toContain("Shifts this training unlocks");
    expect(wrapper.text()).toContain("Dirt patrol (Friday night)");
    // The node says which prerequisites this reader has already done (TRAIN-010).
    expect(wrapper.text()).toContain("Ranger Orientation — not completed yet");
  });

  it("shows an online training with its URL and no signup", async () => {
    stubNode(() => ({ body: onlinePayload({ roster: [], completions: [] }) }));

    const wrapper = await mountDetail(ONLINE_ID);

    expect(wrapper.text()).toContain("Online");
    expect(wrapper.text()).toContain(
      "https://training.signalcamp.dev/radio-theory",
    );
    expect(wrapper.text()).toContain("No signup is needed");
    expect(
      wrapper.findAll("button").some((button) => button.text() === "Sign up"),
    ).toBe(false);
  });

  it("reports a training that is not this department's as the node worded it", async () => {
    stubNode(() => ({
      status: 404,
      body: { message: "Training not found for this department." },
    }));

    const wrapper = await mountDetail(RADIO_ID);

    expect(wrapper.text()).toContain("Training not found for this department.");
    expect(wrapper.text()).not.toContain("Radio Certification");
  });
});
