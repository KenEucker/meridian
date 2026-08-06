// Deployment option administration, as an operator's client reads and writes it
// (M18.30; UI contract 12.4 `department.deployments`; SLB-009, SLB-010).
//
// One read and four commands, none of them queued. Maintaining the list of
// places a department deploys to is setup rather than operations: it is not in
// the closed set of offline-writable work (data/API 7.2), and a location
// created on a disconnected device would be a location the radio watch on the
// other side of the field cannot assign anybody to.
//
// The rules live on the node and their refusals are shown as it worded them —
// the duplicate name, and the one that matters most in practice, which is that
// a deployment somebody is currently standing at cannot be archived until they
// are moved. Repeating either here would only give the page a way to disagree
// with the server (CLIENT-006).

import { meridianJson } from "@/api/meridianApi";

export interface DeploymentOption {
  readonly id: string;
  readonly name: string;
  readonly description: string | null;
  readonly locationDetails: string | null;
  readonly archivedAt: string | null;
  /**
   * How many staff are standing here right now. The node's count, and the same
   * one its archive refusal is measured against, so the page can say what
   * archiving would cost before the operator presses it.
   */
  readonly assignedStaffCount: number;
}

export interface DepartmentDeployments {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly departmentId: string;
  readonly departmentLabel: string;
  readonly deployments: readonly DeploymentOption[];
}

export interface DeploymentDraft {
  readonly name: string;
  readonly description: string;
  readonly locationDetails: string;
}

interface DeploymentsPayload {
  readonly context?: {
    event_id?: string;
    event_label?: string;
    department_id?: string;
    department_label?: string;
  };
  readonly deployments?: readonly {
    id?: string;
    name?: string;
    description?: string | null;
    location_details?: string | null;
    archived_at?: string | null;
    assigned_staff_count?: number;
  }[];
}

export async function getDepartmentDeployments(
  eventId: string,
  departmentId: string,
): Promise<DepartmentDeployments> {
  const payload = await meridianJson<DeploymentsPayload>(
    `/api/events/${encodeURIComponent(eventId)}/departments/${encodeURIComponent(departmentId)}/deployments`,
  );

  return {
    eventId: payload?.context?.event_id ?? eventId,
    eventLabel: payload?.context?.event_label ?? "",
    departmentId: payload?.context?.department_id ?? departmentId,
    departmentLabel: payload?.context?.department_label ?? "",
    deployments: (payload?.deployments ?? []).map((row) => ({
      id: row.id ?? "",
      name: row.name ?? "",
      description: row.description ?? null,
      locationDetails: row.location_details ?? null,
      archivedAt: row.archived_at ?? null,
      assignedStaffCount: row.assigned_staff_count ?? 0,
    })),
  };
}

/**
 * The two free-text fields are sent as typed, empty string and all.
 *
 * The node trims them and stores an emptied one as absent, which is what makes
 * "cleared" and "never written" the same state rather than two the surface has
 * to tell apart.
 */
function attributes(draft: DeploymentDraft): Record<string, string> {
  return {
    name: draft.name,
    description: draft.description,
    location_details: draft.locationDetails,
  };
}

export async function createDeployment(
  eventId: string,
  departmentId: string,
  draft: DeploymentDraft,
): Promise<void> {
  await meridianJson("/api/commands/create-deployment", {
    method: "POST",
    body: JSON.stringify({
      event_id: eventId,
      department_id: departmentId,
      ...attributes(draft),
    }),
  });
}

export async function updateDeployment(
  deploymentId: string,
  draft: DeploymentDraft,
): Promise<void> {
  await meridianJson("/api/commands/update-deployment", {
    method: "POST",
    body: JSON.stringify({
      deployment_id: deploymentId,
      ...attributes(draft),
    }),
  });
}

export async function archiveDeployment(deploymentId: string): Promise<void> {
  await meridianJson("/api/commands/archive-deployment", {
    method: "POST",
    body: JSON.stringify({ deployment_id: deploymentId }),
  });
}

export async function restoreDeployment(deploymentId: string): Promise<void> {
  await meridianJson("/api/commands/restore-deployment", {
    method: "POST",
    body: JSON.stringify({ deployment_id: deploymentId }),
  });
}
