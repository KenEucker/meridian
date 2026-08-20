// The device incident cache: every incident its user has viewed (INC-017,
// INC-018; technical spec 19.2, 9.3, 12.2).
//
// Incidents are deliberately not in the offline read set — "incidents should
// not be greedily synced" — so this cache is the one way an incident reaches a
// device, and its population rule is the point: **the user's own views, never
// bulk sync**. A device holds the incidents its user chose to open, not the
// event's incident log. An earlier Alpha 1 rule capped this at the last five
// viewed; the cap is gone, because a field device that silently dropped the
// sixth incident its user had read was a device losing data its user believed
// they held — the opposite of what the cache is for.
//
// Four bounds, each a requirement rather than housekeeping:
//
//  1. **Per user.** Entries carry the viewer's user id and are served back only
//     to that user. On a shared device, one person's reading history is not
//     another's offline data.
//  2. **Six-week expiry from the last view**, matching the device trust window
//     (technical spec 12.2). Re-viewing an incident refreshes its clock; an
//     entry nobody has opened in six weeks goes quietly.
//  3. **Flushed at logout.** `clearViewedIncidentCache` is registered on the
//     same reset registry the offline read set uses (`main.ts`), so sign-out,
//     signing in as somebody else, a context switch, and a shared-workstation
//     session end all drop it.
//  4. **Excluded from normal emergency exports.** Nothing here feeds an export,
//     and nothing may start to: the cache is a private reading copy, not a
//     record.
//
// In memory first, IndexedDB as the mirror, hydrated at import — the same
// shape as the offline read set store, for the same reason: reads are
// synchronous so a surface asking for a cached incident gets an answer, not a
// promise.

import { ref } from "vue";

import type { ImsIncident } from "@/ims/incidentReadModel";
import {
  createViewedIncidentStorage,
  type StoredViewedIncident,
  type ViewedIncidentStorage,
} from "@/ims/viewedIncidentStorage";
import { DEVICE_TRUST_WINDOW_MS } from "@/session/sessionStaleness";

/** How long an unviewed entry lives: six weeks from its last view (INC-018). */
export const VIEWED_INCIDENT_EXPIRY_MS = DEVICE_TRUST_WINDOW_MS;

/** One served entry: the incident and when its user last opened it. */
export interface ViewedIncidentEntry {
  readonly incident: ImsIncident;
  readonly lastViewedAt: string;
}

let storage: ViewedIncidentStorage = createViewedIncidentStorage();

const held = new Map<string, StoredViewedIncident>();

/**
 * Vue dependency for surfaces that read the cache — the Settings download
 * status reads the count through this (technical spec 9.7).
 */
export const viewedIncidentCacheRevision = ref(0);

/**
 * Whether an entry's six weeks have run out.
 *
 * An unreadable `lastViewedAt` reads as expired. The alternative is serving an
 * incident on the strength of a timestamp nobody could parse, which is the one
 * way a malformed value could extend an entry's life rather than shorten it.
 */
function expired(entry: StoredViewedIncident, now: Date): boolean {
  const viewed = Date.parse(entry.lastViewedAt);

  return Number.isNaN(viewed) || now.getTime() > viewed + VIEWED_INCIDENT_EXPIRY_MS;
}

/**
 * Drop every entry whose six weeks have run out. Removal is scoped to the
 * stale entries only — expiry is per entry, never a flush.
 */
function purgeExpired(now: Date): void {
  const stale: string[] = [];

  for (const [incidentId, entry] of held) {
    if (expired(entry, now)) {
      stale.push(incidentId);
      held.delete(incidentId);
    }
  }

  if (stale.length > 0) {
    void storage.remove(stale).catch(() => undefined);
    viewedIncidentCacheRevision.value += 1;
  }
}

let hydration: Promise<void> = Promise.resolve();

/** Read the durable copies into memory (import-time, and after test resets). */
export function hydrateViewedIncidentCache(now: Date = new Date()): Promise<void> {
  hydration = (async () => {
    let stored: readonly StoredViewedIncident[];

    try {
      stored = await storage.loadAll();
    } catch {
      // A storage that cannot be read leaves the device holding nothing, which
      // is where a device that has never viewed an incident is.
      return;
    }

    for (const entry of stored) {
      // What arrived by a view since boot is newer than what was on disk.
      if (!held.has(entry.incidentId)) {
        held.set(entry.incidentId, entry);
      }
    }

    purgeExpired(now);
    viewedIncidentCacheRevision.value += 1;
  })();

  return hydration;
}

/** Resolves once this device knows what it was already holding. */
export function viewedIncidentCacheHydrated(): Promise<void> {
  return hydration;
}

void hydrateViewedIncidentCache();

/**
 * Store — or refresh — an incident this user has just viewed while connected.
 *
 * Called from the incident read path, never from a sync: the population rule
 * is the user's own views (INC-018). A re-view replaces the copy and restarts
 * the entry's six weeks, which is what "expires six weeks after it was last
 * viewed" means.
 */
export function recordIncidentView(
  incident: ImsIncident,
  userId: string,
  now: Date = new Date(),
): void {
  const entry: StoredViewedIncident = {
    envelope: 1,
    userId,
    eventId: incident.eventId,
    incidentId: incident.id,
    lastViewedAt: now.toISOString(),
    incident: incident as unknown as Readonly<Record<string, unknown>>,
  };

  held.set(incident.id, entry);
  viewedIncidentCacheRevision.value += 1;
  void storage.save(entry).catch(() => undefined);
}

/**
 * The cached copy of one incident, or null when this device may not serve one.
 *
 * Null for an entry another user's view stored, for another event's incident,
 * and for an entry past its six weeks — three different absences with one
 * honest answer, because a cache miss is a cache miss to the surface asking.
 */
export function readViewedIncident(
  eventId: string,
  incidentId: string,
  userId: string,
  now: Date = new Date(),
): ViewedIncidentEntry | null {
  purgeExpired(now);

  const entry = held.get(incidentId);

  if (
    entry === undefined ||
    entry.userId !== userId ||
    entry.eventId !== eventId
  ) {
    return null;
  }

  return {
    incident: entry.incident as unknown as ImsIncident,
    lastViewedAt: entry.lastViewedAt,
  };
}

/**
 * How many incidents this user's views have cached.
 *
 * A count and never a fraction (technical spec 9.7): the cache's whole is the
 * user's own viewing, not something the node names, so there is no denominator
 * to be a fraction of. Zero for a caller with no session — and for every
 * non-IC user, who never views an incident and so never populates an entry.
 */
export function viewedIncidentCount(
  userId: string | null,
  now: Date = new Date(),
): number {
  void viewedIncidentCacheRevision.value;

  if (userId === null) {
    return 0;
  }

  purgeExpired(now);

  let count = 0;

  for (const entry of held.values()) {
    if (entry.userId === userId) {
      count += 1;
    }
  }

  return count;
}

/**
 * Forget every cached incident, in memory now and on disk as soon as it can be
 * reached (INC-018: removed at logout).
 *
 * Reached through the reset registry in `main.ts`, beside the offline read
 * set: sign-out, signing in as somebody else, a context switch, and a
 * shared-workstation session end all run it.
 */
export function clearViewedIncidentCache(): void {
  held.clear();
  viewedIncidentCacheRevision.value += 1;
  void storage.clear().catch(() => undefined);
}

/** Reset module state between tests, optionally onto an injected storage. */
export function resetViewedIncidentCacheForTests(
  nextStorage: ViewedIncidentStorage = createViewedIncidentStorage(),
): void {
  held.clear();
  storage = nextStorage;
  hydration = Promise.resolve();
  viewedIncidentCacheRevision.value = 0;
}
