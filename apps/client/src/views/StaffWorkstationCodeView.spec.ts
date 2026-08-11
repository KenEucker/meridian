import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory, type Router } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { routes, workstationCodeRouteGuard } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession, LOCAL_FIELD_FIXTURE } from "@/session/localFieldSessionFixture";
import { resetOwnNodeIdentity } from "@/session/nodeIdentity";
import {
  acceptScannedWorkstationCode,
  grantScannedWorkstationSignIn,
  resetWorkstationGrantFlow,
  workstationGrantState,
} from "@/session/workstationGrantFlow";
import { buildWorkstationSignInQrText } from "@/support/workstationSignInQr";
import StaffWorkstationCodeView from "@/views/StaffWorkstationCodeView.vue";

/*
 * `staff.workstation-code` (M18.61; AUTH-026 through AUTH-028, AUTH-033,
 * AUTH-034; UI contract 12.3; technical spec 13.4).
 *
 * What is asserted: a scanned request is confirmed with the named workstation
 * and event and granted for the caller's own user; a QR naming a different
 * node is refused with both node identities named; a generated code renders
 * once and is not retrievable on a re-render; a denied camera reaches the
 * typed fallback rather than a dead screen; and the surface is absent in
 * Kiosk mode.
 */

const WORKSTATION_ID = "workstation-gate-a";
const REQUEST_ID = "request-1";
const OWN_NODE = { id: "node-1", name: "onsite-command-1" };

const node = {
  granted: [] as unknown[],
  generated: 0,
  /** What `GET /api/me/workstation-sessions` answers with (M18.71). */
  history: [] as unknown[],
  historyStatus: 200,
  historyReads: 0,
};

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

function stubNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = new URL(String(input)).pathname;
      const method = (init?.method ?? "GET").toUpperCase();

      if (path === "/api/health") {
        return json({
          status: "ok",
          node_id: OWN_NODE.id,
          node_name: OWN_NODE.name,
        });
      }

      if (path === "/api/me/workstation-sessions") {
        node.historyReads += 1;

        return node.historyStatus === 200
          ? json({ sessions: node.history })
          : json({ message: "The node refused." }, node.historyStatus);
      }

      if (path === `/api/kiosk/workstations/${WORKSTATION_ID}`) {
        return json({
          shared_workstation: {
            id: WORKSTATION_ID,
            name: "Gate A Workstation",
            short_code: "K3M7PQRS",
            trusted: true,
          },
          node: OWN_NODE,
          pinned: true,
          organization: { id: "org-1", name: "Northwood Collective" },
          event: { id: LOCAL_FIELD_FIXTURE.eventId, name: "Emberfall 2027", timezone: "UTC" },
          department: null,
        });
      }

      if (method === "POST" && path.endsWith("/grant")) {
        node.granted.push(init?.body === undefined ? null : JSON.parse(String(init.body)));

        return json({
          granted: true,
          sign_in_request: { id: REQUEST_ID, purpose: "sign_in" },
          shared_workstation: { id: WORKSTATION_ID, name: "Gate A Workstation" },
          event_id: LOCAL_FIELD_FIXTURE.eventId,
        });
      }

      if (method === "POST" && path === "/api/auth/shared-workstation-login-code") {
        node.generated += 1;
        const body = init?.body === undefined ? {} : (JSON.parse(String(init.body)) as Record<string, unknown>);
        const targeted = typeof body.shared_workstation_short_code === "string";

        return json(
          {
            code: "K3M7-PQRS",
            expires_at: "2027-07-15T12:00:00+00:00",
            user: { id: "user-1", name: "Local Field Author" },
            shared_workstation: targeted
              ? { id: WORKSTATION_ID, name: "Gate A Workstation" }
              : null,
            event_id: LOCAL_FIELD_FIXTURE.eventId,
          },
          201,
        );
      }

      return json({ message: "Unexpected request in test." }, 500);
    }),
  );
}

function scannedQr(overrides: Partial<Parameters<typeof buildWorkstationSignInQrText>[0]> = {}): string {
  return buildWorkstationSignInQrText({
    workstationId: WORKSTATION_ID,
    nodeId: OWN_NODE.id,
    nodeName: OWN_NODE.name,
    requestId: REQUEST_ID,
    ...overrides,
  });
}

function buildRouter(): Router {
  return createRouter({ history: createWebHistory(), routes });
}

async function mountView(router: Router): Promise<VueWrapper> {
  await router.push({ name: "staff.workstation-code" });
  await router.isReady();

  const wrapper = mount(StaffWorkstationCodeView, { global: { plugins: [router] } });
  await flushPromises();

  return wrapper;
}

beforeEach(() => {
  node.granted = [];
  node.generated = 0;
  node.history = [];
  node.historyStatus = 200;
  node.historyReads = 0;

  window.localStorage.clear();
  resetWorkstationGrantFlow();
  resetOwnNodeIdentity();
  clearClientSession();
  installLocalFieldSession();
  stubNode();
  configureMeridianApi({ baseUrl: "http://node.test", bearerToken: "token-1" });
});

afterEach(() => {
  resetWorkstationGrantFlow();
  resetOwnNodeIdentity();
  clearClientSession();
  configureMeridianApi(null);
  window.localStorage.clear();
  vi.unstubAllGlobals();
});

describe("scanning and granting", () => {
  it("confirms the named workstation and event, then grants for the caller", async () => {
    const accepted = await acceptScannedWorkstationCode(scannedQr());

    expect(accepted).toBe(true);
    expect(workstationGrantState.step).toBe("confirming");
    expect(workstationGrantState.workstationName).toBe("Gate A Workstation");
    expect(workstationGrantState.eventName).toBe("Emberfall 2027");

    const granted = await grantScannedWorkstationSignIn();

    expect(granted).toBe(true);
    expect(workstationGrantState.step).toBe("granted");

    // The grant named the node the QR claimed, and nobody else's user: there
    // is no user field to put another person's identifier in (AUTH-033).
    expect(node.granted).toHaveLength(1);
    expect(node.granted[0]).toEqual({ node_id: OWN_NODE.id });
  });

  it("refuses a foreign-node QR with both node identities named", async () => {
    // AUTH-034: the code would otherwise be issued into the wrong database
    // and fail at the keyboard with no reason given.
    const accepted = await acceptScannedWorkstationCode(
      scannedQr({ nodeId: "node-2", nodeName: "central-1" }),
    );

    expect(accepted).toBe(false);
    expect(workstationGrantState.step).toBe("idle");
    expect(workstationGrantState.error).toContain("central-1");
    expect(workstationGrantState.error).toContain(OWN_NODE.name);
    expect(node.granted).toHaveLength(0);
  });

  it("names a scanned code that is not a workstation sign-in code", async () => {
    const accepted = await acceptScannedWorkstationCode("https://example.test/menu");

    expect(accepted).toBe(false);
    expect(workstationGrantState.error).toContain("not a Meridian workstation sign-in code");
  });
});

describe("the view", () => {
  it("reaches the typed fallback when the camera cannot scan", async () => {
    // jsdom has no BarcodeDetector, which is exactly the "older device"
    // shape: the scan button answers with the typed path, not a dead screen.
    const wrapper = await mountView(buildRouter());

    await wrapper.find('[data-testid="workstation-scan-start"]').trigger("click");
    await flushPromises();

    const error = wrapper.find('[data-testid="workstation-scan-error"]');
    expect(error.exists()).toBe(true);
    expect(error.text()).toContain("Enter the workstation code shown on its screen");

    // The typed fallback is standing right there.
    expect(wrapper.find('[data-testid="workstation-short-code-form"]').exists()).toBe(true);

    wrapper.unmount();
  });

  it("issues a targeted code from a typed workstation short code", async () => {
    const wrapper = await mountView(buildRouter());

    await wrapper.find("#workstation-short-code-input").setValue("K3M7-PQRS");
    await wrapper.find('[data-testid="workstation-short-code-form"]').trigger("submit");
    await flushPromises();

    const display = wrapper.find('[data-testid="workstation-generated-code"]');
    expect(display.exists()).toBe(true);
    expect(display.text()).toContain("K3M7-PQRS");
    expect(display.text()).toContain("Gate A Workstation");
    expect(display.text()).toContain("will not be shown again");

    wrapper.unmount();
  });

  it("renders a generated untargeted code once, unretrievable on re-render", async () => {
    const router = buildRouter();
    const wrapper = await mountView(router);

    await wrapper.find('[data-testid="workstation-untargeted-generate"]').trigger("click");
    await flushPromises();

    const display = wrapper.find('[data-testid="workstation-generated-code"]');
    expect(display.exists()).toBe(true);
    expect(display.text()).toContain("K3M7-PQRS");
    expect(display.text()).toContain("any trusted workstation");
    expect(display.text()).toContain("will not be shown again");

    wrapper.unmount();

    // A fresh render holds nothing: the code lived in the unmounted component
    // and nowhere else, which is what "shown once" means mechanically.
    const remounted = await mountView(router);

    expect(remounted.find('[data-testid="workstation-generated-code"]').exists()).toBe(false);
    expect(remounted.text()).not.toContain("K3M7-PQRS");

    remounted.unmount();
  });
});

describe("mode placement", () => {
  it("is absent in kiosk mode and present in field and admin", () => {
    // A workstation does not sign its users in elsewhere: the route redirects
    // to the kiosk home rather than rendering the surface (M18.61).
    expect(workstationCodeRouteGuard("kiosk")).toEqual({ name: "kiosk.home" });
    expect(workstationCodeRouteGuard("field")).toBe(true);
    expect(workstationCodeRouteGuard("admin")).toBe(true);
  });
});

/*
 * The history of workstations this login has used (M18.71; AUTH-030;
 * technical spec 13.3).
 *
 * On this page because it is about the same machines the rest of it acts on.
 * What has to be readable: whether a session is still live, how a finished one
 * ended, and — the one that costs somebody real trust when it is wrong — a read
 * that failed said as a failed read rather than as an empty history.
 */
describe("workstation history", () => {
  function session(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
      id: "session-1",
      workstation_name: "Gate A Workstation",
      event_name: "Emberfall 2027",
      started_at: "2027-07-15T10:00:00+00:00",
      last_activity_at: "2027-07-15T10:04:00+00:00",
      ended_at: null,
      ended_reason: null,
      active: false,
      ...overrides,
    };
  }

  it("lists the workstations this login has signed in at", async () => {
    node.history = [session()];

    const wrapper = await mountView(buildRouter());
    const history = wrapper.get('[data-testid="workstation-history"]');

    expect(history.text()).toContain("Gate A Workstation");
    expect(history.text()).toContain("Emberfall 2027");

    wrapper.unmount();
  });

  /* The row somebody came here worried about: a kiosk they walked away from. */
  it("says which session is still signed in", async () => {
    node.history = [
      session({ id: "live", active: true }),
      session({ id: "done", ended_reason: "signed_out", ended_at: "2027-07-15T10:30:00+00:00" }),
    ];

    const wrapper = await mountView(buildRouter());
    const rows = wrapper.get('[data-testid="workstation-history"]').findAll("li");

    expect(rows[0].text()).toContain("Signed in now");
    expect(rows[0].attributes("data-active")).toBe("true");
    expect(rows[1].text()).toContain("Signed out");
    expect(rows[1].attributes("data-active")).toBe("false");

    wrapper.unmount();
  });

  /*
   * A session that timed out unobserved carries no end and is not live. Naming
   * it "ended" would claim a moment nobody recorded, so it is distinguished
   * from a sign-out — which is exactly the difference somebody auditing their
   * own history is looking for.
   */
  it("tells a timeout apart from a sign-out", async () => {
    node.history = [session({ ended_reason: "timed_out", active: false })];

    const wrapper = await mountView(buildRouter());

    expect(wrapper.get('[data-testid="workstation-history"]').text()).toContain(
      "Timed out",
    );

    wrapper.unmount();
  });

  it("says nothing has happened when nothing has", async () => {
    node.history = [];

    const wrapper = await mountView(buildRouter());

    expect(wrapper.get('[data-testid="workstation-history"]').text()).toContain(
      "You have not signed in at a shared workstation",
    );

    wrapper.unmount();
  });

  it("reports a failed read as a failed read rather than as an empty history", async () => {
    node.historyStatus = 500;

    const wrapper = await mountView(buildRouter());

    // `get` is the assertion that the panel is there — it throws otherwise.
    // The node's own words are passed through rather than replaced with a
    // generic line, and the way back is offered beside them; the fallback text
    // is for a request that never reached anybody to refuse it.
    const failure = wrapper.get('[data-testid="workstation-history-error"]');

    expect(failure.text()).toContain("The node refused.");
    expect(failure.find("button").exists()).toBe(true);
    // And the empty state did not take its place, which is the failure that
    // would quietly tell somebody they have never signed in anywhere.
    expect(wrapper.text()).not.toContain("You have not signed in at a shared workstation");

    wrapper.unmount();
  });

  /* The sign-in somebody just performed is in the list when they look for it. */
  it("re-reads the history after a grant", async () => {
    const wrapper = await mountView(buildRouter());
    const before = node.historyReads;

    await acceptScannedWorkstationCode(scannedQr());
    await grantScannedWorkstationSignIn();
    await flushPromises();

    expect(node.historyReads).toBeGreaterThan(before);

    wrapper.unmount();
  });
});
