// The Event Horizon's data layer (M18.43, M18.44; HORIZON-001 through
// HORIZON-018; technical spec 21D; data/API 5.8A; UI contract 19C).
//
// One read fills the surface: `GET /api/events/{event}/event-horizon` answers
// with the registered item kinds this staff member can read, their items in
// the server's own order, whether the presentation window applies, and whether
// the member has hidden the surface.
//
// Three properties are load-bearing:
//
//  1. **The node decides everything about the list.** Which kinds are present,
//     each item's state, the words that explain it, and the order it renders
//     in are all the node's answers (HORIZON-006: "the client renders that
//     order and does not sort"). Nothing here re-derives a state or re-ranks a
//     row, because a client that did would be a second reading of readiness
//     that can only drift from the one the rules enforce (CLIENT-006).
//  2. **The read is cached like every other Alpha 1 read** (HORIZON-016), so a
//     device out of coverage renders the copy it holds with the staleness
//     disclosed rather than an empty page — and never presents "nothing
//     outstanding" it has not actually established (19C.9).
//  3. **The two preference commands are offline writes** by data/API 5.8A's
//     own word. The node re-checks the HORIZON-013 guard when the queue
//     drains, so a hide held past a new outstanding item is refused there.
//
// The module also keeps the one piece of state the workflow menu needs:
// whether the surface is present for the session's event (19C.2). The menu
// reads a reactive summary this module maintains; it never fetches on its own.

import { computed, reactive, type ComputedRef } from "vue";
import type { RouteLocationRaw } from "vue-router";

import { holdsMeridianCredential, meridianCachedJson } from "@/api/meridianApi";
import type { ReadFreshness } from "@/offline/readFreshness";
import { queueCommand } from "@/outbox/submitCommand";
import { syncCommandOutbox } from "@/outbox/syncCommandOutbox";

export type EventHorizonItemState = "outstanding" | "complete";

/** One item on the readiness list, exactly as the node worded it (19C.3). */
export interface EventHorizonItem {
  readonly kind: string;
  /** Stable identity for the underlying record (technical spec 21D.2). */
  readonly identity: string;
  readonly state: EventHorizonItemState;
  /** What it is. */
  readonly title: string;
  /** How it currently evaluates, in operational language. */
  readonly evaluation: string;
  /** What would complete it. */
  readonly completion: string;
  readonly dueAt: string | null;
  /** The UI contract screen id the action opens, and the words on the link. */
  readonly actionSurface: string;
  readonly actionLabel: string;
  readonly actionParams: Readonly<Record<string, string>>;
}

export interface EventHorizonKind {
  readonly id: string;
  readonly label: string;
  readonly module: string;
  readonly governedBy: string;
}

/** Where the moment stands against the presentation window (HORIZON-011). */
export interface EventHorizonWindow {
  readonly applies: boolean;
  readonly reason: string;
  readonly leadDays: number;
  readonly opensAt: string | null;
  readonly closesAt: string | null;
}

export interface EventHorizon {
  readonly eventId: string;
  readonly eventLabel: string | null;
  readonly timeZone: string;
  readonly asOf: string;
  readonly window: EventHorizonWindow;
  /** False when no kind is available to this viewer (HORIZON-017). */
  readonly presentable: boolean;
  readonly hidden: boolean;
  readonly canHide: boolean;
  readonly outstandingCount: number;
  readonly kinds: readonly EventHorizonKind[];
  readonly items: readonly EventHorizonItem[];
  readonly freshness: ReadFreshness;
}

interface EventHorizonItemPayload {
  readonly kind: string;
  readonly identity: string;
  readonly state: string;
  readonly title: string;
  readonly evaluation: string;
  readonly completion: string;
  readonly due_at?: string | null;
  readonly action?: {
    readonly surface?: string | null;
    readonly label?: string | null;
    readonly params?: Record<string, string> | null;
  } | null;
}

interface EventHorizonPayload {
  readonly context: {
    readonly event_id: string;
    readonly event_label?: string | null;
    readonly time_zone?: string | null;
    readonly as_of: string;
  };
  readonly window?: {
    readonly applies?: boolean;
    readonly reason?: string | null;
    readonly lead_days?: number;
    readonly opens_at?: string | null;
    readonly closes_at?: string | null;
  };
  readonly presentable?: boolean;
  readonly hidden?: boolean;
  readonly can_hide?: boolean;
  readonly outstanding_count?: number;
  readonly kinds?: readonly {
    readonly id: string;
    readonly label: string;
    readonly module?: string | null;
    readonly governed_by?: string | null;
  }[];
  readonly items?: readonly EventHorizonItemPayload[];
}

/**
 * What the workflow menu needs to know without fetching (19C.2): whether the
 * surface is present for the session's event. Updated by every read this
 * module performs, cleared when the read is for a different event than the
 * summary holds.
 */
export const eventHorizonPresence = reactive<{
  eventId: string | null;
  present: boolean;
  hidden: boolean;
}>({
  eventId: null,
  present: false,
  hidden: false,
});

/** Forget the menu summary. Tests and sign-out use this; nothing else should. */
export function resetEventHorizonPresence(): void {
  eventHorizonPresence.eventId = null;
  eventHorizonPresence.present = false;
  eventHorizonPresence.hidden = false;
}

function rememberPresence(horizon: EventHorizon): void {
  eventHorizonPresence.eventId = horizon.eventId;
  eventHorizonPresence.present = horizon.window.applies && horizon.presentable;
  eventHorizonPresence.hidden = horizon.hidden;
}

/**
 * Whether the workflow menu offers the Event Horizon for the given event
 * (19C.2): present only where the read said the window applies and the member
 * has not hidden it. Absent — not disabled, and never an empty state — outside
 * either condition.
 */
export function useEventHorizonMenuPresence(
  eventId: ComputedRef<string | null>,
): ComputedRef<boolean> {
  return computed(
    () =>
      eventId.value !== null &&
      eventHorizonPresence.eventId === eventId.value &&
      eventHorizonPresence.present &&
      !eventHorizonPresence.hidden,
  );
}

function toItem(payload: EventHorizonItemPayload): EventHorizonItem {
  return {
    kind: payload.kind,
    identity: payload.identity,
    state: payload.state === "complete" ? "complete" : "outstanding",
    title: payload.title,
    evaluation: payload.evaluation,
    completion: payload.completion,
    dueAt: payload.due_at ?? null,
    actionSurface: payload.action?.surface ?? "",
    actionLabel: payload.action?.label ?? "Open",
    actionParams: payload.action?.params ?? {},
  };
}

/**
 * Read one event's readiness list.
 *
 * Cached (HORIZON-016): a member standing where there is no signal still needs
 * to know what they have outstanding, and the surface disclosing the copy's
 * age is what keeps the stored answer honest (19C.9).
 */
export async function fetchEventHorizon(eventId: string): Promise<EventHorizon> {
  const { data, freshness } = await meridianCachedJson<EventHorizonPayload>(
    `/api/events/${eventId}/event-horizon`,
  );

  const horizon: EventHorizon = {
    eventId: data.context.event_id,
    eventLabel: data.context.event_label ?? null,
    timeZone: data.context.time_zone ?? "UTC",
    asOf: data.context.as_of,
    window: {
      applies: data.window?.applies ?? false,
      reason: data.window?.reason ?? "no_active_window",
      leadDays: data.window?.lead_days ?? 30,
      opensAt: data.window?.opens_at ?? null,
      closesAt: data.window?.closes_at ?? null,
    },
    presentable: data.presentable ?? false,
    hidden: data.hidden ?? false,
    canHide: data.can_hide ?? false,
    outstandingCount: data.outstanding_count ?? 0,
    kinds: (data.kinds ?? []).map((kind) => ({
      id: kind.id,
      label: kind.label,
      module: kind.module ?? "",
      governedBy: kind.governed_by ?? "",
    })),
    items: (data.items ?? []).map(toItem),
    freshness,
  };

  rememberPresence(horizon);

  return horizon;
}

/**
 * Refresh the menu's presence summary for the session's event, quietly.
 *
 * Called from the shell when the session resolves an event (19C.2 puts the
 * entry in the workflow menu, and a menu cannot render what nothing has
 * fetched). Failures leave the summary as it stands: a menu entry is not worth
 * an error, and the surface reports its own reads.
 *
 * A client holding no credential asks nothing. The node would answer 401, a
 * refusal `meridianCachedJson` rethrows rather than caching, so the request
 * could only spend a round trip to learn what `holdsMeridianCredential`
 * already says — the same reason the command outbox checks it before sending.
 */
export async function refreshEventHorizonPresence(
  eventId: string,
): Promise<void> {
  if (!holdsMeridianCredential()) {
    return;
  }

  try {
    await fetchEventHorizon(eventId);
  } catch {
    // The read failed and fell through the cache too. Say nothing: the menu
    // simply does not offer the entry until a read lands.
  }
}

function commandIdempotencyKey(commandType: string): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `${commandType}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

/**
 * Hide the surface for this event (HORIZON-012), or restore it (HORIZON-015).
 *
 * Queued like any other offline write (data/API 5.8A) and drained immediately
 * where there is a network. The presence summary updates at once so the menu
 * follows the person's decision without waiting on the drain; the node's
 * HORIZON-013 guard still decides a queued hide when it arrives, and a refusal
 * surfaces through the outbox notice like every other rejected command.
 */
export async function setEventHorizonHidden(
  eventId: string,
  hidden: boolean,
): Promise<void> {
  const commandType = hidden ? "hide-event-horizon" : "show-event-horizon";

  queueCommand({
    commandType,
    idempotencyKey: commandIdempotencyKey(commandType),
    payload: { event_id: eventId },
    eventId,
  });

  if (eventHorizonPresence.eventId === eventId) {
    eventHorizonPresence.hidden = hidden;
  }

  await syncCommandOutbox();
}

/**
 * Where an item's action link goes, or null when this client has no route for
 * the named surface yet.
 *
 * The node names a UI contract section 12 screen id plus the record's own
 * params, and this maps them to a route — the same shape the dashboard's
 * `widgetDestination` uses. Following a link enters the surface under that
 * surface's own authorization (HORIZON-004): a member may be refused there,
 * and that refusal is the linked surface's own honest answer.
 */
export function itemDestination(
  item: EventHorizonItem,
  eventId: string,
): RouteLocationRaw | null {
  switch (item.actionSurface) {
    case "staff.document-acknowledgments":
      return { name: "staff.documents.acknowledgments" };
    case "staff.shift-board":
      return { name: "staff.shifts.index" };
    case "organizer.waivers":
      return { name: "organizer.waivers.index" };
    case "department.training-detail": {
      const departmentId = item.actionParams.department_id;
      const trainingId = item.actionParams.training_id;

      return departmentId === undefined || trainingId === undefined
        ? null
        : {
            name: "events.departments.trainings.show",
            params: { eventId, departmentId, trainingId },
          };
    }
    case "department.shifts": {
      const departmentId = item.actionParams.department_id;

      return departmentId === undefined
        ? null
        : {
            name: "events.departments.shifts.index",
            params: { eventId, departmentId },
          };
    }
    default:
      return null;
  }
}

/** A deadline in the reader's own terms, or null where the item has none. */
export function itemDueLabel(item: EventHorizonItem): string | null {
  if (item.dueAt === null) {
    return null;
  }

  const moment = new Date(item.dueAt);

  return Number.isNaN(moment.getTime())
    ? item.dueAt
    : moment.toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
}
