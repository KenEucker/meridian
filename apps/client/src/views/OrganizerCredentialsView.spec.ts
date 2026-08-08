// The credentials surface against a stubbed node: event credential
// administration (M18.5; CRED-009 through CRED-014) and the eligibility export
// entry point (M16.22; CLIENT-019, CLIENT-020, CLIENT-023; REPORT-001,
// REPORT-010, REPORT-014, REPORT-015).
//
// Two featuresets on one page and two separate authorities over the same
// records, so the tests come in two halves. The export half is the M16.12 path —
// a POST that asks for a scoped short-lived URL, followed by a plain navigation
// to whatever URL came back, and a refusal printed rather than opened. The
// administration half is the read that says what a revocation would cost and the
// connected-only command that spends it.
//
// No server runs for any of it, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_ORGANIZATION_ID,
  LOCAL_FIELD_TEAM_IDS,
  localFieldSessionDocument,
} from "@/session/localFieldSessionFixture";
import { routes } from "@/router";
import { clearClientSession, installClientSession } from "@/session/clientSession";
import { CAPABILITY_EVENT_CREDENTIALS_REVOKE } from "@/session/permissionCodes";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import OrganizerCredentialsView from "@/views/OrganizerCredentialsView.vue";

const EVENT_ID = "11111111-1111-4111-8111-111111111111";
const ISSUED_URL =
  "http://node.test/downloads/events/11111111-1111-4111-8111-111111111111/exports/credential-eligibility?actor=u1&expires=1&signature=abc";

const CREDENTIALS_PATH = `/api/events/${EVENT_ID}/credentials`;
const EXPORT_URL_PATH = `/api/events/${EVENT_ID}/exports/credential-eligibility/download-url`;
const REVOKE_PATH = "/api/commands/revoke-credential";

const WREN_STAFF_ID = "22222222-2222-4222-8222-222222222222";
const RIVER_STAFF_ID = "33333333-3333-4333-8333-333333333333";

/** One row as the node reports it, defaulted so a test names only its point. */
function credentialPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    staff_id: WREN_STAFF_ID,
    legal_name: "Wren Subject",
    preferred_name: null,
    display_name: "Wren Subject",
    handle: "wren",
    departments: ["Gate"],
    status: "eligible",
    status_reason: null,
    status_reason_label: null,
    revoked_at: null,
    future_shift_count: 2,
    completed_shift_count: 1,
    recorded_minutes: 480,
    can_revoke: true,
    revoke_blocked_reason: null,
    ...overrides,
  };
}

/** One request this client made, as the assertions read it. */
interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
}

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
 * A node that answers the credential list and nothing else.
 *
 * The list read happens on mount for anybody holding revocation, so every test
 * in here needs an answer for it whether or not the test is about it.
 */
function stubNodeWithCredentials(
  credentials: readonly Record<string, unknown>[],
  reply: (
    call: NodeCall,
  ) => { readonly status?: number; readonly body: unknown } | null = () => null,
): readonly NodeCall[] {
  return stubNode((call) => {
    const answered = reply(call);

    if (answered !== null) {
      return answered;
    }

    if (call.url.endsWith(CREDENTIALS_PATH)) {
      return {
        body: {
          event_id: EVENT_ID,
          event_name: "Local Field Event",
          credentials,
        },
      };
    }

    return { body: {} };
  });
}

/** Every URL the browser was sent to, so a refusal that opens a tab is visible. */
function recordNavigations(): string[] {
  const opened: string[] = [];

  vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(
    function click(this: HTMLAnchorElement): void {
      opened.push(this.href);
    },
  );

  return opened;
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
  clearOfflineReadSet();
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

/** Work as the organizer, which holds both the export and revocation. */
function actAsOrganizer(): void {
  selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);
}

/**
 * Work as somebody holding revocation and nothing else.
 *
 * The seeded Rangers membership is a department lead as well as an Incident
 * Command lead, so it holds the export too; this replaces the role list with
 * the one shape the fixture cannot express — CRED-011's authority on its own.
 */
function actAsIncidentCommandLeadOnly(): void {
  installClientSession(
    localFieldSessionDocument({
      roles: [
        {
          role_code: "ic_lead",
          role_name: "Incident Command Lead",
          scope_type: "event",
          organization_id: LOCAL_FIELD_ORGANIZATION_ID,
          department_id: LOCAL_FIELD_DEPARTMENT_IDS.gate,
          team_id: LOCAL_FIELD_TEAM_IDS.gateCredentials,
          team_name: "Credentials",
          event_id: EVENT_ID,
          team_grant_id: null,
          reason:
            "You have the Incident Command Lead role because your department commands this event.",
          capabilities: [CAPABILITY_EVENT_CREDENTIALS_REVOKE],
        },
      ],
      capabilities: [CAPABILITY_EVENT_CREDENTIALS_REVOKE],
    }),
    "network",
  );
  selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.gate);
}

async function mountView(): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({ name: "organizer.credentials.index" });
  await router.isReady();

  const wrapper = mount(OrganizerCredentialsView, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return wrapper;
}

function exportButton(wrapper: VueWrapper) {
  return wrapper
    .findAll("button")
    .find((button) => button.text().startsWith("Export"));
}

function revokeButton(wrapper: VueWrapper, name = "Wren Subject") {
  return wrapper
    .findAll("button")
    .find(
      (button) =>
        button.attributes("aria-label") ===
        `Revoke the credential for ${name}`,
    );
}

/**
 * Submit the open confirmation.
 *
 * The form is what carries the handler, and jsdom does not raise `submit` from
 * a click on a submit button, so the test drives the event the component
 * listens for.
 */
async function confirmRevocation(
  wrapper: VueWrapper,
  name = "Wren Subject",
): Promise<void> {
  await wrapper
    .find(`form[aria-label="Revoke the credential for ${name}"]`)
    .trigger("submit");
}

describe("the credential eligibility export entry point", () => {
  it("asks the node for a short-lived URL and navigates to the one it issued", async () => {
    actAsOrganizer();

    const opened = recordNavigations();
    const calls = stubNodeWithCredentials([], (call) =>
      call.url.endsWith(EXPORT_URL_PATH)
        ? { body: { url: ISSUED_URL, expires_at: "2026-08-01T18:05:00+00:00" } }
        : null,
    );

    const wrapper = await mountView();

    await exportButton(wrapper)!.trigger("click");
    await flushPromises();

    const exportCalls = calls.filter((call) =>
      call.url.endsWith(EXPORT_URL_PATH),
    );

    // The credential rides on the request that asks for the URL, never in the
    // link that comes back (CLIENT-019).
    expect(exportCalls).toHaveLength(1);
    expect(exportCalls[0]?.method).toBe("POST");
    // No narrowing is sent: the scope is the caller's own, and the department
    // picker belongs to `organizer.exports`.
    expect(exportCalls[0]?.body).toEqual({});

    expect(opened).toEqual([ISSUED_URL]);
    expect(wrapper.text()).toContain("Credential eligibility is downloading.");
  });

  it("states the scope and the excluded fields before anything is generated", async () => {
    actAsOrganizer();

    const calls = stubNodeWithCredentials([]);
    const wrapper = await mountView();
    const text = wrapper.text();

    // REPORT-014: the scope is readable without opening the file, and
    // REPORT-010's exclusion is stated rather than discovered in a column.
    expect(text).toContain("Local Field Event");
    expect(text).toContain("An organizer exports every department in the event");
    expect(text).toContain("Emergency contacts");
    expect(text).toContain("Phone numbers");
    // Generating nothing asks the node for nothing: an export is a file, not a
    // list this surface renders. The credential list beside it is a read, and
    // it is the only request opening this page makes.
    expect(calls.map((call) => call.method)).toEqual(["GET"]);
    expect(calls[0]?.url).toContain(CREDENTIALS_PATH);
  });

  it("offers the one export this page is about and none of the other four", async () => {
    // M18.25 gave the remaining Alpha 1 exports descriptors and download
    // paths, and this organizer holds all five capabilities. They belong to the
    // export surfaces of M18.26; a credentials page offering a credits
    // ledger is a page that stopped being about credentials.
    actAsOrganizer();
    stubNodeWithCredentials([]);

    const wrapper = await mountView();
    const text = wrapper.text();

    expect(text).toContain("Credential eligibility");
    expect(text).not.toContain("Credits earned");
    expect(text).not.toContain("Hours worked");
    expect(text).not.toContain("Shift roster");
    expect(text).not.toContain("Staff contact list");
  });

  it("prints the node's refusal and opens nothing", async () => {
    actAsOrganizer();

    const opened = recordNavigations();
    stubNodeWithCredentials([], (call) =>
      call.url.endsWith(EXPORT_URL_PATH)
        ? {
            status: 403,
            body: {
              message:
                "You do not have permission to export credential eligibility for this event.",
            },
          }
        : null,
    );

    const wrapper = await mountView();

    await exportButton(wrapper)!.trigger("click");
    await flushPromises();

    // CLIENT-020: authorization is decided at issuance, so a refused export is
    // a sentence on the page rather than a tab onto an error document.
    expect(wrapper.find('[role="alert"]').text()).toContain(
      "You do not have permission to export credential eligibility for this event.",
    );
    expect(opened).toEqual([]);
  });

  it("offers neither featureset to a client whose session carries neither capability", async () => {
    // The Gate membership is ordinary staff. Absent, not disabled (CLIENT-005).
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.gate);

    const wrapper = await mountView();

    expect(exportButton(wrapper)).toBeUndefined();
    expect(revokeButton(wrapper)).toBeUndefined();
    expect(wrapper.text()).toContain(
      "Event credential administration requires organizer or Incident Command lead authority",
    );
  });

  it("offers no export when the session resolved no event to export for", async () => {
    // Every export endpoint is event-scoped, so with no event there is no URL
    // to ask for and nothing honest to offer.
    installClientSession(
      localFieldSessionDocument({
        context: {
          organization_id: "88888888-8888-4888-8888-888888888888",
          event_id: null,
          department_id: LOCAL_FIELD_DEPARTMENT_IDS.organizer,
          node_locked: false,
          node_locked_event_id: null,
          switching_available: true,
        },
      }),
      "network",
    );
    actAsOrganizer();

    const wrapper = await mountView();

    expect(exportButton(wrapper)).toBeUndefined();
  });

  it("refuses to run while the device has no network rather than queueing it", async () => {
    actAsOrganizer();
    setNavigatorOnline(false);

    const calls = stubNodeWithCredentials([], (call) =>
      call.url.endsWith(EXPORT_URL_PATH) ? { body: { url: ISSUED_URL } } : null,
    );
    const wrapper = await mountView();

    expect(exportButton(wrapper)!.attributes("disabled")).toBeDefined();
    expect(wrapper.text()).toContain(
      "Exports are generated by the node and require a server connection.",
    );

    await exportButton(wrapper)!.trigger("click");
    await flushPromises();

    // An export is not a command; there is nothing for the outbox to replay.
    expect(calls.filter((call) => call.url.endsWith(EXPORT_URL_PATH))).toEqual(
      [],
    );
  });
});

describe("event credential administration", () => {
  it("states what revocation would remove and what it would preserve", async () => {
    actAsOrganizer();

    stubNodeWithCredentials([credentialPayload()]);

    const wrapper = await mountView();
    const text = wrapper.text();

    expect(text).toContain("Wren Subject");
    expect(text).toContain("Eligible");
    // CRED-012 and CRED-013 in the same breath, before anybody acts: the two
    // halves of what revocation does are the decision an organizer is making.
    expect(text).toContain("2 upcoming shifts would be removed");
    expect(text).toContain(
      "1 completed shift and 8 hr recorded stay on the record",
    );
    expect(revokeButton(wrapper)).toBeDefined();
  });

  it("sends the command with its reason and replaces the row with the answer", async () => {
    actAsOrganizer();

    const calls = stubNodeWithCredentials([credentialPayload()], (call) =>
      call.url.endsWith(REVOKE_PATH)
        ? {
            body: {
              event_id: EVENT_ID,
              credential: credentialPayload({
                status: "revoked",
                status_reason: "manual_revocation",
                status_reason_label: "Manual revocation",
                revoked_at: "2026-08-01T18:00:00+00:00",
                future_shift_count: 0,
                completed_shift_count: 1,
                recorded_minutes: 480,
                can_revoke: false,
                revoke_blocked_reason: "This credential is already revoked.",
              }),
            },
          }
        : null,
    );

    const wrapper = await mountView();

    await revokeButton(wrapper)!.trigger("click");
    await wrapper.find("textarea").setValue("Asked to leave site.");
    await confirmRevocation(wrapper);
    await flushPromises();

    const revokeCalls = calls.filter((call) => call.url.endsWith(REVOKE_PATH));

    expect(revokeCalls).toHaveLength(1);
    expect(revokeCalls[0]?.method).toBe("POST");
    expect(revokeCalls[0]?.body).toEqual({
      event_id: EVENT_ID,
      staff_id: WREN_STAFF_ID,
      reason: "Asked to leave site.",
    });

    // The answer is the whole row rebuilt, so the surface shows what is now
    // true without a second read — including the hours that survived.
    const text = wrapper.text();
    expect(text).toContain("Revoked");
    expect(text).toContain("Manual revocation");
    expect(text).toContain("This credential is already revoked.");
    expect(text).toContain(
      "1 completed shift and 8 hr recorded stay on the record",
    );
    expect(revokeButton(wrapper)).toBeUndefined();
  });

  it("sends no reason at all when none was typed", async () => {
    actAsOrganizer();

    const calls = stubNodeWithCredentials([credentialPayload()], (call) =>
      call.url.endsWith(REVOKE_PATH)
        ? { body: { credential: credentialPayload({ can_revoke: false }) } }
        : null,
    );

    const wrapper = await mountView();

    await revokeButton(wrapper)!.trigger("click");
    await wrapper.find("textarea").setValue("   ");
    await confirmRevocation(wrapper);
    await flushPromises();

    // An empty reason would be recorded as a reason that says nothing, which
    // reads in an audit log like somebody typed a space rather than like
    // nobody was asked.
    expect(
      calls.find((call) => call.url.endsWith(REVOKE_PATH))?.body,
    ).toEqual({
      event_id: EVENT_ID,
      staff_id: WREN_STAFF_ID,
    });
  });

  it("prints the node's refusal and leaves the row as it was", async () => {
    actAsOrganizer();

    stubNodeWithCredentials([credentialPayload()], (call) =>
      call.url.endsWith(REVOKE_PATH)
        ? {
            status: 403,
            body: {
              message: "You are not authorized to revoke event credentials.",
            },
          }
        : null,
    );

    const wrapper = await mountView();

    await revokeButton(wrapper)!.trigger("click");
    await confirmRevocation(wrapper);
    await flushPromises();

    expect(wrapper.text()).toContain(
      "You are not authorized to revoke event credentials.",
    );
    // CLIENT-006: the node decides, and a refused command changes nothing on
    // the screen that issued it.
    expect(wrapper.text()).toContain("Eligible");
    expect(revokeButton(wrapper)).toBeDefined();
  });

  it("keeps a revoked credential in the list with no control to revoke it again", async () => {
    actAsOrganizer();

    stubNodeWithCredentials([
      credentialPayload({
        staff_id: RIVER_STAFF_ID,
        legal_name: "River Past",
        display_name: "River Past",
        handle: "river",
        status: "revoked",
        status_reason: "manual_revocation",
        status_reason_label: "Manual revocation",
        revoked_at: "2026-07-01T12:00:00+00:00",
        future_shift_count: 0,
        can_revoke: false,
        revoke_blocked_reason: "This credential is already revoked.",
      }),
    ]);

    const wrapper = await mountView();

    // An organizer checking whether a past decision was right is looking for
    // exactly the row a tidier list would have dropped.
    expect(wrapper.text()).toContain("River Past");
    expect(wrapper.text()).toContain("This credential is already revoked.");
    expect(revokeButton(wrapper, "River Past")).toBeUndefined();
  });

  it("says there is nothing to remove rather than counting to zero", async () => {
    actAsOrganizer();

    stubNodeWithCredentials([
      credentialPayload({
        status: "blocked",
        status_reason: "no_signed_up_shifts",
        status_reason_label: "No signed-up shifts",
        future_shift_count: 0,
        completed_shift_count: 0,
        recorded_minutes: 0,
      }),
    ]);

    const wrapper = await mountView();

    await revokeButton(wrapper)!.trigger("click");

    // "Removes Wren Subject from 0 upcoming shifts" is the kind of sentence
    // that makes an operator wonder whether the screen knows what it is about
    // to do, and the answer to that doubt is not a confirmation button.
    expect(wrapper.text()).toContain(
      "Wren Subject has no upcoming shifts to remove. There is no completed work to preserve.",
    );
    expect(wrapper.text()).not.toContain("0 upcoming shifts");
  });

  it("filters the list it already holds without asking the node again", async () => {
    actAsOrganizer();

    const calls = stubNodeWithCredentials([
      credentialPayload(),
      credentialPayload({
        staff_id: RIVER_STAFF_ID,
        legal_name: "River Past",
        display_name: "River Past",
        handle: "river",
      }),
    ]);

    const wrapper = await mountView();

    expect(wrapper.text()).toContain("River Past");

    await wrapper.find("#credential-filter").setValue("wren");

    expect(wrapper.text()).toContain("Wren Subject");
    expect(wrapper.text()).not.toContain("River Past");
    // Filtering is over what the node already sent, so it costs no request and
    // works with the node unreachable.
    expect(calls.filter((call) => call.url.includes(CREDENTIALS_PATH))).toHaveLength(
      1,
    );
  });

  it("refuses to revoke while the device has no network rather than queueing it", async () => {
    actAsOrganizer();

    const calls = stubNodeWithCredentials([credentialPayload()]);
    const wrapper = await mountView();

    setNavigatorOnline(false);
    await flushPromises();

    expect(wrapper.text()).toContain(
      "Revoking a credential removes shifts other people are being scheduled around",
    );
    expect(revokeButton(wrapper)!.attributes("disabled")).toBeDefined();

    expect(calls.filter((call) => call.url.endsWith(REVOKE_PATH))).toEqual([]);
  });

  it("offers the list and no export to an Incident Command lead", async () => {
    actAsIncidentCommandLeadOnly();

    stubNodeWithCredentials([credentialPayload()]);

    const wrapper = await mountView();

    // CRED-011 names them and REPORT-007 does not, so they administer
    // credentials here and are offered no file to take away.
    expect(revokeButton(wrapper)).toBeDefined();
    expect(exportButton(wrapper)).toBeUndefined();
    expect(wrapper.text()).toContain(
      "administered as Incident Command Lead",
    );
  });
});
