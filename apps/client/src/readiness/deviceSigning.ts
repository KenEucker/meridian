// Device signing readiness check (M8.4).
//
// Technical spec section 12.4 (Device signatures) requires device-originated
// offline operations to be signed and browser/PWA signatures to be available
// only when secure key storage exists. Technical spec section 26.2 requires
// the client to fail closed when device signing is unavailable.
//
// This module only detects whether the current client platform is *capable*
// of device signing. It does not generate or register a trusted-device
// keypair, sign an operation, verify a signature, or enforce event mode.
// Those lifecycles belong to later Alpha 1 tasks.

export const DEVICE_SIGNING_CAPABILITY = "device-signing" as const;

/**
 * Individual platform capabilities required before a device signing key can
 * be generated, used, and persisted.
 */
export type DeviceSigningCapability =
  | "secureContext"
  | "signatureApi"
  | "keyGeneration"
  | "persistentKeyStore";

/**
 * Normalized probe of the platform capabilities that back device signing.
 * Kept separate from the global scope so readiness can be evaluated
 * deterministically in tests and reused by later readiness surfaces.
 */
export interface DeviceSigningEnvironment {
  /** Web Crypto's SubtleCrypto is only exposed in secure contexts. */
  readonly isSecureContext: boolean;
  /** `crypto.subtle` signature creation and verification are present. */
  readonly hasSignatureApi: boolean;
  /** A signing keypair can be generated (`generateKey`). */
  readonly hasKeyGeneration: boolean;
  /** A store exists to persist a non-extractable private key. */
  readonly hasPersistentKeyStore: boolean;
}

export interface DeviceSigningReadiness {
  readonly capability: typeof DEVICE_SIGNING_CAPABILITY;
  /** True only when every required capability is present. */
  readonly available: boolean;
  readonly status: "available" | "unavailable";
  /** Required capabilities that are missing, in a stable order. */
  readonly missing: DeviceSigningCapability[];
  /** Human-readable explanation, or `null` when available. */
  readonly reason: string | null;
}

const CAPABILITY_ORDER: readonly DeviceSigningCapability[] = [
  "secureContext",
  "signatureApi",
  "keyGeneration",
  "persistentKeyStore",
];

const CAPABILITY_REASON: Readonly<Record<DeviceSigningCapability, string>> = {
  secureContext: "the app is not running in a secure context",
  signatureApi: "the Web Crypto signature API is unavailable",
  keyGeneration: "a signing keypair cannot be generated on this device",
  persistentKeyStore: "secure local signing-key storage is unavailable",
};

type CryptoLike = {
  readonly subtle?: {
    readonly generateKey?: unknown;
    readonly sign?: unknown;
    readonly verify?: unknown;
  };
};

type ReadinessScope = {
  readonly isSecureContext?: boolean;
  readonly crypto?: CryptoLike;
  readonly indexedDB?: unknown;
};

function isFunction(value: unknown): boolean {
  return typeof value === "function";
}

/**
 * Build a {@link DeviceSigningEnvironment} from a global-like scope. Defaults
 * to `globalThis` so callers on any client surface (PWA, Capacitor webview,
 * Electron renderer) probe the real platform, while tests inject a scope.
 */
export function resolveDeviceSigningEnvironment(
  scope: ReadinessScope = globalThis as ReadinessScope,
): DeviceSigningEnvironment {
  const subtle = scope.crypto?.subtle;

  return {
    isSecureContext: scope.isSecureContext === true,
    hasSignatureApi:
      subtle !== undefined &&
      isFunction(subtle.sign) &&
      isFunction(subtle.verify),
    hasKeyGeneration: subtle !== undefined && isFunction(subtle.generateKey),
    hasPersistentKeyStore:
      scope.indexedDB !== undefined && scope.indexedDB !== null,
  };
}

function collectMissing(
  environment: DeviceSigningEnvironment,
): DeviceSigningCapability[] {
  const present: Record<DeviceSigningCapability, boolean> = {
    secureContext: environment.isSecureContext,
    signatureApi: environment.hasSignatureApi,
    keyGeneration: environment.hasKeyGeneration,
    persistentKeyStore: environment.hasPersistentKeyStore,
  };

  return CAPABILITY_ORDER.filter((capability) => !present[capability]);
}

function describeMissing(missing: DeviceSigningCapability[]): string {
  const fragments = missing.map((capability) => CAPABILITY_REASON[capability]);
  return `Device signing is unavailable: ${fragments.join("; ")}.`;
}

/**
 * Evaluate device signing readiness from a resolved capability probe.
 * Fails closed: readiness is `available` only when every capability is present.
 */
export function evaluateDeviceSigningReadiness(
  environment: DeviceSigningEnvironment,
): DeviceSigningReadiness {
  const missing = collectMissing(environment);
  const available = missing.length === 0;

  return {
    capability: DEVICE_SIGNING_CAPABILITY,
    available,
    status: available ? "available" : "unavailable",
    missing,
    reason: available ? null : describeMissing(missing),
  };
}

/**
 * Convenience entry point that probes the current scope and evaluates device
 * signing readiness in one call.
 */
export function checkDeviceSigningReadiness(
  scope: ReadinessScope = globalThis as ReadinessScope,
): DeviceSigningReadiness {
  return evaluateDeviceSigningReadiness(
    resolveDeviceSigningEnvironment(scope),
  );
}
