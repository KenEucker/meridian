// Client event-mode fail-closed gate (M8.7).
//
// Technical spec sections 8.6 and 26.2 require the client to fail closed in
// event mode when local encryption or device signing is unavailable. This
// module combines the existing capability probes — local encryption (M8.3) and
// device signing (M8.4) — into a single blocking decision. It fails closed:
// event mode is `ready` only when both capabilities are available, and every
// missing capability contributes a human-readable blocker.
//
// This module makes the decision; it does not itself start, stop, or gate any
// offline write. The encryption/signing key lifecycles and the surfaces that
// consume this gate are owned by later Alpha 1 tasks. The server owns the
// matching HTTPS and offline read set fail-closed checks (technical spec 8.6;
// ADR-0003 replaced the PowerSync probe with the read-set one in M18.51).

import type { ReadinessProbeScope } from "@/readiness/checklist";
import {
  checkDeviceSigningReadiness,
  type DeviceSigningReadiness,
} from "@/readiness/deviceSigning";
import {
  checkLocalEncryptionReadiness,
  type LocalEncryptionReadiness,
} from "@/readiness/localEncryption";

/** Client-owned capabilities that must be available before event mode. */
export type EventModeCapability = "localEncryption" | "deviceSigning";

export interface EventModeBlocker {
  readonly capability: EventModeCapability;
  /** Human-readable explanation taken from the capability probe. */
  readonly reason: string;
}

export interface EventModeGate {
  /** True only when every client-owned capability is available. */
  readonly ready: boolean;
  /** True when at least one required capability is unavailable. */
  readonly blocked: boolean;
  /** Missing capabilities with reasons, in a stable order. */
  readonly blockers: EventModeBlocker[];
}

/** Capability probe results the gate depends on today. */
export interface EventModeInputs {
  readonly localEncryption: LocalEncryptionReadiness;
  readonly deviceSigning: DeviceSigningReadiness;
}

const EVENT_MODE_CAPABILITY_ORDER: readonly EventModeCapability[] = [
  "localEncryption",
  "deviceSigning",
];

/**
 * Fallback reason used only if a probe reports unavailable without a reason.
 * The capability probes always supply a reason when unavailable, so this keeps
 * the gate honest rather than silently blocking with no explanation.
 */
const FALLBACK_REASON: Readonly<Record<EventModeCapability, string>> = {
  localEncryption: "Local encryption is unavailable.",
  deviceSigning: "Device signing is unavailable.",
};

/**
 * Evaluate the client event-mode gate from resolved capability probes.
 * Fails closed: event mode is `ready` only when both capabilities are
 * available.
 */
export function evaluateEventModeReadiness(
  inputs: EventModeInputs,
): EventModeGate {
  const readiness: Readonly<
    Record<EventModeCapability, LocalEncryptionReadiness | DeviceSigningReadiness>
  > = {
    localEncryption: inputs.localEncryption,
    deviceSigning: inputs.deviceSigning,
  };

  const blockers: EventModeBlocker[] = [];

  for (const capability of EVENT_MODE_CAPABILITY_ORDER) {
    const probe = readiness[capability];

    if (!probe.available) {
      blockers.push({
        capability,
        reason: probe.reason ?? FALLBACK_REASON[capability],
      });
    }
  }

  const blocked = blockers.length > 0;

  return { ready: !blocked, blocked, blockers };
}

/**
 * Probe the current client scope and evaluate the event-mode gate in one call.
 * Defaults to the real platform (`globalThis`) while tests inject a scope.
 */
export function resolveEventModeReadiness(
  scope?: ReadinessProbeScope,
): EventModeGate {
  return evaluateEventModeReadiness({
    localEncryption: checkLocalEncryptionReadiness(scope),
    deviceSigning: checkDeviceSigningReadiness(scope),
  });
}
