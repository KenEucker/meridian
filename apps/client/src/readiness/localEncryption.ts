// Local encryption readiness check (M8.3).
//
// Technical spec section 12.3 (Local encryption) requires that local field
// data is encrypted, that encryption status is part of device readiness, and
// that offline/event mode fails closed when local encryption is unavailable.
// Technical spec section 26.2 (Production/event safeguards) restates that the
// client must fail closed if local encryption is unavailable.
//
// This module only detects whether the current client platform is *capable*
// of local encryption. It does not generate, persist, unlock, or wipe the
// local encryption key: that key lifecycle is generated after successful
// login/trust and is owned by later Alpha 1 tasks. The readiness UI surface
// (M8.5) and event-mode fail-closed enforcement (M8.7) build on the result
// produced here.

export const LOCAL_ENCRYPTION_CAPABILITY = "local-encryption" as const;

/**
 * Individual platform capabilities required before a local encryption key can
 * be generated, used, persisted, and destroyed on wipe.
 */
export type LocalEncryptionCapability =
  | "secureContext"
  | "subtleCrypto"
  | "keyGeneration"
  | "persistentKeyStore";

/**
 * Normalized probe of the platform capabilities that back local encryption.
 * Kept separate from the global scope so readiness can be evaluated
 * deterministically in tests and reused by later readiness surfaces.
 */
export interface LocalEncryptionEnvironment {
  /** Web Crypto's SubtleCrypto is only exposed in secure contexts. */
  readonly isSecureContext: boolean;
  /** `crypto.subtle` symmetric primitives are present. */
  readonly hasSubtleCrypto: boolean;
  /** A local encryption key can be generated (`generateKey`). */
  readonly hasKeyGeneration: boolean;
  /** A store exists to persist the key and destroy it on wipe. */
  readonly hasPersistentKeyStore: boolean;
}

export interface LocalEncryptionReadiness {
  readonly capability: typeof LOCAL_ENCRYPTION_CAPABILITY;
  /** True only when every required capability is present. */
  readonly available: boolean;
  readonly status: "available" | "unavailable";
  /** Required capabilities that are missing, in a stable order. */
  readonly missing: LocalEncryptionCapability[];
  /** Human-readable explanation, or `null` when available. */
  readonly reason: string | null;
}

const CAPABILITY_ORDER: readonly LocalEncryptionCapability[] = [
  "secureContext",
  "subtleCrypto",
  "keyGeneration",
  "persistentKeyStore",
];

const CAPABILITY_REASON: Readonly<Record<LocalEncryptionCapability, string>> = {
  secureContext: "the app is not running in a secure context",
  subtleCrypto: "the Web Crypto encryption API is unavailable",
  keyGeneration: "an encryption key cannot be generated on this device",
  persistentKeyStore: "secure local key storage is unavailable",
};

type CryptoLike = {
  readonly subtle?: {
    readonly generateKey?: unknown;
    readonly encrypt?: unknown;
    readonly decrypt?: unknown;
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
 * Build a {@link LocalEncryptionEnvironment} from a global-like scope. Defaults
 * to `globalThis` so callers on any client surface (PWA, Capacitor webview,
 * Electron renderer) probe the real platform, while tests inject a scope.
 */
export function resolveLocalEncryptionEnvironment(
  scope: ReadinessScope = globalThis as ReadinessScope,
): LocalEncryptionEnvironment {
  const subtle = scope.crypto?.subtle;

  return {
    isSecureContext: scope.isSecureContext === true,
    hasSubtleCrypto:
      subtle !== undefined &&
      isFunction(subtle.encrypt) &&
      isFunction(subtle.decrypt),
    hasKeyGeneration: subtle !== undefined && isFunction(subtle.generateKey),
    hasPersistentKeyStore:
      scope.indexedDB !== undefined && scope.indexedDB !== null,
  };
}

function collectMissing(
  environment: LocalEncryptionEnvironment,
): LocalEncryptionCapability[] {
  const present: Record<LocalEncryptionCapability, boolean> = {
    secureContext: environment.isSecureContext,
    subtleCrypto: environment.hasSubtleCrypto,
    keyGeneration: environment.hasKeyGeneration,
    persistentKeyStore: environment.hasPersistentKeyStore,
  };

  return CAPABILITY_ORDER.filter((capability) => !present[capability]);
}

function describeMissing(missing: LocalEncryptionCapability[]): string {
  const fragments = missing.map((capability) => CAPABILITY_REASON[capability]);
  return `Local encryption is unavailable: ${fragments.join("; ")}.`;
}

/**
 * Evaluate local encryption readiness from a resolved capability probe.
 * Fails closed: readiness is `available` only when every capability is present.
 */
export function evaluateLocalEncryptionReadiness(
  environment: LocalEncryptionEnvironment,
): LocalEncryptionReadiness {
  const missing = collectMissing(environment);
  const available = missing.length === 0;

  return {
    capability: LOCAL_ENCRYPTION_CAPABILITY,
    available,
    status: available ? "available" : "unavailable",
    missing,
    reason: available ? null : describeMissing(missing),
  };
}

/**
 * Convenience entry point that probes the current scope and evaluates local
 * encryption readiness in one call.
 */
export function checkLocalEncryptionReadiness(
  scope: ReadinessScope = globalThis as ReadinessScope,
): LocalEncryptionReadiness {
  return evaluateLocalEncryptionReadiness(
    resolveLocalEncryptionEnvironment(scope),
  );
}
