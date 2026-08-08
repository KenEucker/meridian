// The shape of the set a device is handed, and what makes a stored one safe to
// serve (M18.48; ADR-0003; CLIENT-021, CLIENT-022; technical spec 9.3, 9.5,
// 11A.4, 11A.7).
//
// `GET /api/offline-read-set` composes the technical spec 9.3 lists for the
// caller and returns three things: a content-addressed `version`, the `sections`
// themselves as named lists of rows, and a `readiness` block naming what the set
// was bounded by. This module is the client's statement of that contract and the
// gate every stored or received set passes through.
//
// Rows are deliberately opaque here. The server decides what a
// `logistics_staff_index` row carries, and a client-side mirror of forty section
// shapes would be a second answer to that question — the failure ADR-0003 exists
// to stop, restated on the device. What this module validates is the envelope: a
// version to negotiate with, sections that are named lists, and the readiness a
// device bounds its own staleness by. A section whose rows are not what a surface
// expected is a bug in that surface's read model (M18.50), not something to be
// discovered halfway through parsing.
//
// Validation is refusal, not repair. A payload that does not match is not
// stored, and the device keeps what it already held: half a set has no honest
// disclosure — "these are your shifts, or some of them" is not something a
// surface can say to somebody standing in a field.

/** One row of one section. Its shape belongs to the section, not to this module. */
export type OfflineReadSetRow = Readonly<Record<string, unknown>>;

/** A section technical spec 9.3 names that this node cannot compose yet. */
export interface DeferredOfflineReadSetSection {
  readonly section: string;
  readonly reason: string;
}

/** When a computed section was computed, and how long it may be trusted. */
export interface OfflineReadSetAggregateFreshness {
  readonly section: string;
  readonly computed_at: string;
  readonly usable_until: string | null;
}

/**
 * What the set was bounded by, reported rather than implied.
 *
 * A device that can say which roles and which modules produced what it holds can
 * also say why a section it held yesterday is gone today (CLIENT-022, MOD-016),
 * and `usable_until` is the 11A.4 staleness rule as a moment rather than as a
 * rule the client re-derives.
 */
export interface OfflineReadSetReadiness {
  readonly composed_at: string;
  readonly context_event_id: string | null;
  readonly node_locked_event_id: string | null;
  readonly usable_until: string | null;
  readonly effective_role_codes: readonly string[];
  readonly active_modules: readonly string[];
  readonly deferred_sections: readonly DeferredOfflineReadSetSection[];
  readonly aggregate_freshness: readonly OfflineReadSetAggregateFreshness[];
  readonly counts: Readonly<Record<string, number>>;
}

/** One composed set, as the node sent it. */
export interface OfflineReadSetPayload {
  readonly version: string;
  readonly sections: Readonly<Record<string, readonly OfflineReadSetRow[]>>;
  readonly readiness: OfflineReadSetReadiness;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isRowList(value: unknown): value is readonly OfflineReadSetRow[] {
  return Array.isArray(value) && value.every((row) => isRecord(row));
}

function isNullableString(value: unknown): value is string | null {
  return value === null || typeof value === "string";
}

function isStringList(value: unknown): value is readonly string[] {
  return (
    Array.isArray(value) && value.every((entry) => typeof entry === "string")
  );
}

function isReadiness(value: unknown): value is OfflineReadSetReadiness {
  if (!isRecord(value)) {
    return false;
  }

  return (
    typeof value.composed_at === "string" &&
    isNullableString(value.context_event_id) &&
    isNullableString(value.node_locked_event_id) &&
    isNullableString(value.usable_until ?? null) &&
    isStringList(value.effective_role_codes) &&
    isStringList(value.active_modules) &&
    Array.isArray(value.deferred_sections) &&
    Array.isArray(value.aggregate_freshness) &&
    isRecord(value.counts)
  );
}

/**
 * Read a payload the node sent, or null when it is not one.
 *
 * A missing `version` is the one failure worth naming separately in the head,
 * because everything downstream hangs off it: without a version there is nothing
 * to send back as `If-None-Match`, so a device would re-transfer the whole set on
 * every refresh — on precisely the connection this endpoint is conditional for.
 */
export function readOfflineReadSetPayload(
  value: unknown,
): OfflineReadSetPayload | null {
  if (!isRecord(value)) {
    return null;
  }

  if (typeof value.version !== "string" || value.version === "") {
    return null;
  }

  if (!isRecord(value.sections) || !isReadiness(value.readiness)) {
    return null;
  }

  const sections: Record<string, readonly OfflineReadSetRow[]> = {};

  for (const [name, rows] of Object.entries(value.sections)) {
    if (!isRowList(rows)) {
      return null;
    }

    sections[name] = rows;
  }

  return {
    version: value.version,
    sections,
    readiness: value.readiness,
  };
}
