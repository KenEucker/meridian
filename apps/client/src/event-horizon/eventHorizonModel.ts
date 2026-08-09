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
//  1. **Where there is a node, the node decides everything about the list.**
//     Which kinds are present, each item's state, the words that explain it,
//     and the order it renders in are all the node's answers (HORIZON-006:
//     "the client renders that order and does not sort"). Nothing here
//     re-derives a state or re-ranks a row off a live read, because a client
//     that did would be a second reading of readiness that can only drift from
//     the one the rules enforce (CLIENT-006).
//  2. **Where there is not, the device compiles what it holds** (HORIZON-016),
//     which is the one place the rule above gives way and does so because the
//     requirement says to: "shall compile from the permission-scoped data the
//     device already holds when no node is reachable, shall disclose that it is
//     incomplete rather than reaching past the sync boundary". The drift the
//     first property guards against is bounded by keeping that compile to the
//     single kind the read set can actually answer, wording its items exactly
//     as the node words them, and naming the other four as unevaluated rather
//     than guessing at them. A partial list never reads as a complete one, and
//     "nothing outstanding" is still never presented off a copy (19C.9).
//
//     M18.43 satisfied this from a cache of the node's own last response;
//     M18.50 deleted that cache and left the surface stating it needed a
//     connection, which the M18.53 audit found and this closes.
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
import type { OfflineReadProjection } from "@/offline/offlineReadProjection";
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
  /**
   * The kinds this answer could not evaluate (HORIZON-016).
   *
   * Always empty on a live read: the node evaluates every kind the viewer holds
   * or omits it from `kinds` entirely. It fills only when the list was compiled
   * on the device, and it is what stops a partial list reading as a complete
   * one — "you have nothing outstanding" and "the one thing this device could
   * check found nothing" are different sentences.
   */
  readonly unevaluatedKinds: readonly EventHorizonKind[];
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
  /**
   * Offline only. The node never sends this — it has no kind it cannot
   * evaluate — and the projection below is its only writer.
   */
  readonly unevaluated_kinds?: readonly {
    readonly id: string;
    readonly label: string;
    readonly module?: string | null;
    readonly governed_by?: string | null;
  }[];
}

/**
 * The five item kinds, mirrored from the server's `EventHorizonCatalog`.
 *
 * A duplication, and a deliberate one. Offline there is no response to read the
 * catalogue out of, and naming what could not be evaluated is the whole point of
 * the disclosure — "four kinds were not checked" is actionable in a way that
 * "this list may be incomplete" is not.
 *
 * It is a safe duplication because the catalogue is fixed: technical spec 21D.2
 * gives it no registration API and no table, and `EventHorizonContractTest`
 * asserts both the five entries and the absence of an `event_horizon_item_kinds`
 * table. A kind added there without being added here shows up as a kind this
 * device silently fails to name, which is why the ids are spelled out rather
 * than derived.
 */
const EVENT_HORIZON_KINDS: readonly EventHorizonKind[] = Object.freeze([
  {
    id: "document_acknowledgment",
    label: "Document acknowledgments",
    module: "documents",
    governedBy: "POL-043 through POL-047",
  },
  {
    id: "waiver",
    label: "Waivers",
    module: "documents",
    governedBy: "WAIVER-003 through WAIVER-006, CRED-005",
  },
  {
    id: "training",
    label: "Trainings",
    module: "qualifications",
    governedBy: "TRAIN-002, TRAIN-008, SHIFT-005",
  },
  {
    id: "shift_signup",
    label: "Shift signup",
    module: "scheduling",
    governedBy: "SHIFT-004, SHIFT-007, SHIFT-008, SHIFT-011, SHIFT-018",
  },
  {
    id: "coverage_gap",
    label: "Team coverage",
    module: "scheduling",
    governedBy: "SHIFT-007, requirements 4.7",
  },
]);

/** The one kind the offline read set carries enough to answer. */
const OFFLINE_EVALUABLE_KIND = "document_acknowledgment";

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

/**
 * Update the menu summary — from a live answer only (19C.2, HORIZON-010).
 *
 * A device-compiled list is deliberately not allowed to put the entry in the
 * menu. The presentation window needs the organization's configured lead-up
 * days, which no device holds, so an offline compile reports the window as
 * applying because it cannot rule it out — and letting that decide the menu
 * would have Meridian *offering* the Event Horizon outside the window it is
 * supposed to appear in, which is the requirement's whole point.
 *
 * Following the entry, or typing the address, still renders the compiled list.
 * The distinction is between what this client offers unprompted and what it
 * shows somebody who asked.
 */
function rememberPresence(horizon: EventHorizon): void {
  if (horizon.freshness.source === "cache") {
    return;
  }

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

/** One acknowledgment requirement, as the read set carries it. */
interface StoredRequirementRow {
  readonly id: string;
  readonly organization_id: string;
  readonly scope_type: string;
  readonly scope_id: string;
  readonly document_type: string;
  readonly document_id: string;
}

interface StoredAcknowledgmentRow {
  readonly document_type: string;
  readonly document_id: string;
  readonly scope_type: string;
  readonly scope_id: string;
}

interface StoredDocumentRow {
  readonly id: string;
  readonly title: string;
}

interface StoredNamedRow {
  readonly id: string;
  readonly name: string | null;
}

/**
 * The readiness list, compiled on the device (HORIZON-016).
 *
 * "The Event Horizon shall compile from the permission-scoped data the device
 * already holds when no node is reachable, shall disclose that it is incomplete
 * rather than reaching past the sync boundary, and shall persist no compiled
 * result." All three clauses are load-bearing, and the second is why this is
 * short.
 *
 * **One kind of the five, and that is a fact about the read set rather than a
 * shortcut.** Acknowledgments are compilable because the set carries all three
 * halves of the question — what was required, what was accepted, and the
 * published documents both refer to. The other four are not:
 *
 *   - waivers and trainings have no section in technical spec 9.3 at all;
 *   - coverage gaps are a lead's read of other people's shifts, which a member's
 *     set does not carry;
 *   - **shift signup is the one worth being explicit about.** The set carries
 *     `shifts`, but only the shifts this member is *already assigned to*
 *     (`RegularStaffSections` derives them from their own assignments), and it
 *     carries no assignment counts. So a device can establish "you are on this
 *     shift" and can never see a shift with a place left — which is the entire
 *     outstanding half of the kind. Compiling the complete half alone would put
 *     a list on screen that shows only shifts already held, and that reads as
 *     "nothing to sign up for": a false all-clear assembled out of true rows,
 *     which is exactly what 19C.9 forbids.
 *
 * So four kinds are reported as unevaluated and none of their items is guessed
 * at. Nothing is persisted: this composes on read from the set and the outbox,
 * the same as every other projection.
 *
 * Ordering is the client's here and only here (HORIZON-006 gives it to the
 * server). Outstanding before complete, then title, so two compiles of the same
 * set cannot differ — the server's deadline ordering has nothing to sort by,
 * since an acknowledgment carries no due date.
 */
function storedEventHorizon(
  eventId: string,
): OfflineReadProjection<EventHorizonPayload> {
  return (source) => {
    if (!source.carries("document_acknowledgment_requirements")) {
      return null;
    }

    const documents = new Map<string, StoredDocumentRow>([
      ...source
        .section<StoredDocumentRow>("policy_documents")
        .map((row): [string, StoredDocumentRow] => [`policy:${row.id}`, row]),
      ...source
        .section<StoredDocumentRow>("procedure_documents")
        .map((row): [string, StoredDocumentRow] => [
          `procedure:${row.id}`,
          row,
        ]),
    ]);

    const departmentNames = new Map<string, string>(
      source
        .section<StoredNamedRow>("departments")
        .map((row): [string, string] => [row.id, row.name ?? "your department"]),
    );

    const acknowledgments = source.section<StoredAcknowledgmentRow>(
      "document_acknowledgments",
    );

    const items = source
      .section<StoredRequirementRow>("document_acknowledgment_requirements")
      .flatMap<EventHorizonItemPayload>((requirement) => {
        const document = documents.get(
          `${requirement.document_type}:${requirement.document_id}`,
        );

        // The node sends only published documents in this caller's audience, so
        // a requirement with no document here is one this device cannot name.
        if (document === undefined) {
          return [];
        }

        const accepted = acknowledgments.some(
          (acknowledgment) =>
            acknowledgment.document_type === requirement.document_type &&
            acknowledgment.document_id === requirement.document_id &&
            acknowledgment.scope_type === requirement.scope_type &&
            acknowledgment.scope_id === requirement.scope_id,
        );

        // The node's own wording, so a stored row and a live one read the same.
        const scopeName =
          requirement.scope_type === "department"
            ? (departmentNames.get(requirement.scope_id) ?? "your department")
            : "the organization";

        return [
          {
            kind: OFFLINE_EVALUABLE_KIND,
            identity: `document-acknowledgment:${requirement.id}`,
            state: accepted ? "complete" : "outstanding",
            title: document.title,
            evaluation: accepted
              ? "You acknowledged this document, and the version you accepted is on record."
              : `${scopeName.charAt(0).toUpperCase()}${scopeName.slice(1)} asks you to acknowledge this document and you have not yet.`,
            completion: accepted
              ? "Nothing — this is done."
              : "Read the document and record your acknowledgment.",
            due_at: null,
            action: {
              surface: "staff.document-acknowledgments",
              label: "Open your acknowledgments",
            },
          },
        ];
      })
      .sort((left, right) => {
        if (left.state !== right.state) {
          return left.state === "outstanding" ? -1 : 1;
        }

        return left.title.localeCompare(right.title);
      });

    return {
      data: {
        context: {
          event_id: eventId,
          as_of: source.storedAt ?? "",
        },
        /*
         * The presentation window is the node's (HORIZON-010, HORIZON-011): it
         * needs the organization's configured lead-up days, which no device
         * holds. Rather than re-deriving it from a default that may not be this
         * organization's, the stored answer does not re-decide whether the
         * surface is offered — a reader who reached this page keeps the list
         * they came for, and the menu's presence stays whatever the last live
         * read established. `offline_unconfirmed` says which of the two this is.
         */
        window: { applies: true, reason: "offline_unconfirmed" },
        presentable: true,
        hidden: false,
        /*
         * Hiding is refused off a stored copy for the reason 19C.9 gives: the
         * device cannot establish that nothing is outstanding, and HORIZON-013
         * only allows hiding when nothing is.
         */
        can_hide: false,
        outstanding_count: items.filter((item) => item.state === "outstanding")
          .length,
        kinds: [
          {
            id: OFFLINE_EVALUABLE_KIND,
            label: "Document acknowledgments",
            module: "documents",
            governed_by: "POL-043 through POL-047",
          },
        ],
        items,
        unevaluated_kinds: EVENT_HORIZON_KINDS.filter(
          (kind) => kind.id !== OFFLINE_EVALUABLE_KIND,
        ).map((kind) => ({
          id: kind.id,
          label: kind.label,
          module: kind.module,
          governed_by: kind.governedBy,
        })),
      },
      narrowed: true,
    };
  };
}

/**
 * Read one event's readiness list.
 *
 * Compiled on the device when no node answers (HORIZON-016): a member standing
 * where there is no signal still needs to know what they have outstanding, and
 * the surface disclosing both the copy's age and what it could not evaluate is
 * what keeps that answer honest (19C.9).
 */
export async function fetchEventHorizon(eventId: string): Promise<EventHorizon> {
  const { data, freshness } = await meridianCachedJson<EventHorizonPayload>(
    `/api/events/${eventId}/event-horizon`,
    { offline: storedEventHorizon(eventId) },
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
    unevaluatedKinds: (data.unevaluated_kinds ?? []).map((kind) => ({
      id: kind.id,
      label: kind.label,
      module: kind.module ?? "",
      governedBy: kind.governed_by ?? "",
    })),
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
