// The Event Horizon data layer (M18.43, M18.44; HORIZON-004, HORIZON-006,
// HORIZON-010; UI contract 19C.2, 19C.4).

import { computed, ref } from "vue";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  eventHorizonPresence,
  fetchEventHorizon,
  itemDestination,
  resetEventHorizonPresence,
  useEventHorizonMenuPresence,
  type EventHorizonItem,
} from "@/event-horizon/eventHorizonModel";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";

function item(overrides: Partial<EventHorizonItem> = {}): EventHorizonItem {
  return {
    kind: "training",
    identity: "training:t-1",
    state: "outstanding",
    title: "Radio Basics",
    evaluation: "This training is required for you and no completion is on record.",
    completion: "Complete the training from its training page.",
    dueAt: null,
    actionSurface: "department.training-detail",
    actionLabel: "Open the training page",
    actionParams: { department_id: "dept-1", training_id: "t-1" },
    ...overrides,
  };
}

function stubNode(body: unknown): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(JSON.stringify(body), {
          status: 200,
          headers: { "content-type": "application/json" },
        }),
    ),
  );
}

beforeEach(() => {
  clearOfflineReadSet();
  resetEventHorizonPresence();
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  configureMeridianApi(null);
  resetEventHorizonPresence();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("the menu presence summary", () => {
  it("offers the entry only for the event the node answered about, while it applies and is not hidden", async () => {
    stubNode({
      context: { event_id: "event-1", as_of: "2027-06-17T12:00:00+00:00" },
      window: { applies: true, reason: "open", lead_days: 30 },
      presentable: true,
      hidden: false,
      can_hide: false,
      outstanding_count: 1,
      kinds: [],
      items: [],
    });

    const eventId = ref<string | null>("event-1");
    const present = useEventHorizonMenuPresence(computed(() => eventId.value));

    // Nothing fetched yet: no entry, rather than a guess (19C.2).
    expect(present.value).toBe(false);

    await fetchEventHorizon("event-1");
    expect(present.value).toBe(true);

    // A different session event is a different answer: the summary does not
    // carry over.
    eventId.value = "event-2";
    expect(present.value).toBe(false);
  });

  it("withholds the entry outside the window and while hidden", async () => {
    stubNode({
      context: { event_id: "event-1", as_of: "2027-06-17T12:00:00+00:00" },
      window: { applies: false, reason: "before_lead_up", lead_days: 30 },
      presentable: true,
      hidden: false,
      can_hide: false,
      outstanding_count: 0,
      kinds: [],
      items: [],
    });

    const present = useEventHorizonMenuPresence(computed(() => "event-1"));

    await fetchEventHorizon("event-1");
    expect(present.value).toBe(false);

    // Hidden withholds it too (HORIZON-012), without touching the read.
    eventHorizonPresence.present = true;
    eventHorizonPresence.hidden = true;
    expect(present.value).toBe(false);
  });
});

describe("action link destinations", () => {
  it("routes each named surface with the record's own params, under that surface's own authorization", () => {
    expect(
      itemDestination(
        item({ actionSurface: "staff.document-acknowledgments" }),
        "event-1",
      ),
    ).toEqual({ name: "staff.documents.acknowledgments" });

    expect(
      itemDestination(item({ actionSurface: "staff.shift-board" }), "event-1"),
    ).toEqual({ name: "staff.shifts.index" });

    expect(itemDestination(item(), "event-1")).toEqual({
      name: "events.departments.trainings.show",
      params: { eventId: "event-1", departmentId: "dept-1", trainingId: "t-1" },
    });

    expect(
      itemDestination(
        item({
          actionSurface: "department.shifts",
          actionParams: { department_id: "dept-1", shift_id: "s-1" },
        }),
        "event-1",
      ),
    ).toEqual({
      name: "events.departments.shifts.index",
      params: { eventId: "event-1", departmentId: "dept-1" },
    });

    // A surface this client has no route for resolves to null, and the card
    // renders its action as absent rather than as a dead control.
    expect(
      itemDestination(item({ actionSurface: "surface.not-built" }), "event-1"),
    ).toBeNull();
  });
});
