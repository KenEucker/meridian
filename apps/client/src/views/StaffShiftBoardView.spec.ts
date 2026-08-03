// The staff shift board against a stubbed node (M18.2; SHIFT-011 through
// SHIFT-015, SHIFT-018; requirements 3.12, 5.5; CLIENT-005, CLIENT-006,
// CLIENT-018, CLIENT-023, CLIENT-024).
//
// What is under test is the seam, not the rules. The node decides whether a
// shift will take somebody and says why when it will not; this surface has to
// render that answer without inventing one of its own, offer the action the node
// said was available, and refuse where a connected-only command cannot be sent.
//
// No server runs for any of it, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearReadCache } from "@/offline/readCache";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  installLocalFieldSession,
  LOCAL_FIELD_FIXTURE,
  localFieldSessionDocument,
} from "@/session/localFieldSessionFixture";
import { routes } from "@/router";
import { clearClientSession, installClientSession } from "@/session/clientSession";
import { resetSelectedSessionDepartment } from "@/session/sessionAccess";
import StaffShiftBoardView from "@/views/StaffShiftBoardView.vue";

const EVENT_ID = LOCAL_FIELD_FIXTURE.eventId;
const OPEN_SHIFT_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
const HELD_SHIFT_ID = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb";
const CLOSED_SHIFT_ID = "cccccccc-cccc-4ccc-8ccc-cccccccccccc";

/** One request this client made, as the assertions read it. */
interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
}

function shiftPayload(overrides: Record<string, unknown> = {}) {
  return {
    id: OPEN_SHIFT_ID,
    department_id: "dept-1",
    department_name: "Gate",
    eligible_team_id: "team-1",
    eligible_team_name: "Credentials",
    title: "Gate Swing",
    starts_at: "2027-07-05T08:00:00+00:00",
    ends_at: "2027-07-05T14:00:00+00:00",
    capacity: 4,
    active_assignment_count: 1,
    signup_opens_at: null,
    signup_closes_at: null,
    schedule_lock_at: null,
    cancelled_at: null,
    required_training_names: [],
    required_waiver_names: [],
    signed_up: false,
    assignment_status: null,
    can_sign_up: true,
    can_withdraw: false,
    schedule_locked: false,
    unavailable_reason: null,
    unavailable_reason_code: null,
    overlap_warnings: [],
    ...overrides,
  };
}

/**
 * Stub the node.
 *
 * `reply` is asked once per request, so a test can answer a read differently
 * after a command has been sent — which is how "the surface re-reads after a
 * write" is observed rather than asserted about.
 */
function stubNode(
  reply: (call: NodeCall) => { readonly status?: number; readonly body: unknown },
): readonly NodeCall[] {
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

/**
 * Drive the device-network signal.
 *
 * The event matters as much as the property: connectivity only moves on
 * `online`/`offline`, so a test that drops the network and does not put it back
 * leaves every later test offline.
 */
function setNavigatorOnline(onLine: boolean): void {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value: onLine,
  });

  window.dispatchEvent(new Event(onLine ? "online" : "offline"));
}

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
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
  setNavigatorOnline(true);
});

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  configureMeridianApi(null);
  resetSelectedSessionDepartment();
  clearClientSession();
  setNavigatorOnline(true);
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

async function mountView(): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({ name: "staff.shifts.index" });
  await router.isReady();

  const wrapper = mount(StaffShiftBoardView, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return wrapper;
}

function buttonLabelled(wrapper: VueWrapper, label: string) {
  return wrapper.findAll("button").find((button) => button.text() === label);
}

describe("the staff shift board", () => {
  it("reads the board for the session's event and offers the shifts the node offered", async () => {
    const calls = stubNode(() => ({
      body: {
        event: { id: EVENT_ID, name: "Local Field Event" },
        shifts: [shiftPayload()],
      },
    }));

    const wrapper = await mountView();

    expect(calls).toHaveLength(1);
    expect(calls[0]?.url).toBe(
      `http://node.test/api/events/${EVENT_ID}/shift-board`,
    );
    expect(calls[0]?.method).toBe("GET");

    const text = wrapper.text();
    expect(text).toContain("Gate Swing");
    expect(text).toContain("Gate");
    expect(text).toContain("Credentials");
    expect(text).toContain("1 of 4 signed up");
    expect(text).toContain("Open");
    expect(buttonLabelled(wrapper, "Sign up")).toBeDefined();
    expect(buttonLabelled(wrapper, "Withdraw")).toBeUndefined();
  });

  it("signs up through the command and re-reads the board", async () => {
    let signedUp = false;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/sign-up-for-shift")) {
        signedUp = true;

        return { status: 201, body: { warnings: [] } };
      }

      return {
        body: {
          event: { id: EVENT_ID, name: "Local Field Event" },
          shifts: [
            shiftPayload(
              signedUp
                ? {
                    signed_up: true,
                    assignment_status: "signed_up",
                    can_sign_up: false,
                    can_withdraw: true,
                    active_assignment_count: 2,
                  }
                : {},
            ),
          ],
        },
      };
    });

    const wrapper = await mountView();

    await buttonLabelled(wrapper, "Sign up")!.trigger("click");
    await flushPromises();

    const command = calls.find((call) =>
      call.url.endsWith("/commands/sign-up-for-shift"),
    );
    expect(command?.method).toBe("POST");
    expect(command?.body).toEqual({ shift_id: OPEN_SHIFT_ID });

    // The re-read is what makes the row honest afterwards: capacity and the
    // available action are the node's answers, not ones this page kept.
    expect(calls.filter((call) => call.method === "GET")).toHaveLength(2);
    expect(wrapper.text()).toContain("Signed up");
    expect(wrapper.text()).toContain("2 of 4 signed up");
    expect(buttonLabelled(wrapper, "Withdraw")).toBeDefined();
    expect(buttonLabelled(wrapper, "Sign up")).toBeUndefined();
  });

  it("withdraws from a shift it holds", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/withdraw-from-shift")
        ? { body: {} }
        : {
            body: {
              event: { id: EVENT_ID, name: "Local Field Event" },
              shifts: [
                shiftPayload({
                  id: HELD_SHIFT_ID,
                  signed_up: true,
                  assignment_status: "signed_up",
                  can_sign_up: false,
                  can_withdraw: true,
                }),
              ],
            },
          },
    );

    const wrapper = await mountView();

    await buttonLabelled(wrapper, "Withdraw")!.trigger("click");
    await flushPromises();

    const command = calls.find((call) =>
      call.url.endsWith("/commands/withdraw-from-shift"),
    );
    expect(command?.method).toBe("POST");
    expect(command?.body).toEqual({ shift_id: HELD_SHIFT_ID });
  });

  it("prints the node's reason for every shift it will not take, and offers no button", async () => {
    // One row per reason in requirements 3.12, as the node words them
    // (SHIFT-018). The surface writes none of these sentences.
    const denials = [
      ["cancelled_shift", "Cancelled shifts do not accept signup."],
      ["signup_closed", "Shift signup is not currently open."],
      ["schedule_locked", "The schedule is locked and cannot be changed."],
      [
        "not_eligible_team_member",
        "Staff must belong to the shift eligible team before signup.",
      ],
      [
        "no_department_membership",
        "Staff must belong to the shift department before signup.",
      ],
      ["do_not_staff", "Do Not Staff records cannot sign up for shifts."],
      [
        "department_ineligible",
        "Ineligible department status prevents shift signup.",
      ],
      [
        "missing_required_training",
        "Required training must be complete before shift signup.",
      ],
      [
        "missing_required_waiver",
        "Required waiver must be complete before shift signup.",
      ],
      [
        "shift_full",
        "This shift is full and cannot accept additional signup.",
      ],
    ] as const;

    stubNode(() => ({
      body: {
        event: { id: EVENT_ID, name: "Local Field Event" },
        shifts: denials.map(([code, message], index) =>
          shiftPayload({
            id: `${CLOSED_SHIFT_ID.slice(0, -1)}${index}`,
            title: `Shift ${code}`,
            can_sign_up: false,
            unavailable_reason_code: code,
            unavailable_reason: message,
          }),
        ),
      },
    }));

    const wrapper = await mountView();
    const text = wrapper.text();

    for (const [code, message] of denials) {
      expect(text).toContain(message);
      expect(
        wrapper.find(`[data-reason-code="${code}"]`).exists(),
      ).toBe(true);
    }

    // Absent, not disabled: a control that could only ever be refused is not an
    // offer (CLIENT-005), and the reason above already says what happened.
    expect(buttonLabelled(wrapper, "Sign up")).toBeUndefined();
    expect(wrapper.text()).toContain("Unavailable");
  });

  it("shows an overlap as a warning on a shift it still offers", async () => {
    stubNode(() => ({
      body: {
        event: { id: EVENT_ID, name: "Local Field Event" },
        shifts: [
          shiftPayload({
            overlap_warnings: [
              {
                code: "schedule_overlap",
                message:
                  "This shift overlaps with Gate Swing (2027-07-05 08:00:00 – 2027-07-05 14:00:00).",
              },
            ],
          }),
        ],
      },
    }));

    const wrapper = await mountView();

    // SHIFT-014: warned, not blocked. The advisory is on screen and the shift
    // is still takeable.
    expect(wrapper.text()).toContain("This shift overlaps with Gate Swing");
    expect(buttonLabelled(wrapper, "Sign up")).toBeDefined();
  });

  it("reports the warning that came back with a signup that succeeded", async () => {
    stubNode((call) =>
      call.url.endsWith("/commands/sign-up-for-shift")
        ? {
            status: 201,
            body: {
              warnings: [
                {
                  code: "schedule_overlap",
                  message: "This shift overlaps with Dirt Patrol.",
                },
              ],
            },
          }
        : {
            body: {
              event: { id: EVENT_ID, name: "Local Field Event" },
              shifts: [shiftPayload()],
            },
          },
    );

    const wrapper = await mountView();

    await buttonLabelled(wrapper, "Sign up")!.trigger("click");
    await flushPromises();

    expect(wrapper.find('[role="status"]').text()).toContain(
      "This shift overlaps with Dirt Patrol.",
    );
  });

  it("prints the node's refusal when a signup is rejected", async () => {
    stubNode((call) =>
      call.url.endsWith("/commands/sign-up-for-shift")
        ? {
            status: 422,
            body: {
              message: "This shift is full and cannot accept additional signup.",
              reason_code: "shift_full",
            },
          }
        : {
            body: {
              event: { id: EVENT_ID, name: "Local Field Event" },
              shifts: [shiftPayload()],
            },
          },
    );

    const wrapper = await mountView();

    await buttonLabelled(wrapper, "Sign up")!.trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain(
      "This shift is full and cannot accept additional signup.",
    );
  });

  it("refuses to send while the device has no network rather than queueing it", async () => {
    const calls = stubNode(() => ({
      body: {
        event: { id: EVENT_ID, name: "Local Field Event" },
        shifts: [shiftPayload()],
      },
    }));

    const wrapper = await mountView();

    setNavigatorOnline(false);
    await flushPromises();

    expect(wrapper.text()).toContain(
      "Signing up and withdrawing need a connection to the node.",
    );
    expect(buttonLabelled(wrapper, "Sign up")!.attributes("disabled")).toBeDefined();

    await buttonLabelled(wrapper, "Sign up")!.trigger("click");
    await flushPromises();

    // Signup is not one of the Alpha 1 offline writes (data/API 7.2), so
    // nothing left this device and nothing was held (CLIENT-018).
    expect(calls.filter((call) => call.method === "POST")).toHaveLength(0);
  });

  it("states that the board needs an event rather than rendering an empty one", async () => {
    installClientSession(
      localFieldSessionDocument({
        context: {
          organization_id: "88888888-8888-4888-8888-888888888888",
          event_id: null,
          department_id: null,
          node_locked: false,
          node_locked_event_id: null,
          switching_available: true,
        },
      }),
      "network",
    );

    const calls = stubNode(() => ({ body: { shifts: [] } }));
    const wrapper = await mountView();

    expect(calls).toHaveLength(0);
    expect(wrapper.text()).toContain(
      "The shift board opens once this device is working in an event",
    );
  });

  it("says the read failed rather than showing an event with no shifts", async () => {
    stubNode(() => ({
      status: 500,
      body: { message: "Something went wrong." },
    }));

    const wrapper = await mountView();

    expect(wrapper.find('[role="alert"]').text()).toContain(
      "Something went wrong.",
    );
    expect(wrapper.text()).not.toContain(
      "No shifts are scheduled for your departments",
    );
  });

  /*
   * Technical spec 9.3: "The device should cache as much authorized data as
   * possible. Offline data may be stale, but stale authorized data is better
   * than no data." A staff member's own shifts are the first thing on that
   * list, and a board that answers "check the connection to this node" is the
   * failure the rule is written against (M18.9).
   */
  it("renders the shifts it stored when the node cannot be reached", async () => {
    stubNode(() => ({
      body: {
        event: { id: EVENT_ID, name: "Local Field Event" },
        shifts: [shiftPayload()],
      },
    }));
    await mountView();

    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const wrapper = await mountView();

    expect(wrapper.find(".shift-board__error").exists()).toBe(false);
    expect(wrapper.text()).toContain("Gate Swing");
    expect(wrapper.get(".stale-read").text()).toContain(
      "This node could not be reached",
    );
  });

  it("states an unreachable node when the device stored no board", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const wrapper = await mountView();

    expect(wrapper.get(".shift-board__error").text()).toContain(
      "Unable to load the shift board",
    );
  });
});
