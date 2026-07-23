import {
  canManageOrganizerDepartments,
  resolveOrganizerDepartmentSession,
  type OrganizerDepartmentSession,
} from "@/organizer-departments/departmentAdminModel";
import {
  fixtureDepartmentAccesses,
  FIXTURE_RANGERS_DEPARTMENT_ID,
} from "@/department-teams/fixtureDepartmentAccess";

export interface OrganizerStaffDepartment {
  readonly departmentId: string;
  readonly departmentName: string;
  readonly status: "active" | "prospective" | "inactive";
  readonly isLead: boolean;
}

export interface OrganizerStaffMember {
  readonly id: string;
  readonly legalName: string;
  readonly preferredName: string | null;
  readonly displayName: string;
  readonly handle: string | null;
  readonly email: string;
  readonly organizationStatus:
    | "prospective"
    | "active"
    | "inactive"
    | "emeritus"
    | "retired"
    | "do_not_staff";
  readonly invited: boolean;
  readonly departments: readonly OrganizerStaffDepartment[];
  readonly leadDepartmentIds: readonly string[];
}

export interface OrganizerStaffDraft {
  legalName: string;
  preferredName: string;
  handle: string;
  email: string;
  departmentId: string;
  invite: boolean;
}

const INITIAL_STAFF: OrganizerStaffMember[] = [
  {
    id: "33333333-3333-4333-8333-333333333301",
    legalName: "Local Field Author",
    preferredName: "Local Field Author",
    displayName: "Local Field Author",
    handle: "local-field-author",
    email: "local-field-author@example.test",
    organizationStatus: "active",
    invited: true,
    departments: [
      {
        departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
        departmentName: "Rangers",
        status: "active",
        isLead: true,
      },
    ],
    leadDepartmentIds: [FIXTURE_RANGERS_DEPARTMENT_ID],
  },
  {
    id: "33333333-3333-4333-8333-333333333334",
    legalName: "Vera Staff",
    preferredName: "Vera",
    displayName: "Vera",
    handle: "vera",
    email: "vera@example.test",
    organizationStatus: "active",
    invited: true,
    departments: [
      {
        departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
        departmentName: "Rangers",
        status: "active",
        isLead: false,
      },
    ],
    leadDepartmentIds: [],
  },
  {
    id: "33333333-3333-4333-8333-333333333341",
    legalName: "Gina Gate",
    preferredName: "Gina",
    displayName: "Gina",
    handle: "gina-gate",
    email: "gina@example.test",
    organizationStatus: "prospective",
    invited: false,
    departments: [],
    leadDepartmentIds: [],
  },
];

const staff = shallowRef<OrganizerStaffMember[]>(
  INITIAL_STAFF.map((member) => ({ ...member })),
);

export function resolveOrganizerStaffSession(): OrganizerDepartmentSession | null {
  return resolveOrganizerDepartmentSession();
}

export function canManageOrganizerStaff(
  current: OrganizerDepartmentSession | null,
): boolean {
  return canManageOrganizerDepartments(current);
}

export function listOrganizerStaff(
  current: OrganizerDepartmentSession | null,
): OrganizerStaffMember[] {
  if (!canManageOrganizerStaff(current)) {
    return [];
  }

  return staff.value
    .slice()
    .sort((left, right) => left.displayName.localeCompare(right.displayName));
}

export function listLeadSelectableDepartments(
  current: OrganizerDepartmentSession | null,
) {
  if (!canManageOrganizerStaff(current)) {
    return [];
  }

  return fixtureDepartmentAccesses
    .filter((department) => department.departmentCode !== "ORG")
    .map((department) => ({
      id: department.departmentId,
      name: department.departmentLabel,
    }))
    .sort((left, right) => left.name.localeCompare(right.name));
}

export function addOrganizerStaff(
  current: OrganizerDepartmentSession | null,
  draft: OrganizerStaffDraft,
): OrganizerStaffMember {
  assertCanManage(current);
  const legalName = draft.legalName.trim();
  const email = draft.email.trim().toLowerCase();

  if (legalName === "" || email === "") {
    throw new Error("Legal name and email are required.");
  }

  if (staff.value.some((member) => member.email === email)) {
    throw new Error("This staff member is already in the organization.");
  }

  const department = listLeadSelectableDepartments(current).find(
    (candidate) => candidate.id === draft.departmentId,
  );
  const preferredName = nullableTrim(draft.preferredName);
  const member: OrganizerStaffMember = {
    id: crypto.randomUUID(),
    legalName,
    preferredName,
    displayName: preferredName ?? legalName,
    handle: nullableTrim(draft.handle),
    email,
    organizationStatus: department ? "active" : "prospective",
    invited: draft.invite,
    departments: department
      ? [
          {
            departmentId: department.id,
            departmentName: department.name,
            status: "active",
            isLead: false,
          },
        ]
      : [],
    leadDepartmentIds: [],
  };

  staff.value = [...staff.value, member];

  return member;
}

export function selectOrganizerDepartmentLead(
  current: OrganizerDepartmentSession | null,
  staffId: string,
  departmentId: string,
): OrganizerStaffMember {
  assertCanManage(current);
  const department = listLeadSelectableDepartments(current).find(
    (candidate) => candidate.id === departmentId,
  );
  const member = staff.value.find((candidate) => candidate.id === staffId);

  if (!member || !department) {
    throw new Error("Select an existing staff member and department.");
  }

  if (member.organizationStatus === "do_not_staff") {
    throw new Error("Do Not Staff records cannot be selected as department leads.");
  }

  const existingDepartment = member.departments.find(
    (assigned) => assigned.departmentId === departmentId,
  );
  const departments = existingDepartment
    ? member.departments.map((assigned) =>
        assigned.departmentId === departmentId
          ? { ...assigned, isLead: true }
          : assigned,
      )
    : [
        ...member.departments,
        {
          departmentId: department.id,
          departmentName: department.name,
          status: "active" as const,
          isLead: true,
        },
      ];

  const updated: OrganizerStaffMember = {
    ...member,
    organizationStatus:
      member.organizationStatus === "prospective"
        ? "active"
        : member.organizationStatus,
    departments,
    leadDepartmentIds: Array.from(
      new Set([...member.leadDepartmentIds, departmentId]),
    ),
  };

  staff.value = staff.value.map((candidate) =>
    candidate.id === staffId ? updated : candidate,
  );

  return updated;
}

export function removeOrganizerDepartmentLead(
  current: OrganizerDepartmentSession | null,
  staffId: string,
  departmentId: string,
): OrganizerStaffMember {
  assertCanManage(current);
  const member = staff.value.find((candidate) => candidate.id === staffId);

  if (!member || !member.leadDepartmentIds.includes(departmentId)) {
    throw new Error("This staff member is not a department lead.");
  }

  const updated: OrganizerStaffMember = {
    ...member,
    departments: member.departments.map((department) =>
      department.departmentId === departmentId
        ? { ...department, isLead: false }
        : department,
    ),
    leadDepartmentIds: member.leadDepartmentIds.filter(
      (candidate) => candidate !== departmentId,
    ),
  };

  staff.value = staff.value.map((candidate) =>
    candidate.id === staffId ? updated : candidate,
  );

  return updated;
}

export function resetOrganizerStaffFixtures(): void {
  staff.value = INITIAL_STAFF.map((member) => ({ ...member }));
}

function assertCanManage(
  current: OrganizerDepartmentSession | null,
): asserts current is OrganizerDepartmentSession {
  if (!canManageOrganizerStaff(current)) {
    throw new Error("Staff administration requires organizer authority.");
  }
}

function nullableTrim(value: string): string | null {
  const trimmed = value.trim();

  return trimmed === "" ? null : trimmed;
}
import { shallowRef } from "vue";
