// What a surface says about the copy it is rendering (M18.9, M18.50; technical
// spec 9.3; UI implementation contract 16.2).
//
// This is the contract every stale-read disclosure in the client hangs off, and
// it is deliberately unchanged by the move from the sixty-entry read cache to
// the offline read set (M18.48, M18.50). A surface says "this is the copy this
// device stored, taken at this moment" whichever store the copy came out of, so
// the migration replaced what fills the store and left what the reader is told
// exactly where it was.
//
// It lives apart from both stores for that reason: the disclosure outlives the
// mechanism. It was in `readCache.ts` until M18.50 deleted that module, and
// thirty surfaces importing their freshness type from whichever store happened
// to be current is how a contract ends up moving every time an implementation
// does.

/** Where the data on screen came from. */
export type ReadSource = "node" | "cache";

export interface ReadFreshness {
  readonly source: ReadSource;
  /**
   * Whether this answer covers less of the question than the request asked.
   *
   * True when a filtered or searched read was answered from what this device
   * holds. The results are then only as complete as that copy, which is a
   * different claim from "these are the matches", and the surface says so —
   * "nothing matches" and "nothing here matches" are not the same sentence.
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

/**
 * The freshness of a copy served out of the offline read set.
 *
 * `storedAt` is when the device took delivery of the set, not when the node
 * composed it. That is the honest one to show: a device that has been offline
 * for six hours holds a set it received six hours ago, and the composition time
 * would read as a claim about how current the rows are rather than about when
 * this device last heard anything.
 */
export function storedRead(
  storedAt: string | null,
  narrowed = false,
): ReadFreshness {
  return { source: "cache", cachedAt: storedAt, narrowed };
}
