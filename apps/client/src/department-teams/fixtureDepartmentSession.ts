// Seed data for the department surfaces that have not been bound to their API
// endpoints yet (M16.16 through M16.18).
//
// This is what was left of `teamAdminModel` when the Admin surfaces moved onto
// their endpoints in M16.15. Shifts, equipment, and Team Overview still read a
// department, its teams, and its team staff synchronously from the fixture, and
// still ask a fixture role what the person looking at them may do. Each of those
// answers becomes the node's when the task that owns the surface binds it, and
// this module goes away with the last of them.
//
// Nothing here writes: the seed departments, teams, and memberships are constant
// for the life of the process. Editing them is the bound surfaces' work now.

import {
  fixtureDepartmentAccesses,
  fixtureDepartmentHasAdminAccess,
  selectedFixtureDepartment,
  type FixtureDepartmentAccess,
} from "@/department-teams/fixtureDepartmentAccess";
import type {
  DepartmentSelfAdminDepartment,
  DepartmentTeam,
  DepartmentTeamStaffMember,
} from "@/department-teams/teamAdminModel";

// The records are shaped the same whether they came from the node or from here,
// so the surfaces still on the fixture keep naming one set of types.
export type {
  DepartmentSelfAdminDepartment,
  DepartmentTeam,
  DepartmentTeamStaffMember,
};

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

const DEVELOPMENT_ORGANIZATION_ID = "11111111-1111-4111-8111-111111111111";
const FIXTURE_TIMESTAMP = "2026-07-01T12:00:00.000Z";

const DEPARTMENTS: readonly DepartmentSelfAdminDepartment[] =
  fixtureDepartmentAccesses.map((department) => ({
    id: department.departmentId,
    organizationId: DEVELOPMENT_ORGANIZATION_ID,
    name: department.departmentLabel,
    code: department.departmentCode,
    description: department.description,
    defaultTeamId:
      department.teams.find((team) => team.isDefault)?.teamId ?? null,
    archivedAt: null,
  }));

const TEAMS: readonly DepartmentTeam[] = fixtureDepartmentAccesses.flatMap(
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

const TEAM_STAFF: readonly DepartmentTeamStaffMember[] =
  fixtureDepartmentAccesses.flatMap((department) =>
    department.teams.flatMap((team) =>
      team.staff.map((member) => ({
        staffId: member.staffId,
        displayName: member.displayName,
        handle: member.handle,
        teamId: team.teamId,
        teamLabel: team.teamLabel,
        membershipRole:
          member.roleLabel === "Team lead"
            ? ("lead" as const)
            : ("member" as const),
        roleLabel: member.roleLabel === "Team lead" ? "Team lead" : "Staff",
      })),
    ),
  );

let session: DepartmentSelfAdminSession | null = null;

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
    DEPARTMENTS.find((department) => department.id === current.departmentId) ??
    null
  );
}

export function listDepartmentTeams(
  current: DepartmentSelfAdminSession | null,
  status: "all" | "active" | "archived" = "all",
): DepartmentTeam[] {
  if (!canAdministerDepartment(current) || current === null) {
    return [];
  }

  return TEAMS.filter((team) => team.departmentId === current.departmentId)
    .filter((team) => {
      if (status === "active") {
        return team.archivedAt === null;
      }

      if (status === "archived") {
        return team.archivedAt !== null;
      }

      return true;
    })
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

  return TEAMS.filter(
    (team) =>
      team.departmentId === current.departmentId && leadTeamIds.has(team.id),
  ).sort((left, right) => left.name.localeCompare(right.name));
}

/**
 * Team staff visible for management: department administer authority sees
 * every department team; team leads see only teams they lead.
 */
export function listManagedTeamStaff(
  current: DepartmentSelfAdminSession | null,
): DepartmentTeamStaffMember[] {
  if (current === null || !canAccessDepartmentAdmin(current)) {
    return [];
  }

  const manageable = new Set(
    canAdministerDepartment(current)
      ? TEAMS.filter((team) => team.departmentId === current.departmentId).map(
          (team) => team.id,
        )
      : current.teamLeadTeamIds,
  );

  return TEAM_STAFF.filter((member) => manageable.has(member.teamId)).sort(
    (left, right) =>
      left.teamLabel.localeCompare(right.teamLabel) ||
      left.displayName.localeCompare(right.displayName),
  );
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
