// Field operational session context for Field Report author surfaces (M9.4;
// bound to the node's session in M16.22).
//
// Technical spec 17.3 and UI contract 14.2 require Field Report create/view to
// show event and department/team context and to set submitted-by from the
// authenticated session (not user-editable). That session is now the real one:
// the event, the user, and the staff record come off the session document the
// node answered `GET /api/me` with, and the device comes from this device's own
// identity (M16.11).
//
// Which matters because the alternative was wrong in the one way that shows up
// only against a real node. The development fixture named a seeded event, a
// seeded staff member, and a seeded device, and a signed-in operator filing a
// Field Report sent those ids to a node that had never heard of them — the node
// refused the submission with "Field Report event does not exist", which is
// exactly what it should say about an event that is not there.
//
// The origin node is the one identifier that does not come from anywhere: a
// browser cannot learn a node id, because nothing publishes one, deliberately.
// It is left absent and the node that receives the command records itself as the
// origin, the same way the attendance commands do since M16.21.
//
// When no session is installed and none can be derived, create/list fail closed
// with an explicit unavailable state rather than inventing identity.
//
// Identity and operational context come from different places, and keeping
// them apart is the point of this module. Who you are — user, staff record,
// device, node, event — is installed once and does not change while you work.
// Where you are working may or may not be knowable, and a Field Report records
// it only when it is:
//
//   - On shift, that is the team whose shift you are checked into, and that
//     team's department. The department switcher does not override it; you
//     cannot work a Rangers shift and file the report against Gate.
//   - Off shift, there is no department and no team. A report filed on your own
//     behalf is yours and the event's, and nothing else's.
//
// The second rule is the one worth being explicit about, because it used to say
// something else. Off shift, the report took whichever department was selected
// in the shell switcher — which is navigation state, "the screens I am looking
// at", not a fact about where anybody was standing. That stamped a department
// onto an immutable record on the strength of what the author happened to be
// reading, and none of the governing documents ask for it: FR-003 records author,
// title, and text; FR-010 says Field Reports may exist independently; technical
// spec 17.3 lists "department/team context *if available*"; and data/API 10.15
// makes both columns nullable. The server has always accepted a report with
// neither, and every one of its acceptance tests files one that way.
//
// So context is attached when it is a verified fact and omitted when it is not.
// "Off-shift" survives as a label the create screen shows, not as an attribution.
//
// A session declares whether it follows operational context, and
// `resolveFieldSession` composes it with identity on read.
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

import {
  OFF_SHIFT_TEAM_LABEL,
  resolveCurrentFieldShift,
} from "@/field-reports/fieldShiftAssignment";
import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";
import { clientSessionState } from "@/session/clientSession";
import { deviceId } from "@/session/deviceIdentity";
import { sessionEventContext } from "@/session/sessionAccess";

export interface FieldSessionContext {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly submittedByUserId: string;
  readonly staffId: string;
  readonly originDeviceId: string;
  /**
   * The node a report filed here originated at, when this client knows one.
   *
   * Null for a browser, which cannot: no response publishes a node id, and the
   * node receiving the command is the node the report originated at anyway. It
   * stays on the type because a client replaying a report that originated
   * somewhere else does know, and dropping the field would lose that.
   */
  readonly originNodeId: string | null;
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
  /**
   * Mark this as the local development stand-in rather than a pinned session, so
   * it stops applying once the node answers for somebody else.
   */
  readonly developmentFixture?: boolean;
}

let installedSession: FieldSessionContext | null = null;
let followsOperationalContext = false;
/**
 * Whether the installed session is the local development fixture rather than a
 * deliberately pinned one.
 *
 * The distinction decides when the install stops applying. A session a test or a
 * tool pinned is an override and stays one; the development fixture stands in
 * for a session nobody had, so the moment the node answers for somebody else it
 * is describing a user who is not here. Without that, a developer who boots the
 * fixture and then signs in for real files their Field Reports against the
 * fixture's event — which is the failure this milestone is fixing.
 */
let installedIsDevelopmentFixture = false;

/** Install the operational session used by Field Report author surfaces. */
export function installFieldSession(
  session: FieldSessionContext,
  options: InstallFieldSessionOptions = {},
): void {
  installedSession = session;
  followsOperationalContext = options.followOperationalContext ?? false;
  installedIsDevelopmentFixture = options.developmentFixture ?? false;
}

/** Clear the installed session (tests / logout placeholder). */
export function clearFieldSession(): void {
  installedSession = null;
  followsOperationalContext = false;
  installedIsDevelopmentFixture = false;
}

/**
 * Current field session, or `null` when auth/event context is unavailable.
 *
 * An installed session is an override and wins; otherwise the session comes off
 * the client's own session document. Either way the department and team are
 * where the author is working now rather than values carried from anywhere else,
 * which is what keeps a report filed on shift attributed to the team whose shift
 * it is.
 */
export function resolveFieldSession(): FieldSessionContext | null {
  if (installedSession !== null && installedOverrideApplies()) {
    return followsOperationalContext
      ? withOperationalContext(installedSession)
      : installedSession;
  }

  return sessionFromClient();
}

/**
 * Whether the installed session still describes the session in effect.
 *
 * True for anything but the development fixture. The fixture applies while it is
 * the document the client is working from and stops the moment a real one
 * replaces it, which is the same rule `installLocalFieldSessionFromEnv` follows
 * on the session side (M16.11).
 */
function installedOverrideApplies(): boolean {
  if (!installedIsDevelopmentFixture) {
    return true;
  }

  const document = clientSessionState.document;

  return (
    document === null || document.user.id === installedSession?.submittedByUserId
  );
}

/**
 * The field session the client's own session document describes, or null when
 * it describes none.
 *
 * Three things have to be true for a Field Report to be filable: an event in
 * effect, a user, and a staff record for that user to file as. A login that
 * speaks for no staff record is a real state — an organizer account that was
 * never added to a roster — and the honest answer for it is no field session,
 * which the create surface renders as unavailable rather than as a form that
 * will be refused on submit.
 */
function sessionFromClient(): FieldSessionContext | null {
  const document = clientSessionState.document;
  const event = sessionEventContext.value;

  if (document === null || event === null) {
    return null;
  }

  const staffId = document.user.staff_ids[0] ?? null;

  if (staffId === null) {
    return null;
  }

  return withOperationalContext({
    eventId: event.eventId,
    eventLabel: event.eventLabel ?? "This event",
    submittedByUserId: document.user.id,
    staffId,
    originDeviceId: deviceId(),
    originNodeId: null,
    departmentId: null,
    departmentLabel: null,
    teamId: null,
    teamLabel: null,
  });
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
 * department would not describe anything that happened.
 *
 * Off shift there is nothing to attribute the report to, and the honest record
 * of that is an absent department and an absent team rather than a plausible
 * one. `OFF_SHIFT_TEAM_LABEL` is carried as a label so the create screen can say
 * which case this is; it names no team and the report claims none.
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

  return {
    ...session,
    departmentId: null,
    departmentLabel: null,
    teamId: null,
    teamLabel: OFF_SHIFT_TEAM_LABEL,
  };
}

/**
 * Development helper that installs the well-known local Field fixture session
 * so author surfaces and command upload QA share server-seeded UUIDs
 * (`php artisan meridian:seed-local-field-fixture`).
 *
 * The fixture pins identity only. Department and team follow the current shift
 * and are absent when there is none, which is how real auth will behave.
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
    { followOperationalContext: true, developmentFixture: true },
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
