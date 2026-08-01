// What the signed-in user may reach, derived from the session response
// (M16.6; CLIENT-004 through CLIENT-006; UI operating guide 18.1;
// UI implementation contract 19A.1).
//
// `clientSession` publishes the document and the access verdict. This module is
// the one place that turns it into the shape navigation is built from: the
// departments the user is associated with, the teams they belong to inside each,
// and the role and capability codes that apply there.
//
// Three properties matter and each is load-bearing:
//
//  1. **Codes in, no screens out.** The session response carries no screen list
//     or menu structure and this module does not invent one. It answers "which
//     codes hold in this department", and `workflowLinks` answers "which entry
//     that permits" (contract 19A.1). Nothing here knows a route name.
//  2. **Scoped, not flat.** A person may run logistics for one department and be
//     ordinary staff in another, so a capability is only ever asked about
//     *somewhere*. The flat `capabilities` list on the document answers "at all",
//     which is the wrong question for a department switcher.
//  3. **Refused means empty.** When the access verdict is not granted — no
//     session, or a cached one whose event window has ended (CLIENT-008) — there
//     are no departments and therefore no navigation. A surface that forgets to
//     check the status still finds nothing to render against.
//
// None of it is enforcement. Server-side authorization is the boundary, and a
// client that fails to hide an action is still refused (CLIENT-006).

import { computed, ref } from "vue";

import { clientSessionState, sessionAccessGranted } from "@/session/clientSession";
import {
  ROLE_DEPARTMENT_LEAD,
  ROLE_SHIFT_LEAD,
} from "@/session/permissionCodes";
import {
  sessionContextEvent,
  type SessionDocument,
  type SessionRole,
} from "@/session/sessionDocument";

/**
 * A team the user belongs to.
 *
 * Every team in the session document is one of the caller's own, so there is no
 * membership flag: presence in the list is the membership. `isTeamLead` is the
 * designation team-scoped roles hang off (TEAM-009), reported so a surface can
 * name the team without re-deriving it from the role list.
 */
export interface SessionTeamAccess {
  readonly teamId: string;
  readonly teamLabel: string;
  readonly teamCode: string | null;
  readonly isDefault: boolean;
  readonly isTeamLead: boolean;
}

/** One department the user is associated with, and what holds there. */
export interface SessionDepartmentAccess {
  readonly departmentId: string;
  readonly departmentLabel: string;
  readonly departmentCode: string | null;
  readonly organizationId: string | null;
  readonly membershipStatus: string | null;
  readonly teams: readonly SessionTeamAccess[];
  /** Effective role codes resolved at this department. */
  readonly roleCodes: readonly string[];
  /** Capability codes those roles carry here, once each. */
  readonly capabilities: readonly string[];
  /** The roles themselves, for a denied surface that has to name one. */
  readonly roles: readonly SessionRole[];
}

/** The event the session resolved to, when it resolved to one. */
export interface SessionEventContext {
  readonly eventId: string;
  /** Null when the node answers for an event the caller holds no association with. */
  readonly eventLabel: string | null;
}

/**
 * When the event the session resolved to runs, as far as the client knows.
 *
 * The *active event window* first and the published dates as the fallback, which
 * is the same precedence `sessionStaleness` bounds a cached session by and the
 * same one authority is handed over on (data/API "Enforcing event authority").
 * A form defaulting a shift to "the event" means the operational phase, which is
 * what setup and teardown happen inside.
 *
 * Either end can be null on its own. An event with a recorded start and no
 * recorded end is a real state, and a caller that needs one of the two should
 * not lose it because the other was never set.
 */
export interface SessionEventWindow {
  readonly startsAt: string | null;
  readonly endsAt: string | null;
}

const selectedDepartmentStorageKey = "meridian.session.departmentId";

const selectedDepartmentId = ref<string | null>(readSelectedDepartmentId());

/** The document navigation may be built from, or null while access is refused. */
const grantedDocument = computed<SessionDocument | null>(() =>
  sessionAccessGranted.value ? clientSessionState.document : null,
);

/**
 * Whether the client holds a session it may act on.
 *
 * The gate for the personal pages — Me, the author's own Field Reports — which
 * belong to the user rather than to a department and which the catalog registers
 * no capability for. Holding a session at all is what permits them, and a client
 * with none renders no navigation whatsoever.
 */
export const sessionEstablished = computed(() => grantedDocument.value !== null);

/**
 * The event the session is operating in (CLIENT-011).
 *
 * Event-scoped routes are only constructible with an id, so this is the gate for
 * offering them at all. It follows the node's lock when there is one, because
 * that is what the server resolved the context from.
 */
export const sessionEventContext = computed<SessionEventContext | null>(() => {
  const document = grantedDocument.value;

  if (document === null || document.context.event_id === null) {
    return null;
  }

  return {
    eventId: document.context.event_id,
    eventLabel: sessionContextEvent(document)?.name ?? null,
  };
});

/**
 * The window the context event runs in, or null when the document carries no
 * event to read one off.
 *
 * A locked node can answer for an event the caller holds no association with, so
 * the document names an event it does not otherwise carry — there is a context
 * and no window, and a form that defaults from this leaves its fields blank
 * rather than defaulting from a guess.
 */
export const sessionEventWindow = computed<SessionEventWindow | null>(() => {
  const document = grantedDocument.value;
  const event = document === null ? null : sessionContextEvent(document);

  if (event === null) {
    return null;
  }

  return {
    startsAt: event.active_event_window_starts_at ?? event.starts_at,
    endsAt: event.active_event_window_ends_at ?? event.ends_at,
  };
});

/**
 * The departments the user is associated with, in the order the server sent
 * them, each carrying what holds there.
 */
export const sessionDepartmentAccesses = computed<
  readonly SessionDepartmentAccess[]
>(() => {
  const document = grantedDocument.value;

  if (document === null) {
    return [];
  }

  return document.departments.map((department) =>
    departmentAccess(document, department.id),
  );
});

/**
 * The department the client is currently working in, or null when the user is
 * associated with none.
 *
 * Precedence is explicit choice, then the context the server resolved
 * (CLIENT-011: a node locked to an event narrows to the user's own department
 * when there is exactly one), then the first association. A stored choice that
 * is no longer an association is ignored rather than honored, which is what
 * keeps a department removed on the server from surviving on the device.
 */
export const selectedSessionDepartment = computed<SessionDepartmentAccess | null>(
  () => {
    const departments = sessionDepartmentAccesses.value;

    if (departments.length === 0) {
      return null;
    }

    const chosen = departments.find(
      (department) => department.departmentId === selectedDepartmentId.value,
    );

    if (chosen !== undefined) {
      return chosen;
    }

    const contextDepartmentId = grantedDocument.value?.context.department_id;

    return (
      departments.find(
        (department) => department.departmentId === contextDepartmentId,
      ) ?? departments[0]!
    );
  },
);

/**
 * Route params for a department-scoped surface, or null when one cannot be
 * built.
 *
 * Both halves are required: a department route with no event is not a route, so
 * a client holding no event context offers no department-scoped entry rather
 * than a link that cannot resolve.
 */
export const selectedSessionDepartmentRouteParams = computed<{
  readonly eventId: string;
  readonly departmentId: string;
} | null>(() => {
  const department = selectedSessionDepartment.value;
  const event = sessionEventContext.value;

  if (department === null || event === null) {
    return null;
  }

  return { eventId: event.eventId, departmentId: department.departmentId };
});

/** The id the client is working under, whatever its source. */
export const selectedSessionDepartmentId = computed(
  () => selectedSessionDepartment.value?.departmentId ?? selectedDepartmentId.value,
);

/**
 * Work in a department.
 *
 * Accepts any id rather than validating against the current associations: the
 * router sets this from a route param, and a session that has not resolved yet
 * must not turn a legitimate deep link into a silent no-op. Resolution happens
 * on read, where an id with no association simply does not select anything.
 */
export function selectSessionDepartment(departmentId: string): void {
  selectedDepartmentId.value = departmentId;
  writeSelectedDepartmentId(departmentId);
}

/** Forget the explicit choice and fall back to the resolved context. */
export function resetSelectedSessionDepartment(): void {
  selectedDepartmentId.value = null;
  clearSelectedDepartmentId();
}

export function departmentHasCapability(
  department: SessionDepartmentAccess | null,
  ...codes: readonly string[]
): boolean {
  return (
    department !== null &&
    codes.some((code) => department.capabilities.includes(code))
  );
}

export function departmentHasRole(
  department: SessionDepartmentAccess | null,
  roleCode: string,
): boolean {
  return department !== null && department.roleCodes.includes(roleCode);
}

/**
 * The teams in this department the user is a designated lead of, and holds the
 * team-scoped role at.
 *
 * Both halves are checked because they answer different questions: the
 * designation says who they are to the team, and the role is the grant that
 * carries authority. A designation with no grant is a title, and the server
 * would refuse the surface it opens.
 */
export function sessionLedTeams(
  department: SessionDepartmentAccess | null,
  roleCode: string,
): readonly SessionTeamAccess[] {
  if (department === null) {
    return [];
  }

  const grantedTeamIds = new Set(
    department.roles
      .filter((role) => role.role_code === roleCode && role.team_id !== null)
      .map((role) => role.team_id as string),
  );

  return department.teams.filter(
    (team) => team.isTeamLead && grantedTeamIds.has(team.teamId),
  );
}

/**
 * A short description of why the user reaches what they reach here.
 *
 * Shown beside each entry in the department switcher, so someone who works
 * across departments can tell them apart by their standing rather than by
 * remembering which one is which. Built from role names the server sent, so it
 * says what the server would say.
 */
export function sessionDepartmentRoleSummary(
  department: SessionDepartmentAccess,
): string {
  const leadTeams = sessionLedTeams(department, ROLE_SHIFT_LEAD).map(
    (team) => team.teamLabel,
  );
  const isDepartmentLead = department.roleCodes.includes(ROLE_DEPARTMENT_LEAD);

  if (isDepartmentLead && leadTeams.length > 0) {
    return `Department lead; team lead for ${leadTeams.join(", ")}`;
  }

  if (isDepartmentLead) {
    return "Department lead";
  }

  if (leadTeams.length > 0) {
    return `Team lead for ${leadTeams.join(", ")}`;
  }

  const roleNames = department.roles
    .map((role) => role.role_name)
    .filter((name): name is string => typeof name === "string" && name !== "");

  return roleNames.length > 0
    ? [...new Set(roleNames)].join(", ")
    : "Staff member";
}

function departmentAccess(
  document: SessionDocument,
  departmentId: string,
): SessionDepartmentAccess {
  const department = document.departments.find(
    (candidate) => candidate.id === departmentId,
  )!;
  const roles = document.roles.filter(
    (role) => role.department_id === departmentId,
  );

  return {
    departmentId: department.id,
    departmentLabel: department.name,
    departmentCode: department.code,
    organizationId: department.organization_id,
    membershipStatus: department.membership_status,
    teams: document.teams
      .filter((team) => team.department_id === departmentId)
      .map((team) => ({
        teamId: team.id,
        teamLabel: team.name,
        teamCode: team.code,
        isDefault: team.is_default,
        isTeamLead: team.is_lead,
      })),
    roleCodes: [...new Set(roles.map((role) => role.role_code))],
    capabilities: [
      ...new Set(roles.flatMap((role) => [...role.capabilities])),
    ],
    roles,
  };
}

function readSelectedDepartmentId(): string | null {
  if (typeof window === "undefined") {
    return null;
  }

  try {
    return window.localStorage.getItem(selectedDepartmentStorageKey);
  } catch {
    return null;
  }
}

function writeSelectedDepartmentId(departmentId: string): void {
  if (typeof window === "undefined") {
    return;
  }

  try {
    window.localStorage.setItem(selectedDepartmentStorageKey, departmentId);
  } catch {
    // Remembering which department someone was working in is a convenience;
    // the switcher still works for the life of the tab without it.
  }
}

function clearSelectedDepartmentId(): void {
  if (typeof window === "undefined") {
    return;
  }

  try {
    window.localStorage.removeItem(selectedDepartmentStorageKey);
  } catch {
    // As above.
  }
}
