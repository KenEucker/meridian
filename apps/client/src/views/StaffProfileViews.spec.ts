// The staff profile surface against a stubbed node (M18.20; VOL-009, VOL-014
// through VOL-016; data/API 10.4; UI contract 12.3; CLIENT-024).
//
// Each test asserts two things: what the screen asked the node, and what it
// did with the answer. The boundary under test is VOL-015/VOL-016's — the form
// writes preferred name, phone, and city/state and nothing else, the identity
// fields are on the page without an input, and a node refusal is shown in the
// node's own words rather than restated.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { routes } from "@/router";
import MeView from "@/views/MeView.vue";
import StaffProfileEditView from "@/views/StaffProfileEditView.vue";

const STAFF_ID = "33333333-3333-4333-8333-333333333331";

interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
  /** Set instead of `body` when the request was a multipart upload. */
  readonly formData: FormData | null;
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
        formData: init?.body instanceof FormData ? init.body : null,
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

/** The `GET /api/me/profile` answer, as `MyProfileController` publishes it. */
function profilePayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: STAFF_ID,
    legal_name: "Vera Example",
    preferred_name: "Vera",
    handle: "vera-radio",
    formerly_known_as: null,
    email: "vera@example.test",
    phone: "555-0100",
    city: "Portland",
    state: "OR",
    date_of_birth: "1990-04-01",
    emergency_contact_name: "Casey Contact",
    emergency_contact_phone: "555-0111",
    profile_picture_url: null,
    self_editable_fields: ["preferred_name", "phone", "city", "state"],
    can_submit_picture: true,
    remaining_self_service_handle_changes: 2,
    handle_change_policy: "organizer_only",
    profile_picture_change_policy: "organizer_only",
    latest_handle_request: null,
    latest_picture_request: null,
    ...overrides,
  };
}

/** A pending picture submission, as the profile read carries it (VOL-021). */
function pendingPicturePayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: "req-picture-1",
    kind: "profile_picture",
    status: "pending",
    previous_handle: null,
    requested_handle: null,
    self_service: false,
    decision_reason: null,
    decided_at: null,
    created_at: "2026-08-04T18:00:00+00:00",
    submitted_picture_url: null,
    ...overrides,
  };
}

const mounted: VueWrapper[] = [];

beforeEach(() => {
  clearOfflineReadSet();
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

async function mountView(component: unknown, routeName: string): Promise<VueWrapper> {
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

describe("staff profile edit", () => {
  it("renders the self-service fields as inputs and the identity fields without any", async () => {
    const calls = stubNode(() => ({ body: { profiles: [profilePayload()] } }));

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    expect(
      calls.filter((call) => call.url === "http://node.test/api/me/profile"),
    ).toHaveLength(1);

    // The VOL-015 set, prefilled from the record. Scoped to the fieldset that
    // saves with the form: the handle beside it is its own path with its own
    // rules (M18.20B), not a fifth field of this one.
    const inputValues = wrapper
      .get("fieldset")
      .findAll("input")
      .map((input) => (input.element as HTMLInputElement).value);

    expect(inputValues).toEqual(["Vera", "555-0100", "Portland", "OR"]);

    // The VOL-016 set is present to read and absent to edit; the page names
    // the assisted path instead.
    expect(wrapper.text()).toContain("Vera Example");
    expect(wrapper.text()).toContain("vera@example.test");
    expect(wrapper.text()).toContain("1990-04-01");
    expect(inputValues).not.toContain("Vera Example");
    expect(inputValues).not.toContain("vera@example.test");
    expect(wrapper.text()).toContain("Ask an organizer");

    // The handle is editable, but through its own path rather than this form
    // (M18.20B): it sits outside the fieldset, prefilled from the record.
    const handleInput = wrapper
      .findAll("input[type='text']")
      .find((input) => (input.element as HTMLInputElement).value === "vera-radio");

    expect(handleInput).toBeTruthy();

    // No picture on record is stated as a state rather than decorated, and an
    // active staff member is offered the uploader (VOL-013, VOL-021).
    expect(wrapper.find(".profile-edit__picture-none").exists()).toBe(true);
    expect(wrapper.find("input[type='file']").exists()).toBe(true);
  });

  it("shows the picture on record when the staff member has one", async () => {
    stubNode(() => ({
      body: {
        profiles: [
          profilePayload({
            profile_picture_url: "http://node.test/storage/avatars/vera.webp",
          }),
        ],
      },
    }));

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    expect(
      wrapper.get(".profile-edit__picture img").attributes("src"),
    ).toBe("http://node.test/storage/avatars/vera.webp");
  });
});

describe("staff profile picture submission", () => {
  it("submits a chosen picture to the node as multipart", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/submit-profile-picture")
        ? { status: 201, body: { request: pendingPicturePayload() } }
        : { body: { profiles: [profilePayload()] } },
    );

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    const input = wrapper.get("input[type='file']");
    const file = new File(["fake-bytes"], "portrait.jpg", { type: "image/jpeg" });
    Object.defineProperty(input.element, "files", { value: [file] });
    await input.trigger("change");
    await flushPromises();

    const upload = calls.find((call) =>
      call.url.endsWith("/commands/submit-profile-picture"),
    );

    expect(upload?.method).toBe("POST");
    // A multipart body, so it is the FormData rather than JSON that carried it.
    expect(upload?.formData?.get("staff_id")).toBe(STAFF_ID);
    expect(upload?.formData?.get("picture")).toBeInstanceOf(File);
  });

  it("keeps the current picture in force while a submission is pending", async () => {
    stubNode(() => ({
      body: {
        profiles: [
          profilePayload({
            profile_picture_url: "http://node.test/storage/avatars/current.webp",
            latest_picture_request: pendingPicturePayload({
              submitted_picture_url: "http://node.test/pending/submitted.webp",
            }),
          }),
        ],
      },
    }));

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    const sources = wrapper
      .findAll(".profile-edit__picture img")
      .map((img) => img.attributes("src"));

    // Both, side by side: the one in force and the one waiting on a decision.
    expect(sources).toEqual([
      "http://node.test/storage/avatars/current.webp",
      "http://node.test/pending/submitted.webp",
    ]);
    expect(wrapper.text()).toContain("On your record now");
    expect(wrapper.text()).toContain("Waiting for review");

    // No second submission is offered while one is outstanding (VOL-024).
    expect(wrapper.find("input[type='file']").exists()).toBe(false);
    expect(
      wrapper.findAll("button").map((button) => button.text()),
    ).toContain("Withdraw submission");
  });

  it("offers no uploader to a staff member who is not active anywhere", async () => {
    stubNode(() => ({
      body: {
        profiles: [profilePayload({ can_submit_picture: false })],
      },
    }));

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    expect(wrapper.find("input[type='file']").exists()).toBe(false);
    expect(wrapper.text()).toContain(
      "once you are an active staff member in an organization",
    );
  });

  it("removes the current picture through its own command", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/remove-profile-picture")
        ? { body: { profile: profilePayload({ profile_picture_url: null }) } }
        : {
            body: {
              profiles: [
                profilePayload({
                  profile_picture_url: "http://node.test/storage/avatars/vera.webp",
                }),
              ],
            },
          },
    );

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    const remove = wrapper
      .findAll("button")
      .find((button) => button.text() === "Remove current picture");

    await remove!.trigger("click");
    await flushPromises();

    const command = calls.find((call) =>
      call.url.endsWith("/commands/remove-profile-picture"),
    );

    expect(command?.body).toMatchObject({ staff_id: STAFF_ID });
    expect(wrapper.text()).toContain("Removed.");
  });
});

describe("staff handle changes", () => {
  it("states how many direct changes remain before one is spent", async () => {
    stubNode(() => ({
      body: { profiles: [profilePayload({ remaining_self_service_handle_changes: 1 })] },
    }));

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    expect(wrapper.text()).toContain(
      "One more handle change takes effect immediately",
    );
  });

  it("reports the node's answer rather than predicting whether a change applied", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/request-handle-change")
        ? {
            status: 201,
            body: {
              request: {
                id: "req-handle-1",
                kind: "handle",
                // The node decided this one needs review even though the
                // client last saw an allowance remaining.
                status: "pending",
                previous_handle: "vera-radio",
                requested_handle: "vera-two",
                self_service: false,
              },
            },
          }
        : { body: { profiles: [profilePayload()] } },
    );

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    const handleInput = wrapper
      .findAll("input[type='text']")
      .find((input) => (input.element as HTMLInputElement).value === "vera-radio");

    await handleInput!.setValue("vera-two");
    await wrapper.findAll("form")[1]!.trigger("submit.prevent");
    await flushPromises();

    const command = calls.find((call) =>
      call.url.endsWith("/commands/request-handle-change"),
    );

    expect(command?.body).toMatchObject({
      staff_id: STAFF_ID,
      handle: "vera-two",
    });
    expect(wrapper.text()).toContain("Submitted for review");
  });

  it("names the outcome the policy will produce rather than promising a change", async () => {
    stubNode(() => ({
      body: {
        profiles: [
          profilePayload({
            // The default: every change reviewed, so no allowance is offered.
            handle_change_policy: "organizer_only",
            remaining_self_service_handle_changes: 0,
          }),
        ],
      },
    }));

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    expect(wrapper.text()).toContain("Handle changes are reviewed by an organizer.");
    expect(
      wrapper.findAll("button").map((button) => button.text()),
    ).toContain("Request handle change");
    expect(
      wrapper.findAll("button").map((button) => button.text()),
    ).not.toContain("Change handle");
  });

  it("shows a pending handle request with the handle still in force", async () => {
    stubNode(() => ({
      body: {
        profiles: [
          profilePayload({
            remaining_self_service_handle_changes: 0,
            latest_handle_request: {
              id: "req-handle-2",
              kind: "handle",
              status: "pending",
              previous_handle: "vera-radio",
              requested_handle: "vera-three",
              self_service: false,
            },
          }),
        ],
      },
    }));

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    expect(wrapper.text()).toContain("vera-three");
    expect(wrapper.text()).toContain("your handle stays");
    expect(
      wrapper.findAll("button").map((button) => button.text()),
    ).toContain("Withdraw request");
  });

  it("saves through update-my-profile and reports that the change applied", async () => {
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/update-my-profile")) {
        return {
          body: {
            profile: profilePayload({
              preferred_name: "Vee",
              city: "Eugene",
            }),
          },
        };
      }

      return { body: { profiles: [profilePayload()] } };
    });

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    await wrapper.get("input[autocomplete='nickname']").setValue("Vee");
    await wrapper.get("input[autocomplete='address-level2']").setValue("Eugene");
    await wrapper.get("form").trigger("submit.prevent");
    await flushPromises();

    const command = calls.find((call) =>
      call.url.endsWith("/commands/update-my-profile"),
    );

    // The payload names the record and carries exactly the VOL-015 fields.
    expect(command?.method).toBe("POST");
    expect(command?.body).toMatchObject({
      staff_id: STAFF_ID,
      preferred_name: "Vee",
      phone: "555-0100",
      city: "Eugene",
      state: "OR",
    });
    expect(Object.keys(command?.body ?? {})).not.toContain("legal_name");
    expect(Object.keys(command?.body ?? {})).not.toContain("email");
    expect(Object.keys(command?.body ?? {})).not.toContain("date_of_birth");
    expect(Object.keys(command?.body ?? {})).not.toContain("handle");

    expect(wrapper.text()).toContain(
      "Saved. These changes take effect immediately.",
    );
  });

  it("shows a node refusal in the node's own words", async () => {
    stubNode((call) =>
      call.url.endsWith("/commands/update-my-profile")
        ? {
            status: 422,
            body: {
              message: "Archived staff profiles cannot be edited.",
            },
          }
        : { body: { profiles: [profilePayload()] } },
    );

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    await wrapper.get("form").trigger("submit.prevent");
    await flushPromises();

    expect(wrapper.text()).toContain("Archived staff profiles cannot be edited.");
    expect(wrapper.text()).not.toContain("Saved.");
  });

  it("says plainly when the login speaks for no staff record", async () => {
    stubNode(() => ({ body: { profiles: [] } }));

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    expect(wrapper.text()).toContain(
      "This login is not linked to a staff profile",
    );
    expect(wrapper.find("form").exists()).toBe(false);
  });
});

describe("staff me profile rows", () => {
  it("shows handle, phone, and city/state from the profile read, and the edit door", async () => {
    stubNode(() => ({ body: { profiles: [profilePayload()] } }));

    const wrapper = await mountView(MeView, "staff.me");

    expect(wrapper.text()).toContain("vera-radio");
    expect(wrapper.text()).toContain("555-0100");
    expect(wrapper.text()).toContain("Portland, OR");

    const editLink = wrapper
      .findAll("a")
      .find((anchor) => anchor.text() === "Edit Profile");

    expect(editLink?.attributes("href")).toBe("/staff/me/edit");
  });

  /*
   * What Me links to, and what it deliberately no longer does (M18.71).
   *
   * Device Readiness and Account and Device are properties of a device rather
   * than of a person, and both live in Settings, which the user menu reaches
   * from every screen. My Requests moved onto Edit Profile, where the change it
   * tracks was made. What is left is four links that are all about the person
   * whose page this is.
   */
  it("links to the person's own pages and not to the device's", async () => {
    stubNode(() => ({ body: { profiles: [profilePayload()] } }));

    const wrapper = await mountView(MeView, "staff.me");
    const links = wrapper.get("nav[aria-label='Me links']");
    const labels = links.findAll("a").map((anchor) => anchor.text());

    expect(labels).toContain("Edit Profile");
    expect(labels).toContain("Workstations");
    expect(labels).not.toContain("Device Readiness");
    expect(labels).not.toContain("Account and Device");
    expect(labels).not.toContain("My Requests");
    // Renamed rather than removed: the page signs you in at a workstation and
    // lists the ones you have used, so it is named for the things.
    expect(labels).not.toContain("Workstation Sign-in");
  });

  it("leaves the profile rows absent when the read fails, rather than printing Not set", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const wrapper = await mountView(MeView, "staff.me");

    expect(wrapper.text()).not.toContain("Handle");
    expect(wrapper.text()).not.toContain("City/State");
  });
});

/*
 * The decided-request notice (VOL-029). A rejection is the state this exists
 * for: without it a staff member submits something, nothing visibly happens,
 * and they have no way to learn why.
 */
describe("staff profile decision notices", () => {
  it("shows a rejection with its reason and clears it through dismiss", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/dismiss-profile-change-request")
        ? { body: { request: { id: "req-handle-9", status: "rejected" } } }
        : {
            body: {
              profiles: [
                profilePayload({
                  latest_handle_request: {
                    id: "req-handle-9",
                    kind: "handle",
                    status: "rejected",
                    previous_handle: "vera-radio",
                    requested_handle: "dispatch",
                    self_service: false,
                    decision_reason: "Dispatch is the desk, not a person.",
                  },
                }),
              ],
            },
          },
    );

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    expect(wrapper.text()).toContain("was not approved");
    expect(wrapper.text()).toContain("Dispatch is the desk, not a person.");

    // A decided request does not block trying again: the form is still there.
    expect(wrapper.find("form.profile-edit__form + section form").exists()).toBe(
      false,
    );
    expect(
      wrapper.findAll("button").map((button) => button.text()),
    ).not.toContain("Withdraw request");

    const clear = wrapper
      .findAll("button")
      .find((button) => button.text() === "Clear");

    await clear!.trigger("click");
    await flushPromises();

    const dismissed = calls.find((call) =>
      call.url.endsWith("/commands/dismiss-profile-change-request"),
    );

    expect(dismissed?.body).toMatchObject({ request_id: "req-handle-9" });
  });

  it("shows a rejected picture submission with its reason", async () => {
    stubNode(() => ({
      body: {
        profiles: [
          profilePayload({
            profile_picture_url: "http://node.test/storage/avatars/vera.webp",
            latest_picture_request: pendingPicturePayload({
              status: "rejected",
              decision_reason: "Please submit a photo showing your face.",
            }),
          }),
        ],
      },
    }));

    const wrapper = await mountView(StaffProfileEditView, "staff.profile.edit");

    expect(wrapper.text()).toContain(
      "Your profile picture was not approved: Please submit a photo showing your face.",
    );

    // The current picture is still the only one shown, and the uploader is
    // offered again so the rejection can be answered with a new submission.
    expect(wrapper.findAll(".profile-edit__picture")).toHaveLength(1);
    expect(wrapper.find("input[type='file']").exists()).toBe(true);
  });
});
