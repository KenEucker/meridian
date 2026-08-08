// The Event Horizon against a stubbed node (M18.43, M18.44; HORIZON-001
// through HORIZON-016; UI contract 19C).
//
// What is under test is the seam, not the rules. The node compiles the list,
// orders it, words every evaluation, and decides whether hiding is available;
// this surface has to render that answer in that order without re-ranking it,
// keep a completed item listed and marked, offer the hide control only when
// the node said it may, and disclose a stored copy as one rather than
// presenting "nothing outstanding" it has not established.
//
// No server runs for any of it, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory, type Router } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { resetEventHorizonPresence } from "@/event-horizon/eventHorizonModel";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { resetCommandOutbox } from "@/outbox/commandOutboxRuntime";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import {
  installLocalFieldSession,
  LOCAL_FIELD_FIXTURE,
} from "@/session/localFieldSessionFixture";
import { resetSelectedSessionDepartment } from "@/session/sessionAccess";
import EventHorizonView from "@/views/EventHorizonView.vue";

const EVENT_ID = LOCAL_FIELD_FIXTURE.eventId;

interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
}

function itemPayload(overrides: Record<string, unknown> = {}) {
  return {
    kind: "document_acknowledgment",
    identity: "document-acknowledgment:req-1",
    state: "outstanding",
    title: "Fire Safety Policy",
    evaluation: "The organization asks you to acknowledge this document and you have not yet.",
    completion: "Read the document and record your acknowledgment.",
    due_at: null,
    action: {
      surface: "staff.document-acknowledgments",
      label: "Open your acknowledgments",
      params: {},
    },
    ...overrides,
  };
}

function horizonPayload(overrides: Record<string, unknown> = {}) {
  return {
    context: {
      event_id: EVENT_ID,
      event_label: "Local Field Event",
      time_zone: "UTC",
      as_of: "2027-06-17T12:00:00+00:00",
    },
    window: {
      applies: true,
      reason: "open",
      lead_days: 30,
      opens_at: "2027-06-01T00:00:00+00:00",
      closes_at: "2027-07-10T00:00:00+00:00",
    },
    presentable: true,
    hidden: false,
    can_hide: false,
    outstanding_count: 1,
    kinds: [
      {
        id: "document_acknowledgment",
        label: "Document acknowledgments",
        module: "documents",
        governed_by: "POL-043 through POL-047",
      },
    ],
    items: [itemPayload()],
    ...overrides,
  };
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

/** A node nothing can reach: every request dies on the wire. */
function stubUnreachableNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => {
      throw new TypeError("Failed to fetch");
    }),
  );
}

function setNavigatorOnline(onLine: boolean): void {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value: onLine,
  });

  window.dispatchEvent(new Event(onLine ? "online" : "offline"));
}

const mounted: VueWrapper[] = [];

beforeEach(() => {
  clearOfflineReadSet();
  resetCommandOutbox();
  resetEventHorizonPresence();
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
  resetCommandOutbox();
  resetEventHorizonPresence();
  setNavigatorOnline(true);
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

async function mountView(): Promise<{ wrapper: VueWrapper; router: Router }> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({ name: "staff.event-horizon" });
  await router.isReady();

  const wrapper = mount(EventHorizonView, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return { wrapper, router };
}

describe("the Event Horizon surface", () => {
  it("renders the node's list in the node's order without re-ranking it", async () => {
    stubNode(() => ({
      body: horizonPayload({
        outstanding_count: 2,
        items: [
          itemPayload({
            kind: "shift_signup",
            identity: "shift-signup:shift-1",
            title: "Sooner Patrol",
            due_at: "2027-07-02T00:00:00+00:00",
            action: { surface: "staff.shift-board", label: "Open the shift board", params: {} },
          }),
          itemPayload({
            identity: "document-acknowledgment:req-1",
            title: "Fire Safety Policy",
          }),
          itemPayload({
            kind: "waiver",
            identity: "waiver:waiver-1",
            state: "complete",
            title: "Signed Waiver",
            evaluation: "Your completion of this waiver is on record and current.",
            completion: "Nothing — this is done.",
            action: { surface: "organizer.waivers", label: "Open waiver administration", params: {} },
          }),
        ],
      }),
    }));

    const { wrapper } = await mountView();
    const text = wrapper.text();

    // Server order, exactly: the outstanding pair before the complete one,
    // with no client-side sort between them.
    const positions = ["Sooner Patrol", "Fire Safety Policy", "Signed Waiver"].map(
      (title) => text.indexOf(title),
    );
    expect(positions.every((position) => position >= 0)).toBe(true);
    expect([...positions].sort((a, b) => a - b)).toEqual(positions);

    // Each item states what it is, how it evaluates, and what completes it
    // (19C.3).
    expect(text).toContain("asks you to acknowledge");
    expect(text).toContain("Read the document and record your acknowledgment.");
  });

  it("keeps a completed item listed and marked complete rather than removing it", async () => {
    stubNode(() => ({
      body: horizonPayload({
        can_hide: true,
        outstanding_count: 0,
        items: [
          itemPayload({
            state: "complete",
            evaluation: "You acknowledged this document, and the version you accepted is on record.",
            completion: "Nothing — this is done.",
          }),
        ],
      }),
    }));

    const { wrapper } = await mountView();

    const completed = wrapper.find('[data-state="complete"]');
    expect(completed.exists()).toBe(true);
    expect(completed.text()).toContain("Fire Safety Policy");
    // The state is text, not color alone (19C.5; section 20).
    expect(completed.text()).toContain("Complete");
    // The action link remains followable: re-reading an acknowledged policy is
    // not an error.
    expect(completed.find("a").exists()).toBe(true);
  });

  it("routes an action link to the surface that resolves the item", async () => {
    stubNode(() => ({ body: horizonPayload() }));

    const { wrapper, router } = await mountView();

    const link = wrapper.find(".event-horizon__action");
    expect(link.exists()).toBe(true);
    expect(link.text()).toBe("Open your acknowledgments");

    const target = router.resolve(link.attributes("href") ?? "");
    expect(target.name).toBe("staff.documents.acknowledgments");
  });

  it("offers the hide control only at zero outstanding items and names the way back", async () => {
    stubNode(() => ({ body: horizonPayload() }));

    const { wrapper } = await mountView();

    // One item outstanding: the control is absent, not disabled (19C.7).
    expect(wrapper.find(".event-horizon__hide").exists()).toBe(false);
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);
  });

  it("hides through the preference command and states its scope", async () => {
    const calls = stubNode(() => ({
      body: horizonPayload({
        can_hide: true,
        outstanding_count: 0,
        items: [itemPayload({ state: "complete" })],
      }),
    }));

    const { wrapper } = await mountView();

    const hide = wrapper.find(".event-horizon__hide");
    expect(hide.exists()).toBe(true);
    // The label states scope, and the way back is named beside the box
    // (19C.7): restoring lives on Me.
    expect(hide.text()).toContain("personal preference");
    expect(hide.text()).toContain("signs nothing off");
    expect(hide.text()).toContain("Me");

    await hide.find('input[type="checkbox"]').setValue(true);
    await flushPromises();

    const command = calls.find((call) =>
      call.url.endsWith("/commands/hide-event-horizon"),
    );
    expect(command?.method).toBe("POST");
    expect(command?.body).toEqual({ event_id: EVENT_ID });
  });

  it("redirects a typed address to home when the window does not apply", async () => {
    stubNode(() => ({
      body: horizonPayload({
        window: {
          applies: false,
          reason: "before_lead_up",
          lead_days: 30,
          opens_at: "2027-06-01T00:00:00+00:00",
          closes_at: "2027-07-10T00:00:00+00:00",
        },
      }),
    }));

    const { router } = await mountView();
    await flushPromises();

    // No route outside the window (19C.2): the address lands at home rather
    // than on an empty state explaining itself.
    expect(router.currentRoute.value.name).toBe("home");
  });

  /*
   * M18.43 rendered this surface from a stored copy of its own response, and
   * M18.50 deleted the store that held it. The Event Horizon is compiled on read
   * (M18.38) — its items are the node's evaluations against the moment it was
   * asked, not records — so the offline read set carries nothing to compose it
   * from, and until it carries a compiled section a device with no node in reach
   * is told so rather than shown an evaluation nobody re-ran.
   *
   * The surface's stored-copy handling (19C.9) is left standing: the disclosure,
   * the withheld all-clear, and the withheld hide control are all driven by the
   * freshness the seam reports, so they come back with the projection rather than
   * having to be written again.
   */
  it("says it needs a connection rather than compiling an answer of its own", async () => {
    stubNode(() => ({
      body: horizonPayload({
        can_hide: true,
        outstanding_count: 0,
        items: [itemPayload({ state: "complete" })],
      }),
    }));

    const first = await mountView();
    first.wrapper.unmount();

    stubUnreachableNode();

    const { wrapper } = await mountView();
    const text = wrapper.text();

    expect(text).toContain("Unable to load the Event Horizon");
    expect(text).not.toContain("Fire Safety Policy");
    // The all-clear is never presented off a read that did not happen either.
    expect(text).not.toContain("Nothing outstanding — everything below is complete.");
    expect(wrapper.find(".event-horizon__hide").exists()).toBe(false);
  });
});
