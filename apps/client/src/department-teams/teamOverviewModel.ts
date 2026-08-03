// Team Overview: the team-lead handoff Staff Me routes to (M11.20; bound to the
// node in M18.9; CLIENT-023).
//
// Staff Me routes an ongoing event by role. Department leads have had Department
// Overview since M10; team leads had nowhere role-appropriate to land, so the
// interim routing dropped them on the Admin page, which answers "who is on my
// team" but not "what is my team doing right now". This model answers the second
// question at team scope.
//
// It used to answer it out of `department-ops/fixtures.ts` and a fixture session,
// which meant the authority test ran against a compiled-in list of who leads
// what. A real department lead opening a team in their own department was
// refused, because they were not in the fixture — the page failed closed on a
// question it had no business answering locally.
//
// Two reads now, and each is one the surface's own authority justifies:
//
//   `GET /api/departments/{department}/teams`  the teams, the roster, and the
//                                              `access` block naming who may
//                                              open what — the same answer the
//                                              Admin surface is shaped by.
//   the department planning read                per-shift aggregates for the
//                                              team, computed by the node.
//
// Authority is the node's `access` block and nothing else. Administer authority
// reaches every team in the department, a team lead reaches only teams they
// lead, and a caller with neither gets no teams to open rather than a narrower
// version of the page.

import {
  getPlanningTable,
  type PlanningRow,
} from "@/department-ops/departmentOpsReadModel";
import type { ShiftLifecycle } from "@/department-ops/types";
import {
  getDepartmentTeamAdminWorkspace,
  type DepartmentTeam,
  type DepartmentTeamStaffMember,
} from "@/department-teams/teamAdminModel";

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

/**
 * Read one team's overview, or null when this caller may not open that team.
 *
 * Null is the honest answer for both "no authority here" and "no such team in
 * the teams you may open". A named team the caller may not open is a miss
 * rather than a redirect to one they may: silently swapping teams would show
 * one team's roster under another team's URL.
 *
 * A failed read throws rather than returning null, because a node that could not
 * be reached is not a refusal and the surface says so differently.
 */
export async function resolveTeamOverview(
  eventId: string,
  departmentId: string,
  teamId: string | null | undefined,
): Promise<TeamOverviewModel | null> {
  if (eventId === "" || departmentId === "" || !teamId) {
    return null;
  }

  const workspace = await getDepartmentTeamAdminWorkspace(departmentId);
  const availableTeams = openableTeams(workspace);
  const team = availableTeams.find((candidate) => candidate.id === teamId) ?? null;

  if (team === null || workspace.department === null) {
    return null;
  }

  const planning = await getPlanningTable(eventId, departmentId, {
    teamId: team.id,
    date: null,
  });
  const shifts = planning.rows.map(toShift);
  const members = workspace.teamStaff
    .filter((member) => member.teamId === team.id)
    .map(toMember);

  return {
    eventId,
    eventLabel: planning.context.eventLabel,
    departmentId,
    departmentLabel: workspace.department.name,
    timeZone: planning.context.timeZone,
    team,
    roleLabel: workspace.access.ledTeamIds.includes(team.id)
      ? "Team lead"
      : "Department lead",
    availableTeams,
    members,
    shifts,
    summary: {
      memberCount: members.length,
      /*
       * The node's count for this team's shifts, not a tally of roster rows.
       * Attendance belongs to a shift rather than to a membership, and somebody
       * on two of this team's shifts is one member and two check-ins.
       */
      checkedInCount: shifts.reduce((total, shift) => total + shift.checkedInCount, 0),
      activeShiftCount: shifts.filter((shift) => shift.lifecycle === "active").length,
      upcomingShiftCount: shifts.filter((shift) => shift.lifecycle === "upcoming")
        .length,
    },
  };
}

/**
 * The teams this caller may open a team overview for.
 *
 * Administer authority is the wider of the two and is asked first, because an
 * administrator may also lead teams and would otherwise be narrowed to the ones
 * they personally lead. Archived teams are out either way: a team that no longer
 * takes anybody has no "right now" to show.
 */
function openableTeams(workspace: {
  readonly access: {
    readonly canAdminister: boolean;
    readonly canViewLedTeams: boolean;
    readonly ledTeamIds: readonly string[];
  };
  readonly teams: readonly DepartmentTeam[];
}): readonly DepartmentTeam[] {
  const active = workspace.teams.filter((team) => team.archivedAt === null);

  if (workspace.access.canAdminister) {
    return active;
  }

  if (workspace.access.canViewLedTeams) {
    return active.filter((team) => workspace.access.ledTeamIds.includes(team.id));
  }

  return [];
}

function toMember(member: DepartmentTeamStaffMember): TeamOverviewMember {
  return {
    staffId: member.staffId,
    displayName: member.displayName,
    handle: member.handle,
    roleLabel: member.roleLabel,
  };
}

function toShift(row: PlanningRow): TeamOverviewShift {
  return {
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
  };
}
