// The department staff list, as a lead's client reads it (M18.30; UI contract
// 12.4 `department.roster`; VOL-011, VOL-012).
//
// One event-and-department-scoped read. Two things about it are the node's
// decisions and this module only carries them across:
//
//  - **How much of the department comes back.** A whole-department reader gets
//    every active membership; a team lead gets their own teams. `access` says
//    which happened, so the page can word its own scope honestly instead of
//    guessing from the length of the list.
//  - **Whether emergency contacts are on the rows at all.** They are absent
//    from the payload for a reader who may not have them rather than served
//    empty, because a blank emergency contact has to keep meaning "none
//    recorded" to the lead reading it. `emergencyContacts` on `access` is what
//    decides whether the column is rendered; a row's own fields are `undefined`
//    when it is false, never `null`.
//
// Filtering is local. A department roster is hundreds of rows at most, and a
// lead standing in a field searching it should not need the node to be
// reachable — the same reasoning SLB-021 applies to the Logistics Desk. The
// read itself is connected-only: it is not in the closed set of offline work,
// and a contact list held on a device is a contact list that goes stale
// silently.

import { meridianJson } from "@/api/meridianApi";

export interface RosterTeamMembership {
  readonly id: string;
  readonly name: string;
  /** `lead` for a designated team lead, otherwise the ordinary membership. */
  readonly membershipRole: string | null;
}

export interface RosterMember {
  readonly membershipId: string;
  readonly staffId: string;
  readonly displayName: string;
  readonly legalName: string;
  readonly preferredName: string | null;
  readonly handle: string | null;
  readonly email: string | null;
  readonly phone: string | null;
  readonly city: string | null;
  readonly state: string | null;
  readonly membershipStatus: string;
  readonly organizationStatus: string | null;
  readonly teams: readonly RosterTeamMembership[];
  /** Present only when the reader holds VOL-012 access here. */
  readonly emergencyContactName?: string | null;
  readonly emergencyContactPhone?: string | null;
}

export interface RosterTeamOption {
  readonly id: string;
  readonly name: string;
  readonly code: string;
  readonly isDefault: boolean;
}

export interface DepartmentRoster {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly departmentId: string;
  readonly departmentLabel: string;
  /** Whether this department is actually working the event in the address. */
  readonly participatesInEvent: boolean;
  readonly wholeDepartment: boolean;
  readonly ledTeamIds: readonly string[];
  readonly emergencyContacts: boolean;
  readonly teams: readonly RosterTeamOption[];
  readonly members: readonly RosterMember[];
}

interface MemberPayload {
  readonly membership_id?: string;
  readonly staff_id?: string;
  readonly display_name?: string;
  readonly legal_name?: string;
  readonly preferred_name?: string | null;
  readonly handle?: string | null;
  readonly email?: string | null;
  readonly phone?: string | null;
  readonly city?: string | null;
  readonly state?: string | null;
  readonly membership_status?: string;
  readonly organization_status?: string | null;
  readonly teams?: readonly {
    id?: string;
    name?: string;
    membership_role?: string | null;
  }[];
  readonly emergency_contact_name?: string | null;
  readonly emergency_contact_phone?: string | null;
}

interface RosterPayload {
  readonly context?: {
    event_id?: string;
    event_label?: string;
    department_id?: string;
    department_label?: string;
    participates_in_event?: boolean;
  };
  readonly access?: {
    whole_department?: boolean;
    led_team_ids?: readonly string[];
    emergency_contacts?: boolean;
  };
  readonly teams?: readonly {
    id?: string;
    name?: string;
    code?: string;
    is_default?: boolean;
  }[];
  readonly members?: readonly MemberPayload[];
}

function toMember(payload: MemberPayload, withEmergencyContacts: boolean): RosterMember {
  const member: RosterMember = {
    membershipId: payload.membership_id ?? "",
    staffId: payload.staff_id ?? "",
    displayName: payload.display_name ?? "",
    legalName: payload.legal_name ?? "",
    preferredName: payload.preferred_name ?? null,
    handle: payload.handle ?? null,
    email: payload.email ?? null,
    phone: payload.phone ?? null,
    city: payload.city ?? null,
    state: payload.state ?? null,
    membershipStatus: payload.membership_status ?? "",
    organizationStatus: payload.organization_status ?? null,
    teams: (payload.teams ?? []).map((team) => ({
      id: team.id ?? "",
      name: team.name ?? "",
      membershipRole: team.membership_role ?? null,
    })),
  };

  if (!withEmergencyContacts) {
    return member;
  }

  return {
    ...member,
    emergencyContactName: payload.emergency_contact_name ?? null,
    emergencyContactPhone: payload.emergency_contact_phone ?? null,
  };
}

export async function getDepartmentRoster(
  eventId: string,
  departmentId: string,
): Promise<DepartmentRoster> {
  const payload = await meridianJson<RosterPayload>(
    `/api/events/${encodeURIComponent(eventId)}/departments/${encodeURIComponent(departmentId)}/roster`,
  );

  const emergencyContacts = payload?.access?.emergency_contacts ?? false;

  return {
    eventId: payload?.context?.event_id ?? eventId,
    eventLabel: payload?.context?.event_label ?? "",
    departmentId: payload?.context?.department_id ?? departmentId,
    departmentLabel: payload?.context?.department_label ?? "",
    participatesInEvent: payload?.context?.participates_in_event ?? true,
    wholeDepartment: payload?.access?.whole_department ?? false,
    ledTeamIds: payload?.access?.led_team_ids ?? [],
    emergencyContacts,
    teams: (payload?.teams ?? []).map((team) => ({
      id: team.id ?? "",
      name: team.name ?? "",
      code: team.code ?? "",
      isDefault: team.is_default ?? false,
    })),
    members: (payload?.members ?? []).map((member) =>
      toMember(member, emergencyContacts),
    ),
  };
}

/**
 * Narrow a roster to a team and a typed query, locally.
 *
 * Matching runs over the name, the handle, the email, and the phone number,
 * because the four are what somebody has in front of them when they are looking
 * for a person: a name they were told, a callsign they heard over the radio, or
 * a number they are trying to place.
 */
export function filterRoster(
  members: readonly RosterMember[],
  { teamId, query }: { teamId: string; query: string },
): readonly RosterMember[] {
  const needle = query.trim().toLowerCase();

  return members.filter((member) => {
    if (teamId !== "" && !member.teams.some((team) => team.id === teamId)) {
      return false;
    }

    if (needle === "") {
      return true;
    }

    return [
      member.displayName,
      member.legalName,
      member.handle,
      member.email,
      member.phone,
    ].some((field) => (field ?? "").toLowerCase().includes(needle));
  });
}
