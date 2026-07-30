// Seed data for the department surfaces that have not been bound to their API
// endpoints yet (M16.14 through M16.22).
//
// It no longer answers "what may this user do". Navigation, the shell, and the
// router read that from the session response (M16.6), and this module reads the
// department selection they set rather than owning one of its own — two
// selections would mean the surface and the shell could disagree about which
// department is being worked.

import { computed } from "vue";

import {
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
  LOCAL_FIELD_TEAM_IDS,
} from "@/field-reports/localFieldFixture";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
  selectedSessionDepartmentId,
} from "@/session/sessionAccess";

export const FIXTURE_RANGERS_DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;
export const FIXTURE_ORGANIZER_DEPARTMENT_ID =
  LOCAL_FIELD_DEPARTMENT_IDS.organizer;
export const FIXTURE_GATE_DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.gate;
export const FIXTURE_DPW_DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.dpw;

export const FIXTURE_ORGANIZER_DEFAULT_TEAM_ID =
  LOCAL_FIELD_TEAM_IDS.organizerDefault;
export const FIXTURE_RANGERS_DIRT_TEAM_ID = LOCAL_FIELD_TEAM_IDS.rangersDirt;
export const FIXTURE_RANGERS_DEFAULT_TEAM_ID =
  LOCAL_FIELD_TEAM_IDS.rangersDefault;
export const FIXTURE_GATE_DEFAULT_TEAM_ID = LOCAL_FIELD_TEAM_IDS.gateDefault;
export const FIXTURE_GATE_CREDENTIALS_TEAM_ID =
  LOCAL_FIELD_TEAM_IDS.gateCredentials;
export const FIXTURE_DPW_DEFAULT_TEAM_ID = LOCAL_FIELD_TEAM_IDS.dpwDefault;
export const FIXTURE_DPW_BIKES_TEAM_ID = LOCAL_FIELD_TEAM_IDS.dpwBikes;

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
    readonly hasOrganizerDepartmentAdministration: boolean;
  };
}

export const fixtureDepartmentAccesses: readonly FixtureDepartmentAccess[] =
  Object.freeze([
    Object.freeze({
      eventId: LOCAL_FIELD_FIXTURE.eventId,
      eventLabel: LOCAL_FIELD_FIXTURE.eventLabel,
      departmentId: FIXTURE_ORGANIZER_DEPARTMENT_ID,
      departmentLabel: "Organizer",
      departmentCode: "ORG",
      description: "Organization-level event administration.",
      isDepartmentLead: false,
      roleLabel: "Organizer",
      teams: Object.freeze([
        Object.freeze({
          teamId: FIXTURE_ORGANIZER_DEFAULT_TEAM_ID,
          teamLabel: "Organizer Default",
          teamCode: "DEFAULT",
          description: "Default organizer administration team.",
          isDefault: true,
          isTeamLead: false,
          isMember: true,
          staff: Object.freeze([
            Object.freeze({
              staffId: LOCAL_FIELD_FIXTURE.staffId,
              displayName: "Local Field Author",
              handle: "local-field-author",
              roleLabel: "Organizer",
            }),
          ]),
        }),
      ]),
      capabilities: Object.freeze({
        hasLogistics: false,
        hasOperations: false,
        hasPlanning: false,
        hasFieldReportPermission: false,
        hasIncidentCommand: false,
        hasEquipmentVisibility: false,
        hasOrganizerDepartmentAdministration: true,
      }),
    }),
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
        hasOrganizerDepartmentAdministration: false,
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
        hasOrganizerDepartmentAdministration: false,
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
        hasOrganizerDepartmentAdministration: false,
      }),
    }),
  ]);

/**
 * The fixture record for the department the client is working in.
 *
 * Resolved from the session's department selection, falling back to Rangers —
 * the fullest of the seeded departments — when the selection names one this
 * fixture has no data for. A fixture surface that cannot find its seed data is a
 * gap in seed data, not a permission decision, and the permission decision was
 * already made from the session response before the surface rendered.
 */
export const selectedFixtureDepartment = computed(
  () =>
    fixtureDepartmentAccesses.find(
      (department) =>
        department.departmentId === selectedSessionDepartmentId.value,
    ) ??
    fixtureDepartmentAccesses.find(
      (department) => department.departmentId === FIXTURE_RANGERS_DEPARTMENT_ID,
    ) ??
    fixtureDepartmentAccesses[0]!,
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

/**
 * Work in one of the seeded departments.
 *
 * Delegates to the session's selection rather than keeping a second one, and
 * still refuses an id this fixture knows nothing about — the callers left are
 * fixture surfaces and their specs, for which an unknown id is a mistake rather
 * than a department the session happens to carry.
 */
export function selectFixtureDepartment(departmentId: string): void {
  if (fixtureDepartmentById(departmentId) === null) {
    return;
  }

  selectSessionDepartment(departmentId);
}

export function resetSelectedFixtureDepartment(): void {
  resetSelectedSessionDepartment();
}

export function fixtureDepartmentHasAdminAccess(
  department: FixtureDepartmentAccess,
): boolean {
  return (
    department.isDepartmentLead ||
    department.teams.some((team) => team.isTeamLead)
  );
}

export function fixtureDepartmentHasOrganizerDepartmentAccess(
  department: FixtureDepartmentAccess,
): boolean {
  return department.capabilities.hasOrganizerDepartmentAdministration;
}

// Branding authority and the role summary used to live here. Both were
// permission questions rather than seed data, and both are now answered from the
// session response: `department.branding.manage` in `brandingRouteProps`, and
// `sessionDepartmentRoleSummary` in `sessionAccess`.
