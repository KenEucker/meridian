import { computed, ref } from "vue";

import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";

export const FIXTURE_RANGERS_DEPARTMENT_ID =
  "66666666-6666-4666-8666-666666666666";
export const FIXTURE_GATE_DEPARTMENT_ID =
  "22222222-2222-4222-8222-222222222202";
export const FIXTURE_DPW_DEPARTMENT_ID =
  "22222222-2222-4222-8222-222222222203";

export const FIXTURE_RANGERS_DIRT_TEAM_ID =
  "77777777-7777-4777-8777-777777777771";
export const FIXTURE_RANGERS_DEFAULT_TEAM_ID =
  "77777777-7777-4777-8777-777777777770";
export const FIXTURE_GATE_DEFAULT_TEAM_ID =
  "77777777-7777-4777-8777-777777777780";
export const FIXTURE_GATE_CREDENTIALS_TEAM_ID =
  "77777777-7777-4777-8777-777777777781";
export const FIXTURE_DPW_DEFAULT_TEAM_ID =
  "77777777-7777-4777-8777-777777777790";
export const FIXTURE_DPW_BIKES_TEAM_ID =
  "77777777-7777-4777-8777-777777777791";

export interface FixtureTeamStaffMember {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly roleLabel: string;
}

export interface FixtureDepartmentTeamAccess {
  readonly teamId: string;
  readonly teamLabel: string;
  readonly teamCode: string;
  readonly description: string | null;
  readonly isDefault: boolean;
  readonly isTeamLead: boolean;
  readonly isMember: boolean;
  readonly staff: readonly FixtureTeamStaffMember[];
}

export interface FixtureDepartmentAccess {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly departmentId: string;
  readonly departmentLabel: string;
  readonly departmentCode: string;
  readonly description: string | null;
  readonly isDepartmentLead: boolean;
  readonly roleLabel: string;
  readonly teams: readonly FixtureDepartmentTeamAccess[];
  readonly capabilities: {
    readonly hasLogistics: boolean;
    readonly hasOperations: boolean;
    readonly hasPlanning: boolean;
    readonly hasFieldReportPermission: boolean;
    readonly hasIncidentCommand: boolean;
    readonly hasEquipmentVisibility: boolean;
  };
}

export const fixtureDepartmentAccesses: readonly FixtureDepartmentAccess[] =
  Object.freeze([
    Object.freeze({
      eventId: LOCAL_FIELD_FIXTURE.eventId,
      eventLabel: LOCAL_FIELD_FIXTURE.eventLabel,
      departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
      departmentLabel: "Rangers",
      departmentCode: "RANGERS",
      description: "Field operations and volunteer support.",
      isDepartmentLead: true,
      roleLabel: "Department lead and team lead",
      teams: Object.freeze([
        Object.freeze({
          teamId: FIXTURE_RANGERS_DEFAULT_TEAM_ID,
          teamLabel: "Rangers Default",
          teamCode: "DEFAULT",
          description: "Default department team.",
          isDefault: true,
          isTeamLead: false,
          isMember: false,
          staff: Object.freeze([]),
        }),
        Object.freeze({
          teamId: FIXTURE_RANGERS_DIRT_TEAM_ID,
          teamLabel: "Dirt",
          teamCode: "DIRT",
          description: "Field patrol team.",
          isDefault: false,
          isTeamLead: true,
          isMember: true,
          staff: Object.freeze([
            Object.freeze({
              staffId: LOCAL_FIELD_FIXTURE.staffId,
              displayName: "Local Field Author",
              handle: "local-field-author",
              roleLabel: "Team lead",
            }),
            Object.freeze({
              staffId: "33333333-3333-4333-8333-333333333334",
              displayName: "Vera Staff",
              handle: "vera",
              roleLabel: "Staff",
            }),
            Object.freeze({
              staffId: "33333333-3333-4333-8333-333333333335",
              displayName: "Sam Shiftlead",
              handle: "sam",
              roleLabel: "Staff",
            }),
          ]),
        }),
      ]),
      capabilities: Object.freeze({
        hasLogistics: true,
        hasOperations: true,
        hasPlanning: true,
        hasFieldReportPermission: true,
        hasIncidentCommand: true,
        hasEquipmentVisibility: true,
      }),
    }),
    Object.freeze({
      eventId: LOCAL_FIELD_FIXTURE.eventId,
      eventLabel: LOCAL_FIELD_FIXTURE.eventLabel,
      departmentId: FIXTURE_GATE_DEPARTMENT_ID,
      departmentLabel: "Gate",
      departmentCode: "GATE",
      description: "Entry and credentialing.",
      isDepartmentLead: false,
      roleLabel: "Staff",
      teams: Object.freeze([
        Object.freeze({
          teamId: FIXTURE_GATE_DEFAULT_TEAM_ID,
          teamLabel: "Gate Default",
          teamCode: "DEFAULT",
          description: "Default department team.",
          isDefault: true,
          isTeamLead: false,
          isMember: false,
          staff: Object.freeze([]),
        }),
        Object.freeze({
          teamId: FIXTURE_GATE_CREDENTIALS_TEAM_ID,
          teamLabel: "Credentials",
          teamCode: "CRED",
          description: "Credential checks and arrival support.",
          isDefault: false,
          isTeamLead: false,
          isMember: true,
          staff: Object.freeze([
            Object.freeze({
              staffId: LOCAL_FIELD_FIXTURE.staffId,
              displayName: "Local Field Author",
              handle: "local-field-author",
              roleLabel: "Staff",
            }),
            Object.freeze({
              staffId: "33333333-3333-4333-8333-333333333341",
              displayName: "Gina Gate",
              handle: "gina-gate",
              roleLabel: "Team lead",
            }),
          ]),
        }),
      ]),
      capabilities: Object.freeze({
        hasLogistics: false,
        hasOperations: false,
        hasPlanning: false,
        hasFieldReportPermission: true,
        hasIncidentCommand: false,
        hasEquipmentVisibility: false,
      }),
    }),
    Object.freeze({
      eventId: LOCAL_FIELD_FIXTURE.eventId,
      eventLabel: LOCAL_FIELD_FIXTURE.eventLabel,
      departmentId: FIXTURE_DPW_DEPARTMENT_ID,
      departmentLabel: "DPW",
      departmentCode: "DPW",
      description: "Build, roads, and event infrastructure.",
      isDepartmentLead: false,
      roleLabel: "Team lead",
      teams: Object.freeze([
        Object.freeze({
          teamId: FIXTURE_DPW_DEFAULT_TEAM_ID,
          teamLabel: "DPW Default",
          teamCode: "DEFAULT",
          description: "Default department team.",
          isDefault: true,
          isTeamLead: false,
          isMember: false,
          staff: Object.freeze([]),
        }),
        Object.freeze({
          teamId: FIXTURE_DPW_BIKES_TEAM_ID,
          teamLabel: "Bikes",
          teamCode: "BIKES",
          description: "Bicycle repair and field mobility support.",
          isDefault: false,
          isTeamLead: true,
          isMember: true,
          staff: Object.freeze([
            Object.freeze({
              staffId: LOCAL_FIELD_FIXTURE.staffId,
              displayName: "Local Field Author",
              handle: "local-field-author",
              roleLabel: "Team lead",
            }),
            Object.freeze({
              staffId: "33333333-3333-4333-8333-333333333351",
              displayName: "Bea Bikes",
              handle: "bea-bikes",
              roleLabel: "Staff",
            }),
            Object.freeze({
              staffId: "33333333-3333-4333-8333-333333333352",
              displayName: "Devon DPW",
              handle: "devon-dpw",
              roleLabel: "Staff",
            }),
          ]),
        }),
      ]),
      capabilities: Object.freeze({
        hasLogistics: false,
        hasOperations: false,
        hasPlanning: false,
        hasFieldReportPermission: true,
        hasIncidentCommand: false,
        hasEquipmentVisibility: false,
      }),
    }),
  ]);

const selectedDepartmentId = ref(FIXTURE_RANGERS_DEPARTMENT_ID);

export const selectedFixtureDepartment = computed(
  () =>
    fixtureDepartmentAccesses.find(
      (department) => department.departmentId === selectedDepartmentId.value,
    ) ?? fixtureDepartmentAccesses[0]!,
);

export const selectedFixtureDepartmentRouteParams = computed(() => ({
  eventId: selectedFixtureDepartment.value.eventId,
  departmentId: selectedFixtureDepartment.value.departmentId,
}));

export function fixtureDepartmentById(
  departmentId: string | null | undefined,
): FixtureDepartmentAccess | null {
  return (
    fixtureDepartmentAccesses.find(
      (department) => department.departmentId === departmentId,
    ) ?? null
  );
}

export function selectFixtureDepartment(departmentId: string): void {
  if (fixtureDepartmentById(departmentId) === null) {
    return;
  }

  selectedDepartmentId.value = departmentId;
}

export function resetSelectedFixtureDepartment(): void {
  selectedDepartmentId.value = FIXTURE_RANGERS_DEPARTMENT_ID;
}

export function fixtureDepartmentHasAdminAccess(
  department: FixtureDepartmentAccess,
): boolean {
  return (
    department.isDepartmentLead ||
    department.teams.some((team) => team.isTeamLead)
  );
}

export function fixtureDepartmentRoleSummary(
  department: FixtureDepartmentAccess,
): string {
  const leadTeams = department.teams
    .filter((team) => team.isTeamLead)
    .map((team) => team.teamLabel);

  if (department.isDepartmentLead && leadTeams.length > 0) {
    return `Department lead; team lead for ${leadTeams.join(", ")}`;
  }

  if (department.isDepartmentLead) {
    return "Department lead";
  }

  if (leadTeams.length > 0) {
    return `Team lead for ${leadTeams.join(", ")}`;
  }

  return "Staff member";
}
