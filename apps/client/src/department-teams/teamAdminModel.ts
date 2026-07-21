import { shallowRef } from "vue";

import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";

export type DepartmentSelfAdminRole =
  | "department_lead"
  | "department_administration"
  | "staff";

export interface DepartmentSelfAdminSession {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly departmentId: string;
  readonly departmentLabel: string;
  readonly role: DepartmentSelfAdminRole;
  readonly roleLabel: string;
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
const DEVELOPMENT_DEPARTMENT_ID = LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId;
const DEVELOPMENT_DEFAULT_TEAM_ID = "77777777-7777-4777-8777-777777777770";
const DEVELOPMENT_OPERATORS_TEAM_ID = "77777777-7777-4777-8777-777777777771";

const DEVELOPMENT_SESSION: DepartmentSelfAdminSession = {
  eventId: LOCAL_DEPARTMENT_OPS_CONTEXT.eventId,
  eventLabel: LOCAL_DEPARTMENT_OPS_CONTEXT.eventLabel,
  departmentId: DEVELOPMENT_DEPARTMENT_ID,
  departmentLabel: LOCAL_DEPARTMENT_OPS_CONTEXT.departmentLabel,
  role: "department_lead",
  roleLabel: "Department lead",
};

const INITIAL_DEPARTMENT: DepartmentSelfAdminDepartment = {
  id: DEVELOPMENT_DEPARTMENT_ID,
  organizationId: DEVELOPMENT_ORGANIZATION_ID,
  name: "Rangers",
  code: "RANGERS",
  description: "Field operations and volunteer support.",
  defaultTeamId: DEVELOPMENT_DEFAULT_TEAM_ID,
  archivedAt: null,
  updatedAt: "2026-07-01T12:00:00.000Z",
};

const INITIAL_TEAMS: DepartmentTeam[] = [
  {
    id: DEVELOPMENT_DEFAULT_TEAM_ID,
    departmentId: DEVELOPMENT_DEPARTMENT_ID,
    name: "Rangers Default",
    code: "DEFAULT",
    description: "Default department team.",
    isDefault: true,
    archivedAt: null,
    createdAt: "2026-07-01T12:00:00.000Z",
    updatedAt: "2026-07-01T12:00:00.000Z",
  },
  {
    id: DEVELOPMENT_OPERATORS_TEAM_ID,
    departmentId: DEVELOPMENT_DEPARTMENT_ID,
    name: "Dirt",
    code: "DIRT",
    description: "Field patrol team.",
    isDefault: false,
    archivedAt: null,
    createdAt: "2026-07-01T12:05:00.000Z",
    updatedAt: "2026-07-01T12:05:00.000Z",
  },
];

let session: DepartmentSelfAdminSession | null = null;
const department = shallowRef<DepartmentSelfAdminDepartment>({
  ...INITIAL_DEPARTMENT,
});
const teams = shallowRef<DepartmentTeam[]>(
  INITIAL_TEAMS.map((team) => ({ ...team })),
);

export function installDevelopmentDepartmentSelfAdminSession(
  overrides: Partial<DepartmentSelfAdminSession> = {},
): DepartmentSelfAdminSession {
  session = {
    ...DEVELOPMENT_SESSION,
    ...overrides,
  };

  return session;
}

export function resolveDepartmentSelfAdminSession(): DepartmentSelfAdminSession | null {
  return session;
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

export function getAdministeredDepartment(
  current: DepartmentSelfAdminSession | null,
): DepartmentSelfAdminDepartment | null {
  if (!canAdministerDepartment(current) || current === null) {
    return null;
  }

  if (department.value.id !== current.departmentId) {
    return null;
  }

  return department.value;
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

  department.value = updated;
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
  department.value = { ...INITIAL_DEPARTMENT };
  teams.value = INITIAL_TEAMS.map((team) => ({ ...team }));
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
