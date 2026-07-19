import { afterEach, describe, expect, it } from "vitest";

import {
  clearFieldSession,
  installDevelopmentFieldSessionFromEnv,
  installFieldSession,
  resolveFieldSession,
  type FieldSessionContext,
} from "@/field-reports/fieldSession";
import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";

afterEach(() => {
  clearFieldSession();
});

describe("installDevelopmentFieldSessionFromEnv", () => {
  it("leaves the session unavailable when the local fixture flag is absent", () => {
    expect(installDevelopmentFieldSessionFromEnv({})).toBeNull();
    expect(resolveFieldSession()).toBeNull();
  });

  it("installs the local Field fixture session when the local fixture flag is true", () => {
    const session = installDevelopmentFieldSessionFromEnv({
      VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION: "true",
    });

    expect(session).toEqual({
      eventId: LOCAL_FIELD_FIXTURE.eventId,
      eventLabel: LOCAL_FIELD_FIXTURE.eventLabel,
      submittedByUserId: LOCAL_FIELD_FIXTURE.submittedByUserId,
      staffId: LOCAL_FIELD_FIXTURE.staffId,
      originDeviceId: LOCAL_FIELD_FIXTURE.originDeviceId,
      originNodeId: LOCAL_FIELD_FIXTURE.originNodeId,
      departmentId: LOCAL_FIELD_FIXTURE.departmentId,
      departmentLabel: LOCAL_FIELD_FIXTURE.departmentLabel,
      teamId: LOCAL_FIELD_FIXTURE.teamId,
      teamLabel: LOCAL_FIELD_FIXTURE.teamLabel,
    });
    expect(resolveFieldSession()).toBe(session);
  });

  it("keeps an explicitly installed session when local development setup runs again", () => {
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

    installFieldSession(explicitSession);

    expect(
      installDevelopmentFieldSessionFromEnv({
        VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION: "true",
      }),
    ).toBe(explicitSession);
  });
});
