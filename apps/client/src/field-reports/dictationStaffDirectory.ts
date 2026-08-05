// Staff an operator may record a dictated Field Report for (M18.24A; FR-015
// through FR-017).
//
// An operator taking a report by radio or at a desk is writing down someone
// else's account. They need to name that person before they start typing, so
// this is the directory the create surface searches.
//
// It has been wrong twice, in two different ways. Until M18.9 it read
// `department-ops/fixtures.ts`, so the picker offered the same four invented
// people to every operator on every event and an operator who picked one filed
// a report against a staff id that existed nowhere. M18.9 pointed it at the
// Logistics Window read, which was real but scoped to whichever department was
// on screen rather than to the authority the caller holds — an `ic_operator`
// working the event got one department, and a Department Operator got one only
// because that is the page they came from.
//
// M18.24A gives dictation its own read, and it answers FR-017 directly: the
// staff this caller's taking authority already reaches, decided by the node
// from the roles they hold. A caller with no such authority is refused rather
// than handed an empty list, because an empty picker is indistinguishable from
// an event with nobody in it and tells an operator nothing they can act on.
//
// The shape stays deliberately narrow — an id, a name, and enough context to tell
// two people with similar names apart — so a wider staff record never leaks into
// the Field Report surfaces through here.

import { meridianCachedJson } from "@/api/meridianApi";

export interface DictationStaffOption {
  readonly staffId: string;
  readonly displayName: string;
  /** Handle or department, empty when the source has neither. */
  readonly detail: string;
}

interface DictationStaffPayload {
  readonly staff?: {
    readonly staff_id: string;
    readonly display_name: string;
    readonly handle: string | null;
    readonly department_label: string | null;
  }[];
}

/**
 * Read the staff an operator may name, sorted by display name.
 *
 * One read, and the node decides what is in it. Cached with the rest of the
 * device's authorized reads, so an operator who has opened the surface once can
 * still take a report with the node out of reach — the Field Report itself is
 * an offline write (data/API 7.2), and a picker that needed a live node would
 * be the one thing standing between a radio call and a record of it.
 */
export async function loadDictationStaffDirectory(
  eventId: string,
): Promise<readonly DictationStaffOption[]> {
  if (eventId === "") {
    return [];
  }

  const read = await meridianCachedJson<DictationStaffPayload>(
    `/api/events/${eventId}/field-report-dictation`,
  );

  return (read.data.staff ?? [])
    .map((staff) => ({
      staffId: staff.staff_id,
      displayName: staff.display_name,
      detail: staff.department_label ?? staff.handle ?? "",
    }))
    .sort((left, right) =>
      left.displayName.localeCompare(right.displayName, undefined, {
        sensitivity: "base",
      }),
    );
}

/**
 * Directory entries matching a typed query, capped so the picker stays a short
 * list rather than the whole roster. An empty query returns the first page of
 * the directory, because an operator who has not typed yet still wants to see
 * who they can pick.
 */
export function searchDictationStaff(
  query: string,
  directory: readonly DictationStaffOption[],
  limit = 8,
): readonly DictationStaffOption[] {
  const needle = query.trim().toLowerCase();

  if (needle.length === 0) {
    return directory.slice(0, limit);
  }

  return directory
    .filter(
      (option) =>
        option.displayName.toLowerCase().includes(needle) ||
        option.detail.toLowerCase().includes(needle),
    )
    .slice(0, limit);
}

/** Resolve one directory entry by staff id, or `null` when it is not listed. */
export function findDictationStaff(
  staffId: string,
  directory: readonly DictationStaffOption[],
): DictationStaffOption | null {
  return directory.find((option) => option.staffId === staffId) ?? null;
}
