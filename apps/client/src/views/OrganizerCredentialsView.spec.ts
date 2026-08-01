// The reporting export entry point against a stubbed node (M16.22; CLIENT-019,
// CLIENT-020, CLIENT-023; REPORT-001, REPORT-010, REPORT-014, REPORT-015).
//
// Two halves are under test and they are the seam this task moved: what the
// surface asks the node for, and what it does with the answer. The first is the
// M16.12 path — a POST that asks for a scoped short-lived URL, followed by a
// plain navigation to whatever URL came back — and the second is that a refusal
// is printed rather than opened.
//
// No server runs for any of it, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { LOCAL_FIELD_DEPARTMENT_IDS } from "@/field-reports/localFieldFixture";
import { routes } from "@/router";
import { clearClientSession, installClientSession } from "@/session/clientSession";
import {
  installLocalFieldSession,
  localFieldSessionDocument,
} from "@/session/localFieldSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import OrganizerCredentialsView from "@/views/OrganizerCredentialsView.vue";

const EVENT_ID = "11111111-1111-4111-8111-111111111111";
const ISSUED_URL =
  "http://node.test/downloads/events/11111111-1111-4111-8111-111111111111/exports/credential-eligibility?actor=u1&expires=1&signature=abc";

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

/** Work as the organizer, which is where the export capability is held. */
function actAsOrganizer(): void {
  selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);
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

describe("the credential eligibility export entry point", () => {
  it("asks the node for a short-lived URL and navigates to the one it issued", async () => {
    actAsOrganizer();

    const opened = recordNavigations();
    const calls = stubNode(() => ({
      body: { url: ISSUED_URL, expires_at: "2026-08-01T18:05:00+00:00" },
    }));

    const wrapper = await mountView();

    await exportButton(wrapper)!.trigger("click");
    await flushPromises();

    // The credential rides on the request that asks for the URL, never in the
    // link that comes back (CLIENT-019).
    expect(calls).toHaveLength(1);
    expect(calls[0]?.url).toBe(
      `http://node.test/api/events/${EVENT_ID}/exports/credential-eligibility/download-url`,
    );
    expect(calls[0]?.method).toBe("POST");
    // No narrowing is sent: the scope is the caller's own, and a department
    // picker is M18.26's.
    expect(calls[0]?.body).toEqual({});

    expect(opened).toEqual([ISSUED_URL]);
    expect(wrapper.text()).toContain("Credential eligibility is downloading.");
  });

  it("states the scope and the excluded fields before anything is generated", async () => {
    actAsOrganizer();

    const calls = stubNode(() => ({ body: {} }));
    const wrapper = await mountView();
    const text = wrapper.text();

    // REPORT-014: the scope is readable without opening the file, and
    // REPORT-010's exclusion is stated rather than discovered in a column.
    expect(text).toContain("Local Field Event");
    expect(text).toContain("An organizer exports every department in the event");
    expect(text).toContain("Emergency contacts");
    expect(text).toContain("Phone numbers");
    // Reading the page asks the node for nothing; an export is a file, not a
    // list this surface renders.
    expect(calls).toHaveLength(0);
  });

  it("prints the node's refusal and opens nothing", async () => {
    actAsOrganizer();

    const opened = recordNavigations();
    stubNode(() => ({
      status: 403,
      body: {
        message:
          "You do not have permission to export credential eligibility for this event.",
      },
    }));

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

  it("offers no export to a client whose session carries no export capability", async () => {
    // The Gate membership is ordinary staff. Absent, not disabled (CLIENT-005).
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.gate);

    const wrapper = await mountView();

    expect(exportButton(wrapper)).toBeUndefined();
    expect(wrapper.text()).toContain(
      "Credential eligibility export requires organizer or department lead authority",
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

    const calls = stubNode(() => ({ body: { url: ISSUED_URL } }));
    const wrapper = await mountView();

    expect(exportButton(wrapper)!.attributes("disabled")).toBeDefined();
    expect(wrapper.text()).toContain(
      "Exports are generated by the node and require a server connection.",
    );

    await exportButton(wrapper)!.trigger("click");
    await flushPromises();

    // An export is not a command; there is nothing for the outbox to replay.
    expect(calls).toHaveLength(0);
  });
});
