import { afterEach, describe, expect, it } from "vitest";

import {
  CommandOutbox,
  CommandOutboxError,
  type OutboxCommand,
} from "@/outbox/commandOutbox";
import {
  commandOutbox,
  commandOutboxRevision,
  reloadCommandOutboxFromLocalStore,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import {
  COMMAND_OUTBOX_LOCAL_STORE_KEY,
  createCommandOutboxLocalStore,
} from "@/outbox/commandOutboxLocalStore";
import { queueCommand } from "@/outbox/submitCommand";

/*
 * The durable command queue (M16.10; CLIENT-015 through CLIENT-017; technical
 * spec 11A.5; data/API 5.3, 5.6; UI implementation contract 16.3).
 */

const QUEUED_AT = "2027-07-04T16:00:00.000Z";

function enqueue(
  outbox: CommandOutbox,
  idempotencyKey: string,
  overrides: Partial<Parameters<CommandOutbox["enqueue"]>[0]> = {},
): OutboxCommand {
  return outbox.enqueue({
    idempotencyKey,
    commandType: "check-in-staff",
    payload: { operation_uuid: idempotencyKey, shift_id: "shift-1" },
    queuedAt: QUEUED_AT,
    ...overrides,
  });
}

afterEach(() => {
  resetCommandOutbox();
  globalThis.localStorage.clear();
});

describe("the command outbox", () => {
  it("holds a command as queued, keyed by its idempotency key", () => {
    const outbox = new CommandOutbox();
    const command = enqueue(outbox, "11111111-1111-4111-8111-111111111111");

    expect(command.status).toBe("queued");
    expect(command.attempts).toBe(0);
    expect(outbox.pending()).toEqual([command]);
  });

  it("treats a repeated key as the same command rather than a second one", () => {
    // Data/API 5.3: "A repeated command with the same command/operation UUID
    // must not create duplicate domain effects." The device side of that is
    // never putting a second copy in the queue to begin with (CLIENT-016).
    const outbox = new CommandOutbox();
    const first = enqueue(outbox, "11111111-1111-4111-8111-111111111111");
    outbox.markSending(first.idempotencyKey, QUEUED_AT);

    const second = enqueue(outbox, "11111111-1111-4111-8111-111111111111", {
      payload: { operation_uuid: "different", shift_id: "shift-9" },
    });

    expect(outbox.size).toBe(1);
    // The held command wins: the first attempt's history is not discarded by a
    // repeat, and neither is its payload replaced.
    expect(second.status).toBe("sending");
    expect(second.payload).toEqual(first.payload);
  });

  it("keeps a rejected command until somebody dismisses it", () => {
    const outbox = new CommandOutbox();
    const command = enqueue(outbox, "11111111-1111-4111-8111-111111111111");

    outbox.markSending(command.idempotencyKey, QUEUED_AT);
    outbox.markRejected(
      command.idempotencyKey,
      "2027-07-04T16:00:05.000Z",
      "The shift has already ended.",
    );

    const rejected = outbox.byStatus("rejected");
    expect(rejected).toHaveLength(1);
    expect(rejected[0]?.statusReason).toBe("The shift has already ended.");
    // It is not pending: a rejected command will not be retried on its own.
    expect(outbox.pending()).toEqual([]);
    expect(outbox.unsent()).toEqual([]);

    expect(outbox.dismiss(command.idempotencyKey)).toBe(true);
    expect(outbox.size).toBe(0);
  });

  it("refuses to dismiss work that has not reached the node", () => {
    const outbox = new CommandOutbox();
    const command = enqueue(outbox, "11111111-1111-4111-8111-111111111111");

    expect(() => outbox.dismiss(command.idempotencyKey)).toThrow(
      CommandOutboxError,
    );
    expect(outbox.size).toBe(1);
  });

  it("returns a failed attempt to the queue with the reason recorded", () => {
    const outbox = new CommandOutbox();
    const command = enqueue(outbox, "11111111-1111-4111-8111-111111111111");

    outbox.markSending(command.idempotencyKey, QUEUED_AT);
    const retried = outbox.markRetryable(
      command.idempotencyKey,
      "Failed to fetch",
    );

    expect(retried.status).toBe("queued");
    expect(retried.attempts).toBe(1);
    expect(retried.statusReason).toBe("Failed to fetch");
    expect(outbox.pending()).toHaveLength(1);
  });

  it("bounds accepted history without ever pruning queued or rejected work", () => {
    const outbox = new CommandOutbox();

    for (let index = 0; index < 4; index += 1) {
      const key = `0000000${index}-1111-4111-8111-111111111111`;
      enqueue(outbox, key);
      outbox.markSending(key, QUEUED_AT);
      outbox.markAccepted(key, QUEUED_AT);
    }

    const queued = enqueue(outbox, "99999999-1111-4111-8111-111111111111");

    expect(outbox.pruneAccepted(2)).toBe(2);
    expect(outbox.byStatus("accepted").map((entry) => entry.idempotencyKey)).toEqual([
      "00000002-1111-4111-8111-111111111111",
      "00000003-1111-4111-8111-111111111111",
    ]);
    expect(outbox.pending()).toEqual([queued]);
  });
});

describe("the durable copy", () => {
  it("survives a restart with the command still queued", () => {
    queueCommand({
      commandType: "check-in-staff",
      idempotencyKey: "11111111-1111-4111-8111-111111111111",
      payload: { operation_uuid: "11111111-1111-4111-8111-111111111111" },
      eventId: "event-1",
      detail: "Vera Staff",
    });

    reloadCommandOutboxFromLocalStore();

    const restored = commandOutbox.get("11111111-1111-4111-8111-111111111111");
    expect(restored?.status).toBe("queued");
    expect(restored?.detail).toBe("Vera Staff");
    expect(restored?.payload).toEqual({
      operation_uuid: "11111111-1111-4111-8111-111111111111",
    });
  });

  it("brings a command that was on the wire back as queued", () => {
    // The process died mid-request and nothing on this device knows whether the
    // node saw it. The idempotency key is what makes sending it again the same
    // command rather than a second one.
    const store = createCommandOutboxLocalStore(globalThis.localStorage);
    store.save([
      {
        idempotencyKey: "11111111-1111-4111-8111-111111111111",
        commandType: "check-in-staff",
        payload: {},
        eventId: null,
        detail: null,
        queuedAt: QUEUED_AT,
        status: "sending",
        attempts: 1,
        lastAttemptAt: QUEUED_AT,
        settledAt: null,
        statusReason: null,
      },
    ]);

    expect(store.load()[0]?.status).toBe("queued");
  });

  it("drops a stored entry it cannot make sense of rather than booting from it", () => {
    // A half-written command has no payload to send and no key to dedupe on.
    globalThis.localStorage.setItem(
      COMMAND_OUTBOX_LOCAL_STORE_KEY,
      JSON.stringify({
        version: 1,
        commands: [
          { idempotencyKey: "", commandType: "check-in-staff" },
          { idempotencyKey: "abc", commandType: "not-a-command", payload: {} },
        ],
      }),
    );

    expect(createCommandOutboxLocalStore(globalThis.localStorage).load()).toEqual(
      [],
    );
  });

  it("tells reading surfaces that the queue changed", () => {
    const before = commandOutboxRevision.value;

    queueCommand({
      commandType: "mark-no-show",
      idempotencyKey: "22222222-1111-4111-8111-111111111111",
      payload: {},
    });

    expect(commandOutboxRevision.value).toBeGreaterThan(before);
  });
});
