// The department self-administration surfaces' data layer (M16.15;
// CLIENT-023, TEAM requirements; data/API 10.6).
//
// Until this task the Admin surface read four departments compiled into the
// client, decided from a fixture role whether the person looking at it was a
// department lead, and archived a team by rewriting a module-level array. None
// of it reached a server, so nothing it showed was true of one.
//
// This module is now a translation of the endpoints in data/API 10.6. Three
// choices in it are deliberate:
//
//  1. **One read for the whole surface.** `GET /api/departments/{id}/teams`
//     already answers with the department, the caller's authority over it, its
//     teams, the staff on those teams, and the department roster. The Admin
//     page renders four panels off that one response rather than four requests
//     that could disagree with each other.
//  2. **Authority comes from the response.** The `access` block is the server's
//     own answer to what this caller may do, and it is the same answer the
//     command endpoints will enforce. The old client-side role predicates are
//     gone; what is left could only have drifted from the server (CLIENT-006).
//  3. **No cache and no client-side validation.** Nothing is held between
//     calls, every write is followed by a re-read, and refusals — a duplicate
//     team code, a default team that may not be archived — are the server's
//     words rather than a second copy of its rules.
//
// These are connected-only surfaces. Department administration is not in the
// closed set of offline-writable work (data/API 7.2), so a request made with no
// node reachable fails and says so rather than queueing.

import { meridianCachedJson, meridianJson } from "@/api/meridianApi";

export type TeamMembershipRole = "member" | "lead";

/** The department being administered, as the node holds it. */
export interface DepartmentSelfAdminDepartment {
  readonly id: string;
  readonly organizationId: string;
  readonly name: string;
  readonly code: string;
  readonly description: string | null;
  readonly defaultTeamId: string | null;
  readonly archivedAt: string | null;
}

export interface DepartmentTeam {
  readonly id: string;
  readonly departmentId: string;
  readonly name: string;
  readonly code: string;
  readonly description: string | null;
  readonly isDefault: boolean;
  readonly archivedAt: string | null;
  readonly createdAt: string;
  readonly updatedAt: string;
}

/** One staff member's membership of one team. */
export interface DepartmentTeamStaffMember {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly teamId: string;
  readonly teamLabel: string;
  readonly membershipRole: TeamMembershipRole;
  readonly roleLabel: string;
}

/** A department member, as offered to the assignment form. */
export interface DepartmentRosterMember {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
}

/**
 * What the caller may do in this department, as the node decided it.
 *
 * An administrator may also lead teams, so `canAdminister` and `ledTeamIds` are
 * not exclusive. Administer authority is the wider of the two and is asked
 * first wherever both would answer.
 */
export interface DepartmentTeamAdminAccess {
  readonly canAdminister: boolean;
  readonly canViewLedTeams: boolean;
  readonly ledTeamIds: readonly string[];
}

/** What the caller may do with one team, as the node decided it. */
export interface DepartmentTeamDetailAccess {
  readonly canAdminister: boolean;
  readonly canViewLedTeam: boolean;
}

/** One team and the authority over it, from one read. */
export interface DepartmentTeamDetail {
  readonly team: DepartmentTeam;
  readonly access: DepartmentTeamDetailAccess;
}

/**
 * One department operational function and the team designated to carry it
 * (M18.12; TEAM-011, TEAM-016), or no team where nothing is designated. The
 * node answers with one row per designatable function, so the panel renders
 * the whole frame without knowing the function list itself.
 */
export interface DepartmentTeamDesignation {
  readonly functionCode: string;
  readonly functionLabel: string;
  readonly roleCode: string;
  readonly teamId: string | null;
  readonly teamName: string | null;
}

/** Everything the Admin surface renders, from one read. */
export interface DepartmentTeamAdminWorkspace {
  readonly department: DepartmentSelfAdminDepartment | null;
  readonly access: DepartmentTeamAdminAccess;
  readonly teams: readonly DepartmentTeam[];
  readonly teamStaff: readonly DepartmentTeamStaffMember[];
  readonly departmentStaff: readonly DepartmentRosterMember[];
  readonly designations: readonly DepartmentTeamDesignation[];
}

/** The editable department fields, as held by the details form. */
export interface DepartmentDetailsDraft {
  name: string;
  code: string;
  description: string;
}

/** The editable team fields, as held by the create and edit forms. */
export interface DepartmentTeamDraft {
  name: string;
  code: string;
  description: string;
}

interface DepartmentPayload {
  readonly id: string;
  readonly organization_id: string;
  readonly name: string;
  readonly code: string;
  readonly description: string | null;
  readonly default_team_id: string | null;
  readonly archived_at: string | null;
}

interface TeamPayload {
  readonly id: string;
  readonly department_id: string;
  readonly name: string;
  readonly code: string;
  readonly description: string | null;
  readonly is_default: boolean;
  readonly archived_at: string | null;
  readonly created_at: string;
  readonly updated_at: string;
}

interface TeamStaffPayload {
  readonly staff_id: string;
  readonly display_name: string;
  readonly handle: string | null;
  readonly team_id: string;
  readonly team_name: string;
  readonly membership_role: string | null;
}

interface DepartmentStaffPayload {
  readonly staff_id: string;
  readonly display_name: string;
  readonly handle: string | null;
}

interface AccessPayload {
  readonly can_administer?: boolean;
  readonly can_view_led_teams?: boolean;
  readonly led_team_ids?: string[];
}

interface TeamAccessPayload {
  readonly can_administer?: boolean;
  readonly can_view_led_team?: boolean;
}

interface DesignationPayload {
  readonly function_code: string;
  readonly function_label: string;
  readonly role_code: string;
  readonly team_id: string | null;
  readonly team_name: string | null;
}

interface TeamIndexPayload {
  readonly department?: DepartmentPayload | null;
  readonly access?: AccessPayload;
  readonly teams?: TeamPayload[];
  readonly team_staff?: TeamStaffPayload[];
  readonly department_staff?: DepartmentStaffPayload[];
  readonly designations?: DesignationPayload[];
}

function toDepartment(payload: DepartmentPayload): DepartmentSelfAdminDepartment {
  return {
    id: payload.id,
    organizationId: payload.organization_id,
    name: payload.name,
    code: payload.code,
    description: payload.description,
    defaultTeamId: payload.default_team_id,
    archivedAt: payload.archived_at,
  };
}

function toTeam(payload: TeamPayload): DepartmentTeam {
  return {
    id: payload.id,
    departmentId: payload.department_id,
    name: payload.name,
    code: payload.code,
    description: payload.description,
    isDefault: payload.is_default,
    archivedAt: payload.archived_at,
    createdAt: payload.created_at,
    updatedAt: payload.updated_at,
  };
}

function toTeamStaffMember(payload: TeamStaffPayload): DepartmentTeamStaffMember {
  // A membership carries a lead designation or it does not; the server leaves
  // the column null for plain members on older rows.
  const isLead = payload.membership_role === "lead";

  return {
    staffId: payload.staff_id,
    displayName: payload.display_name,
    handle: payload.handle,
    teamId: payload.team_id,
    teamLabel: payload.team_name,
    membershipRole: isLead ? "lead" : "member",
    roleLabel: isLead ? "Team lead" : "Staff",
  };
}

function toRosterMember(payload: DepartmentStaffPayload): DepartmentRosterMember {
  return {
    staffId: payload.staff_id,
    displayName: payload.display_name,
    handle: payload.handle,
  };
}

function toDesignation(payload: DesignationPayload): DepartmentTeamDesignation {
  return {
    functionCode: payload.function_code,
    functionLabel: payload.function_label,
    roleCode: payload.role_code,
    teamId: payload.team_id,
    teamName: payload.team_name,
  };
}

/**
 * The submitted form, trimmed.
 *
 * The server trims too, so this changes nothing it stores. It changes what a
 * whitespace-only entry does: sent as typed it passes `required` and comes back
 * as a domain refusal, which reads oddly next to a field that visibly has
 * something in it.
 */
function toAttributes(
  draft: DepartmentDetailsDraft | DepartmentTeamDraft,
): Record<string, unknown> {
  const description = draft.description.trim();

  return {
    name: draft.name.trim(),
    code: draft.code.trim(),
    description: description === "" ? null : description,
  };
}

/**
 * Read the whole Admin surface for one department.
 *
 * Every team is asked for, archived ones included. The endpoint's `status`
 * parameter would narrow the teams it answers with, but the same response also
 * feeds the assignment form, which offers active teams whatever the table is
 * filtered to. Narrowing the table over this response is not the stale local
 * copy that filtering client-side usually means: `archivedAt` arrives on each
 * record from the request the surface just made.
 */
export async function getDepartmentTeamAdminWorkspace(
  departmentId: string,
): Promise<DepartmentTeamAdminWorkspace> {
  const result = (await meridianCachedJson<TeamIndexPayload>(
    `/api/departments/${departmentId}/teams?status=all`,
  )).data;

  return {
    department: result.department ? toDepartment(result.department) : null,
    access: {
      canAdminister: result.access?.can_administer ?? false,
      canViewLedTeams: result.access?.can_view_led_teams ?? false,
      ledTeamIds: result.access?.led_team_ids ?? [],
    },
    teams: (result.teams ?? []).map(toTeam),
    teamStaff: (result.team_staff ?? []).map(toTeamStaffMember),
    departmentStaff: (result.department_staff ?? []).map(toRosterMember),
    designations: (result.designations ?? []).map(toDesignation),
  };
}

/**
 * Read one team and the authority over it.
 *
 * The edit form is opened by two kinds of caller — someone who administers the
 * department and someone who only leads this one team — and only the first may
 * save. Which of the two is asking is on the response, so the form is shaped by
 * the same answer the update command will enforce.
 */
export async function getDepartmentTeam(
  departmentId: string,
  teamId: string,
): Promise<DepartmentTeamDetail> {
  const result = (await meridianCachedJson<TeamPayload & { access?: TeamAccessPayload }>(
    `/api/departments/${departmentId}/teams/${teamId}`,
  )).data;

  return {
    team: toTeam(result),
    access: {
      canAdminister: result.access?.can_administer ?? false,
      canViewLedTeam: result.access?.can_view_led_team ?? false,
    },
  };
}

export async function updateDepartmentDetails(
  departmentId: string,
  draft: DepartmentDetailsDraft,
): Promise<DepartmentSelfAdminDepartment> {
  return toDepartment(
    await meridianJson<DepartmentPayload>(
      "/api/commands/update-department-details",
      {
        method: "POST",
        body: JSON.stringify({
          department_id: departmentId,
          ...toAttributes(draft),
        }),
      },
    ),
  );
}

export async function createDepartmentTeam(
  departmentId: string,
  draft: DepartmentTeamDraft,
): Promise<DepartmentTeam> {
  return toTeam(
    await meridianJson<TeamPayload>("/api/commands/create-team", {
      method: "POST",
      body: JSON.stringify({
        department_id: departmentId,
        ...toAttributes(draft),
      }),
    }),
  );
}

/**
 * Replace a team's editable fields.
 *
 * The department is not sent: the server reads it off the team, which is also
 * what it authorizes against.
 */
export async function updateDepartmentTeam(
  teamId: string,
  draft: DepartmentTeamDraft,
): Promise<DepartmentTeam> {
  return toTeam(
    await meridianJson<TeamPayload>("/api/commands/update-team", {
      method: "POST",
      body: JSON.stringify({ team_id: teamId, ...toAttributes(draft) }),
    }),
  );
}

export async function archiveDepartmentTeam(
  teamId: string,
): Promise<DepartmentTeam> {
  return toTeam(
    await meridianJson<TeamPayload>("/api/commands/archive-team", {
      method: "POST",
      body: JSON.stringify({ team_id: teamId }),
    }),
  );
}

export async function restoreDepartmentTeam(
  teamId: string,
): Promise<DepartmentTeam> {
  return toTeam(
    await meridianJson<TeamPayload>("/api/commands/restore-team", {
      method: "POST",
      body: JSON.stringify({ team_id: teamId }),
    }),
  );
}

export async function assignStaffToTeam(
  teamId: string,
  staffId: string,
): Promise<void> {
  await meridianJson("/api/commands/assign-staff-to-team", {
    method: "POST",
    body: JSON.stringify({ team_id: teamId, staff_id: staffId }),
  });
}

export async function removeStaffFromTeam(
  teamId: string,
  staffId: string,
): Promise<void> {
  await meridianJson("/api/commands/remove-staff-from-team", {
    method: "POST",
    body: JSON.stringify({ team_id: teamId, staff_id: staffId }),
  });
}

export async function selectTeamLead(
  teamId: string,
  staffId: string,
): Promise<void> {
  await meridianJson("/api/commands/select-team-lead", {
    method: "POST",
    body: JSON.stringify({ team_id: teamId, staff_id: staffId }),
  });
}

export async function removeTeamLead(
  teamId: string,
  staffId: string,
): Promise<void> {
  await meridianJson("/api/commands/remove-team-lead", {
    method: "POST",
    body: JSON.stringify({ team_id: teamId, staff_id: staffId }),
  });
}

/**
 * Designate the team carrying a department operational function (TEAM-016),
 * replacing the function's current designation when one exists.
 */
export async function designateDepartmentTeam(
  departmentId: string,
  functionCode: string,
  teamId: string,
): Promise<void> {
  await meridianJson("/api/commands/designate-department-team", {
    method: "POST",
    body: JSON.stringify({
      department_id: departmentId,
      function_code: functionCode,
      team_id: teamId,
    }),
  });
}

export async function removeDepartmentTeamDesignation(
  departmentId: string,
  functionCode: string,
): Promise<void> {
  await meridianJson("/api/commands/remove-department-team-designation", {
    method: "POST",
    body: JSON.stringify({
      department_id: departmentId,
      function_code: functionCode,
    }),
  });
}

/**
 * Suggest a stable team code from a display name: uppercase, spaces and
 * punctuation collapsed to underscores, matching alpha_dash-friendly codes.
 *
 * A suggestion, not a rule: the field stays editable and the server decides
 * whether what was submitted is acceptable.
 */
export function suggestTeamCodeFromName(name: string): string {
  return name
    .trim()
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, 64);
}
