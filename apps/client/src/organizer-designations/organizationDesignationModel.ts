// Organization-level team designations, and how an organizer maintains them
// (M18.12; TEAM-014, TEAM-016; data/API 6.4, 10.6).
//
// One designation exists at this level today: which team within the configured
// Organizers Department carries Staff Coordinator authority. The read answers
// with the current designation and the teams eligible to carry it, because the
// eligibility rule — Organizers Department teams only — is the node's, and the
// configuration surface should offer only what would be accepted.
//
// Department team designations are deliberately not here. TEAM-016 puts them on
// the department administration surface, so they live in
// `department-teams/teamAdminModel.ts` beside the rest of that surface's data.
//
// This is a connected-only surface: every write is followed by a re-read, and a
// refusal is the node's own sentence rather than a second copy of its rules.

import { meridianJson } from "@/api/meridianApi";

/** A team eligible to carry an organization designation. */
export interface DesignationEligibleTeam {
  readonly id: string;
  readonly name: string;
}

/** The current Staff Coordinator designation, or null when none is set. */
export interface StaffCoordinatorDesignation {
  readonly teamId: string;
  readonly teamName: string | null;
}

/** Everything the organization designation featureset renders, from one read. */
export interface OrganizationDesignations {
  readonly organizationId: string;
  readonly organizersDepartment: { id: string; name: string } | null;
  readonly staffCoordinator: StaffCoordinatorDesignation | null;
  readonly eligibleTeams: readonly DesignationEligibleTeam[];
}

interface DesignationsPayload {
  readonly organization_id?: string;
  readonly organizers_department?: { id: string; name: string } | null;
  readonly staff_coordinator?: {
    readonly team_id: string;
    readonly team_name: string | null;
  } | null;
  readonly eligible_teams?: { id: string; name: string }[];
}

function toDesignations(
  organizationId: string,
  payload: DesignationsPayload,
): OrganizationDesignations {
  return {
    organizationId: payload.organization_id ?? organizationId,
    organizersDepartment: payload.organizers_department ?? null,
    staffCoordinator: payload.staff_coordinator
      ? {
          teamId: payload.staff_coordinator.team_id,
          teamName: payload.staff_coordinator.team_name,
        }
      : null,
    eligibleTeams: payload.eligible_teams ?? [],
  };
}

export async function getOrganizationDesignations(
  organizationId: string,
): Promise<OrganizationDesignations> {
  const payload = await meridianJson<DesignationsPayload>(
    `/api/organizations/${encodeURIComponent(organizationId)}/designations`,
  );

  return toDesignations(organizationId, payload);
}

/**
 * Designate the Staff Coordinator team (TEAM-014), replacing the current
 * designation when one exists.
 */
export async function designateStaffCoordinatorTeam(
  organizationId: string,
  teamId: string,
): Promise<OrganizationDesignations> {
  const payload = await meridianJson<DesignationsPayload>(
    "/api/commands/designate-staff-coordinator-team",
    {
      method: "POST",
      body: JSON.stringify({ organization_id: organizationId, team_id: teamId }),
    },
  );

  return toDesignations(organizationId, payload);
}

export async function removeStaffCoordinatorTeam(
  organizationId: string,
): Promise<OrganizationDesignations> {
  const payload = await meridianJson<DesignationsPayload>(
    "/api/commands/remove-staff-coordinator-team",
    {
      method: "POST",
      body: JSON.stringify({ organization_id: organizationId }),
    },
  );

  return toDesignations(organizationId, payload);
}
