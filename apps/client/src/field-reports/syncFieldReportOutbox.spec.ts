import { afterEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  clearFieldSession,
  installFieldSession,
} from "@/field-reports/fieldSession";
import { LOCAL_FIELD_FIXTURE } from "@/session/localFieldSessionFixture";
import {
  attachPendingFieldReportPhotos,
  clearPendingFieldReportPhotos,
  configurePendingFieldReportPhotoStore,
  listPendingFieldReportPhotoRecords,
} from "@/field-reports/pendingFieldReportPhotos";
import {
  createFieldReportPhotoStore,
  fieldReportPhotoStore,
} from "@/field-reports/pendingFieldReportPhotoStore";
import { resetFieldReportRuntime } from "@/field-reports/fieldReportRuntime";
import {
  commandOutbox,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import { submitFieldReport } from "@/field-reports/submitFieldReport";
import { syncFieldReportOutbox } from "@/field-reports/syncFieldReportOutbox";

afterEach(async () => {
  vi.unstubAllGlobals();
  configureMeridianApi(null);
  await clearPendingFieldReportPhotos();
  configurePendingFieldReportPhotoStore(fieldReportPhotoStore);
  await resetFieldReportRuntime();
  resetCommandOutbox();
  clearFieldSession();
});

describe("syncFieldReportOutbox (M9.8)", () => {
  it("no-ops when this device holds no credential", async () => {
    configureMeridianApi({
      baseUrl: "http://127.0.0.1:8000",
      bearerToken: null,
    });

    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const result = await syncFieldReportOutbox();
    expect(result.textAttempted).toBe(0);
    expect(result.blockedReason).toBe(
      "This device is not signed in, so commands are waiting.",
    );
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("submits text then uploads photos when the API accepts them", async () => {
    configurePendingFieldReportPhotoStore(createFieldReportPhotoStore(null));
    configureMeridianApi({
      baseUrl: "http://127.0.0.1:8000",
      bearerToken: "device-token",
    });
    installFieldSession({
      ...LOCAL_FIELD_FIXTURE,
      departmentId: null,
      departmentLabel: null,
      teamId: null,
      teamLabel: null,
    });

    const report = submitFieldReport({
      eventId: LOCAL_FIELD_FIXTURE.eventId,
      submittedByUserId: LOCAL_FIELD_FIXTURE.submittedByUserId,
      staffId: LOCAL_FIELD_FIXTURE.staffId,
      originDeviceId: LOCAL_FIELD_FIXTURE.originDeviceId,
      originNodeId: LOCAL_FIELD_FIXTURE.originNodeId,
      title: "Sync outbox report",
      body: "Body for sync.",
    });
    await attachPendingFieldReportPhotos(report.id, [
      Object.freeze({
        id: "11111111-1111-4111-8111-aaaaaaaaaaaa",
        mimeType: "image/webp" as const,
        byteSize: 3,
        width: 1,
        height: 1,
        bytes: Uint8Array.from([1, 2, 3]),
        checksumSha256: "abc",
        processedAt: "2027-07-04T13:22:10.000Z",
      }),
    ]);

    expect(commandOutbox.unsent("submit-field-report")).toHaveLength(1);

    const fetchMock = vi.fn(async (input: RequestInfo) => {
      const url = String(input);
      if (url.includes("submit-field-report")) {
        return new Response(
          JSON.stringify({
            id: report.id,
            fra_number: "FRA-2027-000001",
            server_received_at: "2027-07-04T13:21:00.000Z",
            sync_status: "accepted",
          }),
          { status: 201, headers: { "Content-Type": "application/json" } },
        );
      }

      return new Response(
        JSON.stringify({
          id: "11111111-1111-4111-8111-aaaaaaaaaaaa",
          checksum: "server-checksum",
        }),
        { status: 201, headers: { "Content-Type": "application/json" } },
      );
    });
    vi.stubGlobal("fetch", fetchMock);

    const result = await syncFieldReportOutbox();

    expect(result).toEqual({
      textAttempted: 1,
      textAccepted: 1,
      textFailed: 0,
      textPending: 0,
      photosAttempted: 1,
      photosUploaded: 1,
      photosFailed: 0,
      blockedReason: null,
      lastError: null,
    });
    expect(commandOutbox.unsent("submit-field-report")).toHaveLength(0);
    expect(commandOutbox.get(report.id)?.status).toBe("accepted");
    const photos = await listPendingFieldReportPhotoRecords(report.id);
    expect(photos).toHaveLength(1);
    expect(photos[0]?.syncStatus).toBe("uploaded");
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("keeps photos queued until the report text is accepted", async () => {
    configurePendingFieldReportPhotoStore(createFieldReportPhotoStore(null));
    configureMeridianApi({
      baseUrl: "http://127.0.0.1:8000",
      bearerToken: "device-token",
    });
    installFieldSession(LOCAL_FIELD_FIXTURE);

    const report = submitFieldReport({
      eventId: LOCAL_FIELD_FIXTURE.eventId,
      submittedByUserId: LOCAL_FIELD_FIXTURE.submittedByUserId,
      staffId: LOCAL_FIELD_FIXTURE.staffId,
      originDeviceId: LOCAL_FIELD_FIXTURE.originDeviceId,
      originNodeId: LOCAL_FIELD_FIXTURE.originNodeId,
      title: "Queued photo report",
      body: "Body waiting on text acceptance.",
    });
    await attachPendingFieldReportPhotos(report.id, [
      Object.freeze({
        id: "22222222-2222-4222-8222-aaaaaaaaaaaa",
        mimeType: "image/webp" as const,
        byteSize: 3,
        width: 1,
        height: 1,
        bytes: Uint8Array.from([1, 2, 3]),
        checksumSha256: "abc",
        processedAt: "2027-07-04T13:22:10.000Z",
      }),
    ]);

    const fetchMock = vi.fn(async () =>
      new Response(JSON.stringify({ message: "Server unavailable." }), {
        status: 503,
        headers: { "Content-Type": "application/json" },
      }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await syncFieldReportOutbox();

    expect(result).toEqual({
      textAttempted: 1,
      textAccepted: 0,
      textFailed: 1,
      textPending: 1,
      photosAttempted: 0,
      photosUploaded: 0,
      photosFailed: 0,
      blockedReason: null,
      lastError: "Server unavailable.",
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const photos = await listPendingFieldReportPhotoRecords(report.id);
    expect(photos[0]?.syncStatus).toBe("pending_upload");
  });
});
