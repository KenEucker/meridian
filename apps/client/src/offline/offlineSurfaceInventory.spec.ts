// The offline surface audit, exercised (M18.53; CLIENT-001, UI-020; technical
// spec 9.3; UI implementation contract 11.13, 16.2).
//
// Every routed surface in the shared client is mounted against a node that does
// not answer, and held to the outcome `offlineSurfaceInventory.ts` records for
// it. Four properties, and the last three are the ones the plan asks for:
//
//   1. the inventory and the router name the same surfaces, so a route added
//      without a decision about its offline behavior fails here rather than
//      being found by somebody standing in a field;
//   2. every surface renders what its row says it renders — the stored copy for
//      one that works offline, the explanation for one that does not;
//   3. no surface prints a transport failure, a stuck spinner, or a rendered
//      `undefined`; a request that never completed is not a bug report;
//   4. a connection-required surface says so rather than rendering empty.
//
// No server runs for any of it (CLIENT-024). `fetch` throws the way a browser's
// does when nothing answers, and the read set is installed through the same pull
// a device performs.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi, meridianFetch } from "@/api/meridianApi";
import {
  OFFLINE_SURFACE_INVENTORY,
  type OfflineSurfaceEntry,
} from "@/offline/offlineSurfaceInventory";
import {
  installOfflineReadSet,
  offlineReadSetPayload,
} from "@/offline/offlineReadSetFixture";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { resetCommandOutbox } from "@/outbox/commandOutboxRuntime";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { resetKioskContext, resolveKioskContext } from "@/session/kioskContext";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
  LOCAL_FIELD_ORGANIZATION_ID,
  LOCAL_FIELD_TEAM_IDS,
} from "@/session/localFieldSessionFixture";
import { selectSessionDepartment } from "@/session/sessionAccess";
import { configureSharedWorkstationId } from "@/session/workstationIdentity";
import {
  enterWorkstationLoginCode,
  resetWorkstationSession,
} from "@/session/workstationSession";

const EVENT_ID = LOCAL_FIELD_FIXTURE.eventId;
const DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;
const WORKSTATION_ID = "workstation-gate-a";
const DOCUMENT_ID = "99999999-9999-4999-8999-999999999995";

/**
 * Route parameters, so a detail surface is asked about something rather than
 * about nothing. The values do not have to exist anywhere: with no node in
 * reach, what is under test is what the surface says about the read it could
 * not make.
 */
const ROUTE_PARAMS: Readonly<Record<string, string>> = {
  eventId: EVENT_ID,
  departmentId: DEPARTMENT_ID,
  organizationId: LOCAL_FIELD_ORGANIZATION_ID,
  teamId: LOCAL_FIELD_TEAM_IDS.rangersDirt,
  shiftId: "99999999-9999-4999-8999-999999999991",
  trainingId: "99999999-9999-4999-8999-999999999992",
  incidentId: "99999999-9999-4999-8999-999999999993",
  fieldReportId: "99999999-9999-4999-8999-999999999994",
  documentId: DOCUMENT_ID,
  documentType: "policy",
  artifactKind: "policy",
  artifactId: "99999999-9999-4999-8999-999999999996",
  applicationId: "99999999-9999-4999-8999-999999999997",
  organizationSlug: "northwood-collective",
  eventSlug: "local-field-event",
};

/**
 * What a request that never completed looks like from a browser.
 *
 * A `TypeError` rather than a status, because the difference is the whole rule:
 * a status means the node spoke and its answer stands, and this is the case the
 * stored copy exists for.
 */
async function unreachableNode(): Promise<void> {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => {
      throw new TypeError("Failed to fetch");
    }),
  );

  /*
   * And the device has *found out*. Reachability is observed rather than probed
   * (M18.52): `nodeReachability` starts unknown, unknown counts as reachable,
   * and a client that has made no requests knows nothing. A device somebody is
   * standing in front of with no signal has made one and had it fail, which is
   * what the connected-only notices on these surfaces read.
   */
  await meridianFetch("/api/health").catch(() => undefined);
}

/** The sections the migrated read models compose their answers from (M18.50). */
function storedSections(): Record<string, readonly Record<string, unknown>[]> {
  return {
    staff: [
      {
        id: LOCAL_FIELD_FIXTURE.staffId,
        legal_name: "Local Field Author",
        preferred_name: "Local",
        handle: "local",
      },
    ],
    organizations: [
      { id: LOCAL_FIELD_ORGANIZATION_ID, name: "Northwood Collective" },
    ],
    events: [{ id: EVENT_ID, name: "Local Field Event" }],
    shifts: [
      {
        id: ROUTE_PARAMS.shiftId,
        event_id: EVENT_ID,
        department_id: DEPARTMENT_ID,
        eligible_team_id: LOCAL_FIELD_TEAM_IDS.rangersDirt,
        title: "Gate Swing",
        department_name_snapshot: "Rangers",
        team_name_snapshot: "Dirt",
        starts_at: "2027-07-04T18:00:00+00:00",
        ends_at: "2027-07-04T22:00:00+00:00",
        capacity: 4,
        signup_opens_at: null,
        signup_closes_at: null,
        schedule_lock_at: null,
        cancelled_at: null,
      },
    ],
    shift_assignments: [],
    /*
     * The Directory projection (M18.77; DIR-037): rows name the context they
     * were composed for, and the surface renders the ones matching the
     * session's resolved event.
     */
    directory_departments: [
      {
        scope: "event",
        organization_id: LOCAL_FIELD_ORGANIZATION_ID,
        organization_label: "Northwood Collective",
        event_id: EVENT_ID,
        event_label: "Local Field Event",
        id: DEPARTMENT_ID,
        name: "Rangers",
        is_organizers: false,
        leads: [],
        teams: [
          {
            id: LOCAL_FIELD_TEAM_IDS.rangersDirt,
            name: "Dirt",
            leads: [],
            members: [LOCAL_FIELD_FIXTURE.staffId],
          },
        ],
        prospectives: [],
      },
    ],
    directory_people: [
      {
        scope: "event",
        organization_id: LOCAL_FIELD_ORGANIZATION_ID,
        organization_label: "Northwood Collective",
        event_id: EVENT_ID,
        event_label: "Local Field Event",
        id: LOCAL_FIELD_FIXTURE.staffId,
        handle: "local",
        years_of_service: 1,
        locations: [
          {
            department_id: DEPARTMENT_ID,
            team_id: LOCAL_FIELD_TEAM_IDS.rangersDirt,
            kind: "team_member",
            status: "active",
          },
        ],
      },
    ],
    field_reports: [
      {
        id: ROUTE_PARAMS.fieldReportId,
        event_id: EVENT_ID,
        staff_id: LOCAL_FIELD_FIXTURE.staffId,
        fra_number: "FRA-2027-000014",
        temporary_local_number: null,
        title: "Stored report",
        body: "Held on this device.",
        sync_status: "accepted",
        device_submitted_at: "2027-07-04T17:00:00+00:00",
        server_received_at: "2027-07-04T17:01:00+00:00",
        created_at: "2027-07-04T17:00:00+00:00",
      },
    ],
    field_report_appends: [],
    policy_documents: [
      {
        id: DOCUMENT_ID,
        organization_id: LOCAL_FIELD_ORGANIZATION_ID,
        scope_type: "organization",
        scope_id: LOCAL_FIELD_ORGANIZATION_ID,
        title: "Volunteer Conduct",
        slug: "volunteer-conduct",
        markdown_source: "# Volunteer Conduct",
        document_revision: 2,
        fragment_revision: 1,
        published_at: "2027-06-01T12:00:00+00:00",
      },
    ],
    procedure_documents: [],
    document_acknowledgment_requirements: [
      {
        id: "requirement-1",
        organization_id: LOCAL_FIELD_ORGANIZATION_ID,
        scope_type: "organization",
        scope_id: LOCAL_FIELD_ORGANIZATION_ID,
        document_type: "policy",
        document_id: DOCUMENT_ID,
        requirement_context: "signup",
      },
    ],
    document_acknowledgments: [],

    /*
     * The Logistics Desk's own rows (M18.47), which the desk projection composes
     * the whole surface from — the staff at the desk, where they are, the shifts
     * in front of it, and who is assigned to them.
     */
    logistics_staff_index: [
      {
        id: `${EVENT_ID}:${DEPARTMENT_ID}:${LOCAL_FIELD_FIXTURE.staffId}`,
        event_id: EVENT_ID,
        department_id: DEPARTMENT_ID,
        staff_id: LOCAL_FIELD_FIXTURE.staffId,
        legal_name: "Robin Field",
        preferred_name: "Robin",
        handle: "robin",
        team_label: "Dirt",
        archived_at: null,
      },
    ],
    logistics_presence: [
      {
        id: "presence-1",
        event_id: EVENT_ID,
        department_id: DEPARTMENT_ID,
        staff_id: LOCAL_FIELD_FIXTURE.staffId,
        current_state: "on_site",
        marked_on_site_at: "2027-07-04T17:00:00+00:00",
        marked_off_site_at: null,
      },
    ],
    logistics_shift_index: [
      {
        id: ROUTE_PARAMS.shiftId,
        event_id: EVENT_ID,
        department_id: DEPARTMENT_ID,
        eligible_team_id: LOCAL_FIELD_TEAM_IDS.rangersDirt,
        title: "Gate A — Day",
        team_name_snapshot: "Dirt",
        starts_at: "2027-07-04T18:00:00+00:00",
        ends_at: "2027-07-04T22:00:00+00:00",
        capacity: 4,
        cancelled_at: null,
      },
    ],
    logistics_shift_assignments: [
      {
        id: "assignment-1",
        shift_id: ROUTE_PARAMS.shiftId,
        staff_id: LOCAL_FIELD_FIXTURE.staffId,
        assignment_status: "confirmed",
      },
    ],
    logistics_attendance: [],
    logistics_equipment_index: [],
    logistics_equipment_checkouts: [],
    logistics_future_signups: [],
  };
}

/**
 * A workstation that was working and then lost its node.
 *
 * The pinned context and the session are established against a node that
 * answers, because that is the only way a Kiosk ever gets either (AUTH-030,
 * UI-019). What the audit is about starts afterwards: UI-020 says the machine
 * keeps the last answer rather than inferring a context, and these surfaces have
 * to hold up once nothing is answering any more.
 */
async function pinWorkstationAgainstAnsweringNode(
  signIn: boolean,
): Promise<void> {
  configureSharedWorkstationId(WORKSTATION_ID);

  vi.stubGlobal(
    "fetch",
    vi.fn(
      async (input: RequestInfo | URL) =>
        new Response(
          JSON.stringify(
            String(input).includes("shared-workstation-session")
              ? {
                  session_key: "kQ7mVt2ZrBdN4xLpWyH3sCfJ8gEaU6nToXvI1bYh",
                  session: {
                    id: "session-1",
                    started_at: "2027-06-01T12:00:00+00:00",
                    last_activity_at: "2027-06-01T12:00:00+00:00",
                    expires_at: "2027-06-01T12:05:00+00:00",
                    inactivity_timeout_seconds: 300,
                    reauthenticated_at: null,
                  },
                  user: { id: "user-1", name: "Dana Reyes" },
                  shared_workstation: {
                    id: WORKSTATION_ID,
                    name: "Gate A Workstation",
                    organization_id: LOCAL_FIELD_ORGANIZATION_ID,
                    department_id: null,
                  },
                  event_id: EVENT_ID,
                }
              : {
                  shared_workstation: {
                    id: WORKSTATION_ID,
                    name: "Gate A Workstation",
                  },
                  pinned: true,
                  organization: {
                    id: LOCAL_FIELD_ORGANIZATION_ID,
                    name: "Northwood Collective",
                  },
                  event: {
                    id: EVENT_ID,
                    name: "Local Field Event",
                    timezone: "UTC",
                    active_event_window_starts_at: null,
                    active_event_window_ends_at: null,
                  },
                  department: { id: DEPARTMENT_ID, name: "Rangers" },
                  context_pinned_at: "2027-05-01T00:00:00+00:00",
                  options: [],
                },
          ),
          { status: 200, headers: { "content-type": "application/json" } },
        ),
    ),
  );

  await resolveKioskContext();

  if (signIn) {
    await enterWorkstationLoginCode({
      sharedWorkstationId: WORKSTATION_ID,
      code: "K3M7PQRS",
    });
  }

  await flushPromises();
}

const mounted: VueWrapper[] = [];

beforeEach(() => {
  window.localStorage.clear();
  clearOfflineReadSet();
  resetCommandOutbox();
  resetWorkstationSession();
  resetKioskContext();
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(async () => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  configureSharedWorkstationId(null);
  resetWorkstationSession();
  resetKioskContext();
  clearClientSession();
  clearOfflineReadSet();
  resetCommandOutbox();
  configureMeridianApi(null);
  window.localStorage.clear();
  vi.unstubAllGlobals();
  await flushPromises();
});

/** The route definitions the client actually serves, by name. */
const ROUTED_SURFACES = routes
  .filter(
    (route) => typeof route.name === "string" && route.component !== undefined,
  )
  .map((route) => ({
    name: String(route.name),
    path: String(route.path),
  }));

function paramsFor(path: string): Record<string, string> {
  const params: Record<string, string> = {};

  for (const [key, value] of Object.entries(ROUTE_PARAMS)) {
    if (path.includes(`:${key}`)) {
      params[key] = value;
    }
  }

  return params;
}

/**
 * Establish the standing the surface is read from, then take the node away.
 *
 * The three families need different standing and each is the standing the
 * surface is for: an organizer page is read by somebody working in the organizer
 * department, a Kiosk page by a workstation that was pinned while a node was
 * answering, everything else by a staff member working in a department.
 */
async function renderOffline(entry: OfflineSurfaceEntry): Promise<string> {
  const surface = ROUTED_SURFACES.find((route) => route.name === entry.route);

  if (surface === undefined) {
    throw new Error(`No route named ${entry.route}.`);
  }

  if (entry.route.startsWith("kiosk.")) {
    /*
     * Code entry and setup are the two surfaces a *locked* workstation shows,
     * and the router refuses code entry while a session is live (technical spec
     * 13.3) — so signing in first would have tested a redirect rather than the
     * screen.
     */
    await pinWorkstationAgainstAnsweringNode(
      entry.route !== "kiosk.workstation-login" && entry.route !== "kiosk.setup",
    );
  } else {
    installLocalFieldSession();
    selectSessionDepartment(
      entry.route.startsWith("organizer.")
        ? LOCAL_FIELD_DEPARTMENT_IDS.organizer
        : DEPARTMENT_ID,
    );

    await installOfflineReadSet(
      offlineReadSetPayload({
        sections: storedSections(),
        readiness: {
          context_event_id: EVENT_ID,
          usable_until: "2099-01-01T00:00:00+00:00",
        },
      }),
      { organizationId: LOCAL_FIELD_ORGANIZATION_ID, eventId: EVENT_ID },
    );
  }

  await unreachableNode();

  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({
    name: entry.route,
    params: paramsFor(surface.path),
  });
  await router.isReady();

  const wrapper = mount({ template: "<RouterView />" } as never, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  // Twice around the loop with a macrotask between: a surface whose read
  // resolves in a `finally` needs the microtask queue drained after the throw,
  // and one that reads a second time on a settled context needs the tick.
  await flushPromises();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await flushPromises();

  return wrapper.text().replace(/\s+/g, " ");
}

describe("the recorded offline surface inventory", () => {
  it("classifies every surface the router serves, and no others", () => {
    const routed = ROUTED_SURFACES.map((route) => route.name).sort();
    const recorded = OFFLINE_SURFACE_INVENTORY.map(
      (entry) => entry.route,
    ).sort();

    expect(recorded).toEqual(routed);
  });

  it("gives every surface one of the two outcomes and a stated basis", () => {
    for (const entry of OFFLINE_SURFACE_INVENTORY) {
      expect(["renders-offline", "connection-required"]).toContain(
        entry.outcome,
      );
      expect(entry.basis.length).toBeGreaterThan(20);
      expect(entry.offlineText).not.toBe("");
    }
  });

  it("records the surfaces that work offline as a stated list", () => {
    /*
     * The point of the audit: which surfaces answer with no node is a product
     * fact somebody can read, rather than whatever the read set most recently
     * happened to cover. A surface joining or leaving this list is a change to
     * what Meridian promises a person standing where there is no signal, and it
     * changes here first.
     */
    const offlineCapable = OFFLINE_SURFACE_INVENTORY.filter(
      (entry) => entry.outcome === "renders-offline",
    ).map((entry) => entry.route);

    expect(offlineCapable).toEqual([
      "home",
      "organizations.index",
      "organizations.events.index",
      "events.departments.index",
      "login",
      "auth.code.entry",
      "readiness",
      "settings.about",
      "events.departments.logistics",
      "events.departments.documents.index",
      "events.departments.branding",
      "events.departments.teams.create",
      "staff.me",
      "staff.event-horizon",
      // M18.77: the Directory renders from the stored authorized projection.
      "directory",
      "staff.shifts.index",
      "signup.documents.acknowledge",
      "staff.documents.acknowledgments",
      "staff.documents.index",
      "staff.workstation-code",
      "staff.field-reports.index",
      "staff.field-reports.create",
      "staff.field-reports.show",
      "ims.field-reports.index",
      "ims.field-reports.create",
      "ims.field-reports.show",
      "ims.restricted",
      "organizer.departments.create",
      "organizer.branding",
      "organizer.documents.index",
      "kiosk.setup",
      "kiosk.workstation-login",
      "kiosk.switch-user",
      "kiosk.reauth",
      "kiosk.safe-timeout",
      "not-found",
    ]);
  });
});

describe("every surface with no node in reach", () => {
  for (const entry of OFFLINE_SURFACE_INVENTORY) {
    it(`${entry.route} — ${entry.outcome}`, async () => {
      const text = await renderOffline(entry);

      expect(text).toContain(entry.offlineText);
    });
  }
});

describe("what no surface may do with no node in reach", () => {
  /*
   * A request that never completed is not a bug report. `Failed to fetch` is
   * what a browser says to a developer, and a rendered `undefined` or `[object
   * Object]` is a template that lost its footing — neither tells a person
   * standing in a field anything they can act on, and both read as a broken
   * screen rather than as a missing connection.
   */
  const TRANSPORT_LEAKS = [
    "Failed to fetch",
    "TypeError",
    "NetworkError",
    "[object Object]",
    "undefined",
    "NaN",
  ];

  /*
   * A spinner that never resolves is the failure this audit is named for: the
   * read threw, and the surface is still saying it is working on it. Every
   * loading state in the client is one of these sentences.
   */
  const UNRESOLVED_LOADING = [
    "Loading…",
    "Loading organization configuration",
    "Reading this event's dashboard",
    "Reading the audit record",
    "Checking…",
  ];

  /*
   * One surface reports the failure verbatim, and it is the one whose subject is
   * the failure. Device diagnostics exists to tell a technician what this device
   * got when it asked, and "Server health: Failed to fetch" is the answer to the
   * question that screen is asking — beside a node connection line already
   * reading "No node reachable". Replacing it with a friendlier sentence would
   * be withholding the diagnostic from the person who came for it.
   */
  const REPORTS_THE_FAILURE_VERBATIM = new Set(["settings.about"]);

  for (const entry of OFFLINE_SURFACE_INVENTORY) {
    it(`${entry.route} renders no transport failure and no stuck spinner`, async () => {
      const text = await renderOffline(entry);

      if (!REPORTS_THE_FAILURE_VERBATIM.has(entry.route)) {
        for (const leak of TRANSPORT_LEAKS) {
          expect(text).not.toContain(leak);
        }
      }

      for (const spinner of UNRESOLVED_LOADING) {
        expect(text).not.toContain(spinner);
      }
    });
  }

  /*
   * "It states plainly that it needs a connection." A surface that cannot work
   * offline has one job with no node in reach, and rendering nothing is not it:
   * an empty screen is indistinguishable from a screen whose answer is that
   * there is nothing there.
   */
  const NAMES_THE_CONNECTION =
    /cannot reach a Meridian node|connection to (this|the) node|needs a connection|require[sd]? (a )?server connection|could not be reached/i;

  for (const entry of OFFLINE_SURFACE_INVENTORY.filter(
    (candidate) => candidate.outcome === "connection-required",
  )) {
    it(`${entry.route} says it needs a connection rather than rendering empty`, async () => {
      const text = await renderOffline(entry);

      expect(text.trim().length).toBeGreaterThan(0);
      expect(text).toMatch(NAMES_THE_CONNECTION);
    });
  }
});
