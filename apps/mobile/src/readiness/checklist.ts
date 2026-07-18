// Readiness checklist model (M8.5).
//
// Technical spec section 14 (Readiness) defines a per user/device/event
// checklist that is advisory only, visible to the user, and never visible to
// organizers. It does not expire automatically and the app must avoid nagging:
// users are encouraged, but not required, to prepare their devices. The
// checklist items, in the order the spec lists them, are:
//
//   logged in
//   device trusted
//   event selected
//   local cache complete
//   encryption active
//   last sync completed
//   trusted server known
//   device signing available
//
// This module turns the readiness signals that exist today into that checklist.
// Only `encryption active` (M8.3) and `device signing available` (M8.4) have
// real capability probes; the remaining items depend on authentication, device
// trust, event selection, local cache, and sync work owned by later Alpha 1
// milestones. Rather than invent behavior, those items are reported honestly as
// `pending` until their signal is wired in by the owning task.

import {
  checkDeviceSigningReadiness,
  type DeviceSigningReadiness,
} from "@/readiness/deviceSigning";
import {
  checkLocalEncryptionReadiness,
  type LocalEncryptionReadiness,
} from "@/readiness/localEncryption";

/**
 * Stable identifiers for the technical spec section 14 readiness items, in the
 * order the spec lists them.
 */
export type ReadinessItemKey =
  | "loggedIn"
  | "deviceTrusted"
  | "eventSelected"
  | "localCacheComplete"
  | "encryptionActive"
  | "lastSyncCompleted"
  | "trustedServerKnown"
  | "deviceSigningAvailable";

/**
 * Advisory state for a single readiness item.
 *
 * - `ready`: the signal confirms the item is satisfied.
 * - `not-ready`: the signal confirms the item is not satisfied.
 * - `pending`: no signal is available yet in this build (the backing feature is
 *   owned by a later Alpha 1 milestone). Pending is not a failure; readiness is
 *   advisory and the app must not nag.
 */
export type ReadinessItemStatus = "ready" | "not-ready" | "pending";

export interface ReadinessChecklistItem {
  readonly key: ReadinessItemKey;
  /** Human-readable label matching the technical spec section 14 wording. */
  readonly label: string;
  readonly status: ReadinessItemStatus;
  /** Short explanation for the current status, or `null` when none applies. */
  readonly detail: string | null;
}

export interface ReadinessSummary {
  readonly ready: number;
  readonly notReady: number;
  readonly pending: number;
  readonly total: number;
}

/**
 * Readiness signals available today. Only local encryption (M8.3) and device
 * signing (M8.4) are wired; the remaining items resolve to `pending`.
 */
export interface ReadinessChecklistInputs {
  readonly localEncryption: LocalEncryptionReadiness;
  readonly deviceSigning: DeviceSigningReadiness;
}

const READINESS_ITEM_ORDER: readonly ReadinessItemKey[] = [
  "loggedIn",
  "deviceTrusted",
  "eventSelected",
  "localCacheComplete",
  "encryptionActive",
  "lastSyncCompleted",
  "trustedServerKnown",
  "deviceSigningAvailable",
];

const READINESS_ITEM_LABEL: Readonly<Record<ReadinessItemKey, string>> = {
  loggedIn: "Logged in",
  deviceTrusted: "Device trusted",
  eventSelected: "Event selected",
  localCacheComplete: "Local cache complete",
  encryptionActive: "Encryption active",
  lastSyncCompleted: "Last sync completed",
  trustedServerKnown: "Trusted server known",
  deviceSigningAvailable: "Device signing available",
};

/**
 * Detail shown for items whose backing signal is not implemented yet. Kept
 * neutral and honest so the checklist never implies a failure or a false pass.
 */
const PENDING_DETAIL = "Not available yet in this build.";

/** Items that resolve to `pending` until their owning milestone wires them up. */
const PENDING_ITEMS: readonly ReadinessItemKey[] = [
  "loggedIn",
  "deviceTrusted",
  "eventSelected",
  "localCacheComplete",
  "lastSyncCompleted",
  "trustedServerKnown",
];

function toItem(
  key: ReadinessItemKey,
  status: ReadinessItemStatus,
  detail: string | null,
): ReadinessChecklistItem {
  return { key, label: READINESS_ITEM_LABEL[key], status, detail };
}

function capabilityItem(
  key: ReadinessItemKey,
  readiness: LocalEncryptionReadiness | DeviceSigningReadiness,
): ReadinessChecklistItem {
  return toItem(
    key,
    readiness.available ? "ready" : "not-ready",
    readiness.reason,
  );
}

/**
 * Build the technical spec section 14 readiness checklist from the signals that
 * exist today. Items without an implemented signal are reported as `pending`.
 */
export function buildReadinessChecklist(
  inputs: ReadinessChecklistInputs,
): ReadinessChecklistItem[] {
  return READINESS_ITEM_ORDER.map((key) => {
    if (key === "encryptionActive") {
      return capabilityItem(key, inputs.localEncryption);
    }
    if (key === "deviceSigningAvailable") {
      return capabilityItem(key, inputs.deviceSigning);
    }
    if (PENDING_ITEMS.includes(key)) {
      return toItem(key, "pending", PENDING_DETAIL);
    }
    // Unreachable: every key is either a capability item or pending.
    return toItem(key, "pending", PENDING_DETAIL);
  });
}

/** Count readiness items by status for an advisory, non-nagging summary. */
export function summarizeReadiness(
  items: readonly ReadinessChecklistItem[],
): ReadinessSummary {
  const summary = { ready: 0, notReady: 0, pending: 0, total: items.length };

  for (const item of items) {
    if (item.status === "ready") {
      summary.ready += 1;
    } else if (item.status === "not-ready") {
      summary.notReady += 1;
    } else {
      summary.pending += 1;
    }
  }

  return summary;
}

/**
 * Minimal global-like scope shared by the local-encryption and device-signing
 * probes, so a single injected scope can drive both. Every member is optional
 * and the probes default to `globalThis` when no scope is supplied.
 */
export interface ReadinessProbeScope {
  readonly isSecureContext?: boolean;
  readonly crypto?: {
    readonly subtle?: {
      readonly generateKey?: unknown;
      readonly encrypt?: unknown;
      readonly decrypt?: unknown;
      readonly sign?: unknown;
      readonly verify?: unknown;
    };
  };
  readonly indexedDB?: unknown;
}

/**
 * Probe the current client scope and build the readiness checklist in one call.
 * Defaults to the real platform (`globalThis`) while tests inject a scope.
 */
export function resolveReadinessChecklist(
  scope?: ReadinessProbeScope,
): ReadinessChecklistItem[] {
  return buildReadinessChecklist({
    localEncryption: checkLocalEncryptionReadiness(scope),
    deviceSigning: checkDeviceSigningReadiness(scope),
  });
}
