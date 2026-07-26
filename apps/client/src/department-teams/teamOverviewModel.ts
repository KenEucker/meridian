import {
  LOCAL_DEPARTMENT_OPS_CONTEXT,
  LOCAL_DEPARTMENT_OVERVIEW,
  LOCAL_PLANNING_TABLE,
} from "@/department-ops/fixtures";
import type {
  ShiftAttendanceState,
  ShiftLifecycle,
} from "@/department-ops/types";
import {
  canAdministerDepartment,
  canLeadDepartmentTeam,
  listManagedTeamStaff,
  listDepartmentTeams,
  listTeamLeadTeams,
  resolveDepartmentSelfAdminSession,
  type DepartmentSelfAdminSession,
  type DepartmentTeam,
} from "@/department-teams/teamAdminModel";

/**
 * Team Overview: the team-lead handoff Staff Me routes to (M11.20).
 *
 * Staff Me routes an ongoing event by role. Department leads have had
 * Department Overview since M10; team leads had nowhere role-appropriate to
 * land, so the interim routing dropped them on the Admin page, which answers
 * "who is on my team" but not "what is my team doing right now". This model
 * answers the second question at team scope, using the same authority that
 * governs the Admin page: department administer authority reaches every team in
 * the department, a team lead reaches only teams they lead, and everyone else
 * fails closed rather than seeing a narrower version of the page.
 */
export interface TeamOverviewShift {
  readonly shiftId: string;
  readonly title: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly lifecycle: ShiftLifecycle;
  readonly capacity: number | null;
  readonly signedUpOrAssignedCount: number;
  readonly checkedInCount: number;
  readonly noShowCount: number;
  readonly statusLabel: string;
}

export interface TeamOverviewMember {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly roleLabel: string;
  readonly attendanceState: ShiftAttendanceState | null;
}

export interface TeamOverviewModel {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly departmentId: string;
  readonly departmentLabel: string;
  readonly timeZone: string;
  readonly team: DepartmentTeam;
  readonly roleLabel: string;
  readonly availableTeams: readonly DepartmentTeam[];
  readonly members: readonly TeamOverviewMember[];
  readonly shifts: readonly TeamOverviewShift[];
  readonly summary: {
    readonly memberCount: number;
    readonly checkedInCount: number;
    readonly activeShiftCount: number;
    readonly upcomingShiftCount: number;
  };
}

export function resolveTeamOverview(
  teamId: string | null | undefined,
  current: DepartmentSelfAdminSession | null = resolveDepartmentSelfAdminSession(),
): TeamOverviewModel | null {
  if (current === null) {
    return null;
  }

  const availableTeams = openableTeams(current);
  // A named team the session may not open is a miss, not a redirect to a team
  // it may: silently swapping teams would show one team's roster under another
  // team's URL.
  const team =
    availableTeams.find((candidate) => candidate.id === teamId) ?? null;

  if (team === null) {
    return null;
  }

  const members = teamMembers(current, team);
  const shifts = teamShifts(team);

  return {
    eventId: current.eventId,
    eventLabel: current.eventLabel,
    departmentId: current.departmentId,
    departmentLabel: current.departmentLabel,
    timeZone: LOCAL_DEPARTMENT_OPS_CONTEXT.timeZone,
    team,
    roleLabel: current.teamLeadTeamIds.includes(team.id)
      ? "Team lead"
      : current.roleLabel,
    availableTeams,
    members,
    shifts,
    summary: {
      memberCount: members.length,
      checkedInCount: members.filter(
        (member) => member.attendanceState === "checked_in",
      ).length,
      activeShiftCount: shifts.filter((shift) => shift.lifecycle === "active")
        .length,
      upcomingShiftCount: shifts.filter(
        (shift) => shift.lifecycle === "upcoming",
      ).length,
    },
  };
}

/**
 * Teams the current session may open a team overview for: every active
 * department team under department administer authority, otherwise only the
 * active teams the session leads.
 */
function openableTeams(current: DepartmentSelfAdminSession): DepartmentTeam[] {
  if (canAdministerDepartment(current)) {
    return listDepartmentTeams(current, "active");
  }

  if (canLeadDepartmentTeam(current)) {
    return listTeamLeadTeams(current).filter((team) => team.archivedAt === null);
  }

  return [];
}

function teamMembers(
  current: DepartmentSelfAdminSession,
  team: DepartmentTeam,
): TeamOverviewMember[] {
  const attendanceByStaffId = new Map(
    LOCAL_DEPARTMENT_OVERVIEW.assignments
      .filter((assignment) => assignment.teamLabel === team.name)
      .map((assignment) => [assignment.staffId, assignment.attendanceState]),
  );

  return listManagedTeamStaff(current)
    .filter((member) => member.teamId === team.id)
    .map((member) => ({
      staffId: member.staffId,
      displayName: member.displayName,
      handle: member.handle,
      roleLabel: member.roleLabel,
      attendanceState: attendanceByStaffId.get(member.staffId) ?? null,
    }));
}

function teamShifts(team: DepartmentTeam): TeamOverviewShift[] {
  return LOCAL_PLANNING_TABLE.rows
    .filter((row) => row.teamId === team.id)
    .map((row) => ({
      shiftId: row.shiftId,
      title: row.title,
      startsAt: row.startsAt,
      endsAt: row.endsAt,
      lifecycle: row.lifecycle,
      capacity: row.capacity,
      signedUpOrAssignedCount: row.signedUpOrAssignedCount,
      checkedInCount: row.checkedInCount,
      noShowCount: row.noShowCount,
      statusLabel: row.statusLabel,
    }))
    .slice()
    .sort((left, right) => left.startsAt.localeCompare(right.startsAt));
}
