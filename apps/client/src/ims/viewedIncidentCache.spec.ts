// The device incident cache (INC-017, INC-018; technical spec 19.2, 12.2).
//
// Two layers under test. The cache's own rules — per-user entries, six-week
// expiry from the last view, the logout flush — are exercised directly, with
// explicit clocks. The wiring — a connected view stores, an unreachable node is
// answered from the cache, a refusal is not, and a caller without
// `incidents.view` never touches it in either direction — is exercised through
// `getEventIncident`, against a stubbed node, because the population rule
// "the user's own views, never bulk sync" is a property of the read path.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import { getEventIncident, type ImsIncident } from "@/ims/incidentReadModel";
import {
  clearViewedIncidentCache,
  readViewedIncident,
  recordIncidentView,
  resetViewedIncidentCacheForTests,
  viewedIncidentCount,
  VIEWED_INCIDENT_EXPIRY_MS,
} from "@/ims/viewedIncidentCache";
import { clearClientSession } from "@/session/clientSession";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
} from "@/session/localFieldSessionFixture";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import type { SessionRole } from "@/session/sessionDocument";

const EVENT_ID = LOCAL_FIELD_FIXTURE.eventId;
const RANGERS = LOCAL_FIELD_DEPARTMENT_IDS.rangers;
const USER_ID = LOCAL_FIELD_FIXTURE.submittedByUserId;
const INCIDENT_ID = "99999999-9999-4999-8999-999999999901";

const viewedAt = new Date("2026-09-11T18:00:00+00:00");

function fakeIncident(overrides: Partial<ImsIncident> = {}): ImsIncident {
  return {
    id: INCIDENT_ID,
    eventId: EVENT_ID,
    incidentNumber: "INC-2026-000042",
    title: "Medical assist near Gate A",
    status: "on_scene",
    priorityLabel: "Serious",
    incidentTypeNames: ["Medical"],
    responders: [],
    linkedIncidents: [],
    attachedFieldReports: [],
    attachments: [],
    startedAt: "2026-09-11T17:40:00+00:00",
    locationName: "Gate A",
    locationAddress: null,
    locationDetails: null,
    createdByName: "Ingrid ICLead",
    createdAt: "2026-09-11T17:45:00+00:00",
    updatedAt: "2026-09-11T17:50:00+00:00",
    closedAt: null,
    nameReferenceChips: [],
    tagChips: [],
    timelineEntries: [],
    ...overrides,
  };
}

/** Weeks after the reference view moment, for the expiry clocks. */
function weeksLater(weeks: number): Date {
  return new Date(viewedAt.getTime() + weeks * 7 * 24 * 60 * 60 * 1000);
}

beforeEach(() => {
  resetViewedIncidentCacheForTests();
});

afterEach(() => {
  resetViewedIncidentCacheForTests();
  clearClientSession();
  resetSelectedSessionDepartment();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
  window.localStorage.clear();
});

describe("the viewed-incident cache's own rules", () => {
  it("stores an entry per view and serves it back to its viewer", () => {
    recordIncidentView(fakeIncident(), USER_ID, viewedAt);

    const entry = readViewedIncident(EVENT_ID, INCIDENT_ID, USER_ID, viewedAt);

    expect(entry).not.toBeNull();
    expect(entry?.incident.title).toBe("Medical assist near Gate A");
    expect(entry?.lastViewedAt).toBe(viewedAt.toISOString());
    expect(viewedIncidentCount(USER_ID, viewedAt)).toBe(1);
  });

  it("refreshes the last-viewed moment on a re-view", () => {
    recordIncidentView(fakeIncident(), USER_ID, viewedAt);
    recordIncidentView(
      fakeIncident({ title: "Medical assist near Gate A (updated)" }),
      USER_ID,
      weeksLater(5),
    );

    // Still one entry — a re-view replaces, never duplicates — and its clock
    // restarted, so it survives past the moment the first view would have
    // expired at.
    expect(viewedIncidentCount(USER_ID, weeksLater(7))).toBe(1);

    const entry = readViewedIncident(
      EVENT_ID,
      INCIDENT_ID,
      USER_ID,
      weeksLater(7),
    );

    expect(entry?.lastViewedAt).toBe(weeksLater(5).toISOString());
    expect(entry?.incident.title).toBe("Medical assist near Gate A (updated)");
  });

  it("has no cap: a sixth viewed incident does not drop the first", () => {
    for (let index = 0; index < 6; index += 1) {
      recordIncidentView(
        fakeIncident({ id: `incident-${index}` }),
        USER_ID,
        viewedAt,
      );
    }

    expect(viewedIncidentCount(USER_ID, viewedAt)).toBe(6);
    expect(
      readViewedIncident(EVENT_ID, "incident-0", USER_ID, viewedAt),
    ).not.toBeNull();
  });

  it("expires an entry six weeks after its last view, and only that entry", () => {
    recordIncidentView(fakeIncident({ id: "incident-old" }), USER_ID, viewedAt);
    recordIncidentView(
      fakeIncident({ id: "incident-recent" }),
      USER_ID,
      weeksLater(5),
    );

    // Seven weeks after the first view: past its six weeks, inside the
    // second's.
    expect(
      readViewedIncident(EVENT_ID, "incident-old", USER_ID, weeksLater(7)),
    ).toBeNull();
    expect(
      readViewedIncident(EVENT_ID, "incident-recent", USER_ID, weeksLater(7)),
    ).not.toBeNull();
    expect(viewedIncidentCount(USER_ID, weeksLater(7))).toBe(1);
  });

  it("holds an entry through the whole six weeks", () => {
    recordIncidentView(fakeIncident(), USER_ID, viewedAt);

    const lastInstant = new Date(
      viewedAt.getTime() + VIEWED_INCIDENT_EXPIRY_MS,
    );

    expect(
      readViewedIncident(EVENT_ID, INCIDENT_ID, USER_ID, lastInstant),
    ).not.toBeNull();
  });

  it("flushes whole at logout", () => {
    recordIncidentView(fakeIncident(), USER_ID, viewedAt);
    recordIncidentView(fakeIncident({ id: "incident-2" }), USER_ID, viewedAt);

    clearViewedIncidentCache();

    expect(viewedIncidentCount(USER_ID, viewedAt)).toBe(0);
    expect(
      readViewedIncident(EVENT_ID, INCIDENT_ID, USER_ID, viewedAt),
    ).toBeNull();
  });

  it("serves an entry only to the user whose view stored it", () => {
    recordIncidentView(fakeIncident(), USER_ID, viewedAt);

    expect(
      readViewedIncident(EVENT_ID, INCIDENT_ID, "user-somebody-else", viewedAt),
    ).toBeNull();
    expect(viewedIncidentCount("user-somebody-else", viewedAt)).toBe(0);
    expect(viewedIncidentCount(null, viewedAt)).toBe(0);
  });

  it("serves an entry only for the event it belongs to", () => {
    recordIncidentView(fakeIncident(), USER_ID, viewedAt);

    expect(
      readViewedIncident("event-other", INCIDENT_ID, USER_ID, viewedAt),
    ).toBeNull();
  });
});

/** The payload `IncidentReadController` publishes, at the fields this needs. */
function incidentPayload(): Record<string, unknown> {
  return {
    id: INCIDENT_ID,
    event_id: EVENT_ID,
    incident_number: "INC-2026-000042",
    status: "on_scene",
    priority_label: "Serious",
    title: "Medical assist near Gate A",
    started_at: "2026-09-11T17:40:00+00:00",
    created_at: "2026-09-11T17:45:00+00:00",
    updated_at: "2026-09-11T17:50:00+00:00",
  };
}

function respondWithIncident() {
  return vi.fn(
    async () =>
      new Response(JSON.stringify({ incident: incidentPayload() }), {
        status: 200,
        headers: { "content-type": "application/json" },
      }),
  );
}

function unreachable() {
  return vi.fn(async () => {
    throw new TypeError("Failed to fetch");
  });
}

function refuse(status: number) {
  return vi.fn(
    async () =>
      new Response(JSON.stringify({ message: "Forbidden." }), {
        status,
        headers: { "content-type": "application/json" },
      }),
  );
}

/** A Rangers session holding exactly the named incident capabilities. */
function installIncidentSession(capabilities: readonly string[]): void {
  const roles: SessionRole[] = [
    {
      role_code: "ic_viewer",
      role_name: "Incident Command Viewer",
      scope_type: "event",
      organization_id: null,
      department_id: RANGERS,
      team_id: null,
      team_name: null,
      event_id: EVENT_ID,
      team_grant_id: null,
      reason: null,
      capabilities: [...capabilities],
    },
  ];

  installLocalFieldSession({ roles });
  selectSessionDepartment(RANGERS);
}

describe("the incident read path and the cache", () => {
  beforeEach(() => {
    configureMeridianApi({
      baseUrl: "http://node.test",
      bearerToken: "device-token",
    });
  });

  it("stores a connected view (INC-017)", async () => {
    installIncidentSession(["incidents.view"]);
    vi.stubGlobal("fetch", respondWithIncident());

    const incident = await getEventIncident(EVENT_ID, INCIDENT_ID);

    expect(incident.id).toBe(INCIDENT_ID);
    expect(viewedIncidentCount(USER_ID)).toBe(1);
    expect(
      readViewedIncident(EVENT_ID, INCIDENT_ID, USER_ID),
    ).not.toBeNull();
  });

  it("answers an unreachable node from the cached copy", async () => {
    installIncidentSession(["incidents.view"]);
    vi.stubGlobal("fetch", respondWithIncident());
    await getEventIncident(EVENT_ID, INCIDENT_ID);

    vi.stubGlobal("fetch", unreachable());

    const incident = await getEventIncident(EVENT_ID, INCIDENT_ID);

    expect(incident.id).toBe(INCIDENT_ID);
    expect(incident.title).toBe("Medical assist near Gate A");
  });

  it("still fails for an incident this user never viewed", async () => {
    installIncidentSession(["incidents.view"]);
    vi.stubGlobal("fetch", unreachable());

    await expect(
      getEventIncident(EVENT_ID, "incident-never-viewed"),
    ).rejects.toThrow("Failed to fetch");
  });

  it("does not serve the cache over the node's refusal", async () => {
    installIncidentSession(["incidents.view"]);
    vi.stubGlobal("fetch", respondWithIncident());
    await getEventIncident(EVENT_ID, INCIDENT_ID);

    // The node spoke: this caller's authority is gone. A cached copy served
    // here would be the client re-granting what the node withheld
    // (CLIENT-006).
    vi.stubGlobal("fetch", refuse(403));

    await expect(getEventIncident(EVENT_ID, INCIDENT_ID)).rejects.toThrow(
      "Forbidden.",
    );
  });

  it("never touches the cache for a caller without incidents.view (INC-018)", async () => {
    installIncidentSession(["field_reports.view_event"]);
    vi.stubGlobal("fetch", respondWithIncident());

    // The node answered — enforcement is the server's — but the cache stays
    // empty: no entry is written for a caller the incident UI does not exist
    // for.
    await getEventIncident(EVENT_ID, INCIDENT_ID);

    expect(viewedIncidentCount(USER_ID)).toBe(0);

    // And nothing is read back either, even if an entry is somehow present.
    recordIncidentView(fakeIncident(), USER_ID, new Date());
    vi.stubGlobal("fetch", unreachable());

    await expect(getEventIncident(EVENT_ID, INCIDENT_ID)).rejects.toThrow(
      "Failed to fetch",
    );
  });
});
