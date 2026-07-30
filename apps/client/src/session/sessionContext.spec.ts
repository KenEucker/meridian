import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  clearClientSession,
  clientSessionState,
  installClientSession,
} from "@/session/clientSession";
import {
  localFieldSessionDocument,
  LOCAL_FIELD_ORGANIZATION_ID,
  LOCAL_FIELD_OTHER_EVENT_ID,
  LOCAL_FIELD_OTHER_ORGANIZATION_ID,
  switchableLocalFieldContext,
} from "@/session/localFieldSession";
import { readCachedSession, writeCachedSession } from "@/session/sessionCache";
import {
  registerSessionContextReset,
  sessionContextOrganization,
  sessionContextOrganizations,
  sessionNodeLock,
  sessionOrganizationId,
  sessionOrganizationLabel,
  sessionSwitchingAvailable,
  sessionSwitchingUnavailableReason,
  switchSessionContext,
} from "@/session/sessionContext";
import type { SessionDocument } from "@/session/sessionDocument";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
  selectedSessionDepartmentId,
} from "@/session/sessionAccess";
import { LOCAL_FIELD_DEPARTMENT_IDS } from "@/field-reports/localFieldFixture";

/*
 * Context resolution and switching (M16.7; CLIENT-011 through CLIENT-014;
 * technical spec 11A.3).
 *
 * No server runs here (CLIENT-024). Documents of the shape `GET /api/me`
 * returns are installed directly, and the one test that switches stubs the
 * endpoint so the request the client makes is itself part of what is asserted.
 */

/** The session a locked on-site node resolves: one event, no choice. */
function installLockedSession(): SessionDocument {
  const document = localFieldSessionDocument();

  installClientSession(document, "network");

  return document;
}

/** The session a node with no lock resolves: two organizations, two events. */
function installSwitchableSession(
  source: "network" | "cache" = "network",
): SessionDocument {
  const document = localFieldSessionDocument(switchableLocalFieldContext());

  installClientSession(document, source);

  return document;
}

/** The document the node answers a switch with. */
function switchedDocument(): SessionDocument {
  const switchable = switchableLocalFieldContext();

  return localFieldSessionDocument({
    ...switchable,
    // The node resolves the organization from the event it resolved the session
    // at, which is what makes an event switch an organization switch.
    context: {
      ...switchable.context,
      organization_id: LOCAL_FIELD_OTHER_ORGANIZATION_ID,
      event_id: LOCAL_FIELD_OTHER_EVENT_ID,
      department_id: null,
    },
    departments: [],
    teams: [],
    roles: [],
    capabilities: [],
    refreshed_at: "2027-03-13T09:00:00+00:00",
  });
}

function respondWith(document: SessionDocument) {
  return vi.fn(
    async (_input: RequestInfo | URL, _init?: RequestInit) =>
      new Response(JSON.stringify(document), {
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

function setDeviceOnLine(value: boolean): void {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value,
  });
  window.dispatchEvent(new Event(value ? "online" : "offline"));
}

beforeEach(() => {
  window.localStorage.clear();
  clearClientSession();
  setDeviceOnLine(true);
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  configureMeridianApi(null);
  clearClientSession();
  resetSelectedSessionDepartment();
  setDeviceOnLine(true);
  window.localStorage.clear();
  vi.unstubAllGlobals();
});

describe("session context resolution", () => {
  it("takes its organization and event from the node's lock", () => {
    // CLIENT-011: the node decides, and the session response narrows it.
    installLockedSession();

    expect(sessionOrganizationId.value).toBe(LOCAL_FIELD_ORGANIZATION_ID);
    expect(sessionOrganizationLabel.value).toBe("Idaho Burners");
    expect(sessionNodeLock.value?.eventLabel).toBe("Local Field Event");
  });

  it("holds no context at all before a session resolves", () => {
    expect(sessionOrganizationId.value).toBeNull();
    expect(sessionNodeLock.value).toBeNull();
    expect(sessionContextOrganizations.value).toEqual([]);
    expect(sessionSwitchingUnavailableReason.value).toBe("no_session");
  });

  it("holds no context once a cached session has outlived its event window", () => {
    // The access verdict is read through, not around: a document the client
    // will not act on offers no context to switch from either (CLIENT-008).
    installClientSession(
      localFieldSessionDocument({
        events: [
          {
            ...localFieldSessionDocument().events[0]!,
            active_event_window_ends_at: "2026-09-15T16:00:00+00:00",
          },
        ],
      }),
      "cache",
      new Date("2026-10-01T00:00:00+00:00"),
    );

    expect(clientSessionState.status).toBe("expired");
    expect(sessionOrganizationId.value).toBeNull();
    expect(sessionContextOrganizations.value).toEqual([]);
  });

  it("lists only the organizations and events the session carries", () => {
    // CLIENT-012: an association is what makes a context offerable, and the
    // response carries the caller's own and no others.
    installSwitchableSession();

    expect(
      sessionContextOrganizations.value.map(
        (organization) => organization.organizationLabel,
      ),
    ).toEqual(["Idaho Burners", "Cascadia Collective"]);

    const current = sessionContextOrganization(LOCAL_FIELD_ORGANIZATION_ID);
    expect(current?.isCurrent).toBe(true);
    expect(current?.events.map((event) => event.eventLabel)).toEqual([
      "Local Field Event",
    ]);
    expect(current?.events[0]?.isCurrent).toBe(true);

    const other = sessionContextOrganization(LOCAL_FIELD_OTHER_ORGANIZATION_ID);
    expect(other?.isCurrent).toBe(false);
    expect(other?.events[0]?.eventId).toBe(LOCAL_FIELD_OTHER_EVENT_ID);
    expect(other?.events[0]?.isCurrent).toBe(false);
  });

  it("groups events under the organization that owns them", () => {
    installSwitchableSession();

    for (const organization of sessionContextOrganizations.value) {
      for (const event of organization.events) {
        expect(event.organizationId).toBe(organization.organizationId);
      }
    }
  });
});

describe("session context switching availability", () => {
  it("offers switching to a connected user holding more than one context", () => {
    installSwitchableSession();

    expect(sessionSwitchingUnavailableReason.value).toBeNull();
    expect(sessionSwitchingAvailable.value).toBe(true);
  });

  it("does not offer switching on a node locked to an event", () => {
    // The node holds that event's records and no others, so a switch it could
    // not serve is not offered (technical spec 11A.3).
    installLockedSession();

    expect(sessionSwitchingUnavailableReason.value).toBe("node_locked");
    expect(sessionSwitchingAvailable.value).toBe(false);
  });

  it("does not offer switching to a device with no network", () => {
    // CLIENT-013.
    installSwitchableSession();
    setDeviceOnLine(false);

    expect(sessionSwitchingUnavailableReason.value).toBe("disconnected");
    expect(sessionSwitchingAvailable.value).toBe(false);
  });

  it("does not offer switching to a device running on cached permissions", () => {
    // A device can hold a network and still not reach its node.
    installSwitchableSession("cache");

    expect(clientSessionState.status).toBe("cached");
    expect(sessionSwitchingUnavailableReason.value).toBe("disconnected");
  });

  it("reports a node lock ahead of a lost connection", () => {
    // A locked install will not offer switching however good its network is, so
    // "reconnect to switch" would be a promise it cannot keep.
    installLockedSession();
    setDeviceOnLine(false);

    expect(sessionSwitchingUnavailableReason.value).toBe("node_locked");
  });

  it("has nothing to offer a user with one organization and one event", () => {
    installClientSession(
      localFieldSessionDocument({
        context: {
          ...localFieldSessionDocument().context,
          node_locked: false,
          node_locked_event_id: null,
          switching_available: false,
        },
      }),
      "network",
    );

    expect(sessionSwitchingUnavailableReason.value).toBe("single_context");
  });
});

describe("switching session context", () => {
  it("resolves the session at the requested event and lands in its organization", async () => {
    // CLIENT-012, CLIENT-014. Organization switching is event switching: the
    // node resolves the organization from the event it resolves the session at.
    installSwitchableSession();

    const fetchMock = respondWith(switchedDocument());
    vi.stubGlobal("fetch", fetchMock);

    expect(await switchSessionContext(LOCAL_FIELD_OTHER_EVENT_ID)).toBe(
      "switched",
    );
    expect(fetchMock.mock.calls[0]?.[0]).toBe(
      `http://node.test/api/me?event_id=${LOCAL_FIELD_OTHER_EVENT_ID}`,
    );
    expect(sessionOrganizationId.value).toBe(LOCAL_FIELD_OTHER_ORGANIZATION_ID);
    expect(sessionOrganizationLabel.value).toBe("Cascadia Collective");
  });

  it("leaves no data from the previous context behind", async () => {
    // CLIENT-014, and the whole point of the reset registry: permissions and
    // navigation come from the replaced document, the department choice is
    // dropped, the durable copy is the new context's, and everything a feature
    // cached for the old one is told to go.
    installSwitchableSession();
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    writeCachedSession(localFieldSessionDocument(switchableLocalFieldContext()));

    const discarded: Array<string | null> = [];
    const unregister = registerSessionContextReset((context) => {
      discarded.push(context.eventId);
    });

    vi.stubGlobal("fetch", respondWith(switchedDocument()));

    expect(await switchSessionContext(LOCAL_FIELD_OTHER_EVENT_ID)).toBe(
      "switched",
    );

    // Each registered reset runs once, and is told the context that has landed
    // so it can keep what belongs to it.
    expect(discarded).toEqual([LOCAL_FIELD_OTHER_EVENT_ID]);
    // The department the user was working in was a department of the previous
    // context and is not carried into the new one.
    expect(selectedSessionDepartmentId.value).toBeNull();
    // No capability, department, or team of the previous context survives, in
    // memory or on disk.
    expect(clientSessionState.document?.capabilities).toEqual([]);
    expect(clientSessionState.document?.departments).toEqual([]);
    expect(readCachedSession()?.document.context.event_id).toBe(
      LOCAL_FIELD_OTHER_EVENT_ID,
    );

    unregister();
  });

  it("stops registered resets from running once they unregister", async () => {
    installSwitchableSession();

    const reset = vi.fn();
    registerSessionContextReset(reset)();

    vi.stubGlobal("fetch", respondWith(switchedDocument()));
    await switchSessionContext(LOCAL_FIELD_OTHER_EVENT_ID);

    expect(reset).not.toHaveBeenCalled();
  });

  it("refuses to switch an offline client and puts nothing on the wire", async () => {
    // CLIENT-013. Nothing is discarded either: the client stays exactly where
    // it was.
    installSwitchableSession();
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    setDeviceOnLine(false);

    const fetchMock = respondWith(switchedDocument());
    vi.stubGlobal("fetch", fetchMock);

    expect(await switchSessionContext(LOCAL_FIELD_OTHER_EVENT_ID)).toBe(
      "unavailable",
    );
    expect(fetchMock).not.toHaveBeenCalled();
    expect(sessionOrganizationId.value).toBe(LOCAL_FIELD_ORGANIZATION_ID);
    expect(selectedSessionDepartmentId.value).toBe(
      LOCAL_FIELD_DEPARTMENT_IDS.rangers,
    );
  });

  it("refuses a context the session holds no association with", async () => {
    installSwitchableSession();

    const fetchMock = respondWith(switchedDocument());
    vi.stubGlobal("fetch", fetchMock);

    expect(await switchSessionContext("event-somebody-elses")).toBe(
      "unavailable",
    );
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("leaves the client where it was when the node cannot be reached", async () => {
    // The previous context is discarded after the node answers, never before,
    // so a switch that does not land costs nothing.
    installSwitchableSession();
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);

    const reset = vi.fn();
    const unregister = registerSessionContextReset(reset);

    vi.stubGlobal("fetch", unreachable());

    expect(await switchSessionContext(LOCAL_FIELD_OTHER_EVENT_ID)).toBe(
      "unreachable",
    );
    expect(reset).not.toHaveBeenCalled();
    expect(sessionOrganizationId.value).toBe(LOCAL_FIELD_ORGANIZATION_ID);
    expect(selectedSessionDepartmentId.value).toBe(
      LOCAL_FIELD_DEPARTMENT_IDS.rangers,
    );

    unregister();
  });
});
