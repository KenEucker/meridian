// Durable device-local storage for the Logistics Window's department index
// (M18.8; SLB-021; CLIENT-023; technical spec 20.5; data/API 5.2).
//
// SLB-021 asks for department-scoped *offline* search across staff, equipment,
// and shifts. M16.21 delivered the department-scoped half by making the desk one
// read instead of a search endpoint plus a per-staff-member detail endpoint —
// data/API 5.2 says so in as many words: "One read of the whole department index
// is what a device can hold and search." Holding it is this module. Until now the
// desk held the response for the life of the page and nothing longer, so a desk
// reopened out of coverage had no index and therefore no search at all, which at
// a gate at two in the morning is a service station that cannot look anybody up.
//
// One entry, not one per department. The same reasoning as the session cache
// (M16.5): the entry names the event and department it was read for, and a desk
// only boots from it when both match the department being opened. A second
// department's index would be a second copy of every staff name on the device to
// answer a question nobody asked, and showing it under the wrong department
// heading is precisely the leak CLIENT-014 forbids. An operator who switches
// departments is online — that is what switching required — so the switch costs
// them a read they were making anyway.
//
// What is stored is what the node already told this client about a department it
// is entitled to work: staff names and handles, presence, the department's
// equipment with each item's holder, and the shifts inside the desk's horizon.
// No token, no credential, and nothing from outside the department. `localStorage`
// is the same device-local seam the session cache and the command outbox use and
// moves onto encrypted local storage with them.
//
// The entry does not expire on a clock. A desk's index is stale the moment it is
// stored and the surface says so with the timestamp it was read at, which is the
// honest disclosure; a duration invented here would either throw away the only
// copy a device has of a department it is still working, or claim freshness it
// cannot know. What drops the entry is a context switch, a sign-out, and a
// shared-workstation session end — all three through
// `registerSessionContextReset`.

import type { LogisticsDeskRead } from "@/department-ops/departmentOpsReadModel";

export const LOGISTICS_DESK_CACHE_KEY = "meridian.department-ops.logistics.v1";

export interface CachedLogisticsDesk {
  readonly desk: LogisticsDeskRead;
  /** When this device stored the copy, by its own clock. */
  readonly cachedAt: string;
}

interface StoredLogisticsDeskState {
  readonly version: 1;
  readonly eventId: string;
  readonly departmentId: string;
  readonly desk: LogisticsDeskRead;
  readonly cachedAt: string;
}

export interface LogisticsDeskCache {
  readonly read: (
    eventId: string,
    departmentId: string,
  ) => CachedLogisticsDesk | null;
  readonly write: (desk: LogisticsDeskRead, cachedAt: string) => void;
  readonly clear: () => void;
}

/**
 * Whether a stored value still has the shape the desk renders from.
 *
 * Structural rather than exhaustive, and deliberately so: what this has to catch
 * is an entry written by an older build whose read model had different fields,
 * because rendering half a desk is worse than rendering none. A stored copy that
 * carries the four collections and its context is one the surface can open;
 * anything else is dropped rather than repaired.
 */
function isStoredDesk(value: unknown): value is LogisticsDeskRead {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const desk = value as Record<string, unknown>;
  const context = desk.context as Record<string, unknown> | undefined;

  return (
    typeof context === "object" &&
    context !== null &&
    typeof context.eventId === "string" &&
    typeof context.departmentId === "string" &&
    typeof context.asOf === "string" &&
    typeof desk.access === "object" &&
    desk.access !== null &&
    Array.isArray(desk.searchableStaff) &&
    Array.isArray(desk.searchableEquipment) &&
    Array.isArray(desk.searchableShifts) &&
    typeof desk.staffWorkspaces === "object" &&
    desk.staffWorkspaces !== null &&
    !Array.isArray(desk.staffWorkspaces)
  );
}

function readStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

/**
 * Create a `localStorage`-backed cache, falling back to memory where storage is
 * unavailable.
 *
 * The fallback is not durability and does not pretend to be, exactly as in the
 * session cache: a device in private browsing or out of quota keeps its index for
 * the life of the page. It is still worth having, because the alternative is a
 * write that throws inside a successful read.
 */
export function createLogisticsDeskCache(
  storage: Storage | null = readStorage(),
): LogisticsDeskCache {
  let memoryFallback: StoredLogisticsDeskState | null = null;

  function coerce(
    state: StoredLogisticsDeskState | null,
    eventId: string,
    departmentId: string,
  ): CachedLogisticsDesk | null {
    if (
      state === null ||
      state.version !== 1 ||
      state.eventId !== eventId ||
      state.departmentId !== departmentId ||
      typeof state.cachedAt !== "string" ||
      !isStoredDesk(state.desk)
    ) {
      return null;
    }

    return Object.freeze({ desk: state.desk, cachedAt: state.cachedAt });
  }

  return {
    read(eventId: string, departmentId: string): CachedLogisticsDesk | null {
      if (!storage) {
        return coerce(memoryFallback, eventId, departmentId);
      }

      try {
        const raw = storage.getItem(LOGISTICS_DESK_CACHE_KEY);

        if (!raw) {
          return null;
        }

        return coerce(
          JSON.parse(raw) as StoredLogisticsDeskState,
          eventId,
          departmentId,
        );
      } catch {
        // A corrupt entry leaves the desk where a device that has never been
        // online is: it retries, or it waits. It does not open half an index.
        return null;
      }
    },

    write(desk: LogisticsDeskRead, cachedAt: string): void {
      const payload: StoredLogisticsDeskState = {
        version: 1,
        eventId: desk.context.eventId,
        departmentId: desk.context.departmentId,
        desk,
        cachedAt,
      };

      if (!storage) {
        memoryFallback = payload;

        return;
      }

      try {
        storage.setItem(LOGISTICS_DESK_CACHE_KEY, JSON.stringify(payload));
      } catch {
        memoryFallback = payload;
      }
    },

    clear(): void {
      memoryFallback = null;

      if (!storage) {
        return;
      }

      try {
        storage.removeItem(LOGISTICS_DESK_CACHE_KEY);
      } catch {
        // Nothing to recover: the in-memory copy is already gone.
      }
    },
  };
}

export const logisticsDeskCache = createLogisticsDeskCache();

/** The stored index for this department, when this device holds one. */
export function readCachedLogisticsDesk(
  eventId: string,
  departmentId: string,
): CachedLogisticsDesk | null {
  return logisticsDeskCache.read(eventId, departmentId);
}

/**
 * Replace the stored index with what the node just answered.
 *
 * A replacement, never a merge. A staff member removed from the department, an
 * item returned, a shift that fell out of the horizon: all three are absences in
 * the new response, and merging is how an absence becomes a row that outlives the
 * fact it described.
 */
export function writeCachedLogisticsDesk(
  desk: LogisticsDeskRead,
  cachedAt: string = new Date().toISOString(),
): void {
  logisticsDeskCache.write(desk, cachedAt);
}

/** Forget the stored index. Context switch, sign-out, and session end call this. */
export function clearCachedLogisticsDesk(): void {
  logisticsDeskCache.clear();
}
