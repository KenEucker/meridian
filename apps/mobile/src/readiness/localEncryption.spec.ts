import { describe, expect, it } from "vitest";

import {
  checkLocalEncryptionReadiness,
  evaluateLocalEncryptionReadiness,
  LOCAL_ENCRYPTION_CAPABILITY,
  resolveLocalEncryptionEnvironment,
  type LocalEncryptionEnvironment,
} from "@/readiness/localEncryption";

function capableEnvironment(): LocalEncryptionEnvironment {
  return {
    isSecureContext: true,
    hasSubtleCrypto: true,
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
        encrypt: () => undefined,
        decrypt: () => undefined,
      },
    },
    indexedDB: {},
  };
}

describe("evaluateLocalEncryptionReadiness", () => {
  it("reports available when every capability is present", () => {
    const readiness = evaluateLocalEncryptionReadiness(capableEnvironment());

    expect(readiness).toEqual({
      capability: LOCAL_ENCRYPTION_CAPABILITY,
      available: true,
      status: "available",
      missing: [],
      reason: null,
    });
  });

  it("fails closed and explains a missing secure context", () => {
    const readiness = evaluateLocalEncryptionReadiness({
      ...capableEnvironment(),
      isSecureContext: false,
    });

    expect(readiness.available).toBe(false);
    expect(readiness.status).toBe("unavailable");
    expect(readiness.missing).toEqual(["secureContext"]);
    expect(readiness.reason).toBe(
      "Local encryption is unavailable: the app is not running in a secure context.",
    );
  });

  it("fails closed when the Web Crypto encryption API is unavailable", () => {
    const readiness = evaluateLocalEncryptionReadiness({
      ...capableEnvironment(),
      hasSubtleCrypto: false,
    });

    expect(readiness.available).toBe(false);
    expect(readiness.missing).toEqual(["subtleCrypto"]);
  });

  it("fails closed when a key cannot be generated", () => {
    const readiness = evaluateLocalEncryptionReadiness({
      ...capableEnvironment(),
      hasKeyGeneration: false,
    });

    expect(readiness.available).toBe(false);
    expect(readiness.missing).toEqual(["keyGeneration"]);
  });

  it("fails closed when secure local key storage is unavailable", () => {
    const readiness = evaluateLocalEncryptionReadiness({
      ...capableEnvironment(),
      hasPersistentKeyStore: false,
    });

    expect(readiness.available).toBe(false);
    expect(readiness.missing).toEqual(["persistentKeyStore"]);
  });

  it("lists every missing capability in a stable order", () => {
    const readiness = evaluateLocalEncryptionReadiness({
      isSecureContext: false,
      hasSubtleCrypto: false,
      hasKeyGeneration: false,
      hasPersistentKeyStore: false,
    });

    expect(readiness.missing).toEqual([
      "secureContext",
      "subtleCrypto",
      "keyGeneration",
      "persistentKeyStore",
    ]);
    expect(readiness.reason).toBe(
      "Local encryption is unavailable: the app is not running in a secure context; " +
        "the Web Crypto encryption API is unavailable; " +
        "an encryption key cannot be generated on this device; " +
        "secure local key storage is unavailable.",
    );
  });
});

describe("resolveLocalEncryptionEnvironment", () => {
  it("maps a fully capable scope to present capabilities", () => {
    expect(resolveLocalEncryptionEnvironment(capableScope())).toEqual({
      isSecureContext: true,
      hasSubtleCrypto: true,
      hasKeyGeneration: true,
      hasPersistentKeyStore: true,
    });
  });

  it("treats an insecure context as unavailable", () => {
    const environment = resolveLocalEncryptionEnvironment({
      ...capableScope(),
      isSecureContext: false,
    });

    expect(environment.isSecureContext).toBe(false);
  });

  it("treats a missing crypto.subtle as no encryption or key generation", () => {
    const environment = resolveLocalEncryptionEnvironment({
      isSecureContext: true,
      indexedDB: {},
    });

    expect(environment.hasSubtleCrypto).toBe(false);
    expect(environment.hasKeyGeneration).toBe(false);
  });

  it("requires the subtle primitives to be callable", () => {
    const environment = resolveLocalEncryptionEnvironment({
      isSecureContext: true,
      crypto: {
        subtle: {
          generateKey: "not-a-function",
          encrypt: undefined,
          decrypt: undefined,
        },
      },
      indexedDB: {},
    });

    expect(environment.hasSubtleCrypto).toBe(false);
    expect(environment.hasKeyGeneration).toBe(false);
  });

  it("treats a missing indexedDB as no persistent key store", () => {
    const environment = resolveLocalEncryptionEnvironment({
      ...capableScope(),
      indexedDB: undefined,
    });

    expect(environment.hasPersistentKeyStore).toBe(false);
  });
});

describe("checkLocalEncryptionReadiness", () => {
  it("probes the provided scope and evaluates readiness in one call", () => {
    expect(checkLocalEncryptionReadiness(capableScope())).toEqual({
      capability: LOCAL_ENCRYPTION_CAPABILITY,
      available: true,
      status: "available",
      missing: [],
      reason: null,
    });
  });

  it("fails closed for an empty scope", () => {
    const readiness = checkLocalEncryptionReadiness({});

    expect(readiness.available).toBe(false);
    expect(readiness.missing).toEqual([
      "secureContext",
      "subtleCrypto",
      "keyGeneration",
      "persistentKeyStore",
    ]);
  });
});
