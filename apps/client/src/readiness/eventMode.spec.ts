import { describe, expect, it } from "vitest";

import type { DeviceSigningReadiness } from "@/readiness/deviceSigning";
import {
  evaluateEventModeReadiness,
  resolveEventModeReadiness,
} from "@/readiness/eventMode";
import type { LocalEncryptionReadiness } from "@/readiness/localEncryption";

function availableEncryption(): LocalEncryptionReadiness {
  return {
    capability: "local-encryption",
    available: true,
    status: "available",
    missing: [],
    reason: null,
  };
}

function unavailableEncryption(reason: string): LocalEncryptionReadiness {
  return {
    capability: "local-encryption",
    available: false,
    status: "unavailable",
    missing: ["secureContext"],
    reason,
  };
}

function availableSigning(): DeviceSigningReadiness {
  return {
    capability: "device-signing",
    available: true,
    status: "available",
    missing: [],
    reason: null,
  };
}

function unavailableSigning(reason: string): DeviceSigningReadiness {
  return {
    capability: "device-signing",
    available: false,
    status: "unavailable",
    missing: ["secureContext"],
    reason,
  };
}

function capableScope() {
  return {
    isSecureContext: true,
    crypto: {
      subtle: {
        generateKey: () => undefined,
        encrypt: () => undefined,
        decrypt: () => undefined,
        sign: () => undefined,
        verify: () => undefined,
      },
    },
    indexedDB: {},
  };
}

describe("evaluateEventModeReadiness", () => {
  it("is ready only when encryption and signing are both available", () => {
    const gate = evaluateEventModeReadiness({
      localEncryption: availableEncryption(),
      deviceSigning: availableSigning(),
    });

    expect(gate).toEqual({ ready: true, blocked: false, blockers: [] });
  });

  it("fails closed when local encryption is unavailable", () => {
    const gate = evaluateEventModeReadiness({
      localEncryption: unavailableEncryption("Local encryption is unavailable: no crypto."),
      deviceSigning: availableSigning(),
    });

    expect(gate.ready).toBe(false);
    expect(gate.blocked).toBe(true);
    expect(gate.blockers).toEqual([
      {
        capability: "localEncryption",
        reason: "Local encryption is unavailable: no crypto.",
      },
    ]);
  });

  it("fails closed when device signing is unavailable", () => {
    const gate = evaluateEventModeReadiness({
      localEncryption: availableEncryption(),
      deviceSigning: unavailableSigning("Device signing is unavailable: no keys."),
    });

    expect(gate.ready).toBe(false);
    expect(gate.blocked).toBe(true);
    expect(gate.blockers).toEqual([
      {
        capability: "deviceSigning",
        reason: "Device signing is unavailable: no keys.",
      },
    ]);
  });

  it("lists both blockers in a stable order when both are unavailable", () => {
    const gate = evaluateEventModeReadiness({
      localEncryption: unavailableEncryption("encryption reason"),
      deviceSigning: unavailableSigning("signing reason"),
    });

    expect(gate.blocked).toBe(true);
    expect(gate.blockers.map((blocker) => blocker.capability)).toEqual([
      "localEncryption",
      "deviceSigning",
    ]);
  });
});

describe("resolveEventModeReadiness", () => {
  it("is ready for a fully capable client scope", () => {
    expect(resolveEventModeReadiness(capableScope())).toEqual({
      ready: true,
      blocked: false,
      blockers: [],
    });
  });

  it("fails closed for an empty scope", () => {
    const gate = resolveEventModeReadiness({});

    expect(gate.ready).toBe(false);
    expect(gate.blocked).toBe(true);
    expect(gate.blockers.map((blocker) => blocker.capability)).toEqual([
      "localEncryption",
      "deviceSigning",
    ]);
    for (const blocker of gate.blockers) {
      expect(blocker.reason.length).toBeGreaterThan(0);
    }
  });
});
