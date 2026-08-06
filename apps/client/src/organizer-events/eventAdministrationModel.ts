// Event administration, as an organizer's client reads it (M18.29, M18.31; UI
// contract 12.6 `organizer.events`; ORG-005, ORG-006; PLACE-003; data/API 10.2,
// 10.6).
//
// One read answers with the organization's events, and each event carries four
// things a surface cannot work out for itself:
//
//  - which departments work it, and which of the organization's departments
//    could be added — participation is per event, so both lists are too;
//  - what Incident Command resolves to today — the override where one is set,
//    and the ORG-005 organization default otherwise — so "inherited" is a
//    stated answer rather than a blank select;
//  - why a participating department may not be removed, where that is the case,
//    worded by the node so the sentence read before the attempt and the one
//    returned after it are the same sentence;
//  - where the event sits in its authority lifecycle, and which node holds
//    authority for its other records while it runs.
//
// The participating list is also the Incident Command choice list. ORG-006
// admits only a department assigned to the event, which is this list exactly,
// so the client reads one list for both rather than being sent two that could
// disagree.
//
// The last of those is context, not a gate. Every other event-scoped record
// moves to the on-site primary node during the active window, but the `events`
// row is exempt by design so that an organizer can close or extend the window
// that makes the node read-only for everything else (M12.6). The surface says
// what is happening elsewhere and still offers the edit.
//
// Connected-only, and nothing is cached. An event's schedule is what the freeze,
// the credential window, and the hours grace period are measured from, and two
// devices editing a stale copy of it would be two answers to when the event is.

import { meridianJson } from "@/api/meridianApi";

/** A department, named for a select or a list. */
export interface EventDepartmentOption {
  readonly id: string;
  readonly name: string;
}

/**
 * A department that works this event (data/API 10.6), and what it runs.
 *
 * `removalRefusal` is the node's sentence, present exactly when the department
 * holds a designation the event still depends on — Incident Command (ORG-006)
 * or Placement (PLACE-003).
 */
export interface ParticipatingDepartment extends EventDepartmentOption {
  readonly isIncidentCommand: boolean;
  readonly isPlacement: boolean;
  readonly removalRefusal: string | null;
}

/** What Incident Command resolves to for an event right now. */
export interface EffectiveIncidentCommand {
  readonly id: string;
  readonly name: string;
  /** True when the event names no override and runs on the ORG-005 default. */
  readonly inherited: boolean;
}

/** Where the event is in its lifecycle, and who holds its other records. */
export interface EventWriteAuthority {
  readonly phase: string;
  /** The on-site node authoritative for this event's records, while it runs. */
  readonly authoritativeNode: string | null;
}

export interface AdministrableEvent {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
  readonly timezone: string;
  readonly minimumStaffAge: number | null;
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  readonly activeWindowStartsAt: string | null;
  readonly activeWindowEndsAt: string | null;
  readonly archived: boolean;
  readonly icDepartmentId: string | null;
  readonly effectiveIcDepartment: EffectiveIncidentCommand | null;
  /** PLACE-002, read-only here: choosing it is M14.1's, with its map authority. */
  readonly placementDepartment: EventDepartmentOption | null;
  readonly participatingDepartments: readonly ParticipatingDepartment[];
  readonly assignableDepartments: readonly EventDepartmentOption[];
  readonly authority: EventWriteAuthority;
}

export interface EventAdministration {
  readonly organizationId: string;
  /** ORG-005: what an event with no override of its own runs on. */
  readonly defaultIcDepartment: EventDepartmentOption | null;
  readonly events: readonly AdministrableEvent[];
}

/** The fields a create or an edit carries. */
export interface EventAdministrationDraft {
  readonly name: string;
  readonly slug: string;
  readonly timezone: string;
  readonly minimum_staff_age?: number | null;
  readonly starts_at?: string | null;
  readonly ends_at?: string | null;
  readonly active_event_window_starts_at?: string | null;
  readonly active_event_window_ends_at?: string | null;
  /**
   * Present only when the save means to change the designation. The node
   * treats an absent key as "leave it alone" and a present null as "clear it",
   * which is the difference between saving the schedule and unassigning the
   * department that runs the event's incidents.
   */
  readonly ic_department_id?: string | null;
}

interface EventPayload {
  readonly id?: string;
  readonly name?: string;
  readonly slug?: string;
  readonly timezone?: string;
  readonly minimum_staff_age?: number | null;
  readonly starts_at?: string | null;
  readonly ends_at?: string | null;
  readonly active_event_window_starts_at?: string | null;
  readonly active_event_window_ends_at?: string | null;
  readonly archived?: boolean;
  readonly ic_department_id?: string | null;
  readonly effective_ic_department?: {
    id: string;
    name: string;
    inherited: boolean;
  } | null;
  readonly placement_department?: EventDepartmentOption | null;
  readonly participating_departments?: readonly {
    id: string;
    name: string;
    is_incident_command?: boolean;
    is_placement?: boolean;
    removal_refusal?: string | null;
  }[];
  readonly assignable_departments?: readonly EventDepartmentOption[];
  readonly authority?: {
    phase?: string;
    authoritative_node?: string | null;
  };
}

interface AdministrationPayload {
  readonly organization_id?: string;
  readonly default_ic_department?: EventDepartmentOption | null;
  readonly events?: readonly EventPayload[];
}

function toEvent(payload: EventPayload): AdministrableEvent {
  return {
    id: payload.id ?? "",
    name: payload.name ?? "Unnamed event",
    slug: payload.slug ?? "",
    timezone: payload.timezone ?? "UTC",
    minimumStaffAge: payload.minimum_staff_age ?? null,
    startsAt: payload.starts_at ?? null,
    endsAt: payload.ends_at ?? null,
    activeWindowStartsAt: payload.active_event_window_starts_at ?? null,
    activeWindowEndsAt: payload.active_event_window_ends_at ?? null,
    archived: payload.archived === true,
    icDepartmentId: payload.ic_department_id ?? null,
    effectiveIcDepartment: payload.effective_ic_department ?? null,
    placementDepartment: payload.placement_department ?? null,
    participatingDepartments: (payload.participating_departments ?? []).map(
      (department) => ({
        id: department.id,
        name: department.name,
        isIncidentCommand: department.is_incident_command === true,
        isPlacement: department.is_placement === true,
        removalRefusal: department.removal_refusal ?? null,
      }),
    ),
    assignableDepartments: payload.assignable_departments ?? [],
    authority: {
      phase: payload.authority?.phase ?? "preparation",
      authoritativeNode: payload.authority?.authoritative_node ?? null,
    },
  };
}

function toAdministration(
  organizationId: string,
  payload: AdministrationPayload,
): EventAdministration {
  return {
    organizationId: payload.organization_id ?? organizationId,
    defaultIcDepartment: payload.default_ic_department ?? null,
    events: (payload.events ?? []).map(toEvent),
  };
}

export async function getEventAdministration(
  organizationId: string,
): Promise<EventAdministration> {
  const payload = await meridianJson<AdministrationPayload>(
    `/api/organizations/${encodeURIComponent(organizationId)}/events`,
  );

  return toAdministration(organizationId, payload);
}

export async function createEvent(
  organizationId: string,
  draft: EventAdministrationDraft,
): Promise<EventAdministration> {
  const payload = await meridianJson<AdministrationPayload>(
    "/api/commands/create-event",
    {
      method: "POST",
      body: JSON.stringify({ organization_id: organizationId, ...draft }),
    },
  );

  return toAdministration(organizationId, payload);
}

export async function updateEvent(
  organizationId: string,
  eventId: string,
  draft: EventAdministrationDraft,
): Promise<EventAdministration> {
  const payload = await meridianJson<AdministrationPayload>(
    "/api/commands/update-event",
    {
      method: "POST",
      body: JSON.stringify({ event_id: eventId, ...draft }),
    },
  );

  return toAdministration(organizationId, payload);
}

/** Add a department to an event, or bring back one that was removed. */
export async function assignDepartmentToEvent(
  organizationId: string,
  eventId: string,
  departmentId: string,
): Promise<EventAdministration> {
  return participationCommand(
    "/api/commands/assign-department-to-event",
    organizationId,
    eventId,
    departmentId,
  );
}

/**
 * Take a department out of an event. The node refuses while the department
 * holds the event's Incident Command (ORG-006) or Placement (PLACE-003)
 * designation.
 */
export async function removeDepartmentFromEvent(
  organizationId: string,
  eventId: string,
  departmentId: string,
): Promise<EventAdministration> {
  return participationCommand(
    "/api/commands/remove-department-from-event",
    organizationId,
    eventId,
    departmentId,
  );
}

async function participationCommand(
  path: string,
  organizationId: string,
  eventId: string,
  departmentId: string,
): Promise<EventAdministration> {
  const payload = await meridianJson<AdministrationPayload>(path, {
    method: "POST",
    body: JSON.stringify({ event_id: eventId, department_id: departmentId }),
  });

  return toAdministration(organizationId, payload);
}
