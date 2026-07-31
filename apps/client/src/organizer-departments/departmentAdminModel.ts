// The organizer department administration surfaces' data layer (M16.14;
// CLIENT-023, ORG-002; data/API 10.6).
//
// Until this task the departments an organizer saw were four objects compiled
// into the client, mutated in a module-level ref, and reset between tests. What
// they administered was therefore nothing: the list showed departments no server
// had, and archiving one changed a browser tab.
//
// This module is now a thin translation of the endpoints in data/API 10.6. Two
// choices in it are deliberate:
//
//  1. **No cache.** Every function is a request and nothing is held between
//     them. Departments are edited from two surfaces and archived from a third
//     view of the same list, and a module-level copy would be the thing that
//     shows an organizer the row they just archived as still active. The views
//     hold the result of the call they made and reload after a write.
//  2. **No client-side authorization, and no client-side uniqueness rule.** The
//     old model refused writes itself and duplicated the code-uniqueness check.
//     The server owns both (CLIENT-006), it answers with the failing field, and
//     a second copy here could only ever drift into refusing something the
//     server would allow.
//
// These are connected-only surfaces. Organization administration is not in the
// closed set of offline-writable work (data/API 7.2), so a request made with no
// node reachable fails and says so rather than queueing.

import { meridianJson } from "@/api/meridianApi";

/** One department, as the administration surfaces render it. */
export interface OrganizerDepartment {
  readonly id: string;
  readonly organizationId: string;
  readonly name: string;
  readonly code: string;
  readonly description: string | null;
  /** An ISO-8601 timestamp when archived, null while active. */
  readonly archivedAt: string | null;
}

/** The editable fields, as held by the create and edit forms. */
export interface OrganizerDepartmentDraft {
  name: string;
  code: string;
  description: string;
}

/** The archived-state filter the list surface offers. */
export type OrganizerDepartmentStatus = "all" | "active" | "archived";

interface DepartmentPayload {
  readonly id: string;
  readonly organization_id: string;
  readonly name: string;
  readonly code: string;
  readonly description: string | null;
  readonly archived_at: string | null;
}

function toDepartment(payload: DepartmentPayload): OrganizerDepartment {
  return {
    id: payload.id,
    organizationId: payload.organization_id,
    name: payload.name,
    code: payload.code,
    description: payload.description,
    archivedAt: payload.archived_at,
  };
}

/**
 * The submitted form, trimmed.
 *
 * The server trims too, so this changes nothing it stores. It changes what a
 * whitespace-only entry does: sent as typed it passes `required` and comes back
 * as a domain refusal, which reads oddly next to a field that visibly has
 * something in it.
 */
function toAttributes(draft: OrganizerDepartmentDraft): Record<string, unknown> {
  const description = draft.description.trim();

  return {
    name: draft.name.trim(),
    code: draft.code.trim(),
    description: description === "" ? null : description,
  };
}

export async function listOrganizerDepartments(
  organizationId: string,
  status: OrganizerDepartmentStatus = "all",
): Promise<readonly OrganizerDepartment[]> {
  const result = await meridianJson<{ departments?: DepartmentPayload[] }>(
    `/api/organizations/${organizationId}/departments?status=${status}`,
  );

  return (result.departments ?? []).map(toDepartment);
}

export async function getOrganizerDepartment(
  organizationId: string,
  departmentId: string,
): Promise<OrganizerDepartment> {
  return toDepartment(
    await meridianJson<DepartmentPayload>(
      `/api/organizations/${organizationId}/departments/${departmentId}`,
    ),
  );
}

export async function createOrganizerDepartment(
  organizationId: string,
  draft: OrganizerDepartmentDraft,
): Promise<OrganizerDepartment> {
  return toDepartment(
    await meridianJson<DepartmentPayload>("/api/commands/create-department", {
      method: "POST",
      body: JSON.stringify({
        organization_id: organizationId,
        ...toAttributes(draft),
      }),
    }),
  );
}

/**
 * Replace a department's editable fields.
 *
 * The organization is not sent: the server reads it off the department, which
 * is also what it authorizes against.
 */
export async function updateOrganizerDepartment(
  departmentId: string,
  draft: OrganizerDepartmentDraft,
): Promise<OrganizerDepartment> {
  return toDepartment(
    await meridianJson<DepartmentPayload>("/api/commands/update-department", {
      method: "POST",
      body: JSON.stringify({
        department_id: departmentId,
        ...toAttributes(draft),
      }),
    }),
  );
}

export async function archiveOrganizerDepartment(
  departmentId: string,
): Promise<OrganizerDepartment> {
  return toDepartment(
    await meridianJson<DepartmentPayload>("/api/commands/archive-department", {
      method: "POST",
      body: JSON.stringify({ department_id: departmentId }),
    }),
  );
}

export async function restoreOrganizerDepartment(
  departmentId: string,
): Promise<OrganizerDepartment> {
  return toDepartment(
    await meridianJson<DepartmentPayload>("/api/commands/restore-department", {
      method: "POST",
      body: JSON.stringify({ department_id: departmentId }),
    }),
  );
}
