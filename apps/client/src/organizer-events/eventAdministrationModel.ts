// Event administration, as an organizer's client reads it (M18.29; UI contract
// 12.6 `organizer.events`; ORG-005, ORG-006; data/API 10.2).
//
// One read answers with the organization's events, and each event carries three
// things a surface cannot work out for itself:
//
//  - the departments eligible to run its Incident Command, which ORG-006 limits
//    to the departments assigned to *that* event, so the list is per event and
//    never per organization;
//  - what Incident Command resolves to today — the override where one is set,
//    and the ORG-005 organization default otherwise — so "inherited" is a
//    stated answer rather than a blank select;
//  - where the event sits in its authority lifecycle, and which node holds
//    authority for its other records while it runs.
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

/** A department this event may designate as its Incident Command (ORG-006). */
export interface EventDepartmentOption {
  readonly id: string;
  readonly name: string;
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
  readonly icDepartmentOptions: readonly EventDepartmentOption[];
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
  readonly ic_department_options?: readonly EventDepartmentOption[];
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
    icDepartmentOptions: payload.ic_department_options ?? [],
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
