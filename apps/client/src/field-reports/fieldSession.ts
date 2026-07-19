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

import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";

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
 * Development helper that installs the well-known local Field fixture session
 * so author surfaces and command upload QA share server-seeded UUIDs
 * (`php artisan meridian:seed-local-field-fixture`).
 */
export function installDevelopmentFieldSession(): FieldSessionContext {
  const session: FieldSessionContext = {
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
  };

  installFieldSession(session);

  return session;
}
