// Field operational session context for Field Report author surfaces (M9.4).
//
// Technical spec 17.3 and UI contract 14.2 require Field Report create/view to
// show event and department/team context and to set submitted-by from the
// authenticated session (not user-editable). Full auth, device trust, and
// event-selection wiring remain later milestones; this module is the injectable
// seam those surfaces use until then.
//
// When no session is installed, create/list fail closed with an explicit
// unavailable state rather than inventing identity.

export interface FieldSessionContext {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly submittedByUserId: string;
  readonly staffId: string;
  readonly originDeviceId: string;
  readonly originNodeId: string;
  readonly departmentId: string | null;
  readonly departmentLabel: string | null;
  readonly teamId: string | null;
  readonly teamLabel: string | null;
}

let installedSession: FieldSessionContext | null = null;

/** Install the operational session used by Field Report author surfaces. */
export function installFieldSession(session: FieldSessionContext): void {
  installedSession = session;
}

/** Clear the installed session (tests / logout placeholder). */
export function clearFieldSession(): void {
  installedSession = null;
}

/** Current field session, or `null` when auth/event context is unavailable. */
export function resolveFieldSession(): FieldSessionContext | null {
  return installedSession;
}

/**
 * Development/testing helper that installs a clearly labeled placeholder
 * session so author Field Report surfaces remain exercisable before auth and
 * event selection land.
 */
export function installDevelopmentFieldSession(): FieldSessionContext {
  const session: FieldSessionContext = {
    eventId: "dev-event-1",
    eventLabel: "Development Event (placeholder)",
    submittedByUserId: "dev-user-1",
    staffId: "dev-staff-1",
    originDeviceId: "dev-device-1",
    originNodeId: "dev-node-1",
    departmentId: "dev-department-1",
    departmentLabel: "Development Department (placeholder)",
    teamId: "dev-team-1",
    teamLabel: "Development Team (placeholder)",
  };

  installFieldSession(session);

  return session;
}
