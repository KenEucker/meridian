// A session document builder for the session specs (M16.5).
//
// The document `GET /api/me` returns has nine top-level keys and the parts that
// matter to the offline cache — the context event and its window — sit three
// levels down. Building one inline in every test would bury the field under test
// in thirty lines of scaffolding, so the shape lives here once and each test
// overrides only what it is about.
//
// Kept beside the module it describes rather than in a test directory because it
// has to move whenever `SessionDocument` moves. Nothing in the application
// imports it.

import type {
  SessionDocument,
  SessionEvent,
} from "@/session/sessionDocument";

export const FIXTURE_EVENT_ID = "event-decompression-2026";
export const FIXTURE_ORGANIZATION = "org-northwood-collective";

/** An event whose active window is open around the fixture's reference time. */
export function fixtureSessionEvent(
  overrides: Partial<SessionEvent> = {},
): SessionEvent {
  return {
    id: FIXTURE_EVENT_ID,
    organization_id: FIXTURE_ORGANIZATION,
    name: "Emberfall 2026",
    slug: "emberfall-2026",
    status: "published",
    timezone: "UTC",
    starts_at: "2026-09-10T16:00:00+00:00",
    ends_at: "2026-09-13T16:00:00+00:00",
    active_event_window_starts_at: "2026-09-08T16:00:00+00:00",
    active_event_window_ends_at: "2026-09-15T16:00:00+00:00",
    is_node_locked: true,
    ...overrides,
  };
}

export function fixtureSessionDocument(
  overrides: Partial<SessionDocument> = {},
): SessionDocument {
  const event = fixtureSessionEvent();

  return {
    user: {
      id: "user-dana",
      name: "Dana Departmentlead",
      email: "dana@example.com",
      staff_ids: ["staff-dana"],
    },
    roles: [
      {
        role_code: "department_lead",
        role_name: "Department Lead",
        scope_type: "department",
        organization_id: FIXTURE_ORGANIZATION,
        department_id: "dept-rangers",
        team_id: "team-command",
        team_name: "Command",
        event_id: event.id,
        team_grant_id: "grant-1",
        reason: "Team lead of Command",
        capabilities: ["department.manage", "shift.assign"],
      },
    ],
    capabilities: ["department.manage", "shift.assign"],
    organizations: [
      {
        id: FIXTURE_ORGANIZATION,
        name: "Northwood Collective",
        slug: "northwood-collective",
        status: "approved",
        archived_at: null,
      },
    ],
    events: [event],
    departments: [
      {
        id: "dept-rangers",
        organization_id: FIXTURE_ORGANIZATION,
        name: "Rangers",
        code: "RANGERS",
        membership_status: "active",
        archived_at: null,
      },
    ],
    teams: [
      {
        id: "team-command",
        department_id: "dept-rangers",
        organization_id: FIXTURE_ORGANIZATION,
        name: "Command",
        code: "COMMAND",
        is_default: false,
        is_lead: true,
        archived_at: null,
      },
    ],
    context: {
      organization_id: FIXTURE_ORGANIZATION,
      event_id: event.id,
      department_id: "dept-rangers",
      node_locked: true,
      node_locked_event_id: event.id,
      switching_available: false,
    },
    /*
     * A reader who has hidden nothing and trimmed nothing (M18.69).
     *
     * Stated rather than left off, because leaving it off means the catalog's
     * defaults apply and the fixture's navigation changes shape every time a
     * page is given a new default. The specs built on this fixture are about
     * what capability codes permit; a preference is a separate question, and
     * one a spec asking it should set for itself.
     */
    preferences: { hidden_pages: [], menu_hidden_pages: [] },
    refreshed_at: "2026-09-11T18:30:00+00:00",
    ...overrides,
  };
}
