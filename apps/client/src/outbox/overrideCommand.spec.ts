import { afterEach, describe, expect, it } from "vitest";

import { CommandOutboxError } from "@/outbox/commandOutbox";
import {
  commandOutbox,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import {
  CommandOverrideError,
  overrideCommand,
  queueCommand,
  retryCommand,
} from "@/outbox/submitCommand";

/*
 * Resolving a refusal on somebody's own authority (M18.55; CLIENT-017,
 * CLIENT-017A; technical spec 11A.5; data/API 5.6; requirements 2.4).
 *
 * No server runs here (CLIENT-024), and none is needed: what this file asserts
 * is what the *device* will and will not offer. The node re-decides every one of
 * these conditions when the override arrives — the allowlist, the capability,
 * and every eligibility rule but the one named — and `UnscheduledShiftAdditionTest`
 * is where that half is held to account. Two answers to one question is the
 * design, not a duplication: this one decides what to render, and the node's
 * decides what happens.
 */

const OVERRIDER = () => true;
const NOT_AN_OVERRIDER = () => false;
const OVERRIDE_KEY = "22222222-2222-4222-8222-222222222222";

/** A refused Logistics addition, held on the device exactly as a drain leaves one. */
function refusedAddition(
  reasonCode: string | null,
  key = "11111111-1111-4111-8111-111111111111",
): string {
  queueCommand({
    commandType: "add-staff-to-shift",
    idempotencyKey: key,
    payload: {
      shift_id: "shift-1",
      staff_id: "staff-1",
      operation_uuid: key,
      device_created_at: "2027-07-04T02:10:00.000Z",
    },
    eventId: "event-1",
    detail: "Vera Staff",
  });

  commandOutbox.markSending(key, "2027-07-04T02:10:01.000Z");
  commandOutbox.markRejected(
    key,
    "2027-07-04T02:10:02.000Z",
    "Staff must be marked on-site with this department before unscheduled shift addition.",
    reasonCode,
  );

  return key;
}

function override(key: string, holdsCapability = OVERRIDER) {
  return overrideCommand(key, {
    holdsCapability,
    newIdempotencyKey: () => OVERRIDE_KEY,
  });
}

afterEach(() => {
  resetCommandOutbox();
});

describe("overriding a refused command", () => {
  it("issues a distinct command that names the refused one", () => {
    // The property the whole design turns on (CLIENT-017A). A retry under the
    // refused command's own key would produce, on acceptance, a record that
    // reads as though nothing was ever in question; two commands is what makes
    // the record hold both facts — the node refused this, and a named person
    // then chose to proceed.
    const refused = refusedAddition("staff_not_on_site");

    const queued = override(refused);

    expect(queued.idempotencyKey).toBe(OVERRIDE_KEY);
    expect(queued.idempotencyKey).not.toBe(refused);
    expect(queued.commandType).toBe("override-shift-addition");
    expect(queued.overridesIdempotencyKey).toBe(refused);
    expect(queued.payload).toEqual({
      shift_id: "shift-1",
      staff_id: "staff-1",
      // The override's own key, not the refused command's: `operation_uuid` is
      // the addition's identity on the node and replay is decided from it.
      operation_uuid: OVERRIDE_KEY,
      device_created_at: "2027-07-04T02:10:00.000Z",
      overridden_operation_uuid: refused,
      overridden_reason_code: "staff_not_on_site",
    });
  });

  it("leaves the refusal held, marked as acted on rather than resolved", () => {
    // The override is queued, not applied. Until the node answers it, the
    // refusal is still what happened, and rewriting it now would be the device
    // claiming an outcome nobody has given it.
    const refused = refusedAddition("staff_not_on_site");

    override(refused);

    const held = commandOutbox.get(refused);

    expect(held?.status).toBe("rejected");
    expect(held?.statusReason).toContain("marked on-site");
    expect(held?.overriddenByIdempotencyKey).toBe(OVERRIDE_KEY);
    // And it stops being one of the refusals asking somebody for a decision.
    expect(commandOutbox.unresolvedRejections()).toHaveLength(0);
  });

  it("refuses an override without the capability", () => {
    const refused = refusedAddition("staff_not_on_site");

    expect(() => override(refused, NOT_AN_OVERRIDER)).toThrow(
      CommandOverrideError,
    );
    expect(commandOutbox.get(refused)?.overriddenByIdempotencyKey).toBeNull();
    expect(commandOutbox.byType("override-shift-addition")).toHaveLength(0);
  });

  it("refuses do_not_staff at any authority", () => {
    // An organization's exclusion decision about a person, made deliberately and
    // recorded at organization scope. A Logistics desk does not overturn one,
    // and neither does the authority above it: the allowlist is a property of
    // the reason, not of the caller, so holding the capability changes nothing.
    const refused = refusedAddition("do_not_staff");

    expect(() => override(refused)).toThrow(
      /not one that can be overridden/i,
    );
    expect(commandOutbox.byType("override-shift-addition")).toHaveLength(0);
  });

  it.each([
    ["missing_required_waiver"],
    ["no_department_membership"],
    ["department_ineligible"],
    ["already_assigned"],
    ["unauthorized"],
  ])("refuses %s, which is not on the allowlist", (reasonCode) => {
    const refused = refusedAddition(reasonCode);

    expect(() => override(refused)).toThrow(CommandOverrideError);
    expect(commandOutbox.byType("override-shift-addition")).toHaveLength(0);
  });

  it.each([
    ["staff_not_on_site"],
    ["not_eligible_team_member"],
    ["missing_required_training"],
  ])("offers %s, which is on the allowlist", (reasonCode) => {
    const refused = refusedAddition(reasonCode);

    expect(override(refused).payload.overridden_reason_code).toBe(reasonCode);
  });

  it("refuses a refusal the node gave no code for", () => {
    // An unnamed refusal is not one this client can say is negotiable. Reading
    // it as overridable would make the allowlist depend on the node having
    // remembered to send a field.
    const refused = refusedAddition(null);

    expect(() => override(refused)).toThrow(CommandOverrideError);
  });

  it("refuses a command the node has not refused", () => {
    queueCommand({
      commandType: "add-staff-to-shift",
      idempotencyKey: "33333333-3333-4333-8333-333333333333",
      payload: {},
      eventId: "event-1",
    });

    expect(() => override("33333333-3333-4333-8333-333333333333")).toThrow(
      /only a command the node refused/i,
    );
  });

  it("refuses a command with no resolution path at all", () => {
    // CLIENT-017's floor is the default and stays the answer for every command
    // but the one M18.55 gave a path to.
    queueCommand({
      commandType: "check-in-staff",
      idempotencyKey: "44444444-4444-4444-8444-444444444444",
      payload: {},
      eventId: "event-1",
    });
    commandOutbox.markSending(
      "44444444-4444-4444-8444-444444444444",
      "2027-07-04T02:10:01.000Z",
    );
    commandOutbox.markRejected(
      "44444444-4444-4444-8444-444444444444",
      "2027-07-04T02:10:02.000Z",
      "The shift has not started.",
      "staff_not_on_site",
    );

    expect(() => override("44444444-4444-4444-8444-444444444444")).toThrow(
      /cannot be overridden/i,
    );
  });

  it("refuses a second override of the same refusal", () => {
    const refused = refusedAddition("staff_not_on_site");

    override(refused);

    expect(() =>
      overrideCommand(refused, {
        holdsCapability: OVERRIDER,
        newIdempotencyKey: () => "55555555-5555-4555-8555-555555555555",
      }),
    ).toThrow(/already been overridden/i);
  });

  it("refuses to retry a refusal somebody overrode", () => {
    // Two commands racing to add the same person to the same shift, one of them
    // issued under an authority it never had. The loser comes back refused as
    // "already assigned", which is a confusing answer to a question nobody
    // meant to ask twice.
    const refused = refusedAddition("staff_not_on_site");

    override(refused);

    expect(() => retryCommand(refused)).toThrow(CommandOutboxError);
    expect(commandOutbox.get(refused)?.status).toBe("rejected");
  });
});
