// The device a bearer token is bound to (M16.11; AUTH-021; technical spec 11.4,
// 12.4; data/API 12.1).
//
// Token issuance requires a resolvable device, and a request that cannot supply
// one is refused rather than issued an unbound token. What the node needs is a
// stable identifier the client generates once and keeps, plus — the first time
// that identifier is seen — a label, a platform, and a device public key.
//
// So this module owns two durable things and nothing else:
//
//  1. **The identifier.** A UUID in local storage. It is not a credential: it
//     names hardware, it authenticates nothing, and the node still requires a
//     login code before it will issue anything against it. Losing it costs a
//     device record, not a session.
//  2. **The signing key.** Generated once, on this device, with the private half
//     non-extractable and kept in IndexedDB — the same "secure local signing-key
//     storage" the readiness check probes for (technical spec 12.4). Only the
//     public half ever leaves, as PEM, at registration.
//
// A device that cannot generate or keep a key does not sign in. That is the
// fail-closed answer rather than an inconvenience: the alternative is
// registering key material nothing can verify, which signs in today and fails at
// the point where a signature actually matters.
//
// Nothing here re-registers. A device that has signed in before sends only its
// identifier on the next sign-in, and the node resolves the row it already has.

import { meridianAppConfig } from "@/app/appConfig";

/** The `device` object a token issuance request carries. */
export interface DeviceRegistration {
  readonly id: string;
  readonly label: string;
  readonly platform: string;
  readonly public_key: string;
}

/** Why this device cannot present an identity a token could be bound to. */
export class DeviceIdentityError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "DeviceIdentityError";
  }
}

const DEVICE_ID_KEY = "meridian.device.id";
const PUBLIC_KEY_KEY = "meridian.device.public-key";

const DATABASE_NAME = "meridian.device";
const KEY_STORE_NAME = "signing-keys";
const KEY_RECORD_ID = "device";

const SIGNING_ALGORITHM: RsaHashedKeyGenParams = {
  name: "RSASSA-PKCS1-v1_5",
  modulusLength: 2048,
  publicExponent: new Uint8Array([1, 0, 1]),
  hash: "SHA-256",
};

/**
 * Where the private half lives.
 *
 * An interface because a spec has no IndexedDB and no interest in one: what a
 * test needs to establish is that the key is generated once and reused, and an
 * in-memory store says that as well as a database does.
 */
export interface DeviceKeyStore {
  readonly read: () => Promise<CryptoKeyPair | null>;
  readonly write: (pair: CryptoKeyPair) => Promise<void>;
}

export interface DeviceIdentityEnvironment {
  readonly storage: Storage | null;
  readonly crypto: Crypto | null;
  readonly keys: DeviceKeyStore | null;
}

let environmentOverride: DeviceIdentityEnvironment | null = null;

/** Test seam: run against a supplied storage, crypto, and key store. */
export function configureDeviceIdentity(
  environment: DeviceIdentityEnvironment | null,
): void {
  environmentOverride = environment;
}

function readStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

function environment(): DeviceIdentityEnvironment {
  if (environmentOverride !== null) {
    return environmentOverride;
  }

  const indexed = globalThis.indexedDB ?? null;

  return {
    storage: readStorage(),
    crypto: globalThis.crypto ?? null,
    keys: indexed === null ? null : indexedDbKeyStore(indexed),
  };
}

/**
 * This device's stable identifier, generated on first use.
 *
 * Read synchronously so a caller can say who this device is without waiting on
 * a key store. A device with no writable storage still gets an identifier — for
 * the life of the page, which is enough to sign in with and honest about what it
 * is: the node registers a new device rather than resolving the old one.
 */
let memoryDeviceId: string | null = null;

export function deviceId(): string {
  const storage = environment().storage;
  const stored = readItem(storage, DEVICE_ID_KEY);

  if (stored !== null) {
    return stored;
  }

  const generated = memoryDeviceId ?? newUuid();

  memoryDeviceId = generated;
  writeItem(storage, DEVICE_ID_KEY, generated);

  return generated;
}

/**
 * What this device calls itself when God Mode lists it.
 *
 * Product name and platform, and nothing that identifies a person. A device list
 * is read to answer "which of these do I revoke", so the label has to be
 * recognizable to whoever is holding the hardware.
 */
export function deviceLabel(): string {
  return `${meridianAppConfig.productName} (${devicePlatform()})`;
}

export function devicePlatform(): string {
  switch (meridianAppConfig.deploymentTarget) {
    case "mobile":
      return "mobile";
    case "desktop":
      return "desktop";
    default:
      return "web";
  }
}

/**
 * The device identity a token issuance request carries.
 *
 * Always sends the full descriptor, not only the identifier. A node that already
 * knows this device ignores everything but the id; a node that has never seen it
 * — a device signing in to a second node, or one whose record was removed — can
 * register it without a second round trip.
 *
 * @throws DeviceIdentityError when no key can be generated or kept
 */
export async function resolveDeviceRegistration(): Promise<DeviceRegistration> {
  return {
    id: deviceId(),
    label: deviceLabel(),
    platform: devicePlatform(),
    public_key: await devicePublicKey(),
  };
}

/**
 * The public half of this device's signing key, as PEM.
 *
 * Cached alongside the identifier so a returning device does not touch the key
 * store to answer a question whose answer cannot change.
 */
export async function devicePublicKey(): Promise<string> {
  const { storage, crypto, keys } = environment();
  const cached = readItem(storage, PUBLIC_KEY_KEY);

  if (cached !== null) {
    return cached;
  }

  if (crypto?.subtle === undefined || keys === null) {
    throw new DeviceIdentityError(
      "This device cannot create the signing key a sign-in requires. Secure key storage is unavailable — a connection that is not private is the usual reason.",
    );
  }

  try {
    const pair = (await keys.read()) ?? (await generate(crypto, keys));
    const exported = await crypto.subtle.exportKey("spki", pair.publicKey);
    const pem = toPem(exported);

    writeItem(storage, PUBLIC_KEY_KEY, pem);

    return pem;
  } catch (error) {
    if (error instanceof DeviceIdentityError) {
      throw error;
    }

    throw new DeviceIdentityError(
      "This device could not create the signing key a sign-in requires.",
    );
  }
}

/**
 * Forget this device's key material.
 *
 * Not called by sign-out: signing out disposes of a credential, and the device
 * is still the same hardware afterwards. This exists for the specs and for a
 * later task that needs to rotate a key.
 */
export function forgetDeviceKeyMaterial(): void {
  const storage = environment().storage;

  removeItem(storage, PUBLIC_KEY_KEY);
}

async function generate(
  crypto: Crypto,
  keys: DeviceKeyStore,
): Promise<CryptoKeyPair> {
  // Non-extractable: the private half is generated here and can never be read
  // back out, by this client or by anything that gets at the store.
  const pair = await crypto.subtle.generateKey(SIGNING_ALGORITHM, false, [
    "sign",
    "verify",
  ]);

  await keys.write(pair);

  return pair;
}

/** SPKI bytes as the PEM the node parses (`NodeSignatureAlgorithm`). */
function toPem(spki: ArrayBuffer): string {
  const bytes = new Uint8Array(spki);
  let binary = "";

  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }

  const lines = btoa(binary).match(/.{1,64}/g) ?? [];

  return `-----BEGIN PUBLIC KEY-----\n${lines.join("\n")}\n-----END PUBLIC KEY-----\n`;
}

function indexedDbKeyStore(factory: IDBFactory): DeviceKeyStore {
  function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
      const request = factory.open(DATABASE_NAME, 1);

      request.onupgradeneeded = () => {
        if (!request.result.objectStoreNames.contains(KEY_STORE_NAME)) {
          request.result.createObjectStore(KEY_STORE_NAME);
        }
      };

      request.onsuccess = () => resolve(request.result);
      request.onerror = () =>
        reject(request.error ?? new Error("The device key store could not be opened."));
    });
  }

  async function transact<T>(
    mode: IDBTransactionMode,
    run: (store: IDBObjectStore) => IDBRequest<T>,
  ): Promise<T> {
    const database = await open();

    try {
      return await new Promise<T>((resolve, reject) => {
        const request = run(
          database.transaction(KEY_STORE_NAME, mode).objectStore(KEY_STORE_NAME),
        );

        request.onsuccess = () => resolve(request.result);
        request.onerror = () =>
          reject(request.error ?? new Error("The device key store refused the operation."));
      });
    } finally {
      database.close();
    }
  }

  return {
    async read(): Promise<CryptoKeyPair | null> {
      const stored = await transact<unknown>("readonly", (store) =>
        store.get(KEY_RECORD_ID),
      );

      return isKeyPair(stored) ? stored : null;
    },

    async write(pair: CryptoKeyPair): Promise<void> {
      await transact("readwrite", (store) => store.put(pair, KEY_RECORD_ID));
    },
  };
}

function isKeyPair(value: unknown): value is CryptoKeyPair {
  return (
    typeof value === "object" &&
    value !== null &&
    "privateKey" in value &&
    "publicKey" in value
  );
}

function newUuid(): string {
  const cryptoApi = environment().crypto;

  if (cryptoApi?.randomUUID !== undefined) {
    return cryptoApi.randomUUID();
  }

  // A UUID the node will accept, from whatever randomness this platform has.
  // Only reached on a platform without `crypto.randomUUID`, which is also a
  // platform that will fail the key requirement moments later.
  const hex = Array.from({ length: 32 }, () =>
    Math.floor(Math.random() * 16).toString(16),
  );

  hex[12] = "4";
  hex[16] = ["8", "9", "a", "b"][Math.floor(Math.random() * 4)] as string;

  const digits = hex.join("");

  return [
    digits.slice(0, 8),
    digits.slice(8, 12),
    digits.slice(12, 16),
    digits.slice(16, 20),
    digits.slice(20, 32),
  ].join("-");
}

function readItem(storage: Storage | null, key: string): string | null {
  if (storage === null) {
    return null;
  }

  try {
    const value = storage.getItem(key);

    return value === null || value === "" ? null : value;
  } catch {
    return null;
  }
}

function writeItem(storage: Storage | null, key: string, value: string): void {
  if (storage === null) {
    return;
  }

  try {
    storage.setItem(key, value);
  } catch {
    // A device that cannot persist this still works for as long as it is open.
  }
}

function removeItem(storage: Storage | null, key: string): void {
  if (storage === null) {
    return;
  }

  try {
    storage.removeItem(key);
  } catch {
    // Nothing to recover.
  }
}
