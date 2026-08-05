// What the client may export, and how it asks for the file (M16.22, M18.25;
// CLIENT-019, CLIENT-020; REPORT-001 through REPORT-007).
//
// The view spec covers the surface. This one covers the things underneath it
// that a surface cannot show: that the authority computed here follows the
// grant rather than the department, that a narrowed export names the department
// in the body the endpoint reads it from, and that each descriptor points at
// its own report — the failure a list of five exports invites is the button
// that runs the wrong one.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { MeridianApiError, configureMeridianApi } from "@/api/meridianApi";
import {
  installLocalFieldSession,
  localFieldSessionDocument,
  LOCAL_FIELD_DEPARTMENT_IDS,
} from "@/session/localFieldSessionFixture";
import { CAPABILITY_REPORTS_HOURS_WORKED_EXPORT } from "@/session/permissionCodes";
import {
  departmentReportingExportAuthority,
  downloadCredentialEligibilityExport,
  downloadReportingExport,
  HOURS_WORKED_EXPORT,
  organizerReportingExportAuthority,
  REPORTING_EXPORTS,
  STAFF_CONTACT_EXPORT,
} from "@/reporting/reportingExports";
import { clearClientSession } from "@/session/clientSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";

const EVENT_ID = "11111111-1111-4111-8111-111111111111";

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

beforeEach(() => {
  installLocalFieldSession();
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
  vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {});
});

afterEach(() => {
  configureMeridianApi(null);
  resetSelectedSessionDepartment();
  clearClientSession();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("what this client may export", () => {
  it("reports the export and the roles that carry it where the grant is held", () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);

    const authority = organizerReportingExportAuthority.value;

    expect(authority?.eventId).toBe(EVENT_ID);
    expect(authority?.eventLabel).toBe("Local Field Event");
    // Named as the node named it, so the page says what the server would say.
    expect(authority?.roleLabel).toBe("Organizer");
    expect(authority?.exports).toEqual(REPORTING_EXPORTS);
  });

  it("offers only the exports the capability list actually carries", () => {
    // CLIENT-005: an export the caller cannot run is absent, not disabled. The
    // five codes are granted separately, so holding one is not holding five.
    const document = localFieldSessionDocument();

    installLocalFieldSession({
      roles: document.roles.map((role) =>
        role.role_code === "organizer"
          ? { ...role, capabilities: [CAPABILITY_REPORTS_HOURS_WORKED_EXPORT] }
          : role,
      ),
    });
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);

    expect(organizerReportingExportAuthority.value?.exports).toEqual([
      HOURS_WORKED_EXPORT,
    ]);
  });

  it("reports nothing in a department where the same user holds no export grant", () => {
    // The capability is checked where it was granted: organizing one department
    // does not carry into another the same person is ordinary staff in.
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.gate);

    expect(organizerReportingExportAuthority.value).toBeNull();
    expect(departmentReportingExportAuthority.value).toBeNull();
  });

  it("reports nothing without a session at all", () => {
    clearClientSession();

    expect(organizerReportingExportAuthority.value).toBeNull();
    expect(departmentReportingExportAuthority.value).toBeNull();
  });
});

describe("which of REPORT-014's two surfaces a standing belongs to", () => {
  it("gives an organizer the event-wide authority and no department one", () => {
    // REPORT-006: the organizer's export covers the event, so the surface that
    // promises one department is not theirs — even though they are working in
    // the Organizers Department when they hold it.
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);

    expect(organizerReportingExportAuthority.value?.exports).toEqual(
      REPORTING_EXPORTS,
    );
    expect(departmentReportingExportAuthority.value).toBeNull();
  });

  it("gives a department lead the department authority and no event-wide one", () => {
    // REPORT-007, and the half that matters: a lead offered an event-wide
    // surface would be reading a scope the node was never going to serve them.
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);

    const authority = departmentReportingExportAuthority.value;

    expect(authority?.exports).toEqual(REPORTING_EXPORTS);
    expect(authority?.roleLabel).toBe("Department Lead");
    // The narrowing the department surface sends with every request.
    expect(authority?.departmentId).toBe(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    expect(authority?.departmentLabel).toBe("Rangers");
    expect(organizerReportingExportAuthority.value).toBeNull();
  });

  it("reads the reach off the role rather than off the capability", () => {
    // All five codes go to organizers and to department roles alike, so the
    // capability list cannot answer this. Same codes, same department, one role
    // code changed: the standing moves from one surface to the other.
    const document = localFieldSessionDocument();

    installLocalFieldSession({
      roles: document.roles.map((role) =>
        role.role_code === "organizer"
          ? { ...role, role_code: "lead_organizer", role_name: "Lead Organizer" }
          : role,
      ),
    });
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);

    expect(organizerReportingExportAuthority.value?.roleLabel).toBe(
      "Lead Organizer",
    );
    expect(departmentReportingExportAuthority.value).toBeNull();
  });
});

describe("running the credential eligibility export", () => {
  it("asks for a URL scoped to the event and sends no narrowing by default", async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse({ url: "http://node.test/downloads/x", expires_at: null }),
    );
    vi.stubGlobal("fetch", fetchMock);

    await expect(
      downloadCredentialEligibilityExport(EVENT_ID),
    ).resolves.toEqual({ url: "http://node.test/downloads/x", expiresAt: null });

    const [path, init] = fetchMock.mock.calls[0] as unknown as [
      string,
      RequestInit,
    ];
    expect(path).toBe(
      `http://node.test/api/events/${EVENT_ID}/exports/credential-eligibility/download-url`,
    );
    expect(init.body).toBe("{}");
  });

  it("names a department narrowing in the body the endpoint reads it from", async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse({ url: "http://node.test/downloads/x" }),
    );
    vi.stubGlobal("fetch", fetchMock);

    // A narrowing can only narrow: the node refuses a department outside the
    // caller's own scope rather than widening to it (REPORT-006, REPORT-007).
    await downloadCredentialEligibilityExport(
      EVENT_ID,
      LOCAL_FIELD_DEPARTMENT_IDS.rangers,
    );

    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(JSON.parse(String(init.body))).toEqual({
      department_id: LOCAL_FIELD_DEPARTMENT_IDS.rangers,
    });
  });

  it("throws the node's refusal rather than resolving to nothing", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        jsonResponse(
          {
            message:
              "You do not have permission to export credential eligibility for that department.",
          },
          403,
        ),
      ),
    );

    await expect(
      downloadCredentialEligibilityExport(EVENT_ID, "some-other-department"),
    ).rejects.toThrow(MeridianApiError);
  });
});

describe("the five Alpha 1 exports", () => {
  it("sends each descriptor to its own report's endpoint", async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse({ url: "http://node.test/downloads/x" }),
    );
    vi.stubGlobal("fetch", fetchMock);

    for (const descriptor of REPORTING_EXPORTS) {
      await downloadReportingExport(descriptor, EVENT_ID);
    }

    // The endpoint rides on the descriptor, so the entry that was rendered is
    // the entry that names where it goes. Reading the calls back against the
    // ids is what would catch a copy-pasted endpoint.
    const requested = fetchMock.mock.calls.map(
      (call) => (call as unknown as [string, RequestInit])[0],
    );

    expect(requested).toEqual(
      REPORTING_EXPORTS.map(
        (descriptor) =>
          `http://node.test/api/events/${EVENT_ID}/exports/${descriptor.id}/download-url`,
      ),
    );
  });

  it("states the excluded fields of every export before it is generated", () => {
    // REPORT-014: an organizer sees that emergency contacts are excluded
    // without opening the file. Only the contact list may carry them at all,
    // and there it is a condition rather than a column.
    for (const descriptor of REPORTING_EXPORTS) {
      expect(descriptor.columns).not.toContain("emergency_contact_name");
      expect(descriptor.columns).not.toContain("date_of_birth");

      if (descriptor.id !== "staff-contact") {
        expect(descriptor.excludes).toContain("Emergency contacts");
        expect(descriptor.conditionalColumns).toBeUndefined();
      }
    }

    expect(STAFF_CONTACT_EXPORT.conditionalColumns?.columns).toEqual([
      "emergency_contact_name",
      "emergency_contact_phone",
    ]);
    // REPORT-008: the roster excludes phone numbers, which the contact list
    // carries for every caller.
    expect(STAFF_CONTACT_EXPORT.columns).toContain("staff_phone");
    expect(
      REPORTING_EXPORTS.find((descriptor) => descriptor.id === "shift-roster")
        ?.excludes,
    ).toContain("Phone numbers");
  });
});
