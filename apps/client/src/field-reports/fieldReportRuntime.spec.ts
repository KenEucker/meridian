import { afterEach, describe, expect, it } from "vitest";

import {
  authorFieldReportCatalog,
  discardFieldReportsOutsideEvent,
  persistFieldReportRuntime,
  reloadFieldReportRuntimeFromLocalStore,
  resetFieldReportRuntime,
} from "@/field-reports/fieldReportRuntime";
import {
  createOfflineFieldReport,
  FIELD_REPORT_ACCEPTED,
  FIELD_REPORT_PENDING_SYNC,
  type CreateOfflineFieldReportInput,
  type OfflineFieldReport,
} from "@/field-reports/offlineFieldReport";
import {
  commandOutbox,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import { queueCommand } from "@/outbox/submitCommand";

/*
 * What a context switch does to the author's Field Report catalog (M16.7;
 * CLIENT-014).
 *
 * The catalog is device-held and event-scoped, so it is one of the places the
 * previous context is still on screen after a switch. What it must not do is
 * throw away unsent work to satisfy a display rule.
 */

const BASE_INPUT: CreateOfflineFieldReportInput = {
  eventId: "event-previous",
  submittedByUserId: "user-1",
  staffId: "staff-1",
  originDeviceId: "device-1",
  originNodeId: "node-1",
  title: "Medical assist near Gate A",
  body: "Observed a medical assist near Gate A.",
};

function report(
  id: string,
  overrides: Partial<CreateOfflineFieldReportInput> = {},
  syncStatus: OfflineFieldReport["syncStatus"] = FIELD_REPORT_ACCEPTED,
): OfflineFieldReport {
  return {
    ...createOfflineFieldReport(
      { ...BASE_INPUT, ...overrides },
      { generateId: () => id, now: () => new Date("2027-06-01T12:00:00.000Z") },
    ),
    syncStatus,
  };
}

function install(reports: readonly OfflineFieldReport[]): void {
  for (const entry of reports) {
    authorFieldReportCatalog.recordSubmitted(entry);
  }

  persistFieldReportRuntime();
}

afterEach(async () => {
  await resetFieldReportRuntime();
  resetCommandOutbox();
});

describe("reconciling the catalog against the command outbox", () => {
  it("queues a command for a pending report that has none", () => {
    // The queue this replaced was rebuilt from the catalog on every hydrate and
    // never had a durable copy of its own, so every report already pending when
    // a device updates arrives with a catalog entry and no command. Without
    // this, the report reads "Queued locally" forever and nothing ever sends it
    // — silent, and this device is the only copy of what the author wrote.
    install([
      report(
        "cccccccc-1111-2222-3333-444455556666",
        {},
        FIELD_REPORT_PENDING_SYNC,
      ),
    ]);
    resetCommandOutbox();

    reloadFieldReportRuntimeFromLocalStore();

    const command = commandOutbox.get("cccccccc-1111-2222-3333-444455556666");
    expect(command?.commandType).toBe("submit-field-report");
    expect(command?.status).toBe("queued");
    expect(command?.payload).toMatchObject({
      id: "cccccccc-1111-2222-3333-444455556666",
      event_id: "event-previous",
    });
  });

  it("queues nothing for a report the node has already accepted", () => {
    install([report("aaaaaaaa-1111-2222-3333-444455556666")]);
    resetCommandOutbox();

    reloadFieldReportRuntimeFromLocalStore();

    expect(commandOutbox.size).toBe(0);
  });

  it("does not resurrect a command the node refused", () => {
    // A rejection is the user's to deal with (CLIENT-017). Re-queueing it behind
    // their back would send work the node has already said no to, and would hide
    // the refusal they were supposed to see.
    install([
      report(
        "cccccccc-1111-2222-3333-444455556666",
        {},
        FIELD_REPORT_PENDING_SYNC,
      ),
    ]);
    queueCommand({
      commandType: "submit-field-report",
      idempotencyKey: "cccccccc-1111-2222-3333-444455556666",
      payload: { id: "cccccccc-1111-2222-3333-444455556666" },
      eventId: "event-previous",
    });
    commandOutbox.markSending(
      "cccccccc-1111-2222-3333-444455556666",
      "2027-06-01T12:00:00.000Z",
    );
    commandOutbox.markRejected(
      "cccccccc-1111-2222-3333-444455556666",
      "2027-06-01T12:00:01.000Z",
      "That event has ended.",
    );

    reloadFieldReportRuntimeFromLocalStore();

    expect(commandOutbox.get("cccccccc-1111-2222-3333-444455556666")?.status).toBe(
      "rejected",
    );
    expect(commandOutbox.size).toBe(1);
  });
});

describe("discarding Field Reports on a context switch", () => {
  it("drops the previous event's accepted reports and keeps the new event's", () => {
    install([
      report("aaaaaaaa-1111-2222-3333-444455556666"),
      report("bbbbbbbb-1111-2222-3333-444455556666", { eventId: "event-next" }),
    ]);

    discardFieldReportsOutsideEvent("event-next");

    expect(
      authorFieldReportCatalog.snapshot().map((entry) => entry.eventId),
    ).toEqual(["event-next"]);
  });

  it("keeps unsent work whatever event it belongs to", () => {
    // Discarding it would destroy something a user typed, which is the one
    // outcome the outbox exists to prevent (technical spec 11A.5). It stays
    // queued, so the sync that drains the outbox still has it.
    install([
      report("aaaaaaaa-1111-2222-3333-444455556666"),
      report(
        "cccccccc-1111-2222-3333-444455556666",
        {},
        FIELD_REPORT_PENDING_SYNC,
      ),
    ]);
    queueCommand({
      commandType: "submit-field-report",
      idempotencyKey: "cccccccc-1111-2222-3333-444455556666",
      payload: { id: "cccccccc-1111-2222-3333-444455556666" },
      eventId: "event-previous",
    });

    discardFieldReportsOutsideEvent("event-next");

    expect(authorFieldReportCatalog.snapshot().map((entry) => entry.id)).toEqual(
      ["cccccccc-1111-2222-3333-444455556666"],
    );
    expect(
      commandOutbox.unsent().map((command) => command.idempotencyKey),
    ).toEqual(["cccccccc-1111-2222-3333-444455556666"]);
  });

  it("does not leave the dropped reports on disk to come back on the next boot", () => {
    install([report("aaaaaaaa-1111-2222-3333-444455556666")]);

    discardFieldReportsOutsideEvent("event-next");
    reloadFieldReportRuntimeFromLocalStore();

    expect(authorFieldReportCatalog.snapshot()).toEqual([]);
  });
});
