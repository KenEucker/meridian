// Staff an operator may record a dictated Field Report for.
//
// An operator taking a report by radio or at a desk is writing down someone
// else's account. They need to name that person before they start typing, so
// this is the directory the create surface searches.
//
// It reads the department operations fixtures rather than a directory endpoint
// because that is where the client's staff roster lives today; when a real
// staff directory read lands, this module is the single seam that changes. The
// shape it returns is deliberately narrow — an id, a name, and enough context to
// tell two people with similar names apart — so a wider staff record never leaks
// into the Field Report surfaces through here.

import { LOCAL_DEPARTMENT_OVERVIEW, LOCAL_LOGISTICS_DESK } from "@/department-ops/fixtures";

export interface DictationStaffOption {
  readonly staffId: string;
  readonly displayName: string;
  /** Team or other short disambiguator, empty when the source has none. */
  readonly detail: string;
}

/**
 * Every staff member an operator can name, sorted by display name.
 *
 * Sources are merged on `staffId` with the first one winning, so a staff member
 * who appears in both the logistics roster and the shift assignments is listed
 * once.
 */
export function dictationStaffDirectory(): readonly DictationStaffOption[] {
  const byStaffId = new Map<string, DictationStaffOption>();

  for (const staff of LOCAL_LOGISTICS_DESK.searchableStaff) {
    byStaffId.set(staff.staffId, {
      staffId: staff.staffId,
      displayName: staff.displayName,
      detail: staff.teamLabel,
    });
  }

  for (const assignment of LOCAL_DEPARTMENT_OVERVIEW.assignments) {
    if (byStaffId.has(assignment.staffId)) {
      continue;
    }

    byStaffId.set(assignment.staffId, {
      staffId: assignment.staffId,
      displayName: assignment.displayName,
      detail: assignment.teamLabel,
    });
  }

  return [...byStaffId.values()].sort((left, right) =>
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
  limit = 8,
  directory: readonly DictationStaffOption[] = dictationStaffDirectory(),
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
  directory: readonly DictationStaffOption[] = dictationStaffDirectory(),
): DictationStaffOption | null {
  return directory.find((option) => option.staffId === staffId) ?? null;
}
