import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  configureDeviceIdentity,
  deviceId,
  DeviceIdentityError,
  devicePlatform,
  devicePublicKey,
  resolveDeviceRegistration,
  type DeviceKeyStore,
} from "@/session/deviceIdentity";

/*
 * The device a token is bound to (M16.11; AUTH-021; technical spec 11.4, 12.4).
 *
 * No WebCrypto and no IndexedDB here: what has to hold is that the identifier
 * and the key are each made once and kept, that only the public half leaves, and
 * that a device which cannot keep a key is refused rather than registered with
 * key material nothing can verify.
 */

const SPKI = new Uint8Array([48, 42, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]).buffer;

function memoryStorage(): Storage {
  const entries = new Map<string, string>();

  return {
    get length() {
      return entries.size;
    },
    clear: () => entries.clear(),
    getItem: (key: string) => entries.get(key) ?? null,
    key: (index: number) => [...entries.keys()][index] ?? null,
    removeItem: (key: string) => void entries.delete(key),
    setItem: (key: string, value: string) => void entries.set(key, value),
  } as Storage;
}

function memoryKeyStore(): DeviceKeyStore & { readonly writes: number[] } {
  let stored: CryptoKeyPair | null = null;
  const writes: number[] = [];

  return {
    writes,
    read: async () => stored,
    write: async (pair: CryptoKeyPair) => {
      stored = pair;
      writes.push(writes.length + 1);
    },
  };
}

function fakeCrypto(): Crypto & { readonly generated: number[] } {
  const generated: number[] = [];

  return {
    generated,
    randomUUID: () => "11111111-1111-4111-8111-111111111111",
    subtle: {
      generateKey: vi.fn(async () => {
        generated.push(generated.length + 1);

        return { privateKey: {}, publicKey: {} } as unknown as CryptoKeyPair;
      }),
      exportKey: vi.fn(async () => SPKI),
    },
  } as unknown as Crypto & { readonly generated: number[] };
}

let storage: Storage;
let keys: ReturnType<typeof memoryKeyStore>;
let crypto: ReturnType<typeof fakeCrypto>;

beforeEach(() => {
  storage = memoryStorage();
  keys = memoryKeyStore();
  crypto = fakeCrypto();

  configureDeviceIdentity({ storage, keys, crypto });
});

afterEach(() => {
  configureDeviceIdentity(null);
});

describe("device identity", () => {
  it("generates one identifier and keeps it", () => {
    const first = deviceId();

    expect(first).toBe("11111111-1111-4111-8111-111111111111");
    expect(deviceId()).toBe(first);
    expect(storage.getItem("meridian.device.id")).toBe(first);
  });

  it("keeps the identifier a previous run stored", () => {
    storage.setItem("meridian.device.id", "22222222-2222-4222-8222-222222222222");

    expect(deviceId()).toBe("22222222-2222-4222-8222-222222222222");
  });

  it("exports the public half as PEM the node can parse", async () => {
    const pem = await devicePublicKey();

    expect(pem.startsWith("-----BEGIN PUBLIC KEY-----\n")).toBe(true);
    expect(pem.trimEnd().endsWith("-----END PUBLIC KEY-----")).toBe(true);
    expect(crypto.subtle.exportKey).toHaveBeenCalledWith("spki", expect.anything());
  });

  /*
   * The private half is generated non-extractable, which is the only reason it
   * is safe to keep at all: nothing — this client included — can read it back
   * out of the store.
   */
  it("generates the signing key non-extractable", async () => {
    await devicePublicKey();

    expect(crypto.subtle.generateKey).toHaveBeenCalledWith(
      expect.objectContaining({ name: "RSASSA-PKCS1-v1_5" }),
      false,
      ["sign", "verify"],
    );
  });

  it("generates the key once and reuses it", async () => {
    const first = await devicePublicKey();

    // Once from the cache, and once from the key store with the cache dropped.
    expect(await devicePublicKey()).toBe(first);

    storage.removeItem("meridian.device.public-key");

    expect(await devicePublicKey()).toBe(first);
    expect(crypto.generated).toHaveLength(1);
    expect(keys.writes).toHaveLength(1);
  });

  it("describes this device for the token issuance request", async () => {
    const registration = await resolveDeviceRegistration();

    expect(registration.id).toBe(deviceId());
    expect(registration.platform).toBe(devicePlatform());
    expect(registration.label).toContain("Meridian");
    expect(registration.public_key).toContain("BEGIN PUBLIC KEY");
  });

  /*
   * Fail closed. A device with no secure key storage cannot be issued a token,
   * and saying so is better than registering something unusable — the refusal
   * lands at sign-in rather than at the first signature that matters.
   */
  it("refuses to identify a device that cannot keep a key", async () => {
    configureDeviceIdentity({ storage, keys: null, crypto });

    await expect(resolveDeviceRegistration()).rejects.toBeInstanceOf(
      DeviceIdentityError,
    );

    configureDeviceIdentity({ storage, keys, crypto: null });

    await expect(resolveDeviceRegistration()).rejects.toBeInstanceOf(
      DeviceIdentityError,
    );
  });

  it("reports a key store that fails as a device that cannot sign in", async () => {
    configureDeviceIdentity({
      storage,
      crypto,
      keys: {
        read: async () => {
          throw new Error("IndexedDB is unavailable.");
        },
        write: async () => undefined,
      },
    });

    await expect(devicePublicKey()).rejects.toBeInstanceOf(DeviceIdentityError);
  });
});
