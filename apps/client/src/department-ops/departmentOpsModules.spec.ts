// Which regions the department operations surfaces compose (M19.18; MOD-019;
// technical spec 15A.7, 15A.8).
//
// The node omits the sections it does not run from the payload, and the client
// withholds the regions that would have rendered them. Both halves are needed
// and neither is enforcement: a region that survived would render empty rather
// than leak anything, and the point of removing it is that an empty region
// makes a false statement — "no equipment is checked out" describes a quiet
// desk, not an organization that has no equipment desk (CLIENT-005).

import { afterEach, describe, expect, it } from "vitest";

import { computed } from "vue";

import { useDepartmentOpsModules } from "@/department-ops/departmentOpsModules";
import { clearClientSession, installClientSession } from "@/session/clientSession";
import {
  LOCAL_FIELD_DEPARTMENT_IDS,
  localFieldOrganizationsWithout,
  localFieldSessionDocument,
} from "@/session/localFieldSessionFixture";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import {
  MODULE_EQUIPMENT,
  MODULE_EVENT_GEOGRAPHY,
  MODULE_SCHEDULING,
  type ModuleKey,
} from "@/session/sessionModules";

const RANGERS = LOCAL_FIELD_DEPARTMENT_IDS.rangers;

function establish(...inactive: readonly ModuleKey[]): void {
  installClientSession(
    localFieldSessionDocument({
      organizations: localFieldOrganizationsWithout(...inactive),
    }),
    "network",
  );
  selectSessionDepartment(RANGERS);
}

afterEach(() => {
  clearClientSession();
  resetSelectedSessionDepartment();
});

describe("the department operations module answers", () => {
  it("reports every region present for an organization running everything", () => {
    establish();

    const modules = useDepartmentOpsModules(computed(() => RANGERS));

    expect(modules.scheduling.value).toBe(true);
    expect(modules.equipment.value).toBe(true);
    expect(modules.geography.value).toBe(true);
  });

  it("withholds one region at a time and leaves the others standing", () => {
    for (const [inactive, expected] of [
      [MODULE_SCHEDULING, "scheduling"],
      [MODULE_EQUIPMENT, "equipment"],
      [MODULE_EVENT_GEOGRAPHY, "geography"],
    ] as const) {
      establish(inactive);

      const modules = useDepartmentOpsModules(computed(() => RANGERS));
      const answers = {
        scheduling: modules.scheduling.value,
        equipment: modules.equipment.value,
        geography: modules.geography.value,
      };

      /*
       * The failure this guards against is a surface that degrades correctly
       * for the module somebody tested and blanks for its neighbour, so the
       * assertion is on the whole set rather than on the one that moved.
       */
      expect(answers).toEqual({
        scheduling: expected !== "scheduling",
        equipment: expected !== "equipment",
        geography: expected !== "geography",
      });

      clearClientSession();
      resetSelectedSessionDepartment();
    }
  });

  it("answers for the organization the addressed department belongs to", () => {
    /*
     * A department-scoped address names its department before the client has
     * selected it, and somebody working across two organizations can address
     * either desk. Asking about a department the document does not carry falls
     * back to the organization on screen rather than guessing.
     */
    establish(MODULE_EQUIPMENT);

    const known = useDepartmentOpsModules(computed(() => RANGERS));
    const unknown = useDepartmentOpsModules(computed(() => "department-nowhere"));

    expect(known.equipment.value).toBe(false);
    expect(unknown.equipment.value).toBe(false);
  });

  it("presumes every region present when this client cannot say", () => {
    /*
     * Not knowing is not the same as running nothing (technical spec 15A.8). No
     * session document means no answer, and no answer leaves the region on
     * screen to be answered honestly by the node — the same direction the
     * router guard takes.
     */
    const modules = useDepartmentOpsModules(computed(() => RANGERS));

    expect(modules.scheduling.value).toBe(true);
    expect(modules.equipment.value).toBe(true);
    expect(modules.geography.value).toBe(true);
  });
});
