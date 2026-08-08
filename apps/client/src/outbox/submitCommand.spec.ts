import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi, meridianFetch } from "@/api/meridianApi";
import {
  centralReachability,
  recordCentralReach,
  resetCentralReachability,
} from "@/offline/centralReachability";
import {
  recordNodeUnreachable,
  resetNodeReachability,
} from "@/offline/nodeReachability";
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
  resetNodeReachability();
  resetCentralReachability();
  vi.unstubAllGlobals();
});

describe("the command catalog", () => {
  it("registers exactly the Alpha 1 offline writes as queueable", () => {
    // Data/API 7.2 closes the list, and 5.6 says so: "Offline-writable commands
    // remain those listed in 7.2." The Event Horizon's two preference commands
    // are the one addition a later section makes explicitly — 5.8A: "the two
    // preference commands may be queued offline like any other command"
    // (M18.44). Anything else is connected-only, which is why the catalog is
    // default-deny rather than default-queue.
    expect(
      COMMAND_CATALOG.filter((command) => command.offlineWritable).map(
        (command) => command.type,
      ),
    ).toEqual([
      "submit-field-report",
      "check-in-staff",
      "check-out-staff",
      "mark-no-show",
      "hide-event-horizon",
      "show-event-horizon",
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

  /*
   * Which tier the refusal reads (M18.52; UI contract 16.1).
   *
   * "Connected" is the local tier and only the local tier. An incident is
   * created on the node that will hold it, so an on-site node with the internet
   * down takes one; a device with no node to send to does not, and that is the
   * refusal the catalog's reason is written for.
   */
  it("sends against a reachable node whose central is unreachable", async () => {
    const fetchMock = vi.fn(
      async () =>
        new Response(JSON.stringify({ id: "incident-1" }), {
          status: 201,
          headers: {
            "Content-Type": "application/json",
            "Meridian-Central-Reach": "unreachable",
          },
        }),
    );
    vi.stubGlobal("fetch", fetchMock);

    // The node answers and reports that it cannot reach central, which is the
    // state the banner shows as "Central unreachable" while this desk keeps
    // working.
    await meridianFetch("/api/me");
    expect(centralReachability.value).toBe("unreachable");

    await expect(
      sendConnectedCommand({
        commandType: "create-incident",
        idempotencyKey: "11111111-1111-4111-8111-111111111111",
        payload: { title: "Structure fire" },
      }),
    ).resolves.toEqual({ id: "incident-1" });
  });

  it("is refused when no node is reachable, even having heard central was fine", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    recordCentralReach("reachable");
    recordNodeUnreachable();

    await expect(
      sendConnectedCommand({
        commandType: "create-incident",
        idempotencyKey: "11111111-1111-4111-8111-111111111111",
        payload: { title: "Structure fire" },
      }),
    ).rejects.toThrow(describeCommand("create-incident").connectedOnlyReason!);

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
