import { afterEach, describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";

import CommandOutboxNotice from "@/outbox/CommandOutboxNotice.vue";
import {
  commandOutbox,
  notifyCommandOutbox,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import { queueCommand } from "@/outbox/submitCommand";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import { localFieldSessionDocument } from "@/session/localFieldSessionFixture";

/*
 * What the client tells the user about the commands it is holding (M16.10;
 * CLIENT-017, CLIENT-017A; UI implementation contract 16.3; UI operating guide
 * 17.7).
 */

function queue(key: string, detail: string | null = null): void {
  queueCommand({
    commandType: "check-in-staff",
    idempotencyKey: key,
    payload: { operation_uuid: key },
    detail,
  });
}

/** A refused Logistics addition, which is the one command with an override path. */
function queueAddition(key: string, detail: string | null = null): void {
  queueCommand({
    commandType: "add-staff-to-shift",
    idempotencyKey: key,
    payload: { shift_id: "shift-1", staff_id: "staff-1", operation_uuid: key },
    detail,
  });
}

function reject(key: string, reason: string, reasonCode?: string): void {
  commandOutbox.markSending(key, "2027-07-04T16:00:00.000Z");
  commandOutbox.markRejected(
    key,
    "2027-07-04T16:00:05.000Z",
    reason,
    reasonCode ?? null,
  );
  notifyCommandOutbox();
}

/** Sign in holding exactly the capabilities named, and nothing else. */
function holdCapabilities(...capabilities: readonly string[]): void {
  installClientSession(
    localFieldSessionDocument({ capabilities: [...capabilities] }),
    "network",
  );
}

function accept(key: string): void {
  commandOutbox.markSending(key, "2027-07-04T16:00:00.000Z");
  commandOutbox.markAccepted(key, "2027-07-04T16:00:05.000Z");
  notifyCommandOutbox();
}

afterEach(() => {
  resetCommandOutbox();
  clearClientSession();
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

  it("offers a refused command back to the queue, on the person's say-so", async () => {
    queue("11111111-1111-4111-8111-111111111111", "Vera Staff");
    reject("11111111-1111-4111-8111-111111111111", "The shift has already ended.");

    const wrapper = mount(CommandOutboxNotice);
    const buttons = wrapper.findAll(".command-outbox__action");
    const tryAgain = buttons.find((button) => button.text() === "Try again");

    expect(tryAgain).toBeDefined();
    await tryAgain!.trigger("click");

    expect(
      commandOutbox.get("11111111-1111-4111-8111-111111111111")?.status,
    ).toBe("queued");
    // Back to the ordinary held-work state rather than gone.
    expect(wrapper.find(".command-outbox").attributes("data-outbox-state")).toBe(
      "queued",
    );
  });

  it("drops a refused command only when a person says so", async () => {
    queue("11111111-1111-4111-8111-111111111111");
    reject("11111111-1111-4111-8111-111111111111", "The shift has already ended.");

    const wrapper = mount(CommandOutboxNotice);
    expect(commandOutbox.size).toBe(1);

    await wrapper.findAll(".command-outbox__action").find((button) => button.text() === "Dismiss")!.trigger("click");

    expect(commandOutbox.size).toBe(0);
    expect(wrapper.find(".command-outbox").exists()).toBe(false);
  });
});

/*
 * The third answer a refusal can have (M18.55; CLIENT-017A).
 *
 * Offered rarely and by design: the command needs an override path, the node's
 * reason needs to be on that command's allowlist, and the session needs the
 * capability. Where any of the three is missing, nothing is rendered — an absent
 * control says the decision is not this person's, where a disabled one would
 * invite them to wonder what they are missing.
 */
describe("overriding a refusal from the notice", () => {
  const ADDITION = "11111111-1111-4111-8111-111111111111";
  const OVERRIDE_CAPABILITY = "department.shift_additions.override";

  function overrideButton(wrapper: ReturnType<typeof mount>) {
    const button = wrapper.find("[data-outbox-override]");

    return button.exists() ? button : null;
  }

  it("offers the override where the reason and the capability allow it", async () => {
    holdCapabilities(OVERRIDE_CAPABILITY);
    queueAddition(ADDITION, "Vera Staff");
    reject(
      ADDITION,
      "Staff must be marked on-site with this department before unscheduled shift addition.",
      "staff_not_on_site",
    );

    const wrapper = mount(CommandOutboxNotice);
    const button = overrideButton(wrapper);

    expect(button?.text()).toBe("Add anyway");

    await button!.trigger("click");

    // A distinct command, not a retry of the refused one. The refusal is still
    // held and still says what the node said; what changed is that somebody has
    // acted on it.
    const override = commandOutbox.byType("override-shift-addition");

    expect(override).toHaveLength(1);
    expect(override[0]?.overridesIdempotencyKey).toBe(ADDITION);
    expect(override[0]?.idempotencyKey).not.toBe(ADDITION);
    expect(commandOutbox.get(ADDITION)?.status).toBe("rejected");
    expect(wrapper.find("[data-outbox-overridden]").exists()).toBe(true);
    expect(wrapper.text()).toContain("overridden on your authority");
    // And it is no longer among the refusals asking somebody for a decision.
    expect(overrideButton(wrapper)).toBeNull();
  });

  it("offers nothing without the capability", () => {
    // The Logistics role that issues additions does not hold the override. A
    // refusal the same desk that hit it may wave away is a confirmation dialog
    // rather than a rule.
    holdCapabilities("department.attendance.manage");
    queueAddition(ADDITION, "Vera Staff");
    reject(
      ADDITION,
      "Staff must be marked on-site with this department before unscheduled shift addition.",
      "staff_not_on_site",
    );

    const wrapper = mount(CommandOutboxNotice);

    expect(overrideButton(wrapper)).toBeNull();
    // The other two answers are still there. Losing the override does not lose
    // CLIENT-017's floor.
    expect(wrapper.text()).toContain("Try again");
    expect(wrapper.text()).toContain("Dismiss");
  });

  it("offers nothing for do_not_staff, whatever the reader holds", () => {
    holdCapabilities(OVERRIDE_CAPABILITY);
    queueAddition(ADDITION, "Vera Staff");
    reject(
      ADDITION,
      "Do Not Staff records cannot be added to shifts.",
      "do_not_staff",
    );

    const wrapper = mount(CommandOutboxNotice);

    expect(overrideButton(wrapper)).toBeNull();
    expect(wrapper.text()).toContain("Do Not Staff records cannot be added");
  });

  it("offers nothing for a command with no override path", () => {
    holdCapabilities(OVERRIDE_CAPABILITY);
    queue(ADDITION, "Vera Staff");
    reject(ADDITION, "The shift has already ended.", "staff_not_on_site");

    const wrapper = mount(CommandOutboxNotice);

    expect(overrideButton(wrapper)).toBeNull();
  });

  it("offers nothing for a refusal the node gave no code for", () => {
    holdCapabilities(OVERRIDE_CAPABILITY);
    queueAddition(ADDITION, "Vera Staff");
    reject(ADDITION, "No.");

    const wrapper = mount(CommandOutboxNotice);

    expect(overrideButton(wrapper)).toBeNull();
  });
});
