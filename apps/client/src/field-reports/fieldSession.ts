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
//
// Identity and operational context come from different places, and keeping
// them apart is the point of this module. Who you are — user, staff record,
// device, node, event — is installed once and does not change while you work.
// Where you are working does change, and a Field Report has to record where
// you actually were when you filed it:
//
//   - On shift, that is the team whose shift you are checked into, and that
//     team's department. The department switcher does not override it; you
//     cannot work a Rangers shift and file the report against Gate.
//   - Off shift, there is no team, so the report takes the department you have
//     selected and records the team as off-shift.
//
// A session therefore declares whether it follows that operational context,
// and `resolveFieldSession` composes it with identity on read.
//
// Resolving on read is also what makes the create screen live. The author
// surfaces resolve the session inside a `computed`, so switching department
// updates the context they show without any of them subscribing to the
// switcher themselves.
//
// Only the create side is affected. A Field Report carries the department it
// was filed under, and the author list is scoped by author and never by
// department (FR-004; technical spec 17.6), so switching department does not
// hide reports filed from a different one.

import { selectedFixtureDepartment } from "@/department-teams/fixtureDepartmentAccess";
import {
  OFF_SHIFT_TEAM_LABEL,
  resolveCurrentFieldShift,
} from "@/field-reports/fieldShiftAssignment";
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

export interface InstallFieldSessionOptions {
  /**
   * Resolve department and team from the current shift, falling back to the
   * active department selection. Real auth will set this; a test that pins a
   * department deliberately leaves it off.
   */
  readonly followOperationalContext?: boolean;
}

let installedSession: FieldSessionContext | null = null;
let followsOperationalContext = false;

/** Install the operational session used by Field Report author surfaces. */
export function installFieldSession(
  session: FieldSessionContext,
  options: InstallFieldSessionOptions = {},
): void {
  installedSession = session;
  followsOperationalContext = options.followOperationalContext ?? false;
}

/** Clear the installed session (tests / logout placeholder). */
export function clearFieldSession(): void {
  installedSession = null;
  followsOperationalContext = false;
}

/**
 * Current field session, or `null` when auth/event context is unavailable.
 *
 * When the installed session follows operational context, the department and
 * team on the returned context are where the author is working now rather than
 * the values the session was installed with.
 */
export function resolveFieldSession(): FieldSessionContext | null {
  if (installedSession === null) {
    return null;
  }

  if (!followsOperationalContext) {
    return installedSession;
  }

  return withOperationalContext(installedSession);
}

/**
 * The session as installed, ignoring shift and department selection. Exposed
 * for anything that needs "who is signed in" rather than "where they are
 * working" — neither switching department nor checking into a shift changes
 * this.
 */
export function resolveInstalledFieldSession(): FieldSessionContext | null {
  return installedSession;
}

/**
 * Overlay where the author is working onto their identity.
 *
 * The shift wins when there is one, department included: a team belongs to
 * exactly one department, so a report attributed to a team under some other
 * department would not describe anything that happened. Off shift there is no
 * team to name, and the report says so rather than borrowing the department's
 * default team, which nobody was working.
 */
function withOperationalContext(
  session: FieldSessionContext,
): FieldSessionContext {
  const shift = resolveCurrentFieldShift(session.staffId);

  if (shift !== null) {
    return {
      ...session,
      departmentId: shift.departmentId,
      departmentLabel: shift.departmentLabel,
      teamId: shift.teamId,
      teamLabel: shift.teamLabel,
    };
  }

  const department = selectedFixtureDepartment.value;

  if (department === undefined) {
    return session;
  }

  return {
    ...session,
    departmentId: department.departmentId,
    departmentLabel: department.departmentLabel,
    teamId: null,
    teamLabel: OFF_SHIFT_TEAM_LABEL,
  };
}

/**
 * Development helper that installs the well-known local Field fixture session
 * so author surfaces and command upload QA share server-seeded UUIDs
 * (`php artisan meridian:seed-local-field-fixture`).
 *
 * The fixture pins identity only. Department and team follow the current
 * shift, and the shell's department switcher when there is no shift, which is
 * how real auth will behave.
 */
export function installDevelopmentFieldSession(): FieldSessionContext {
  installFieldSession(
    {
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
    },
    { followOperationalContext: true },
  );

  return resolveFieldSession()!;
}

/** Install the local development fixture session when enabled by Vite env. */
export function installDevelopmentFieldSessionFromEnv(
  env: Pick<ImportMetaEnv, "VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION"> = import.meta.env,
): FieldSessionContext | null {
  if (env.VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION !== "true") {
    return resolveFieldSession();
  }

  return resolveFieldSession() ?? installDevelopmentFieldSession();
}
