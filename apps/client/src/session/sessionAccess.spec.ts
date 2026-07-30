import { afterEach, describe, expect, it } from "vitest";

import {
  departmentBrandingRouteProps,
  organizationBrandingRouteProps,
} from "@/branding/brandingRouteProps";
import {
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_ORGANIZER_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
} from "@/department-teams/fixtureDepartmentAccess";
import { clearClientSession } from "@/session/clientSession";
import {
  installLocalFieldSession,
  localFieldSessionDocument,
} from "@/session/localFieldSession";
import {
  departmentHasCapability,
  departmentHasRole,
  resetSelectedSessionDepartment,
  selectSessionDepartment,
  selectedSessionDepartment,
  selectedSessionDepartmentRouteParams,
  sessionDepartmentAccesses,
  sessionDepartmentRoleSummary,
  sessionEstablished,
  sessionEventContext,
} from "@/session/sessionAccess";

/**
 * The scoped view of the session document navigation is built from (M16.6;
 * CLIENT-004 through CLIENT-006).
 */

afterEach(() => {
  clearClientSession();
  resetSelectedSessionDepartment();
});

describe("session department access", () => {
  it("holds nothing until a session is established", () => {
    expect(sessionEstablished.value).toBe(false);
    expect(sessionDepartmentAccesses.value).toEqual([]);
    expect(selectedSessionDepartment.value).toBeNull();
    expect(selectedSessionDepartmentRouteParams.value).toBeNull();
    expect(sessionEventContext.value).toBeNull();
  });

  it("scopes capabilities and roles to the department they were resolved at", () => {
    installLocalFieldSession();

    selectSessionDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    const rangers = selectedSessionDepartment.value;

    expect(departmentHasRole(rangers, "department_lead")).toBe(true);
    expect(departmentHasCapability(rangers, "department.administer")).toBe(true);
    expect(departmentHasCapability(rangers, "incidents.view")).toBe(true);

    selectSessionDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    const gate = selectedSessionDepartment.value;

    // The same signed-in person, one department over. A flat capability list
    // would say yes to both of these.
    expect(departmentHasRole(gate, "department_lead")).toBe(false);
    expect(departmentHasCapability(gate, "department.administer")).toBe(false);
    expect(departmentHasCapability(gate, "incidents.view")).toBe(false);
  });

  it("ignores a stored selection the session no longer carries", () => {
    // A department taken away on the server must not survive on the device as a
    // remembered choice. With no context department to fall back to either, the
    // client works in the first association it holds rather than in none.
    const document = localFieldSessionDocument();

    selectSessionDepartment("dept-that-does-not-exist");
    installLocalFieldSession({
      context: { ...document.context, department_id: null },
    });

    expect(selectedSessionDepartment.value?.departmentId).toBe(
      FIXTURE_ORGANIZER_DEPARTMENT_ID,
    );
  });

  it("prefers the department the server resolved the context to", () => {
    installLocalFieldSession({
      context: {
        organization_id: "88888888-8888-4888-8888-888888888888",
        event_id: "11111111-1111-4111-8111-111111111111",
        department_id: FIXTURE_GATE_DEPARTMENT_ID,
        node_locked: true,
        node_locked_event_id: "11111111-1111-4111-8111-111111111111",
        switching_available: false,
      },
    });

    expect(selectedSessionDepartment.value?.departmentId).toBe(
      FIXTURE_GATE_DEPARTMENT_ID,
    );
  });

  it("builds department route params only with an event to build them from", () => {
    const document = localFieldSessionDocument();

    installLocalFieldSession({
      context: { ...document.context, event_id: null },
    });
    selectSessionDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);

    expect(selectedSessionDepartment.value).not.toBeNull();
    expect(selectedSessionDepartmentRouteParams.value).toBeNull();
  });

  it("summarizes standing from the role names the server sent", () => {
    installLocalFieldSession();

    const byId = (id: string) =>
      sessionDepartmentAccesses.value.find(
        (department) => department.departmentId === id,
      )!;

    expect(sessionDepartmentRoleSummary(byId(FIXTURE_RANGERS_DEPARTMENT_ID))).toBe(
      "Department lead; team lead for Dirt",
    );
    expect(sessionDepartmentRoleSummary(byId(FIXTURE_DPW_DEPARTMENT_ID))).toBe(
      "Team lead for Bikes",
    );
    expect(sessionDepartmentRoleSummary(byId(FIXTURE_GATE_DEPARTMENT_ID))).toBe(
      "Staff",
    );
  });
});

describe("branding surface authority", () => {
  /**
   * The denied state on both branding screens is driven by `canManage`, and the
   * capability behind it is the one BRAND-019 draws the line at. A surface a
   * user may not edit is still shown to them read-only, which is where the
   * operating guide's rule that unavailable actions are hidden gives way to its
   * rule that a denied surface explains itself (18.1, 18.2).
   */
  it("permits organization branding from the organizer capability only", () => {
    installLocalFieldSession();

    selectSessionDepartment(FIXTURE_ORGANIZER_DEPARTMENT_ID);
    expect(organizationBrandingRouteProps().canManage).toBe(true);

    selectSessionDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    expect(organizationBrandingRouteProps().canManage).toBe(false);
  });

  it("permits department branding from the department capability only", () => {
    installLocalFieldSession();

    selectSessionDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
    expect(departmentBrandingRouteProps()).toMatchObject({
      departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
      departmentName: "Rangers",
      canManage: true,
    });

    // A designated team lead administers shifts and their own team, and has no
    // say over the department's identity.
    selectSessionDepartment(FIXTURE_DPW_DEPARTMENT_ID);
    expect(departmentBrandingRouteProps().canManage).toBe(false);
  });

  it("refuses both branding surfaces with no session at all", () => {
    expect(organizationBrandingRouteProps().canManage).toBe(false);
    expect(departmentBrandingRouteProps().canManage).toBe(false);
  });
});
