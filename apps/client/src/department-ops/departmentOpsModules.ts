// Which of the department operations surfaces' regions the organization runs
// (M19.18; MOD-019; technical spec 15A.7, 15A.8; data/API 5.9).
//
// The four surfaces — Department Overview, the Logistics Desk, the Operations
// Center, and the Planning Table — are core. Each of them composes records from
// modules an organization may not run, and none of them may fail, blank, or
// error because one is off. The node omits the sections it does not run from the
// payload; this is the client half, and it exists because omitting a section is
// not the same as the surface knowing why it is missing.
//
// The distinction matters on screen. An absent `searchable_equipment` and a
// department that happens to hold no equipment arrive at the client as the same
// empty array, and "No equipment is checked out" is a true sentence about the
// first and a misleading one about the second — it describes a desk that could
// have equipment out and does not, when the organization has no equipment desk
// at all. CLIENT-005 settles it: an unavailable thing is absent, not empty and
// not disabled. So the region goes rather than going quiet.
//
// The answers come from the session document's per-organization module set,
// which is cached with the offline permission cache — so a desk composed from
// the read set with no node in reach withholds the same regions a live one does
// (technical spec 15A.8).
//
// The department in the address decides which organization is asked, not the one
// the client was last working in: a lead who works in two organizations can
// address either desk, and each answers for itself.

import { computed, type ComputedRef } from "vue";

import {
  MODULE_EQUIPMENT,
  MODULE_EVENT_GEOGRAPHY,
  MODULE_SCHEDULING,
  moduleActiveForDepartment,
} from "@/session/sessionModules";

/** The three modules the department operations surfaces compose from. */
export interface DepartmentOpsModules {
  /** Shifts, signups, rosters, attendance cards, and plan-versus-actual rows. */
  readonly scheduling: ComputedRef<boolean>;
  /** The checkout desk, the inventory, and everything held. */
  readonly equipment: ComputedRef<boolean>;
  /** Deployments, and the location somebody is currently assigned to. */
  readonly geography: ComputedRef<boolean>;
}

/**
 * What this department's organization runs, for a surface to compose from.
 *
 * Each answer presumes yes where the client cannot say — no session document, a
 * document from a build older than MOD-015, or a department the document does
 * not carry. That is the same direction the router guard takes: the failure mode
 * of not knowing is a region that renders and is answered honestly by the node,
 * never a product that silently disappears.
 */
export function useDepartmentOpsModules(
  departmentId: ComputedRef<string>,
): DepartmentOpsModules {
  return {
    scheduling: computed(() =>
      moduleActiveForDepartment(departmentId.value || null, MODULE_SCHEDULING),
    ),
    equipment: computed(() =>
      moduleActiveForDepartment(departmentId.value || null, MODULE_EQUIPMENT),
    ),
    geography: computed(() =>
      moduleActiveForDepartment(
        departmentId.value || null,
        MODULE_EVENT_GEOGRAPHY,
      ),
    ),
  };
}
