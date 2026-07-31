// The organizer staff administration surface's data layer (M16.14; CLIENT-023;
// VOL-001 through VOL-006; data/API 10.6).
//
// Staff intake and department lead selection were three people compiled into the
// client. Adding one added it to an array, and the person it described did not
// exist anywhere an event could staff them from.
//
// This module now sends the three commands the server already publishes and
// reports what it answers. What it deliberately does not do is model the rules:
//
//  - **Standing is the server's answer, not a local derivation.** Intake into a
//    department promotes a prospective record to active (VOL-002, VOL-003), lead
//    selection creates the department's leads team and the membership behind it
//    (VOL-006), and an existing record matched by email is reused rather than
//    duplicated. The old model reimplemented the first, guessed at the second,
//    and refused the third. Every one of those is read off the response now.
//  - **Refusals come from the server.** "Do Not Staff records cannot be selected
//    as department leads" and "This staff member is already in the organization"
//    are sentences the server owns; the client shows what it was told.
//
// Connected-only, for the same reason as department administration: staff intake
// is not offline-writable work (data/API 7.2).

import { meridianJson } from "@/api/meridianApi";

/** One of a staff member's department memberships, within this organization. */
export interface OrganizerStaffDepartment {
  readonly departmentId: string;
  readonly departmentName: string | null;
  /** A department membership status (data/API 10.6). */
  readonly status: string | null;
  readonly isLead: boolean;
}

export interface OrganizerStaffMember {
  readonly id: string;
  readonly legalName: string;
  readonly preferredName: string | null;
  readonly displayName: string;
  readonly handle: string | null;
  readonly email: string | null;
  /** Organization-level standing, or null for a record with none (VOL-002). */
  readonly organizationStatus: string | null;
  readonly invited: boolean;
  readonly departments: readonly OrganizerStaffDepartment[];
  readonly leadDepartmentIds: readonly string[];
}

/** The intake form's fields. */
export interface OrganizerStaffDraft {
  legalName: string;
  preferredName: string;
  handle: string;
  email: string;
  departmentId: string;
  invite: boolean;
}

interface StaffPayload {
  readonly id: string;
  readonly legal_name: string;
  readonly preferred_name: string | null;
  readonly display_name: string;
  readonly handle: string | null;
  readonly email: string | null;
  readonly organization_status: string | null;
  readonly invited: boolean;
  readonly departments?: readonly {
    readonly department_id: string;
    readonly department_name: string | null;
    readonly status: string | null;
    readonly is_lead: boolean;
  }[];
  readonly lead_department_ids?: readonly string[];
}

function toStaffMember(payload: StaffPayload): OrganizerStaffMember {
  return {
    id: payload.id,
    legalName: payload.legal_name,
    preferredName: payload.preferred_name,
    displayName: payload.display_name,
    handle: payload.handle,
    email: payload.email,
    organizationStatus: payload.organization_status,
    invited: payload.invited,
    departments: (payload.departments ?? []).map((department) => ({
      departmentId: department.department_id,
      departmentName: department.department_name,
      status: department.status,
      isLead: department.is_lead,
    })),
    leadDepartmentIds: payload.lead_department_ids ?? [],
  };
}

function nullableTrim(value: string): string | null {
  const trimmed = value.trim();

  return trimmed === "" ? null : trimmed;
}

export async function listOrganizerStaff(
  organizationId: string,
): Promise<readonly OrganizerStaffMember[]> {
  const result = await meridianJson<{ staff?: StaffPayload[] }>(
    `/api/organizations/${organizationId}/staff`,
  );

  return (result.staff ?? []).map(toStaffMember);
}

/**
 * Add someone to the organization, optionally into a department and optionally
 * with an invitation.
 *
 * `invite` is sent as a JSON boolean because the server compares it by identity
 * against `true`; a truthy string would validate and then quietly not invite.
 */
export async function addOrganizerStaff(
  organizationId: string,
  draft: OrganizerStaffDraft,
): Promise<OrganizerStaffMember> {
  const departmentId = nullableTrim(draft.departmentId);

  return toStaffMember(
    await meridianJson<StaffPayload>("/api/commands/add-organization-staff", {
      method: "POST",
      body: JSON.stringify({
        organization_id: organizationId,
        legal_name: draft.legalName.trim(),
        preferred_name: nullableTrim(draft.preferredName),
        handle: nullableTrim(draft.handle),
        email: draft.email.trim(),
        ...(departmentId === null ? {} : { department_id: departmentId }),
        invite: draft.invite === true,
      }),
    }),
  );
}

export async function selectOrganizerDepartmentLead(
  staffId: string,
  departmentId: string,
): Promise<OrganizerStaffMember> {
  const result = await meridianJson<{ staff: StaffPayload }>(
    "/api/commands/select-department-lead",
    {
      method: "POST",
      body: JSON.stringify({ staff_id: staffId, department_id: departmentId }),
    },
  );

  return toStaffMember(result.staff);
}

export async function removeOrganizerDepartmentLead(
  staffId: string,
  departmentId: string,
): Promise<OrganizerStaffMember> {
  const result = await meridianJson<{ staff: StaffPayload }>(
    "/api/commands/remove-department-lead",
    {
      method: "POST",
      body: JSON.stringify({ staff_id: staffId, department_id: departmentId }),
    },
  );

  return toStaffMember(result.staff);
}
