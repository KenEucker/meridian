import { afterEach, describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";

import CommandOutboxNotice from "@/outbox/CommandOutboxNotice.vue";
import {
  commandOutbox,
  notifyCommandOutbox,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import { queueCommand } from "@/outbox/submitCommand";

/*
 * What the client tells the user about the commands it is holding (M16.10;
 * CLIENT-017; UI implementation contract 16.3; UI operating guide 17.7).
 */

function queue(key: string, detail: string | null = null): void {
  queueCommand({
    commandType: "check-in-staff",
    idempotencyKey: key,
    payload: { operation_uuid: key },
    detail,
  });
}

function reject(key: string, reason: string): void {
  commandOutbox.markSending(key, "2027-07-04T16:00:00.000Z");
  commandOutbox.markRejected(key, "2027-07-04T16:00:05.000Z", reason);
  notifyCommandOutbox();
}

function accept(key: string): void {
  commandOutbox.markSending(key, "2027-07-04T16:00:00.000Z");
  commandOutbox.markAccepted(key, "2027-07-04T16:00:05.000Z");
  notifyCommandOutbox();
}

afterEach(() => {
  resetCommandOutbox();
});

describe("the command outbox notice", () => {
  it("says nothing when the device owes the node nothing", () => {
    const wrapper = mount(CommandOutboxNotice);

    expect(wrapper.find(".command-outbox").exists()).toBe(false);
  });

  it("stays silent when everything has been accepted", () => {
    // Routine field work is not interrupted with sync noise (contract 16.2); the
    // full queue including accepted commands is on device diagnostics.
    queue("11111111-1111-4111-8111-111111111111");
    accept("11111111-1111-4111-8111-111111111111");

    const wrapper = mount(CommandOutboxNotice);

    expect(wrapper.find(".command-outbox").exists()).toBe(false);
  });

  it("reports held work as information rather than as a failure", () => {
    queue("11111111-1111-4111-8111-111111111111");

    const wrapper = mount(CommandOutboxNotice);

    expect(wrapper.find(".command-outbox").attributes("data-outbox-state")).toBe(
      "queued",
    );
    expect(wrapper.text()).toContain("Queued");
    expect(wrapper.text()).toContain(
      "1 command is held on this device, waiting to reach the node.",
    );
  });

  it("names a refused command and the node's reason for refusing it", () => {
    queue("11111111-1111-4111-8111-111111111111", "Vera Staff");
    reject("11111111-1111-4111-8111-111111111111", "The shift has already ended.");

    const wrapper = mount(CommandOutboxNotice);

    expect(wrapper.find(".command-outbox").attributes("data-outbox-state")).toBe(
      "rejected",
    );
    expect(wrapper.text()).toContain("Check-in — Vera Staff");
    expect(wrapper.text()).toContain("The shift has already ended.");
  });

  it("reports accepted commands alongside the work still outstanding", () => {
    queue("11111111-1111-4111-8111-111111111111");
    accept("11111111-1111-4111-8111-111111111111");
    queue("22222222-1111-4111-8111-111111111111");

    const wrapper = mount(CommandOutboxNotice);

    expect(wrapper.text()).toContain(
      "1 command has been accepted by the node.",
    );
  });

  it("drops a refused command only when a person says so", async () => {
    queue("11111111-1111-4111-8111-111111111111");
    reject("11111111-1111-4111-8111-111111111111", "The shift has already ended.");

    const wrapper = mount(CommandOutboxNotice);
    expect(commandOutbox.size).toBe(1);

    await wrapper.find(".command-outbox__dismiss").trigger("click");

    expect(commandOutbox.size).toBe(0);
    expect(wrapper.find(".command-outbox").exists()).toBe(false);
  });
});
