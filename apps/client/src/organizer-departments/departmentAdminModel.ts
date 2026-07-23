import { shallowRef } from "vue";

import {
  fixtureDepartmentHasOrganizerDepartmentAccess,
  selectedFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";

export type OrganizerDepartmentRole = "organizer" | "lead_organizer" | "staff";

export interface OrganizerDepartmentSession {
  readonly organizationId: string;
  readonly organizationLabel: string;
  readonly role: OrganizerDepartmentRole;
  readonly roleLabel: string;
}

export interface OrganizerDepartment {
  readonly id: string;
  readonly organizationId: string;
  readonly name: string;
  readonly code: string;
  readonly description: string | null;
  readonly defaultTeamId: string | null;
  readonly archivedAt: string | null;
  readonly createdAt: string;
  readonly updatedAt: string;
}

export interface OrganizerDepartmentDraft {
  name: string;
  code: string;
  description: string;
}

const DEVELOPMENT_ORGANIZATION_ID = "11111111-1111-4111-8111-111111111111";

const DEVELOPMENT_SESSION: OrganizerDepartmentSession = {
  organizationId: DEVELOPMENT_ORGANIZATION_ID,
  organizationLabel: "Signal Camp (development)",
  role: "organizer",
  roleLabel: "Organizer",
};

const INITIAL_DEPARTMENTS: OrganizerDepartment[] = [
  {
    id: "22222222-2222-4222-8222-222222222201",
    organizationId: DEVELOPMENT_ORGANIZATION_ID,
    name: "Organizer",
    code: "ORG",
    description: "Organization-level event administration.",
    defaultTeamId: "77777777-7777-4777-8777-777777777760",
    archivedAt: null,
    createdAt: "2026-07-01T11:55:00.000Z",
    updatedAt: "2026-07-01T11:55:00.000Z",
  },
  {
    id: "66666666-6666-4666-8666-666666666666",
    organizationId: DEVELOPMENT_ORGANIZATION_ID,
    name: "Rangers",
    code: "RANGERS",
    description: "Field operations and volunteer support.",
    defaultTeamId: "22222222-2222-4222-8222-222222222211",
    archivedAt: null,
    createdAt: "2026-07-01T12:00:00.000Z",
    updatedAt: "2026-07-01T12:00:00.000Z",
  },
  {
    id: "22222222-2222-4222-8222-222222222202",
    organizationId: DEVELOPMENT_ORGANIZATION_ID,
    name: "Gate",
    code: "GATE",
    description: "Entry and credentialing.",
    defaultTeamId: "22222222-2222-4222-8222-222222222212",
    archivedAt: null,
    createdAt: "2026-07-01T12:05:00.000Z",
    updatedAt: "2026-07-01T12:05:00.000Z",
  },
  {
    id: "22222222-2222-4222-8222-222222222203",
    organizationId: DEVELOPMENT_ORGANIZATION_ID,
    name: "DPW",
    code: "DPW",
    description: "Build, roads, and event infrastructure.",
    defaultTeamId: "77777777-7777-4777-8777-777777777790",
    archivedAt: null,
    createdAt: "2026-07-01T12:10:00.000Z",
    updatedAt: "2026-07-01T12:10:00.000Z",
  },
];

let session: OrganizerDepartmentSession | null = null;
const departments = shallowRef<OrganizerDepartment[]>([...INITIAL_DEPARTMENTS]);

export function installDevelopmentOrganizerDepartmentSession(
  overrides: Partial<OrganizerDepartmentSession> = {},
): OrganizerDepartmentSession {
  session = {
    ...DEVELOPMENT_SESSION,
    ...overrides,
  };

  return session;
}

export function resolveOrganizerDepartmentSession(): OrganizerDepartmentSession | null {
  return session;
}

export function clearOrganizerDepartmentSession(): void {
  session = null;
}

export function canManageOrganizerDepartments(
  current: OrganizerDepartmentSession | null,
): boolean {
  return (
    current !== null &&
    fixtureDepartmentHasOrganizerDepartmentAccess(
      selectedFixtureDepartment.value,
    ) &&
    (current.role === "organizer" || current.role === "lead_organizer")
  );
}

export function listOrganizerDepartments(
  current: OrganizerDepartmentSession | null,
  status: "all" | "active" | "archived" = "all",
): OrganizerDepartment[] {
  if (!canManageOrganizerDepartments(current) || current === null) {
    return [];
  }

  return departments.value
    .filter((department) => department.organizationId === current.organizationId)
    .filter((department) => {
      if (status === "active") {
        return department.archivedAt === null;
      }

      if (status === "archived") {
        return department.archivedAt !== null;
      }

      return true;
    })
    .slice()
    .sort((left, right) => left.name.localeCompare(right.name));
}

export function getOrganizerDepartment(
  current: OrganizerDepartmentSession | null,
  departmentId: string,
): OrganizerDepartment | null {
  if (!canManageOrganizerDepartments(current) || current === null) {
    return null;
  }

  return (
    departments.value.find(
      (department) =>
        department.id === departmentId &&
        department.organizationId === current.organizationId,
    ) ?? null
  );
}

export function createOrganizerDepartment(
  current: OrganizerDepartmentSession | null,
  draft: OrganizerDepartmentDraft,
): OrganizerDepartment {
  assertCanManage(current);
  const name = draft.name.trim();
  const code = draft.code.trim();
  const description = draft.description.trim();

  if (name === "" || code === "") {
    throw new Error("Department name and code are required.");
  }

  assertCodeUnique(current.organizationId, code);

  const now = new Date().toISOString();
  const department: OrganizerDepartment = {
    id: crypto.randomUUID(),
    organizationId: current.organizationId,
    name,
    code,
    description: description === "" ? null : description,
    defaultTeamId: crypto.randomUUID(),
    archivedAt: null,
    createdAt: now,
    updatedAt: now,
  };

  departments.value = [...departments.value, department];

  return department;
}

export function updateOrganizerDepartment(
  current: OrganizerDepartmentSession | null,
  departmentId: string,
  draft: OrganizerDepartmentDraft,
): OrganizerDepartment {
  assertCanManage(current);
  const existing = getOrganizerDepartment(current, departmentId);

  if (existing === null) {
    throw new Error("Department not found.");
  }

  const name = draft.name.trim();
  const code = draft.code.trim();
  const description = draft.description.trim();

  if (name === "" || code === "") {
    throw new Error("Department name and code are required.");
  }

  assertCodeUnique(current.organizationId, code, departmentId);

  const updated: OrganizerDepartment = {
    ...existing,
    name,
    code,
    description: description === "" ? null : description,
    updatedAt: new Date().toISOString(),
  };

  departments.value = departments.value.map((department) =>
    department.id === departmentId ? updated : department,
  );

  return updated;
}

export function archiveOrganizerDepartment(
  current: OrganizerDepartmentSession | null,
  departmentId: string,
): OrganizerDepartment {
  assertCanManage(current);
  const existing = getOrganizerDepartment(current, departmentId);

  if (existing === null) {
    throw new Error("Department not found.");
  }

  if (existing.archivedAt !== null) {
    throw new Error("Department is already archived.");
  }

  const archived: OrganizerDepartment = {
    ...existing,
    archivedAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  };

  departments.value = departments.value.map((department) =>
    department.id === departmentId ? archived : department,
  );

  return archived;
}

export function restoreOrganizerDepartment(
  current: OrganizerDepartmentSession | null,
  departmentId: string,
): OrganizerDepartment {
  assertCanManage(current);
  const existing = getOrganizerDepartment(current, departmentId);

  if (existing === null) {
    throw new Error("Department not found.");
  }

  if (existing.archivedAt === null) {
    throw new Error("Department is not archived.");
  }

  const restored: OrganizerDepartment = {
    ...existing,
    archivedAt: null,
    updatedAt: new Date().toISOString(),
  };

  departments.value = departments.value.map((department) =>
    department.id === departmentId ? restored : department,
  );

  return restored;
}

export function resetOrganizerDepartmentFixtures(): void {
  departments.value = INITIAL_DEPARTMENTS.map((department) => ({
    ...department,
  }));
}

function assertCanManage(
  current: OrganizerDepartmentSession | null,
): asserts current is OrganizerDepartmentSession {
  if (!canManageOrganizerDepartments(current) || current === null) {
    throw new Error("You do not have permission to manage departments.");
  }
}

function assertCodeUnique(
  organizationId: string,
  code: string,
  ignoreId?: string,
): void {
  const conflict = departments.value.find(
    (department) =>
      department.organizationId === organizationId &&
      department.code === code &&
      department.id !== ignoreId,
  );

  if (conflict) {
    throw new Error(
      "A department with this code already exists in the organization.",
    );
  }
}
