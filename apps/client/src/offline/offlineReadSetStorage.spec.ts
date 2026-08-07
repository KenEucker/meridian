// The durable half of the offline read set store (M18.48; technical spec 9.3).
//
// jsdom ships no IndexedDB, and this project's baseline is not extended with a
// polyfill to obtain one. What is asserted here is therefore what the adapter
// does with the API rather than what a browser engine does with the bytes: the
// database and store it opens, the single key it writes under, that it closes
// the connection it opened, and that every failure the specification can hand it
// — a refused open, a blocked upgrade, a failed request — comes back as a
// rejection the store above turns into "held in memory only" rather than into a
// throw inside a successful refresh.
//
// The double below implements exactly the surface the adapter touches. It is
// deliberately not a general IndexedDB: a fake that tried to be one would be a
// second implementation to be wrong in, which is the thing this whole milestone
// is about not having.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { offlineReadSetPayload } from "@/offline/offlineReadSetFixture";
import {
  createIndexedDbOfflineReadSetStorage,
  createMemoryOfflineReadSetStorage,
  createOfflineReadSetStorage,
  OFFLINE_READ_SET_DATABASE,
  OFFLINE_READ_SET_OBJECT_STORE,
  OFFLINE_READ_SET_RECORD_KEY,
  type StoredOfflineReadSet,
} from "@/offline/offlineReadSetStorage";

function record(version = "version-1"): StoredOfflineReadSet {
  return {
    envelope: 1,
    context: { organizationId: "organization-1", eventId: "event-1" },
    storedAt: "2027-06-01T12:00:00+00:00",
    set: offlineReadSetPayload({ version }),
  };
}

type RequestOutcome = "success" | "error" | "blocked";

interface FakeIndexedDb {
  readonly factory: IDBFactory;
  readonly rows: Map<IDBValidKey, unknown>;
  readonly opened: Array<{ name: string; version?: number }>;
  readonly transactions: Array<{ store: string; mode: IDBTransactionMode }>;
  readonly closed: () => number;
  readonly createdStores: () => readonly string[];
  failOpen: (outcome: RequestOutcome | null) => void;
  failRequests: (failing: boolean) => void;
  /** Make the database one that has been opened by a previous run. */
  alreadyExists: () => void;
}

/** The slice of IndexedDB the adapter actually uses, and nothing else. */
function fakeIndexedDb(): FakeIndexedDb {
  const rows = new Map<IDBValidKey, unknown>();
  const opened: Array<{ name: string; version?: number }> = [];
  const transactions: Array<{ store: string; mode: IDBTransactionMode }> = [];
  const createdStores: string[] = [];

  let closes = 0;
  let openOutcome: RequestOutcome | null = null;
  let requestsFail = false;

  let existing = false;

  const state = {
    failOpen(outcome: RequestOutcome | null) {
      openOutcome = outcome;
    },
    failRequests(failing: boolean) {
      requestsFail = failing;
    },
    alreadyExists() {
      existing = true;
    },
  };

  function settle<T>(
    request: { onsuccess: null | (() => void); onerror: null | (() => void) },
    outcome: "success" | "error",
    apply: () => T,
  ): void {
    queueMicrotask(() => {
      if (outcome === "success") {
        apply();
        request.onsuccess?.();

        return;
      }

      request.onerror?.();
    });
  }

  function makeRequest<T>(apply: () => T): IDBRequest<T> {
    const request = {
      result: undefined as T,
      error: requestsFail ? new Error("Request failed.") : null,
      onsuccess: null as null | (() => void),
      onerror: null as null | (() => void),
    };

    settle(request, requestsFail ? "error" : "success", () => {
      request.result = apply();
    });

    return request as unknown as IDBRequest<T>;
  }

  const database = {
    objectStoreNames: {
      contains: (name: string) => existing || createdStores.includes(name),
    },
    createObjectStore: (name: string) => {
      createdStores.push(name);
    },
    transaction: (store: string, mode: IDBTransactionMode) => {
      transactions.push({ store, mode });

      return {
        objectStore: () => ({
          get: (key: IDBValidKey) => makeRequest(() => rows.get(key)),
          put: (value: unknown, key: IDBValidKey) =>
            makeRequest(() => {
              rows.set(key, value);

              return key;
            }),
          delete: (key: IDBValidKey) =>
            makeRequest(() => {
              rows.delete(key);

              return undefined;
            }),
        }),
      };
    },
    close: () => {
      closes += 1;
    },
  };

  const factory = {
    open: (name: string, version?: number) => {
      opened.push({ name, version });

      const request = {
        result: database as unknown as IDBDatabase,
        error: openOutcome === "error" ? new Error("Refused.") : null,
        onsuccess: null as null | (() => void),
        onerror: null as null | (() => void),
        onblocked: null as null | (() => void),
        onupgradeneeded: null as null | (() => void),
      };

      queueMicrotask(() => {
        if (openOutcome === "error") {
          request.onerror?.();

          return;
        }

        if (openOutcome === "blocked") {
          request.onblocked?.();

          return;
        }

        if (!existing) {
          request.onupgradeneeded?.();
        }

        request.onsuccess?.();
      });

      return request as unknown as IDBOpenDBRequest;
    },
  };

  return {
    factory: factory as unknown as IDBFactory,
    rows,
    opened,
    transactions,
    closed: () => closes,
    createdStores: () => createdStores,
    ...state,
  };
}

let fake: FakeIndexedDb;

beforeEach(() => {
  fake = fakeIndexedDb();
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("the IndexedDB storage", () => {
  it("creates the read-set store the first time it is opened", async () => {
    const storage = createIndexedDbOfflineReadSetStorage(fake.factory)!;

    await storage.save(record());

    expect(fake.opened[0]).toEqual({
      name: OFFLINE_READ_SET_DATABASE,
      version: 1,
    });
    expect(fake.createdStores()).toEqual([OFFLINE_READ_SET_OBJECT_STORE]);
  });

  it("creates nothing on a database a previous run already set up", async () => {
    const storage = createIndexedDbOfflineReadSetStorage(fake.factory)!;

    fake.alreadyExists();
    await storage.save(record());

    expect(fake.createdStores()).toEqual([]);
    expect((await storage.load())?.set.version).toBe("version-1");
  });

  it("writes and reads one record under one key", async () => {
    // A device holds one user's authorized reads for one context, not a pile of
    // them. The fixed key is what makes a second stored set impossible rather
    // than merely undone.
    const storage = createIndexedDbOfflineReadSetStorage(fake.factory)!;

    await storage.save(record());
    await storage.save(record("version-2"));

    expect([...fake.rows.keys()]).toEqual([OFFLINE_READ_SET_RECORD_KEY]);
    expect((await storage.load())?.set.version).toBe("version-2");
  });

  it("reads nothing before anything is written", async () => {
    const storage = createIndexedDbOfflineReadSetStorage(fake.factory)!;

    expect(await storage.load()).toBeNull();
  });

  it("deletes the record it holds", async () => {
    const storage = createIndexedDbOfflineReadSetStorage(fake.factory)!;

    await storage.save(record());
    await storage.clear();

    expect(fake.rows.size).toBe(0);
    expect(await storage.load()).toBeNull();
  });

  it("reads in a read-only transaction and writes in a read-write one", async () => {
    const storage = createIndexedDbOfflineReadSetStorage(fake.factory)!;

    await storage.save(record());
    await storage.load();
    await storage.clear();

    expect(fake.transactions.map((entry) => entry.mode)).toEqual([
      "readwrite",
      "readonly",
      "readwrite",
    ]);
    expect(
      fake.transactions.every(
        (entry) => entry.store === OFFLINE_READ_SET_OBJECT_STORE,
      ),
    ).toBe(true);
  });

  it("closes every connection it opens", async () => {
    // Opened per operation rather than held: a held connection is one more thing
    // a context switch or a session end would have to remember to dispose of.
    const storage = createIndexedDbOfflineReadSetStorage(fake.factory)!;

    await storage.save(record());
    await storage.load();

    expect(fake.closed()).toBe(fake.opened.length);
  });

  it("rejects rather than hanging when the database will not open", async () => {
    const storage = createIndexedDbOfflineReadSetStorage(fake.factory)!;

    fake.failOpen("error");

    await expect(storage.load()).rejects.toThrow();
  });

  it("rejects rather than waiting on a tab holding an older version open", async () => {
    // Waiting would hang a refresh on a tab nobody is looking at. Rejecting
    // sends the store to memory, and the device keeps working.
    const storage = createIndexedDbOfflineReadSetStorage(fake.factory)!;

    fake.failOpen("blocked");

    await expect(storage.save(record())).rejects.toThrow(
      /blocked by another tab/,
    );
  });

  it("rejects when the write itself fails", async () => {
    const storage = createIndexedDbOfflineReadSetStorage(fake.factory)!;

    fake.failRequests(true);

    await expect(storage.save(record())).rejects.toThrow();
  });

  it("is absent on a device with no IndexedDB", () => {
    expect(createIndexedDbOfflineReadSetStorage(null)).toBeNull();
  });
});

describe("choosing a storage", () => {
  it("falls back to memory where the device has no IndexedDB", async () => {
    // jsdom is one such device, and so is a browser in private mode. The set
    // then lives for the life of the process, which is stated rather than
    // hidden: it is still better than holding nothing.
    vi.stubGlobal("indexedDB", undefined);

    const storage = createOfflineReadSetStorage();

    await storage.save(record());

    expect((await storage.load())?.set.version).toBe("version-1");
  });

  it("uses IndexedDB where the device has one", async () => {
    vi.stubGlobal("indexedDB", fake.factory);

    await createOfflineReadSetStorage().save(record());

    expect(fake.opened).toHaveLength(1);
  });

  it("keeps nothing after the memory storage is cleared", async () => {
    const memory = createMemoryOfflineReadSetStorage();

    await memory.save(record());
    await memory.clear();

    expect(await memory.load()).toBeNull();
  });
});
