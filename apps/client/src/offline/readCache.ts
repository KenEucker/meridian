// Durable device-local storage for authorized reads (M18.9; technical spec 9.3,
// 9.5; CLIENT-021, CLIENT-022; UI implementation contract 16.2).
//
// The rule this exists to implement is one sentence of the technical
// specification and it is not a hedge:
//
//   "The device should cache as much authorized data as possible. Offline data
//    may be stale, but stale authorized data is better than no data."
//
// Section 9.3 then lists what each role should be holding — a staff member's own
// shifts, their department and team info, basic event info, the policies
// published to them; a lead's Department Overview summaries, department roster,
// schedule, and attendance; a planner's identity-free aggregate rows. Until this
// module, one surface cached anything: the Logistics Desk, because SLB-021 named
// it out loud. Everything else answered an unreachable node with "Unable to load
// … check the connection", which is the failure 9.3 is written against.
//
// It caches at the request seam rather than per read model, so a surface joins by
// changing which JSON helper it calls. A cache per model would mean thirty
// bespoke stores, thirty validation functions, and thirty chances to disagree
// about what "stale" means.
//
// Three rules make a stored copy safe to serve:
//
//  1. **Keyed by the whole request path, query included.** The Planning Table
//     filtered to one team is a different answer from the unfiltered one, and
//     serving either as the other would be a lie about what the numbers cover.
//  2. **Only a node that did not answer is fallen back on.** A `MeridianApiError`
//     carries a status, which means the node spoke — a refusal, a revoked token,
//     a department this user may no longer work. Serving a stored copy there
//     would be the client re-granting what the node had just withdrawn
//     (CLIENT-006, CLIENT-010).
//  3. **Nothing outlives the session that authorized it.** The whole store is
//     dropped on sign-out, on a context switch, and on a shared-workstation
//     session end, through the same registry every other context-scoped cache
//     uses. A device holds one user's authorized reads, not a pile of them.
//
// What is stored is what the node already sent this client about data it is
// entitled to read. `localStorage` is the same device-local seam the session
// cache and the command outbox use and moves onto encrypted local storage with
// them. PowerSync replication (M16.13) is the eventual home for the reads it
// covers; this is what the client can honestly do until then, and the disclosure
// it carries stays true either way.

import { ref } from "vue";

export const READ_CACHE_KEY = "meridian.reads.v1";

/** How many entries to keep. Oldest read is evicted first. */
const MAX_ENTRIES = 60;

/** Where the data on screen came from. */
export type ReadSource = "node" | "cache";

export interface ReadFreshness {
  readonly source: ReadSource;
  /**
   * Whether this copy is broader than what was asked for, and the caller has to
   * narrow it itself.
   *
   * True when a filtered or searched read fell back to the unfiltered copy this
   * device holds. The results are then only as complete as that copy, which is a
   * different claim from "these are the matches", and the surface says so.
   */
  readonly narrowed?: boolean;
  /**
   * When this device stored the copy, for a cached read; null for a live one.
   *
   * The disclosure hangs off this: a surface showing a stored copy names the
   * moment it was taken rather than presenting it as current.
   */
  readonly cachedAt: string | null;
}

export const LIVE_READ: ReadFreshness = Object.freeze({
  source: "node",
  cachedAt: null,
  narrowed: false,
});

export interface CachedRead<T> {
  readonly data: T;
  readonly freshness: ReadFreshness;
}

interface StoredEntry {
  readonly path: string;
  readonly payload: unknown;
  readonly cachedAt: string;
}

interface StoredState {
  readonly version: 1;
  readonly entries: StoredEntry[];
}

function readStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

export interface ReadCache {
  readonly read: (path: string) => StoredEntry | null;
  readonly write: (path: string, payload: unknown, cachedAt: string) => void;
  readonly clear: () => void;
}

/**
 * Create a `localStorage`-backed cache, falling back to memory where storage is
 * unavailable.
 *
 * The fallback is not durability and does not pretend to be: a device in private
 * browsing or out of quota keeps its reads for the life of the page. It is still
 * worth having, because the alternative is a write that throws inside a
 * successful read.
 */
export function createReadCache(
  storage: Storage | null = readStorage(),
): ReadCache {
  let memory: StoredEntry[] = [];

  function load(): StoredEntry[] {
    if (!storage) {
      return memory;
    }

    try {
      const raw = storage.getItem(READ_CACHE_KEY);

      if (!raw) {
        return [];
      }

      const parsed = JSON.parse(raw) as Partial<StoredState>;

      if (parsed.version !== 1 || !Array.isArray(parsed.entries)) {
        return [];
      }

      return parsed.entries.filter(
        (entry): entry is StoredEntry =>
          typeof entry === "object" &&
          entry !== null &&
          typeof (entry as StoredEntry).path === "string" &&
          typeof (entry as StoredEntry).cachedAt === "string",
      );
    } catch {
      // A corrupt store leaves the client where a device that has never been
      // online is: it retries, or it waits. It does not serve half a payload.
      return [];
    }
  }

  function save(entries: StoredEntry[]): void {
    if (!storage) {
      memory = entries;

      return;
    }

    try {
      storage.setItem(
        READ_CACHE_KEY,
        JSON.stringify({ version: 1, entries } satisfies StoredState),
      );
    } catch {
      /*
       * Out of quota. Halve the store and try once more before giving up to
       * memory: a device that has filled its budget should keep its most recent
       * reads rather than lose the lot, and the oldest are the ones worth least.
       */
      const half = entries.slice(Math.floor(entries.length / 2));

      try {
        storage.setItem(
          READ_CACHE_KEY,
          JSON.stringify({ version: 1, entries: half } satisfies StoredState),
        );
      } catch {
        memory = entries;
      }
    }
  }

  return {
    read(path: string): StoredEntry | null {
      return load().find((entry) => entry.path === path) ?? null;
    },

    write(path: string, payload: unknown, cachedAt: string): void {
      // Newest last, one entry per path, oldest evicted first.
      const entries = load().filter((entry) => entry.path !== path);

      entries.push({ path, payload, cachedAt });
      save(entries.slice(-MAX_ENTRIES));
    },

    clear(): void {
      memory = [];

      if (!storage) {
        return;
      }

      try {
        storage.removeItem(READ_CACHE_KEY);
      } catch {
        // Nothing to recover: the in-memory copy is already gone.
      }
    },
  };
}

export const readCache = createReadCache();

/**
 * Whether the last read this client completed came out of the store.
 *
 * A page-level signal for the shell, so a surface discloses staleness without
 * every read model having to thread freshness into its own view. The precise,
 * per-read notice still belongs on surfaces where one read is the whole page and
 * the moment it was taken changes what an operator does with it — the Logistics
 * Desk's index and the staff shift board both carry their own.
 *
 * Reset by the next live read rather than by a timer: what the reader needs to
 * know is whether what they are looking at came from the node, and that stops
 * being true the moment one answers.
 */
export const servingStoredReads = ref(false);

/** Note that a read was answered from the store, for the shell's disclosure. */
export function noteStoredRead(): void {
  servingStoredReads.value = true;
}

/** Note that the node answered, which clears the disclosure. */
export function noteLiveRead(): void {
  servingStoredReads.value = false;
}

/** Forget every stored read. Sign-out, context switch, and session end call this. */
export function clearReadCache(): void {
  readCache.clear();
  servingStoredReads.value = false;
}
