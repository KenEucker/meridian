import { shallowRef } from "vue";

import {
  fixtureDepartmentAccesses,
  fixtureDepartmentHasAdminAccess,
  selectedFixtureDepartment,
  type FixtureDepartmentAccess,
  type FixtureTeamStaffMember,
} from "@/department-teams/fixtureDepartmentAccess";

export type DepartmentSelfAdminRole =
  | "department_lead"
  | "department_administration"
  | "team_lead"
  | "staff";

export interface DepartmentSelfAdminSession {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly departmentId: string;
  readonly departmentLabel: string;
  readonly role: DepartmentSelfAdminRole;
  readonly roleLabel: string;
  readonly teamLeadTeamIds: readonly string[];
  /** Teams the signed-in staff member belongs to, lead or not (TEAM-008). */
  readonly memberTeamIds: readonly string[];
}

export interface DepartmentSelfAdminDepartment {
  readonly id: string;
  readonly organizationId: string;
  readonly name: string;
  readonly code: string;
  readonly description: string | null;
  readonly defaultTeamId: string | null;
  readonly archivedAt: string | null;
  readonly updatedAt: string;
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

export interface DepartmentTeamStaffMember extends FixtureTeamStaffMember {
  readonly teamId: string;
  readonly teamLabel: string;
}

export type TeamMembershipRole = "member" | "lead";

/**
 * Mutable team staff membership managed from the Admin surface (M11.17):
 * department leads designate team leads, and department/team leads assign or
 * remove permitted staff on teams they manage.
 */
export interface ManagedTeamStaffMember {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly teamId: string;
  readonly membershipRole: TeamMembershipRole;
}

export interface DepartmentRosterMember {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
}

export interface DepartmentDetailsDraft {
  name: string;
  code: string;
  description: string;
}

export interface DepartmentTeamDraft {
  name: string;
  code: string;
  description: string;
}

const DEVELOPMENT_ORGANIZATION_ID = "11111111-1111-4111-8111-111111111111";
const FIXTURE_TIMESTAMP = "2026-07-01T12:00:00.000Z";

const INITIAL_DEPARTMENTS: DepartmentSelfAdminDepartment[] =
  fixtureDepartmentAccesses.map((department) => ({
    id: department.departmentId,
    organizationId: DEVELOPMENT_ORGANIZATION_ID,
    name: department.departmentLabel,
    code: department.departmentCode,
    description: department.description,
    defaultTeamId:
      department.teams.find((team) => team.isDefault)?.teamId ?? null,
    archivedAt: null,
    updatedAt: FIXTURE_TIMESTAMP,
  }));

const INITIAL_TEAMS: DepartmentTeam[] = fixtureDepartmentAccesses.flatMap(
  (department) =>
    department.teams.map((team) => ({
      id: team.teamId,
      departmentId: department.departmentId,
      name: team.teamLabel,
      code: team.teamCode,
      description: team.description,
      isDefault: team.isDefault,
      archivedAt: null,
      createdAt: FIXTURE_TIMESTAMP,
      updatedAt: FIXTURE_TIMESTAMP,
    })),
);

const INITIAL_TEAM_STAFF: ManagedTeamStaffMember[] =
  fixtureDepartmentAccesses.flatMap((department) =>
    department.teams.flatMap((team) =>
      team.staff.map((member) => ({
        staffId: member.staffId,
        displayName: member.displayName,
        handle: member.handle,
        teamId: team.teamId,
        membershipRole:
          member.roleLabel === "Team lead"
            ? ("lead" as const)
            : ("member" as const),
      })),
    ),
  );

/**
 * Additional active department members without a non-default team yet, so the
 * assignment workflow has realistic candidates in the development fixture.
 */
const INITIAL_DEPARTMENT_ROSTER: Record<string, DepartmentRosterMember[]> = {};

for (const department of fixtureDepartmentAccesses) {
  const seen = new Map<string, DepartmentRosterMember>();
  for (const team of department.teams) {
    for (const member of team.staff) {
      seen.set(member.staffId, {
        staffId: member.staffId,
        displayName: member.displayName,
        handle: member.handle,
      });
    }
  }
  INITIAL_DEPARTMENT_ROSTER[department.departmentId] = [...seen.values()];
}

INITIAL_DEPARTMENT_ROSTER[
  fixtureDepartmentAccesses[1]!.departmentId
]!.push(
  {
    staffId: "33333333-3333-4333-8333-333333333361",
    displayName: "Riley Reserve",
    handle: "riley-reserve",
  },
  {
    staffId: "33333333-3333-4333-8333-333333333362",
    displayName: "Noor Newstaff",
    handle: "noor-newstaff",
  },
);
INITIAL_DEPARTMENT_ROSTER[
  fixtureDepartmentAccesses[3]!.departmentId
]!.push({
  staffId: "33333333-3333-4333-8333-333333333363",
  displayName: "Pat Pathfinder",
  handle: "pat-pathfinder",
});

let session: DepartmentSelfAdminSession | null = null;
const departments = shallowRef<DepartmentSelfAdminDepartment[]>(
  INITIAL_DEPARTMENTS.map((department) => ({ ...department })),
);
const teams = shallowRef<DepartmentTeam[]>(
  INITIAL_TEAMS.map((team) => ({ ...team })),
);
const teamStaff = shallowRef<ManagedTeamStaffMember[]>(
  INITIAL_TEAM_STAFF.map((member) => ({ ...member })),
);

export function installDevelopmentDepartmentSelfAdminSession(
  overrides: Partial<DepartmentSelfAdminSession> = {},
): DepartmentSelfAdminSession {
  session = {
    ...sessionForDepartment(selectedFixtureDepartment.value),
    ...overrides,
  };

  return session;
}

export function resolveDepartmentSelfAdminSession(): DepartmentSelfAdminSession | null {
  return session ?? sessionForDepartment(selectedFixtureDepartment.value);
}

export function clearDepartmentSelfAdminSession(): void {
  session = null;
}

export function canAdministerDepartment(
  current: DepartmentSelfAdminSession | null,
): boolean {
  return (
    current !== null &&
    (current.role === "department_lead" ||
      current.role === "department_administration")
  );
}

export function canLeadDepartmentTeam(
  current: DepartmentSelfAdminSession | null,
): boolean {
  return (
    current !== null &&
    current.role !== "staff" &&
    current.teamLeadTeamIds.length > 0
  );
}

export function canAccessDepartmentAdmin(
  current: DepartmentSelfAdminSession | null,
): boolean {
  return canAdministerDepartment(current) || canLeadDepartmentTeam(current);
}

export function getCurrentDepartment(
  current: DepartmentSelfAdminSession | null,
): DepartmentSelfAdminDepartment | null {
  if (current === null) {
    return null;
  }

  return (
    departments.value.find(
      (department) => department.id === current.departmentId,
    ) ?? null
  );
}

export function getAdministeredDepartment(
  current: DepartmentSelfAdminSession | null,
): DepartmentSelfAdminDepartment | null {
  if (!canAdministerDepartment(current) || current === null) {
    return null;
  }

  return getCurrentDepartment(current);
}

export function updateDepartmentDetails(
  current: DepartmentSelfAdminSession | null,
  draft: DepartmentDetailsDraft,
): DepartmentSelfAdminDepartment {
  assertCanAdminister(current);
  const existing = getAdministeredDepartment(current);

  if (existing === null) {
    throw new Error("Department not found.");
  }

  const name = draft.name.trim();
  const code = draft.code.trim();
  const description = draft.description.trim();

  if (name === "" || code === "") {
    throw new Error("Department name and code are required.");
  }

  const updated: DepartmentSelfAdminDepartment = {
    ...existing,
    name,
    code,
    description: description === "" ? null : description,
    updatedAt: new Date().toISOString(),
  };

  departments.value = departments.value.map((department) =>
    department.id === updated.id ? updated : department,
  );
  session = {
    ...current,
    departmentLabel: updated.name,
  };

  return updated;
}

export function listDepartmentTeams(
  current: DepartmentSelfAdminSession | null,
  status: "all" | "active" | "archived" = "all",
): DepartmentTeam[] {
  if (!canAdministerDepartment(current) || current === null) {
    return [];
  }

  return teams.value
    .filter((team) => team.departmentId === current.departmentId)
    .filter((team) => {
      if (status === "active") {
        return team.archivedAt === null;
      }

      if (status === "archived") {
        return team.archivedAt !== null;
      }

      return true;
    })
    .slice()
    .sort((left, right) => {
      if (left.isDefault !== right.isDefault) {
        return left.isDefault ? -1 : 1;
      }

      return left.name.localeCompare(right.name);
    });
}

export function listTeamLeadTeams(
  current: DepartmentSelfAdminSession | null,
): DepartmentTeam[] {
  if (!canLeadDepartmentTeam(current) || current === null) {
    return [];
  }

  const leadTeamIds = new Set(current.teamLeadTeamIds);

  return teams.value
    .filter(
      (team) =>
        team.departmentId === current.departmentId && leadTeamIds.has(team.id),
    )
    .slice()
    .sort((left, right) => left.name.localeCompare(right.name));
}

export function listTeamLeadStaff(
  current: DepartmentSelfAdminSession | null,
): DepartmentTeamStaffMember[] {
  if (!canLeadDepartmentTeam(current) || current === null) {
    return [];
  }

  const leadTeamIds = new Set(current.teamLeadTeamIds);

  return staffForTeams(current, leadTeamIds);
}

/**
 * Team staff visible for management: department administer authority sees
 * every department team; team leads see only teams they lead (M11.17).
 */
export function listManagedTeamStaff(
  current: DepartmentSelfAdminSession | null,
): DepartmentTeamStaffMember[] {
  if (current === null || !canAccessDepartmentAdmin(current)) {
    return [];
  }

  return staffForTeams(current, new Set(manageableTeamIds(current)));
}

/**
 * Teams the current session may assign staff to (active teams only).
 */
export function listAssignableTeams(
  current: DepartmentSelfAdminSession | null,
): DepartmentTeam[] {
  if (current === null || !canAccessDepartmentAdmin(current)) {
    return [];
  }

  const manageable = new Set(manageableTeamIds(current));

  return teams.value
    .filter(
      (team) =>
        team.departmentId === current.departmentId &&
        team.archivedAt === null &&
        manageable.has(team.id),
    )
    .slice()
    .sort((left, right) => left.name.localeCompare(right.name));
}

/**
 * Active department members available as assignment candidates.
 */
export function listAssignableStaff(
  current: DepartmentSelfAdminSession | null,
): DepartmentRosterMember[] {
  if (current === null || !canAccessDepartmentAdmin(current)) {
    return [];
  }

  return (INITIAL_DEPARTMENT_ROSTER[current.departmentId] ?? [])
    .slice()
    .sort((left, right) => left.displayName.localeCompare(right.displayName));
}

export function assignStaffToTeam(
  current: DepartmentSelfAdminSession | null,
  teamId: string,
  staffId: string,
): void {
  assertCanManageTeam(current, teamId);

  const team = requireDepartmentTeam(current, teamId);
  if (team.archivedAt !== null) {
    throw new Error("Archived teams cannot receive new assignments.");
  }

  const candidate = (INITIAL_DEPARTMENT_ROSTER[current.departmentId] ?? []).find(
    (member) => member.staffId === staffId,
  );
  if (!candidate) {
    throw new Error("Staff must belong to the department before team assignment.");
  }

  const alreadyAssigned = teamStaff.value.some(
    (member) => member.teamId === teamId && member.staffId === staffId,
  );
  if (alreadyAssigned) {
    throw new Error("This staff member is already assigned to the selected team.");
  }

  teamStaff.value = [
    ...teamStaff.value,
    {
      staffId: candidate.staffId,
      displayName: candidate.displayName,
      handle: candidate.handle,
      teamId,
      membershipRole: "member",
    },
  ];
}

export function removeStaffFromTeam(
  current: DepartmentSelfAdminSession | null,
  teamId: string,
  staffId: string,
): void {
  assertCanManageTeam(current, teamId);

  const team = requireDepartmentTeam(current, teamId);
  if (team.isDefault) {
    throw new Error("Staff cannot be removed from the department default team.");
  }

  const assigned = teamStaff.value.some(
    (member) => member.teamId === teamId && member.staffId === staffId,
  );
  if (!assigned) {
    throw new Error("This staff member is not assigned to the selected team.");
  }

  teamStaff.value = teamStaff.value.filter(
    (member) => !(member.teamId === teamId && member.staffId === staffId),
  );
}

/**
 * Department leads designate an individual team lead (M11.17). The designated
 * membership gains team-scoped lead authority; other members keep member role.
 */
export function selectTeamLead(
  current: DepartmentSelfAdminSession | null,
  teamId: string,
  staffId: string,
): void {
  assertCanAdminister(current);
  const team = requireDepartmentTeam(current, teamId);
  if (team.archivedAt !== null) {
    throw new Error("Archived teams cannot receive lead designations.");
  }

  const existing = teamStaff.value.find(
    (member) => member.teamId === teamId && member.staffId === staffId,
  );

  if (existing?.membershipRole === "lead") {
    throw new Error("This staff member is already a lead of the selected team.");
  }

  if (existing) {
    teamStaff.value = teamStaff.value.map((member) =>
      member.teamId === teamId && member.staffId === staffId
        ? { ...member, membershipRole: "lead" }
        : member,
    );
    return;
  }

  const candidate = (INITIAL_DEPARTMENT_ROSTER[current.departmentId] ?? []).find(
    (member) => member.staffId === staffId,
  );
  if (!candidate) {
    throw new Error(
      "Staff must belong to the department before team lead designation.",
    );
  }

  teamStaff.value = [
    ...teamStaff.value,
    {
      staffId: candidate.staffId,
      displayName: candidate.displayName,
      handle: candidate.handle,
      teamId,
      membershipRole: "lead",
    },
  ];
}

export function removeTeamLead(
  current: DepartmentSelfAdminSession | null,
  teamId: string,
  staffId: string,
): void {
  assertCanAdminister(current);
  requireDepartmentTeam(current, teamId);

  const existing = teamStaff.value.find(
    (member) =>
      member.teamId === teamId &&
      member.staffId === staffId &&
      member.membershipRole === "lead",
  );
  if (!existing) {
    throw new Error("This staff member is not a lead of the selected team.");
  }

  teamStaff.value = teamStaff.value.map((member) =>
    member.teamId === teamId && member.staffId === staffId
      ? { ...member, membershipRole: "member" }
      : member,
  );
}

function manageableTeamIds(current: DepartmentSelfAdminSession): string[] {
  if (canAdministerDepartment(current)) {
    return teams.value
      .filter((team) => team.departmentId === current.departmentId)
      .map((team) => team.id);
  }

  return [...current.teamLeadTeamIds];
}

function staffForTeams(
  current: DepartmentSelfAdminSession,
  teamIds: Set<string>,
): DepartmentTeamStaffMember[] {
  const teamLabelById = new Map(
    teams.value
      .filter((team) => team.departmentId === current.departmentId)
      .map((team) => [team.id, team.name]),
  );

  return teamStaff.value
    .filter((member) => teamIds.has(member.teamId))
    .map((member) => ({
      staffId: member.staffId,
      displayName: member.displayName,
      handle: member.handle,
      roleLabel: member.membershipRole === "lead" ? "Team lead" : "Staff",
      teamId: member.teamId,
      teamLabel: teamLabelById.get(member.teamId) ?? "Unknown team",
    }))
    .sort(
      (left, right) =>
        left.teamLabel.localeCompare(right.teamLabel) ||
        left.displayName.localeCompare(right.displayName),
    );
}

function assertCanManageTeam(
  current: DepartmentSelfAdminSession | null,
  teamId: string,
): asserts current is DepartmentSelfAdminSession {
  if (current === null || !canAccessDepartmentAdmin(current)) {
    throw new Error("You do not have permission to manage team staff.");
  }

  if (!manageableTeamIds(current).includes(teamId)) {
    throw new Error(
      "You are not authorized to manage staff for the selected team.",
    );
  }
}

function requireDepartmentTeam(
  current: DepartmentSelfAdminSession,
  teamId: string,
): DepartmentTeam {
  const team = teams.value.find(
    (candidate) =>
      candidate.id === teamId &&
      candidate.departmentId === current.departmentId,
  );

  if (!team) {
    throw new Error("Team not found.");
  }

  return team;
}

export function getDepartmentTeam(
  current: DepartmentSelfAdminSession | null,
  teamId: string,
): DepartmentTeam | null {
  if (!canAdministerDepartment(current) || current === null) {
    return null;
  }

  return (
    teams.value.find(
      (team) =>
        team.id === teamId && team.departmentId === current.departmentId,
    ) ?? null
  );
}

export function createDepartmentTeam(
  current: DepartmentSelfAdminSession | null,
  draft: DepartmentTeamDraft,
): DepartmentTeam {
  assertCanAdminister(current);
  const name = draft.name.trim();
  const code = draft.code.trim();
  const description = draft.description.trim();

  if (name === "" || code === "") {
    throw new Error("Team name and code are required.");
  }

  assertTeamCodeUnique(current.departmentId, code);

  const now = new Date().toISOString();
  const team: DepartmentTeam = {
    id: crypto.randomUUID(),
    departmentId: current.departmentId,
    name,
    code,
    description: description === "" ? null : description,
    isDefault: false,
    archivedAt: null,
    createdAt: now,
    updatedAt: now,
  };

  teams.value = [...teams.value, team];

  return team;
}

export function updateDepartmentTeam(
  current: DepartmentSelfAdminSession | null,
  teamId: string,
  draft: DepartmentTeamDraft,
): DepartmentTeam {
  assertCanAdminister(current);
  const existing = getDepartmentTeam(current, teamId);

  if (existing === null) {
    throw new Error("Team not found.");
  }

  const name = draft.name.trim();
  const code = draft.code.trim();
  const description = draft.description.trim();

  if (name === "" || code === "") {
    throw new Error("Team name and code are required.");
  }

  assertTeamCodeUnique(current.departmentId, code, teamId);

  const updated: DepartmentTeam = {
    ...existing,
    name,
    code,
    description: description === "" ? null : description,
    updatedAt: new Date().toISOString(),
  };

  teams.value = teams.value.map((team) =>
    team.id === teamId ? updated : team,
  );

  return updated;
}

export function archiveDepartmentTeam(
  current: DepartmentSelfAdminSession | null,
  teamId: string,
): DepartmentTeam {
  assertCanAdminister(current);
  const existing = getDepartmentTeam(current, teamId);

  if (existing === null) {
    throw new Error("Team not found.");
  }

  if (existing.isDefault) {
    throw new Error("Default teams cannot be archived.");
  }

  if (existing.archivedAt !== null) {
    throw new Error("Team is already archived.");
  }

  const archived: DepartmentTeam = {
    ...existing,
    archivedAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  };

  teams.value = teams.value.map((team) =>
    team.id === teamId ? archived : team,
  );

  return archived;
}

export function restoreDepartmentTeam(
  current: DepartmentSelfAdminSession | null,
  teamId: string,
): DepartmentTeam {
  assertCanAdminister(current);
  const existing = getDepartmentTeam(current, teamId);

  if (existing === null) {
    throw new Error("Team not found.");
  }

  if (existing.archivedAt === null) {
    throw new Error("Team is not archived.");
  }

  const restored: DepartmentTeam = {
    ...existing,
    archivedAt: null,
    updatedAt: new Date().toISOString(),
  };

  teams.value = teams.value.map((team) =>
    team.id === teamId ? restored : team,
  );

  return restored;
}

export function resetDepartmentSelfAdminFixtures(): void {
  departments.value = INITIAL_DEPARTMENTS.map((department) => ({
    ...department,
  }));
  teams.value = INITIAL_TEAMS.map((team) => ({ ...team }));
  teamStaff.value = INITIAL_TEAM_STAFF.map((member) => ({ ...member }));
}

function sessionForDepartment(
  department: FixtureDepartmentAccess,
): DepartmentSelfAdminSession {
  const teamLeadTeamIds = department.teams
    .filter((team) => team.isTeamLead)
    .map((team) => team.teamId);
  const memberTeamIds = department.teams
    .filter((team) => team.isMember)
    .map((team) => team.teamId);
  const hasAdminAccess = fixtureDepartmentHasAdminAccess(department);

  return {
    eventId: department.eventId,
    eventLabel: department.eventLabel,
    departmentId: department.departmentId,
    departmentLabel: department.departmentLabel,
    role: department.isDepartmentLead
      ? "department_lead"
      : teamLeadTeamIds.length > 0
        ? "team_lead"
        : "staff",
    roleLabel: hasAdminAccess ? department.roleLabel : "Staff",
    teamLeadTeamIds,
    memberTeamIds,
  };
}

/**
 * Suggest a stable team code from a display name: uppercase, spaces and
 * punctuation collapsed to underscores, matching alpha_dash-friendly codes.
 */
export function suggestTeamCodeFromName(name: string): string {
  return name
    .trim()
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, 64);
}

function assertCanAdminister(
  current: DepartmentSelfAdminSession | null,
): asserts current is DepartmentSelfAdminSession {
  if (!canAdministerDepartment(current) || current === null) {
    throw new Error("You do not have permission to administer this department.");
  }
}

function assertTeamCodeUnique(
  departmentId: string,
  code: string,
  ignoreId?: string,
): void {
  const conflict = teams.value.find(
    (team) =>
      team.departmentId === departmentId &&
      team.code === code &&
      team.id !== ignoreId,
  );

  if (conflict) {
    throw new Error("A team with this code already exists in the department.");
  }
}
