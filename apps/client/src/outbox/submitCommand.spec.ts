import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import { COMMAND_CATALOG, describeCommand } from "@/outbox/commandCatalog";
import {
  commandOutbox,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import {
  ConnectedOnlyCommandError,
  queueCommand,
  sendConnectedCommand,
} from "@/outbox/submitCommand";

/*
 * Issuing a command (M16.10; CLIENT-015, CLIENT-016, CLIENT-018; technical spec
 * 11A.5; data/API 5.6, 7.2; UI implementation contract 16.3).
 *
 * No server runs here (CLIENT-024). The connected-only path is exercised against
 * a stubbed `fetch` and against a device the test puts offline.
 */

function setDeviceOnLine(onLine: boolean): void {
  Object.defineProperty(globalThis.navigator, "onLine", {
    configurable: true,
    get: () => onLine,
  });
  globalThis.dispatchEvent(new Event(onLine ? "online" : "offline"));
}

beforeEach(() => {
  setDeviceOnLine(true);
  configureMeridianApi({
    baseUrl: "http://127.0.0.1:8000",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  resetCommandOutbox();
  configureMeridianApi(null);
  setDeviceOnLine(true);
  vi.unstubAllGlobals();
});

describe("the command catalog", () => {
  it("registers exactly the Alpha 1 offline writes as queueable", () => {
    // Data/API 7.2 closes the list, and 5.6 says so: "Offline-writable commands
    // remain those listed in 7.2." Anything else is connected-only, which is why
    // the catalog is default-deny rather than default-queue.
    expect(
      COMMAND_CATALOG.filter((command) => command.offlineWritable).map(
        (command) => command.type,
      ),
    ).toEqual([
      "submit-field-report",
      "check-in-staff",
      "check-out-staff",
      "mark-no-show",
    ]);
  });

  it("gives every connected-only command a reason a person can read", () => {
    for (const command of COMMAND_CATALOG) {
      if (!command.offlineWritable) {
        expect(command.connectedOnlyReason).toBeTruthy();
      }
    }
  });
});

describe("queueing an offline write", () => {
  it("holds the command durably and returns before touching the network", () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const command = queueCommand({
      commandType: "check-in-staff",
      idempotencyKey: "11111111-1111-4111-8111-111111111111",
      payload: { operation_uuid: "11111111-1111-4111-8111-111111111111" },
      eventId: "event-1",
      detail: "Vera Staff",
    });

    expect(command.status).toBe("queued");
    expect(commandOutbox.pending()).toHaveLength(1);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("queues one command however many times it is issued", () => {
    const input = {
      commandType: "check-in-staff",
      idempotencyKey: "11111111-1111-4111-8111-111111111111",
      payload: { operation_uuid: "11111111-1111-4111-8111-111111111111" },
    } as const;

    queueCommand(input);
    queueCommand(input);
    queueCommand(input);

    expect(commandOutbox.size).toBe(1);
  });
});

describe("a command restricted to connected operation", () => {
  it.each([
    "create-incident",
    "acknowledge-document",
    "submit-application",
    "publish-event-map",
  ] as const)("is refused at queue time rather than queued (%s)", (type) => {
    // CLIENT-018 and UI implementation contract 16.3: "Telling a user their work
    // is queued when it can never send is worse than refusing it."
    expect(() =>
      queueCommand({
        commandType: type,
        idempotencyKey: "11111111-1111-4111-8111-111111111111",
        payload: {},
      }),
    ).toThrow(ConnectedOnlyCommandError);

    expect(commandOutbox.size).toBe(0);
  });

  it("refuses with the reason the surface shows the person who issued it", () => {
    expect(() =>
      queueCommand({
        commandType: "acknowledge-document",
        idempotencyKey: "11111111-1111-4111-8111-111111111111",
        payload: {},
      }),
    ).toThrow(describeCommand("acknowledge-document").connectedOnlyReason!);
  });

  it("is refused, and still not queued, when the device has no network", async () => {
    setDeviceOnLine(false);
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    await expect(
      sendConnectedCommand({
        commandType: "create-incident",
        idempotencyKey: "11111111-1111-4111-8111-111111111111",
        payload: { title: "Structure fire" },
      }),
    ).rejects.toThrow(ConnectedOnlyCommandError);

    expect(fetchMock).not.toHaveBeenCalled();
    expect(commandOutbox.size).toBe(0);
  });

  it("goes straight to the node when there is a connection", async () => {
    const fetchMock = vi.fn(
      async () =>
        new Response(JSON.stringify({ id: "incident-1" }), {
          status: 201,
          headers: { "Content-Type": "application/json" },
        }),
    );
    vi.stubGlobal("fetch", fetchMock);

    await expect(
      sendConnectedCommand({
        commandType: "create-incident",
        idempotencyKey: "11111111-1111-4111-8111-111111111111",
        payload: { title: "Structure fire" },
      }),
    ).resolves.toEqual({ id: "incident-1" });

    expect(fetchMock).toHaveBeenCalledWith(
      "http://127.0.0.1:8000/api/commands/create-incident",
      expect.objectContaining({ method: "POST" }),
    );
    // Sent is not queued: a connected-only command never enters the outbox, so
    // there is nothing for a later drain to send a second time.
    expect(commandOutbox.size).toBe(0);
  });

  it("refuses to send an offline write down the connected path", async () => {
    // The queue is where that work belongs, and sending it here would leave the
    // device with no durable copy of it.
    await expect(
      sendConnectedCommand({
        commandType: "check-in-staff",
        idempotencyKey: "11111111-1111-4111-8111-111111111111",
        payload: {},
      }),
    ).rejects.toThrow(ConnectedOnlyCommandError);
  });
});
