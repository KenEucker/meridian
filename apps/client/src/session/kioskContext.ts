// What this Kiosk is working in (M18.32; UI-019, UI-020, UI-021; technical spec
// 13.1; UI contract 18.1).
//
// A shared workstation is pinned to one organization and one event, and
// optionally to one department. UI-019 makes that a precondition of normal
// operation and UI-020 makes it a fact the Kiosk is only allowed to *read*:
//
//   "When Kiosk mode starts without a pinned context, it shall enter setup
//    rather than inferring context from the current user, event data, viewport,
//    local network, or last route."
//
// So this module asks the node and publishes the answer. It derives nothing.
// There is no fallback to the session's event, none to the department the last
// route named, and none to whatever the device happens to have cached about
// events — each of those is one of the five inferences the requirement names.
//
// Three properties are load-bearing.
//
//  1. **It answers before anybody signs in.** Whether the Kiosk may offer a
//     login screen at all is what this decides, and a locked workstation holds
//     no credential to ask with. The read is therefore unauthenticated and keyed
//     by the workstation id this machine already knows (`workstationIdentity`),
//     which is not a credential either: entering the pinned event still needs a
//     login code the node issued to a named user (AUTH-030).
//  2. **The last answer is kept, and is not an inference.** A node that cannot
//     be reached is the ordinary state of an on-site machine, and a Kiosk that
//     dropped into setup every time the network hiccuped would be a Kiosk nobody
//     could work at. What is stored is the node's own answer about this machine,
//     alongside the machine's identity and for the same reason — it is
//     configuration, not session data, and it survives a restart the way the
//     workstation id does. Remembering what the node said is not deriving it
//     from something else.
//  3. **It grants nothing.** Pinned context "limits and frames the Kiosk shell;
//     it does not grant the active user any authority" (technical spec 13.3).
//     Nothing here is ever consulted to decide whether an action is allowed.

import { computed, reactive, readonly } from "vue";

import { MeridianApiError, meridianJson } from "@/api/meridianApi";
import { sharedWorkstationId } from "@/session/workstationIdentity";

const storageKey = "meridian.workstation.context";

export interface KioskPinnedEvent {
  readonly id: string;
  readonly name: string;
  readonly timeZone: string;
  readonly activeWindowStartsAt: string | null;
  readonly activeWindowEndsAt: string | null;
}

export interface KioskNamedRecord {
  readonly id: string;
  readonly name: string;
}

export interface KioskPinnedContext {
  readonly workstationId: string;
  readonly workstationName: string;
  /** UI-019 in one field: an organization and an event, both present. */
  readonly pinned: boolean;
  readonly organization: KioskNamedRecord | null;
  readonly event: KioskPinnedEvent | null;
  readonly department: KioskNamedRecord | null;
  readonly pinnedAt: string | null;
}

/** One event an authorized organizer may pin this workstation to. */
export interface KioskContextOption extends KioskPinnedEvent {
  readonly departments: readonly KioskNamedRecord[];
}

type KioskContextResolution =
  /** Nothing has been asked yet. */
  | "unresolved"
  /** A read is in flight. */
  | "resolving"
  /** The node answered. */
  | "resolved"
  /** The node could not be reached, and this is whatever was stored. */
  | "stored"
  /** The node answered that it knows no such trusted workstation. */
  | "unknown";

interface KioskContextState {
  status: KioskContextResolution;
  context: KioskPinnedContext | null;
  options: readonly KioskContextOption[];
  /** What to tell somebody whose pinned-context change was refused. */
  error: string | null;
  saving: boolean;
}

const state = reactive<KioskContextState>({
  status: "unresolved",
  context: readStoredContext(),
  options: [],
  error: null,
  saving: false,
});

export const kioskContextState = readonly(state);

/**
 * Whether this machine may operate as a Kiosk at all (UI-019).
 *
 * False while nothing has been resolved, which is the honest answer: a machine
 * that has not yet been told what it is pinned to is a machine in setup, and
 * that is where UI-020 says it belongs until the node says otherwise.
 */
export const kioskContextPinned = computed<boolean>(
  () => state.context?.pinned === true,
);

/** The event the workstation is pinned to, for a surface that reads in it. */
export const kioskPinnedEventId = computed<string | null>(
  () => state.context?.event?.id ?? null,
);

/** The department the workstation is pinned to, when it is pinned to one. */
export const kioskPinnedDepartmentId = computed<string | null>(
  () => state.context?.department?.id ?? null,
);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function asString(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

function readNamedRecord(value: unknown): KioskNamedRecord | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asString(value.id);

  return id === null ? null : { id, name: asString(value.name) ?? "" };
}

function readEvent(value: unknown): KioskPinnedEvent | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asString(value.id);

  if (id === null) {
    return null;
  }

  return {
    id,
    name: asString(value.name) ?? "",
    timeZone: asString(value.timezone) ?? "UTC",
    activeWindowStartsAt: asString(value.active_event_window_starts_at),
    activeWindowEndsAt: asString(value.active_event_window_ends_at),
  };
}

function readContext(value: unknown): KioskPinnedContext | null {
  if (!isRecord(value) || !isRecord(value.shared_workstation)) {
    return null;
  }

  const workstationId = asString(value.shared_workstation.id);

  if (workstationId === null) {
    return null;
  }

  return {
    workstationId,
    workstationName: asString(value.shared_workstation.name) ?? "",
    pinned: value.pinned === true,
    organization: readNamedRecord(value.organization),
    event: readEvent(value.event),
    department: readNamedRecord(value.department),
    pinnedAt: asString(value.context_pinned_at),
  };
}

function readOptions(value: unknown): readonly KioskContextOption[] {
  if (!isRecord(value) || !Array.isArray(value.options)) {
    return [];
  }

  return value.options
    .map((entry): KioskContextOption | null => {
      const event = readEvent(entry);

      if (event === null) {
        return null;
      }

      const departments = isRecord(entry) && Array.isArray(entry.departments)
        ? entry.departments
            .map(readNamedRecord)
            .filter((record): record is KioskNamedRecord => record !== null)
        : [];

      return { ...event, departments };
    })
    .filter((option): option is KioskContextOption => option !== null);
}

/**
 * Ask the node what this machine is pinned to.
 *
 * A refusal is the node speaking: this workstation is not one it holds, or is no
 * longer trusted, and a machine in that state is in setup whatever it has
 * stored. A request that never completed is an unreachable node, which is not a
 * verdict about the workstation — the stored answer stands and says so.
 */
export async function resolveKioskContext(): Promise<KioskPinnedContext | null> {
  const workstationId = sharedWorkstationId.value;

  if (workstationId === null) {
    state.status = "unknown";
    state.context = null;
    writeStoredContext(null);

    return null;
  }

  state.status = "resolving";

  try {
    const context = readContext(
      await meridianJson<unknown>(`/api/kiosk/workstations/${workstationId}`),
    );

    state.status = context === null ? "unknown" : "resolved";
    state.context = context;
    writeStoredContext(context);

    return context;
  } catch (error) {
    if (error instanceof MeridianApiError) {
      state.status = "unknown";
      state.context = null;
      writeStoredContext(null);

      return null;
    }

    state.status = "stored";

    return state.context;
  }
}

/**
 * The events this workstation may be pinned to (UI-021).
 *
 * Needs a credential, so it is only ever asked with somebody signed in — which
 * is also the only state a re-pin can happen from, since a workstation that is
 * not pinned cannot be signed in to at all.
 */
export async function loadKioskContextOptions(): Promise<void> {
  const workstationId = state.context?.workstationId ?? sharedWorkstationId.value;

  if (workstationId === null) {
    return;
  }

  state.error = null;

  try {
    const payload = await meridianJson<unknown>(
      `/api/kiosk/workstations/${workstationId}/pinned-context/options`,
    );

    state.options = readOptions(payload);

    const context = readContext(payload);

    if (context !== null) {
      state.context = context;
      writeStoredContext(context);
    }
  } catch (error) {
    state.options = [];
    state.error =
      error instanceof MeridianApiError
        ? error.message
        : "This workstation could not reach the node to read its events.";
  }
}

/**
 * Change the pinned event, and the optional pinned department (UI-021).
 *
 * The node ends whatever session the workstation is holding, because that
 * session was signed in to the previous context. Nothing here has to notice:
 * the next request the Kiosk makes is refused and `workstationSession` locks the
 * machine, which is the same path a timeout takes.
 */
export async function pinKioskContext(input: {
  readonly eventId: string;
  readonly departmentId: string | null;
}): Promise<boolean> {
  const workstationId = state.context?.workstationId ?? sharedWorkstationId.value;

  if (workstationId === null || state.saving) {
    return false;
  }

  state.saving = true;
  state.error = null;

  try {
    const payload = await meridianJson<unknown>(
      `/api/kiosk/workstations/${workstationId}/pinned-context`,
      {
        method: "PUT",
        body: JSON.stringify({
          event_id: input.eventId,
          department_id: input.departmentId,
        }),
      },
    );

    const context = readContext(payload);

    if (context !== null) {
      state.status = "resolved";
      state.context = context;
      writeStoredContext(context);
    }

    state.options = readOptions(payload);

    return context !== null;
  } catch (error) {
    state.error =
      error instanceof MeridianApiError
        ? error.message
        : "This workstation could not reach the node to change its pinned context.";

    return false;
  } finally {
    state.saving = false;
  }
}

function readStoredContext(): KioskPinnedContext | null {
  try {
    const stored = window.localStorage.getItem(storageKey);

    return stored === null ? null : readContext(JSON.parse(stored) as unknown);
  } catch {
    // A machine that cannot read its stored context asks the node, which is
    // where the answer comes from anyway.
    return null;
  }
}

function writeStoredContext(context: KioskPinnedContext | null): void {
  try {
    if (context === null) {
      window.localStorage.removeItem(storageKey);

      return;
    }

    window.localStorage.setItem(
      storageKey,
      JSON.stringify({
        shared_workstation: {
          id: context.workstationId,
          name: context.workstationName,
        },
        pinned: context.pinned,
        organization: context.organization,
        event:
          context.event === null
            ? null
            : {
                id: context.event.id,
                name: context.event.name,
                timezone: context.event.timeZone,
                active_event_window_starts_at: context.event.activeWindowStartsAt,
                active_event_window_ends_at: context.event.activeWindowEndsAt,
              },
        department: context.department,
        context_pinned_at: context.pinnedAt,
      }),
    );
  } catch {
    // The value still applies for this run. A machine that cannot persist it
    // re-reads on the next boot, which is what it does anyway.
  }
}

/** Reset module state between tests. */
export function resetKioskContext(): void {
  state.status = "unresolved";
  state.context = null;
  state.options = [];
  state.error = null;
  state.saving = false;
  writeStoredContext(null);
}
