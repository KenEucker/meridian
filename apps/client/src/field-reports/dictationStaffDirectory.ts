// Staff an operator may record a dictated Field Report for (M18.9; FR-015
// through FR-017).
//
// An operator taking a report by radio or at a desk is writing down someone
// else's account. They need to name that person before they start typing, so
// this is the directory the create surface searches.
//
// It read `department-ops/fixtures.ts` until M18.9, which meant the picker
// offered the same four invented people to every operator on every event, and an
// operator who picked one filed a report against a staff id that existed
// nowhere. The directory is now the department index the node already answers
// for the Logistics Window: one read, department-scoped, and scoped again by
// whatever the node will disclose to the caller.
//
// That the source is a node read rather than a list held here is the whole point
// of FR-016. M18.24A gives dictation its own server side and may narrow this
// further; until then an operator sees the department they are working and
// nobody outside it, rather than a fixture that respected no scope at all.
//
// The shape stays deliberately narrow — an id, a name, and enough context to tell
// two people with similar names apart — so a wider staff record never leaks into
// the Field Report surfaces through here.

import { getLogisticsDesk } from "@/department-ops/departmentOpsReadModel";

export interface DictationStaffOption {
  readonly staffId: string;
  readonly displayName: string;
  /** Team or other short disambiguator, empty when the source has none. */
  readonly detail: string;
}

/**
 * Read the staff an operator may name, sorted by display name.
 *
 * One read, and the node decides what is in it. A caller the node will not
 * answer for gets the refusal rather than a shorter list, because a directory
 * that quietly empties is indistinguishable from a department with nobody in it.
 */
export async function loadDictationStaffDirectory(
  eventId: string,
  departmentId: string,
): Promise<readonly DictationStaffOption[]> {
  if (eventId === "" || departmentId === "") {
    return [];
  }

  const desk = await getLogisticsDesk(eventId, departmentId);

  return desk.searchableStaff
    .map((staff) => ({
      staffId: staff.staffId,
      displayName: staff.displayName,
      detail: staff.teamLabel,
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
