import { describe, expect, it } from "vitest";

import {
  checkDeviceSigningReadiness,
  DEVICE_SIGNING_CAPABILITY,
  evaluateDeviceSigningReadiness,
  resolveDeviceSigningEnvironment,
  type DeviceSigningEnvironment,
} from "@/readiness/deviceSigning";

function capableEnvironment(): DeviceSigningEnvironment {
  return {
    isSecureContext: true,
    hasSignatureApi: true,
    hasKeyGeneration: true,
    hasPersistentKeyStore: true,
  };
}

function capableScope() {
  return {
    isSecureContext: true,
    crypto: {
      subtle: {
        generateKey: () => undefined,
        sign: () => undefined,
        verify: () => undefined,
      },
    },
    indexedDB: {},
  };
}

describe("evaluateDeviceSigningReadiness", () => {
  it("reports available when every capability is present", () => {
    const readiness = evaluateDeviceSigningReadiness(capableEnvironment());

    expect(readiness).toEqual({
      capability: DEVICE_SIGNING_CAPABILITY,
      available: true,
      status: "available",
      missing: [],
      reason: null,
    });
  });

  it("fails closed and explains a missing secure context", () => {
    const readiness = evaluateDeviceSigningReadiness({
      ...capableEnvironment(),
      isSecureContext: false,
    });

    expect(readiness.available).toBe(false);
    expect(readiness.status).toBe("unavailable");
    expect(readiness.missing).toEqual(["secureContext"]);
    expect(readiness.reason).toBe(
      "Device signing is unavailable: the app is not running in a secure context.",
    );
  });

  it("fails closed when the Web Crypto signature API is unavailable", () => {
    const readiness = evaluateDeviceSigningReadiness({
      ...capableEnvironment(),
      hasSignatureApi: false,
    });

    expect(readiness.available).toBe(false);
    expect(readiness.missing).toEqual(["signatureApi"]);
  });

  it("fails closed when a signing keypair cannot be generated", () => {
    const readiness = evaluateDeviceSigningReadiness({
      ...capableEnvironment(),
      hasKeyGeneration: false,
    });

    expect(readiness.available).toBe(false);
    expect(readiness.missing).toEqual(["keyGeneration"]);
  });

  it("fails closed when secure local key storage is unavailable", () => {
    const readiness = evaluateDeviceSigningReadiness({
      ...capableEnvironment(),
      hasPersistentKeyStore: false,
    });

    expect(readiness.available).toBe(false);
    expect(readiness.missing).toEqual(["persistentKeyStore"]);
  });

  it("lists every missing capability in a stable order", () => {
    const readiness = evaluateDeviceSigningReadiness({
      isSecureContext: false,
      hasSignatureApi: false,
      hasKeyGeneration: false,
      hasPersistentKeyStore: false,
    });

    expect(readiness.missing).toEqual([
      "secureContext",
      "signatureApi",
      "keyGeneration",
      "persistentKeyStore",
    ]);
    expect(readiness.reason).toBe(
      "Device signing is unavailable: the app is not running in a secure context; " +
        "the Web Crypto signature API is unavailable; " +
        "a signing keypair cannot be generated on this device; " +
        "secure local signing-key storage is unavailable.",
    );
  });
});

describe("resolveDeviceSigningEnvironment", () => {
  it("maps a fully capable scope to present capabilities", () => {
    expect(resolveDeviceSigningEnvironment(capableScope())).toEqual({
      isSecureContext: true,
      hasSignatureApi: true,
      hasKeyGeneration: true,
      hasPersistentKeyStore: true,
    });
  });

  it("treats an insecure context as unavailable", () => {
    const environment = resolveDeviceSigningEnvironment({
      ...capableScope(),
      isSecureContext: false,
    });

    expect(environment.isSecureContext).toBe(false);
  });

  it("treats a missing crypto.subtle as no signature API or key generation", () => {
    const environment = resolveDeviceSigningEnvironment({
      isSecureContext: true,
      indexedDB: {},
    });

    expect(environment.hasSignatureApi).toBe(false);
    expect(environment.hasKeyGeneration).toBe(false);
  });

  it("requires the subtle primitives to be callable", () => {
    const environment = resolveDeviceSigningEnvironment({
      isSecureContext: true,
      crypto: {
        subtle: {
          generateKey: "not-a-function",
          sign: undefined,
          verify: undefined,
        },
      },
      indexedDB: {},
    });

    expect(environment.hasSignatureApi).toBe(false);
    expect(environment.hasKeyGeneration).toBe(false);
  });

  it("requires both signature creation and verification", () => {
    const environment = resolveDeviceSigningEnvironment({
      ...capableScope(),
      crypto: {
        subtle: {
          generateKey: () => undefined,
          sign: () => undefined,
        },
      },
    });

    expect(environment.hasSignatureApi).toBe(false);
  });

  it("treats a missing indexedDB as no persistent key store", () => {
    const environment = resolveDeviceSigningEnvironment({
      ...capableScope(),
      indexedDB: undefined,
    });

    expect(environment.hasPersistentKeyStore).toBe(false);
  });
});

describe("checkDeviceSigningReadiness", () => {
  it("probes the provided scope and evaluates readiness in one call", () => {
    expect(checkDeviceSigningReadiness(capableScope())).toEqual({
      capability: DEVICE_SIGNING_CAPABILITY,
      available: true,
      status: "available",
      missing: [],
      reason: null,
    });
  });

  it("fails closed for an empty scope", () => {
    const readiness = checkDeviceSigningReadiness({});

    expect(readiness.available).toBe(false);
    expect(readiness.missing).toEqual([
      "secureContext",
      "signatureApi",
      "keyGeneration",
      "persistentKeyStore",
    ]);
  });
});
