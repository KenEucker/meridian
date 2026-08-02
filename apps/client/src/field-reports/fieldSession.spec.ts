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
import { clearClientSession } from "@/session/clientSession";
import { deviceId } from "@/session/deviceIdentity";
import {
  installLocalFieldSession,
  LOCAL_FIELD_ORGANIZATION_ID,
} from "@/session/localFieldSession";

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
  clearClientSession();
  resetFieldShiftResolver();
  resetSelectedFixtureDepartment();
  window.localStorage.clear();
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

    /*
     * Off shift, because nothing has installed a resolver that can say
     * otherwise (M18.9). The department and team used to arrive here from the
     * fixture's own idea of who was checked in, which was an answer no real
     * author ever got.
     */
    expect(session).toEqual({
      eventId: LOCAL_FIELD_FIXTURE.eventId,
      eventLabel: LOCAL_FIELD_FIXTURE.eventLabel,
      submittedByUserId: LOCAL_FIELD_FIXTURE.submittedByUserId,
      staffId: LOCAL_FIELD_FIXTURE.staffId,
      originDeviceId: LOCAL_FIELD_FIXTURE.originDeviceId,
      originNodeId: LOCAL_FIELD_FIXTURE.originNodeId,
      departmentId: null,
      departmentLabel: null,
      teamId: null,
      teamLabel: OFF_SHIFT_TEAM_LABEL,
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

/*
 * On-shift attribution is driven by an installed resolver now (M18.9).
 *
 * It used to come from the fixture, which answered only for fixture staff ids
 * and so answered "off shift" for every real author. Installing the resolver
 * these tests need makes the subject explicit: what is under test is that the
 * shift wins over the department switcher, not that a fixture had a shift in it.
 */
function installRangersDirtShift(): void {
  installFieldShiftResolver(() => ({
    shiftId: "shift-rangers-day",
    shiftTitle: "Ranger Dirt Day Shift",
    departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
    departmentLabel: "Rangers",
    teamId: FIXTURE_RANGERS_DIRT_TEAM_ID,
    teamLabel: "Dirt",
  }));
}

describe("resolveFieldSession while on shift", () => {
  it("records the team whose shift the author is checked into", () => {
    installRangersDirtShift();
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
    installRangersDirtShift();
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
  it("records no department and no team", () => {
    // A report filed on the author's own behalf is theirs and the event's.
    // FR-003 records author, title, and text; FR-010 lets a report exist
    // independently; technical spec 17.3 lists department/team context "if
    // available"; data/API 10.15 makes both columns nullable. Off shift it is
    // not available, so the record says so instead of guessing.
    offShift();
    installDevelopmentFieldSession();

    expect(resolveFieldSession()).toMatchObject({
      departmentId: null,
      departmentLabel: null,
      teamId: null,
      teamLabel: OFF_SHIFT_TEAM_LABEL,
    });
  });

  it("does not take the department the author happens to be looking at", () => {
    // The switcher is navigation state — which department's screens are open —
    // and never a claim about where somebody was standing. Attributing an
    // immutable record to it on the strength of what the author was reading is
    // the behavior this replaced.
    offShift();
    installDevelopmentFieldSession();

    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);

    expect(resolveFieldSession()?.departmentId).toBeNull();

    selectFixtureDepartment(FIXTURE_DPW_DEPARTMENT_ID);

    expect(resolveFieldSession()?.departmentId).toBeNull();
  });

  it("never borrows a team nobody was working", () => {
    // The author belongs to teams in Rangers. They were not working one, so the
    // report does not claim they were — not even the department's default team.
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

describe("resolveFieldSession from the client's own session (M16.22)", () => {
  it("takes the event, user, staff, and device from the session document", () => {
    offShift();
    installLocalFieldSession();

    // The identity is the node's answer; the device is this device. The origin
    // node is absent because a browser cannot learn one — the node that accepts
    // the command records itself as the origin.
    expect(resolveFieldSession()).toMatchObject({
      eventId: LOCAL_FIELD_FIXTURE.eventId,
      eventLabel: LOCAL_FIELD_FIXTURE.eventLabel,
      submittedByUserId: LOCAL_FIELD_FIXTURE.submittedByUserId,
      staffId: LOCAL_FIELD_FIXTURE.staffId,
      originDeviceId: deviceId(),
      originNodeId: null,
    });
  });

  it("is unavailable for a login that speaks for no staff record", () => {
    installLocalFieldSession({
      user: {
        id: "user-organizer",
        name: "No Staff",
        email: "no-staff@example.test",
        staff_ids: [],
      },
    });

    expect(resolveFieldSession()).toBeNull();
  });

  it("is unavailable while the session holds no event", () => {
    installLocalFieldSession({
      context: {
        organization_id: LOCAL_FIELD_ORGANIZATION_ID,
        event_id: null,
        department_id: null,
        node_locked: false,
        node_locked_event_id: null,
        switching_available: false,
      },
    });

    expect(resolveFieldSession()).toBeNull();
  });

  it("drops the development fixture once the node answers for somebody else", () => {
    // The failure this replaced: a developer boots on the fixture, signs in for
    // real, and files a Field Report against the fixture's event — which the
    // node refuses, because that event is not there.
    offShift();
    installDevelopmentFieldSession();
    installLocalFieldSession({
      user: {
        id: "user-real",
        name: "Real Operator",
        email: "real@example.test",
        staff_ids: ["staff-real"],
      },
    });

    expect(resolveFieldSession()).toMatchObject({
      submittedByUserId: "user-real",
      staffId: "staff-real",
      originDeviceId: deviceId(),
      originNodeId: null,
    });
  });

  it("keeps a deliberately pinned session whoever the node answers for", () => {
    installFieldSession(explicitSession);
    installLocalFieldSession();

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
