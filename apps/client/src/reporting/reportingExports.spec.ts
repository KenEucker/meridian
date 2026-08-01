// What the client may export, and how it asks for the file (M16.22; CLIENT-019,
// CLIENT-020; REPORT-001, REPORT-006, REPORT-007).
//
// The view spec covers the surface. This one covers the two things underneath
// it that a surface cannot show: that the authority computed here follows the
// grant rather than the department, and that a narrowed export names the
// department in the body the endpoint reads it from.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { MeridianApiError, configureMeridianApi } from "@/api/meridianApi";
import { LOCAL_FIELD_DEPARTMENT_IDS } from "@/field-reports/localFieldFixture";
import {
  CREDENTIAL_ELIGIBILITY_EXPORT,
  downloadCredentialEligibilityExport,
  reportingExportAuthority,
} from "@/reporting/reportingExports";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";
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

    const authority = reportingExportAuthority.value;

    expect(authority?.eventId).toBe(EVENT_ID);
    expect(authority?.eventLabel).toBe("Local Field Event");
    // Named as the node named it, so the page says what the server would say.
    expect(authority?.roleLabel).toBe("Organizer");
    expect(authority?.exports).toEqual([CREDENTIAL_ELIGIBILITY_EXPORT]);
  });

  it("reports nothing in a department where the same user holds no export grant", () => {
    // The capability is checked where it was granted: organizing one department
    // does not carry into another the same person is ordinary staff in.
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.gate);

    expect(reportingExportAuthority.value).toBeNull();
  });

  it("reports nothing without a session at all", () => {
    clearClientSession();

    expect(reportingExportAuthority.value).toBeNull();
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
