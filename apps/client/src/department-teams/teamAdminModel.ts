import { shallowRef } from "vue";

import {
  fixtureDepartmentAccesses,
  fixtureDepartmentById,
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

let session: DepartmentSelfAdminSession | null = null;
const departments = shallowRef<DepartmentSelfAdminDepartment[]>(
  INITIAL_DEPARTMENTS.map((department) => ({ ...department })),
);
const teams = shallowRef<DepartmentTeam[]>(
  INITIAL_TEAMS.map((team) => ({ ...team })),
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

  const access = fixtureDepartmentById(current.departmentId);
  if (!access) {
    return [];
  }

  const leadTeamIds = new Set(current.teamLeadTeamIds);

  return access.teams
    .filter((team) => leadTeamIds.has(team.teamId))
    .flatMap((team) =>
      team.staff.map((member) => ({
        ...member,
        teamId: team.teamId,
        teamLabel: team.teamLabel,
      })),
    )
    .sort(
      (left, right) =>
        left.teamLabel.localeCompare(right.teamLabel) ||
        left.displayName.localeCompare(right.displayName),
    );
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
}

function sessionForDepartment(
  department: FixtureDepartmentAccess,
): DepartmentSelfAdminSession {
  const teamLeadTeamIds = department.teams
    .filter((team) => team.isTeamLead)
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
