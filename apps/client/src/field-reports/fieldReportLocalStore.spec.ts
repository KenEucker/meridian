import { describe, expect, it } from "vitest";

import {
  createFieldReportLocalStore,
  FIELD_REPORT_LOCAL_STORE_KEY,
} from "@/field-reports/fieldReportLocalStore";
import {
  createOfflineFieldReport,
  FIELD_REPORT_PENDING_SYNC,
} from "@/field-reports/offlineFieldReport";

function sampleReport() {
  return createOfflineFieldReport(
    {
      eventId: "event-1",
      submittedByUserId: "user-1",
      staffId: "staff-1",
      originDeviceId: "device-1",
      originNodeId: "node-1",
      title: "Persisted report title",
      body: "Persisted report body",
    },
    {
      generateId: () => "3f2a1b9c-1111-2222-3333-444455556666",
      now: () => new Date("2027-06-01T12:00:00.000Z"),
    },
  );
}

describe("fieldReportLocalStore", () => {
  it("round-trips author reports through localStorage", () => {
    const memory = new Map<string, string>();
    const storage = {
      getItem: (key: string) => memory.get(key) ?? null,
      setItem: (key: string, value: string) => {
        memory.set(key, value);
      },
      removeItem: (key: string) => {
        memory.delete(key);
      },
      clear: () => memory.clear(),
      key: () => null,
      get length() {
        return memory.size;
      },
    } satisfies Storage;

    const store = createFieldReportLocalStore(storage);
    const report = sampleReport();

    store.save([report]);

    const loaded = store.load();
    expect(loaded).toHaveLength(1);
    expect(loaded[0]?.id).toBe(report.id);
    expect(loaded[0]?.title).toBe("Persisted report title");
    expect(loaded[0]?.body).toBe("Persisted report body");
    expect(loaded[0]?.syncStatus).toBe(FIELD_REPORT_PENDING_SYNC);
    expect(loaded[0]?.appends).toEqual([]);
    expect(memory.has(FIELD_REPORT_LOCAL_STORE_KEY)).toBe(true);

    store.clear();
    expect(store.load()).toEqual([]);
  });

  it("round-trips appends and hydrates legacy reports without an appends array", () => {
    const memory = new Map<string, string>();
    const storage = {
      getItem: (key: string) => memory.get(key) ?? null,
      setItem: (key: string, value: string) => {
        memory.set(key, value);
      },
      removeItem: (key: string) => {
        memory.delete(key);
      },
      clear: () => memory.clear(),
      key: () => null,
      get length() {
        return memory.size;
      },
    } satisfies Storage;

    const store = createFieldReportLocalStore(storage);
    const report = sampleReport();
    const withAppend = Object.freeze({
      ...report,
      appends: Object.freeze([
        Object.freeze({
          id: "aaaaaaaa-1111-2222-3333-444455556666",
          body: "Appended locally",
          deviceSubmittedAt: "2027-06-01T13:00:00.000Z",
          syncStatus: FIELD_REPORT_PENDING_SYNC,
        }),
      ]),
    });

    store.save([withAppend]);
    expect(store.load()[0]?.appends[0]?.body).toBe("Appended locally");

    const legacy = { ...report } as Record<string, unknown>;
    delete legacy.appends;
    memory.set(
      FIELD_REPORT_LOCAL_STORE_KEY,
      JSON.stringify({ version: 1, reports: [legacy] }),
    );

    expect(store.load()[0]?.appends).toEqual([]);
  });

  it("ignores corrupt storage payloads", () => {
    const storage = {
      getItem: () => "{not-json",
      setItem: () => undefined,
      removeItem: () => undefined,
      clear: () => undefined,
      key: () => null,
      length: 0,
    } satisfies Storage;

    const store = createFieldReportLocalStore(storage);
    expect(store.load()).toEqual([]);
  });
});
