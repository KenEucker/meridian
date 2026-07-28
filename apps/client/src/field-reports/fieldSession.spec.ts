import { afterEach, describe, expect, it } from "vitest";

import {
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  FIXTURE_RANGERS_DIRT_TEAM_ID,
  resetSelectedFixtureDepartment,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import {
  installFieldShiftResolver,
  OFF_SHIFT_TEAM_LABEL,
  resetFieldShiftResolver,
} from "@/field-reports/fieldShiftAssignment";
import {
  clearFieldSession,
  installDevelopmentFieldSession,
  installDevelopmentFieldSessionFromEnv,
  installFieldSession,
  resolveFieldSession,
  resolveInstalledFieldSession,
  type FieldSessionContext,
} from "@/field-reports/fieldSession";
import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";

const explicitSession: FieldSessionContext = {
  eventId: "event-existing",
  eventLabel: "Existing Event",
  submittedByUserId: "user-existing",
  staffId: "staff-existing",
  originDeviceId: "device-existing",
  originNodeId: "node-existing",
  departmentId: "department-existing",
  departmentLabel: "Existing Department",
  teamId: "team-existing",
  teamLabel: "Existing Team",
};

/** Nobody is on shift. */
function offShift(): void {
  installFieldShiftResolver(() => null);
}

afterEach(() => {
  clearFieldSession();
  resetFieldShiftResolver();
  resetSelectedFixtureDepartment();
});

describe("installDevelopmentFieldSessionFromEnv", () => {
  it("leaves the session unavailable when the local fixture flag is absent", () => {
    expect(installDevelopmentFieldSessionFromEnv({})).toBeNull();
    expect(resolveFieldSession()).toBeNull();
  });

  it("installs the local Field fixture identity when the local fixture flag is true", () => {
    const session = installDevelopmentFieldSessionFromEnv({
      VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION: "true",
    });

    // The fixture staff member is checked into the Rangers Dirt day shift, so
    // that is what a report they file right now belongs to.
    expect(session).toEqual({
      eventId: LOCAL_FIELD_FIXTURE.eventId,
      eventLabel: LOCAL_FIELD_FIXTURE.eventLabel,
      submittedByUserId: LOCAL_FIELD_FIXTURE.submittedByUserId,
      staffId: LOCAL_FIELD_FIXTURE.staffId,
      originDeviceId: LOCAL_FIELD_FIXTURE.originDeviceId,
      originNodeId: LOCAL_FIELD_FIXTURE.originNodeId,
      departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
      departmentLabel: "Rangers",
      teamId: FIXTURE_RANGERS_DIRT_TEAM_ID,
      teamLabel: "Dirt",
    });
    expect(resolveFieldSession()).toEqual(session);
  });

  it("keeps an explicitly installed session when local development setup runs again", () => {
    installFieldSession(explicitSession);

    expect(
      installDevelopmentFieldSessionFromEnv({
        VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION: "true",
      }),
    ).toBe(explicitSession);
  });
});

describe("resolveFieldSession while on shift", () => {
  it("records the team whose shift the author is checked into", () => {
    installDevelopmentFieldSession();

    expect(resolveFieldSession()).toMatchObject({
      departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
      departmentLabel: "Rangers",
      teamId: FIXTURE_RANGERS_DIRT_TEAM_ID,
      teamLabel: "Dirt",
    });
  });

  it("keeps the shift's department even when another department is selected", () => {
    // You cannot work a Rangers shift and file the report against Gate, so the
    // shift wins over the switcher rather than being merged with it.
    installDevelopmentFieldSession();

    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);

    expect(resolveFieldSession()).toMatchObject({
      departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
      teamId: FIXTURE_RANGERS_DIRT_TEAM_ID,
      teamLabel: "Dirt",
    });
  });

  it("takes the department that owns the shift, not the one the shift is viewed from", () => {
    installFieldShiftResolver(() => ({
      shiftId: "shift-gate-swing",
      shiftTitle: "Gate Swing",
      departmentId: FIXTURE_GATE_DEPARTMENT_ID,
      departmentLabel: "Gate",
      teamId: "team-gate-credentials",
      teamLabel: "Credentials",
    }));
    installDevelopmentFieldSession();

    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);

    expect(resolveFieldSession()).toMatchObject({
      departmentId: FIXTURE_GATE_DEPARTMENT_ID,
      departmentLabel: "Gate",
      teamId: "team-gate-credentials",
      teamLabel: "Credentials",
    });
  });
});

describe("resolveFieldSession while off shift", () => {
  it("records the active department with no team", () => {
    offShift();
    installDevelopmentFieldSession();

    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);

    expect(resolveFieldSession()).toMatchObject({
      departmentId: FIXTURE_GATE_DEPARTMENT_ID,
      departmentLabel: "Gate",
      teamId: null,
      teamLabel: OFF_SHIFT_TEAM_LABEL,
    });
  });

  it("follows a later switch rather than resolving once at install", () => {
    offShift();
    installDevelopmentFieldSession();

    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);
    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);

    expect(resolveFieldSession()).toMatchObject({
      departmentId: FIXTURE_DPW_DEPARTMENT_ID,
      departmentLabel: "DPW",
      teamLabel: OFF_SHIFT_TEAM_LABEL,
    });
  });

  it("never borrows a team nobody was working", () => {
    // The department has teams, and the author belongs to one of them. They
    // were not working it, so the report does not claim they were.
    offShift();
    installDevelopmentFieldSession();

    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);

    expect(resolveFieldSession()?.teamId).toBeNull();
  });
});

describe("resolveFieldSession identity", () => {
  it("keeps identity fixed across a department switch", () => {
    offShift();
    installDevelopmentFieldSession();

    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);

    expect(resolveFieldSession()).toMatchObject({
      eventId: LOCAL_FIELD_FIXTURE.eventId,
      submittedByUserId: LOCAL_FIELD_FIXTURE.submittedByUserId,
      staffId: LOCAL_FIELD_FIXTURE.staffId,
      originDeviceId: LOCAL_FIELD_FIXTURE.originDeviceId,
      originNodeId: LOCAL_FIELD_FIXTURE.originNodeId,
    });
  });

  it("returns a pinned session unchanged, including its department and team", () => {
    // A session installed without opting into operational context is the seam
    // real auth and tests use to state a department deliberately.
    installFieldSession(explicitSession);
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);

    expect(resolveFieldSession()).toBe(explicitSession);
  });
});

describe("resolveInstalledFieldSession", () => {
  it("reports the session as installed, ignoring shift and department", () => {
    installDevelopmentFieldSession();

    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);

    expect(resolveInstalledFieldSession()).toMatchObject({
      departmentId: LOCAL_FIELD_FIXTURE.departmentId,
      teamId: LOCAL_FIELD_FIXTURE.teamId,
    });
  });

  it("is null when no session is installed", () => {
    expect(resolveInstalledFieldSession()).toBeNull();
  });
});
