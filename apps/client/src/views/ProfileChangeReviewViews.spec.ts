// The two profile change request surfaces against a stubbed node (M18.20D;
// VOL-019 through VOL-022, VOL-024, VOL-025, VOL-029; UI contract 12.3, 12.6).
//
// The reviewer's queue and the submitter's own view are tested together because
// they are two ends of one exchange: a rejection typed on one has to be the
// sentence read on the other, and asserting that in one file is what keeps the
// two from drifting apart.
//
// Each test asserts what the surface asked the node and what it did with the
// answer. The scope rule itself is the node's — `GET /api/staff-profile-change-requests`
// answers only with the organizations the caller reviews for — so what is
// asserted here is that the client renders that answer rather than filtering a
// wider one, and that a caller who reviews nowhere is told so rather than shown
// an empty table.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { clearReadCache } from "@/offline/readCache";
import { routes } from "@/router";
import OrganizerProfileChangeRequestsView from "@/views/OrganizerProfileChangeRequestsView.vue";
import StaffProfileRequestsView from "@/views/StaffProfileRequestsView.vue";

const STAFF_ID = "33333333-3333-4333-8333-333333333331";
const ORGANIZATION_ID = "11111111-1111-4111-8111-111111111111";
const QUEUE_URL = "http://node.test/api/staff-profile-change-requests";

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

/** A queue row, as `StaffProfileChangeRequestController::index` publishes it. */
function handleRow(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: "req-handle-1",
    kind: "handle",
    status: "pending",
    organization_id: ORGANIZATION_ID,
    organization_name: "Emberfall Collective",
    staff_id: STAFF_ID,
    staff_name: "vera-radio",
    previous_handle: "vera-radio",
    requested_handle: "dispatch",
    handle_collisions: [],
    current_picture_url: null,
    submitted_picture_url: null,
    created_at: "2026-08-04T18:00:00+00:00",
    ...overrides,
  };
}

function pictureRow(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: "req-picture-1",
    kind: "profile_picture",
    status: "pending",
    organization_id: ORGANIZATION_ID,
    organization_name: "Emberfall Collective",
    staff_id: STAFF_ID,
    staff_name: "vera-radio",
    previous_handle: null,
    requested_handle: null,
    handle_collisions: [],
    current_picture_url: "http://node.test/storage/avatars/current.webp",
    submitted_picture_url: "http://node.test/pending/submitted.webp",
    created_at: "2026-08-04T18:05:00+00:00",
    ...overrides,
  };
}

/** The `GET /api/me/profile` answer the submitter's own surface reads. */
function profilePayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: STAFF_ID,
    legal_name: "Vera Example",
    preferred_name: "Vera",
    handle: "vera-radio",
    email: "vera@example.test",
    phone: null,
    city: null,
    state: null,
    date_of_birth: null,
    profile_picture_url: null,
    self_editable_fields: ["preferred_name", "phone", "city", "state"],
    can_submit_picture: true,
    remaining_self_service_handle_changes: 0,
    handle_change_policy: "organizer_only",
    profile_picture_change_policy: "organizer_only",
    latest_handle_request: null,
    latest_picture_request: null,
    ...overrides,
  };
}

const mounted: VueWrapper[] = [];

beforeEach(() => {
  clearReadCache();
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

async function mountView(
  component: unknown,
  routeName: string,
): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({ name: routeName });
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return wrapper;
}

function buttonNamed(wrapper: VueWrapper, label: string) {
  return wrapper.findAll("button").find((button) => button.text() === label);
}

describe("organizer profile change request review", () => {
  it("shows the previous and requested handle side by side", async () => {
    const calls = stubNode(() => ({
      body: { organization_ids: [ORGANIZATION_ID], requests: [handleRow()] },
    }));

    const wrapper = await mountView(
      OrganizerProfileChangeRequestsView,
      "organizer.profile-change-requests.index",
    );

    expect(calls.filter((call) => call.url === QUEUE_URL)).toHaveLength(1);

    const pair = wrapper.get("[data-testid='handle-pair']");

    expect(pair.text()).toContain("vera-radio");
    expect(pair.text()).toContain("dispatch");
    expect(wrapper.text()).toContain("Emberfall Collective");
  });

  it("shows the current and submitted picture side by side", async () => {
    stubNode(() => ({
      body: { organization_ids: [ORGANIZATION_ID], requests: [pictureRow()] },
    }));

    const wrapper = await mountView(
      OrganizerProfileChangeRequestsView,
      "organizer.profile-change-requests.index",
    );

    const sources = wrapper
      .get("[data-testid='picture-pair']")
      .findAll("img")
      .map((img) => img.attributes("src"));

    expect(sources).toEqual([
      "http://node.test/storage/avatars/current.webp",
      "http://node.test/pending/submitted.webp",
    ]);
    expect(wrapper.text()).toContain("On the record now");
    expect(wrapper.text()).toContain("Submitted");
  });

  /*
   * VOL-020. The collision is information for the reviewer, not a rule Meridian
   * applies: two people may legitimately be told apart by their departments, so
   * both decisions stay available with the names on screen.
   */
  it("names a handle collision to the reviewer and still allows the decision", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/approve-profile-change-request")
        ? {
            body: {
              request: {
                id: "req-handle-1",
                kind: "handle",
                status: "approved",
                decision_reason: null,
                decided_at: "2026-08-04T19:00:00+00:00",
              },
            },
          }
        : {
            body: {
              organization_ids: [ORGANIZATION_ID],
              requests: [
                handleRow({ handle_collisions: ["Dana Departmentlead"] }),
              ],
            },
          },
    );

    const wrapper = await mountView(
      OrganizerProfileChangeRequestsView,
      "organizer.profile-change-requests.index",
    );

    const collision = wrapper.get("[data-testid='handle-collision']");

    expect(collision.text()).toContain("Dana Departmentlead");

    const approve = buttonNamed(wrapper, "Approve");

    expect(approve?.attributes("disabled")).toBeUndefined();

    await approve!.trigger("click");
    await flushPromises();

    const command = calls.find((call) =>
      call.url.endsWith("/commands/approve-profile-change-request"),
    );

    expect(command?.method).toBe("POST");
    expect(command?.body).toMatchObject({ request_id: "req-handle-1" });
  });

  /*
   * VOL-025. A rejection the submitter is told about has to say something, so
   * the control is unavailable until it does — the node requires the reason too,
   * and this keeps a reviewer from meeting that refusal after they thought they
   * had decided.
   */
  it("refuses to reject without a reason and sends the reason once given", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/reject-profile-change-request")
        ? {
            body: {
              request: {
                id: "req-handle-1",
                kind: "handle",
                status: "rejected",
                decision_reason: "Dispatch is the desk, not a person.",
                decided_at: "2026-08-04T19:00:00+00:00",
              },
            },
          }
        : {
            body: {
              organization_ids: [ORGANIZATION_ID],
              requests: [handleRow()],
            },
          },
    );

    const wrapper = await mountView(
      OrganizerProfileChangeRequestsView,
      "organizer.profile-change-requests.index",
    );

    expect(buttonNamed(wrapper, "Reject")?.attributes("disabled")).toBeDefined();

    await wrapper.get("textarea").setValue("Dispatch is the desk, not a person.");

    const reject = buttonNamed(wrapper, "Reject");

    expect(reject?.attributes("disabled")).toBeUndefined();

    await reject!.trigger("click");
    await flushPromises();

    const command = calls.find((call) =>
      call.url.endsWith("/commands/reject-profile-change-request"),
    );

    expect(command?.body).toMatchObject({
      request_id: "req-handle-1",
      reason: "Dispatch is the desk, not a person.",
    });
  });

  /*
   * VOL-019 on the client side. The node narrows the queue to the organizations
   * the caller holds the capability in and refuses a caller holding it nowhere;
   * that refusal is a different answer from a failed read and is shown as one.
   */
  it("renders only the requests the node returned, without asking for more", async () => {
    const calls = stubNode(() => ({
      body: {
        organization_ids: [ORGANIZATION_ID],
        requests: [handleRow()],
      },
    }));

    const wrapper = await mountView(
      OrganizerProfileChangeRequestsView,
      "organizer.profile-change-requests.index",
    );

    // One read, no per-organization fan-out: the scope is the node's answer.
    expect(calls).toHaveLength(1);
    expect(wrapper.findAll(".review__request")).toHaveLength(1);
  });

  it("states the refusal when the caller reviews for no organization", async () => {
    stubNode(() => ({
      status: 403,
      body: {
        message: "You do not review profile change requests for any organization.",
      },
    }));

    const wrapper = await mountView(
      OrganizerProfileChangeRequestsView,
      "organizer.profile-change-requests.index",
    );

    expect(wrapper.text()).toContain(
      "requires organizer or Staff Coordinator authority",
    );
    expect(buttonNamed(wrapper, "Try again")).toBeUndefined();
  });

  it("says plainly when nothing is waiting", async () => {
    stubNode(() => ({ body: { organization_ids: [ORGANIZATION_ID], requests: [] } }));

    const wrapper = await mountView(
      OrganizerProfileChangeRequestsView,
      "organizer.profile-change-requests.index",
    );

    expect(wrapper.text()).toContain("Nothing is waiting on a decision.");
  });
});

describe("staff profile requests", () => {
  /*
   * VOL-025 from the other end: the reason typed above is the sentence read
   * here. Without it a staff member submits something, nothing visibly
   * happens, and they have no way to learn why.
   */
  it("shows a rejection and the reviewer's reason", async () => {
    stubNode(() => ({
      body: {
        profiles: [
          profilePayload({
            latest_handle_request: {
              id: "req-handle-1",
              kind: "handle",
              status: "rejected",
              previous_handle: "vera-radio",
              requested_handle: "dispatch",
              self_service: false,
              decision_reason: "Dispatch is the desk, not a person.",
              decided_at: "2026-08-04T19:00:00+00:00",
            },
          }),
        ],
      },
    }));

    const wrapper = await mountView(
      StaffProfileRequestsView,
      "staff.profile.requests",
    );

    expect(wrapper.text()).toContain("Not approved");
    expect(wrapper.text()).toContain("vera-radio → dispatch");
    expect(wrapper.get("[data-testid='decision-reason']").text()).toBe(
      "Dispatch is the desk, not a person.",
    );
  });

  it("offers Withdraw while a request is pending and Clear once it is decided", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/withdraw-profile-change-request")
        ? { body: { request: { id: "req-picture-1", status: "withdrawn" } } }
        : {
            body: {
              profiles: [
                profilePayload({
                  latest_picture_request: {
                    id: "req-picture-1",
                    kind: "profile_picture",
                    status: "pending",
                    previous_handle: null,
                    requested_handle: null,
                    self_service: false,
                    decision_reason: null,
                    decided_at: null,
                    submitted_picture_url: "http://node.test/pending/submitted.webp",
                  },
                }),
              ],
            },
          },
    );

    const wrapper = await mountView(
      StaffProfileRequestsView,
      "staff.profile.requests",
    );

    expect(wrapper.text()).toContain("Waiting for review");
    expect(buttonNamed(wrapper, "Clear")).toBeUndefined();

    await buttonNamed(wrapper, "Withdraw")!.trigger("click");
    await flushPromises();

    const command = calls.find((call) =>
      call.url.endsWith("/commands/withdraw-profile-change-request"),
    );

    expect(command?.body).toMatchObject({ request_id: "req-picture-1" });
  });

  /*
   * VOL-029. Clearing removes the notice and nothing else — the row stays on
   * the node because it is audit history and one of the facts the VOL-018
   * allowance is counted from.
   */
  it("clears a decided request through dismiss rather than withdraw", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/dismiss-profile-change-request")
        ? { body: { request: { id: "req-handle-2", status: "approved" } } }
        : {
            body: {
              profiles: [
                profilePayload({
                  latest_handle_request: {
                    id: "req-handle-2",
                    kind: "handle",
                    status: "approved",
                    previous_handle: "vera-radio",
                    requested_handle: "vera-two",
                    self_service: false,
                    decision_reason: null,
                    decided_at: "2026-08-04T19:00:00+00:00",
                  },
                }),
              ],
            },
          },
    );

    const wrapper = await mountView(
      StaffProfileRequestsView,
      "staff.profile.requests",
    );

    expect(buttonNamed(wrapper, "Withdraw")).toBeUndefined();

    await buttonNamed(wrapper, "Clear")!.trigger("click");
    await flushPromises();

    const command = calls.find((call) =>
      call.url.endsWith("/commands/dismiss-profile-change-request"),
    );

    expect(command?.body).toMatchObject({ request_id: "req-handle-2" });
  });

  /*
   * VOL-017, VOL-028: the number is what somebody came here to check before
   * spending one, so it is on the page whether or not anything is outstanding.
   */
  it("states the remaining self-service handle changes", async () => {
    stubNode(() => ({
      body: {
        profiles: [
          profilePayload({
            handle_change_policy: "auto_approved",
            remaining_self_service_handle_changes: 1,
          }),
        ],
      },
    }));

    const wrapper = await mountView(
      StaffProfileRequestsView,
      "staff.profile.requests",
    );

    expect(wrapper.text()).toContain(
      "1 handle change takes effect immediately",
    );
    expect(wrapper.text()).toContain("You have not submitted a handle change");
  });

  it("says handle changes are reviewed when no allowance applies", async () => {
    stubNode(() => ({ body: { profiles: [profilePayload()] } }));

    const wrapper = await mountView(
      StaffProfileRequestsView,
      "staff.profile.requests",
    );

    expect(wrapper.text()).toContain(
      "Handle changes are reviewed before they take effect.",
    );
  });

  it("says plainly when the login speaks for no staff record", async () => {
    stubNode(() => ({ body: { profiles: [] } }));

    const wrapper = await mountView(
      StaffProfileRequestsView,
      "staff.profile.requests",
    );

    expect(wrapper.text()).toContain("not linked to a staff profile");
  });
});
