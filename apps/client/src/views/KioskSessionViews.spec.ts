import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import KioskSessionBar from "@/components/KioskSessionBar.vue";
import { resetFieldReportRuntime } from "@/field-reports/fieldReportRuntime";
import { submitFieldReport } from "@/field-reports/submitFieldReport";
import {
  reloadCommandOutboxFromLocalStore,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { localFieldSessionDocument } from "@/session/localFieldSession";
import { configureSharedWorkstationId } from "@/session/workstationIdentity";
import {
  enterWorkstationLoginCode,
  evaluateWorkstationSession,
  resetWorkstationSession,
  workstationSessionState,
} from "@/session/workstationSession";
import KioskSafeTimeoutView from "@/views/KioskSafeTimeoutView.vue";
import KioskWorkstationLoginView from "@/views/KioskWorkstationLoginView.vue";

/*
 * The kiosk surfaces a shared-workstation session begins, holds, and ends at
 * (M16.9; UI implementation contract 12.8; technical spec 13.3).
 *
 * What is asserted here is what each surface says in each state, and where a
 * session ending lands — not that the routes exist.
 */

const SESSION_KEY = "kQ7mVt2ZrBdN4xLpWyH3sCfJ8gEaU6nToXvI1bYh";
const WORKSTATION_ID = "workstation-gate-a";

const node = { expiresAt: "2027-06-01T12:05:00+00:00" };

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

      if (path === "/api/me") {
        return json(localFieldSessionDocument());
      }

      if (method === "DELETE") {
        return json({ ended: true });
      }

      return json(
        {
          ...(method === "POST" ? { session_key: SESSION_KEY } : {}),
          session: {
            id: "session-1",
            started_at: "2027-06-01T12:00:00+00:00",
            last_activity_at: "2027-06-01T12:00:00+00:00",
            expires_at: node.expiresAt,
            inactivity_timeout_seconds: 300,
          },
          user: { id: "user-1", name: "Dana Reyes" },
          shared_workstation: {
            id: WORKSTATION_ID,
            name: "Gate A Workstation",
            organization_id: "org-1",
            department_id: null,
          },
          event_id: "event-1",
        },
        method === "POST" ? 201 : 200,
      );
    }),
  );
}

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

async function signIn(): Promise<void> {
  await enterWorkstationLoginCode({
    sharedWorkstationId: WORKSTATION_ID,
    code: "K3M7PQRS",
  });
}

beforeEach(() => {
  node.expiresAt = "2027-06-01T12:05:00+00:00";
  window.localStorage.clear();
  resetWorkstationSession();
  clearClientSession();
  configureSharedWorkstationId(WORKSTATION_ID);
  stubNode();
  configureMeridianApi({ baseUrl: "http://node.test", bearerToken: null });
});

afterEach(async () => {
  configureSharedWorkstationId(null);
  resetWorkstationSession();
  clearClientSession();
  await resetFieldReportRuntime();
  resetCommandOutbox();
  configureMeridianApi(null);
  window.localStorage.clear();
  vi.unstubAllGlobals();
});

describe("the kiosk session bar", () => {
  it("shows the active user prominently while a session is live", async () => {
    // Technical spec 13.3: "The active user is shown prominently at all times."
    // In the shell chrome, not behind a menu.
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.home" });
    await router.isReady();

    const wrapper = mount(KioskSessionBar, { global: { plugins: [router] } });

    expect(wrapper.find(".kiosk-session__name").text()).toBe("Dana Reyes");
    expect(wrapper.find(".kiosk-session__end").text()).toBe("End session");
  });

  it("shows nothing at all while the workstation is locked", async () => {
    const router = buildRouter();
    await router.push({ name: "kiosk.workstation-login" });
    await router.isReady();

    const wrapper = mount(KioskSessionBar, { global: { plugins: [router] } });

    expect(wrapper.find('[data-testid="kiosk-session-bar"]').exists()).toBe(false);
  });

  it("warns before the timeout and offers a way to continue", async () => {
    // The kiosk guide asks for both: "a timeout that lands mid-sentence, in a
    // field, at night, is how people stop trusting the workstation".
    await signIn();
    evaluateWorkstationSession(new Date("2027-06-01T12:04:10+00:00"));

    const router = buildRouter();
    await router.push({ name: "kiosk.home" });
    await router.isReady();

    const wrapper = mount(KioskSessionBar, { global: { plugins: [router] } });

    expect(wrapper.find(".kiosk-session__warning").exists()).toBe(true);
    expect(wrapper.find(".kiosk-session__continue").text()).toBe("I'm still here");
  });

  it("ends the session from the control beside the name it ends", async () => {
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.home" });
    await router.isReady();

    const wrapper = mount(KioskSessionBar, { global: { plugins: [router] } });
    await wrapper.find(".kiosk-session__end").trigger("click");
    await flushPromises();

    expect(workstationSessionState.status).toBe("locked");
    expect(router.currentRoute.value.name).toBe("kiosk.workstation-login");
  });

  it("sends a timeout to the safe surface rather than the login screen", async () => {
    // A timeout leaves somebody's records on screen with nobody watching, so it
    // lands somewhere that holds none of them.
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.home" });
    await router.isReady();

    mount(KioskSessionBar, { global: { plugins: [router] } });
    evaluateWorkstationSession(new Date("2027-06-01T12:05:00+00:00"));
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("kiosk.safe-timeout");
  });
});

describe("kiosk.workstation-login", () => {
  it("signs in and lands on the workstation dashboard", async () => {
    const router = buildRouter();
    await router.push({ name: "kiosk.workstation-login" });
    await router.isReady();

    const wrapper = mount(KioskWorkstationLoginView, {
      global: { plugins: [router] },
    });
    await wrapper.find(".workstation-login__code").setValue("K3M7PQRS");
    await wrapper.find(".workstation-login__form").trigger("submit");
    await flushPromises();

    expect(workstationSessionState.user?.name).toBe("Dana Reyes");
    expect(router.currentRoute.value.name).toBe("kiosk.home");
  });

  it("offers no field on a machine that is not a trusted workstation", async () => {
    // A field the node could only refuse teaches somebody their code is broken
    // when the machine is. It is a setup problem and it says so.
    configureSharedWorkstationId(null);

    const router = buildRouter();
    await router.push({ name: "kiosk.workstation-login" });
    await router.isReady();

    const wrapper = mount(KioskWorkstationLoginView, {
      global: { plugins: [router] },
    });

    expect(wrapper.find(".workstation-login__form").exists()).toBe(false);
    expect(wrapper.find(".workstation-login__unconfigured").text()).toContain(
      "not set up as a trusted shared workstation",
    );
  });

  it("clears the field and states the refusal when a code is not accepted", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        json({ message: "That login code is not valid.", reason: "invalid_code" }, 422),
      ),
    );

    const router = buildRouter();
    await router.push({ name: "kiosk.workstation-login" });
    await router.isReady();

    const wrapper = mount(KioskWorkstationLoginView, {
      global: { plugins: [router] },
    });
    await wrapper.find(".workstation-login__code").setValue("WRONG234");
    await wrapper.find(".workstation-login__form").trigger("submit");
    await flushPromises();

    expect(wrapper.find(".workstation-login__error").text()).toBe(
      "That login code is not valid.",
    );
    expect(
      (wrapper.find(".workstation-login__code").element as HTMLInputElement).value,
    ).toBe("");
  });
});

describe("kiosk.safe-timeout", () => {
  it("holds nothing about whoever was signed in", async () => {
    await signIn();
    evaluateWorkstationSession(new Date("2027-06-01T12:05:00+00:00"));

    const router = buildRouter();
    await router.push({ name: "kiosk.safe-timeout" });
    await router.isReady();

    const wrapper = mount(KioskSafeTimeoutView, { global: { plugins: [router] } });

    expect(wrapper.text()).not.toContain("Dana Reyes");
    expect(wrapper.text()).not.toContain("Gate A Workstation");
  });

  it("says the queued work is still there", async () => {
    // The honest answer to "did the machine keep my check-in", stated rather
    // than left for somebody to worry about (kiosk guide 4.4).
    submitFieldReport(
      {
        eventId: "event-1",
        submittedByUserId: "user-1",
        staffId: "staff-1",
        originDeviceId: "device-1",
        originNodeId: "node-1",
        title: "Radio handed back at Gate A",
        body: "Radio handed back at Gate A.",
      },
      {
        generateId: () => "aaaaaaaa-1111-2222-3333-444455556666",
        now: () => new Date("2027-06-01T12:01:00.000Z"),
      },
    );
    // Read back from the durable store, which is the state this surface is
    // reached in: the session end wiped the catalog and left the queue.
    reloadCommandOutboxFromLocalStore();

    const router = buildRouter();
    await router.push({ name: "kiosk.safe-timeout" });
    await router.isReady();

    const wrapper = mount(KioskSafeTimeoutView, { global: { plugins: [router] } });

    expect(wrapper.find(".safe-timeout__queued").text()).toContain(
      "1 queued command is",
    );
  });
});

describe("the kiosk routes", () => {
  it("sends a locked workstation to the login screen", async () => {
    const router = buildRouter();
    await router.push({ name: "kiosk.home" });
    await router.isReady();

    expect(router.currentRoute.value.name).toBe("kiosk.workstation-login");
  });

  it("refuses to reach code entry while somebody is signed in", async () => {
    // "Switching users requires ending the current session first — there is no
    // quiet handover" (kiosk guide 4.4), including by typing the URL.
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.workstation-login" });
    await router.isReady();

    expect(router.currentRoute.value.name).toBe("kiosk.home");
  });

  it("keeps the safe-timeout surface reachable with no session at all", async () => {
    const router = buildRouter();
    await router.push({ name: "kiosk.safe-timeout" });
    await router.isReady();

    expect(router.currentRoute.value.name).toBe("kiosk.safe-timeout");
  });
});
