// Where the offline read set is kept between runs (M18.48; ADR-0003;
// technical spec 9.3; CLIENT-021).
//
// **The layer, and why it is this one.** ADR-0003 left the choice to the
// measurements M18.46 and M18.47 were required to record, between plain
// IndexedDB with in-memory filtering and RxDB over its free Dexie adapter. The
// numbers came back: 10.7 kB for an ordinary staff member, 48.0 kB for the
// heaviest measured caller — a Logistics operator with Planning and Operations
// standing as well — and 8.5 kB for the largest single section.
//
// M18.47 named one measurement it could not take, because no seeder builds it:
// a department of four hundred staff and four hundred tracked units, which is
// where the searchable indexes stop being small. That set is now built rather
// than extrapolated ({@link measuredDepartmentReadSet}) and was run against
// Chrome's own IndexedDB: **209 kB stored, first search 0.4 ms, and 0.044 ms per
// keystroke over the eight hundred rows thereafter**. The origin's quota on that
// machine was 2.9 GB against `localStorage`'s five.
//
// Two hundred kilobytes is one `put` and one `get` of one record. It is not a
// query workload, and a query engine bought to serve it would be paid for in a
// dependency, a schema that has to track every section the server adds, and a
// replication protocol beside the one the outbox already implements. So: plain
// IndexedDB, one record, filtered in memory by {@link OfflineReadSetStore}.
// **RxDB is not adopted**, and the constraint that would have come with it — its
// IndexedDB, OPFS, SQLite, and Filesystem adapters are premium, so only the free
// Dexie adapter was ever on the table — is recorded here rather than discovered
// later.
//
// IndexedDB rather than `localStorage`, which is what the read cache this
// replaces used. `localStorage` is synchronous, is capped near 5 MB in every
// browser that ships it, and stores strings, so a set of any size is serialized
// on the main thread on every write and re-parsed on every read. IndexedDB is
// asynchronous, has a quota measured against the origin's disk budget rather
// than a fixed few megabytes, and stores structured values. The set is one
// record either way; what changes is that the record can grow.
//
// The port is here so the store above it does not know which it got. A device in
// private browsing, an Electron renderer with storage disabled, and a test
// process without an implementation all land on the memory adapter, and the
// consequence is stated rather than hidden: the set survives a reload where
// IndexedDB is available and does not where it is not. That is the same trade
// `createReadCache` and the command outbox's local store already make, and it is
// worth making, because the alternative is a durable write that throws inside a
// successful refresh.

import type { OfflineReadSetPayload } from "@/offline/offlineReadSet";

export const OFFLINE_READ_SET_DATABASE = "meridian-offline";
export const OFFLINE_READ_SET_OBJECT_STORE = "read-set";

/**
 * The one record key.
 *
 * A device holds one user's authorized reads for one context, not a pile of
 * them: a second set under a second key would be a copy of somebody's department
 * roster outliving their session on a shared workstation. Storing under a fixed
 * key makes that impossible rather than merely undone.
 */
export const OFFLINE_READ_SET_RECORD_KEY = "current";

/** The organization and event a set was composed for. */
export interface OfflineReadSetContext {
  readonly organizationId: string | null;
  readonly eventId: string | null;
}

/** One stored set, with the context and moment that make it readable. */
export interface StoredOfflineReadSet {
  /** Envelope version. A record that does not match is discarded, not migrated. */
  readonly envelope: 1;
  readonly context: OfflineReadSetContext;
  readonly storedAt: string;
  readonly set: OfflineReadSetPayload;
}

export interface OfflineReadSetStorage {
  readonly load: () => Promise<StoredOfflineReadSet | null>;
  readonly save: (record: StoredOfflineReadSet) => Promise<void>;
  readonly clear: () => Promise<void>;
}

/**
 * A storage that keeps the set for the life of the page.
 *
 * Not durability, and it does not pretend to be. It is what a device with no
 * IndexedDB gets, and it still beats holding nothing: a refresh that succeeds
 * once serves every surface until the process ends.
 */
export function createMemoryOfflineReadSetStorage(): OfflineReadSetStorage {
  let held: StoredOfflineReadSet | null = null;

  return {
    load: () => Promise.resolve(held),
    save: (record) => {
      held = record;

      return Promise.resolve();
    },
    clear: () => {
      held = null;

      return Promise.resolve();
    },
  };
}

function indexedDbFactory(): IDBFactory | null {
  try {
    return globalThis.indexedDB ?? null;
  } catch {
    // Accessing `indexedDB` throws rather than returning undefined in some
    // sandboxed contexts, which is a "no" like any other.
    return null;
  }
}

function awaitRequest<T>(request: IDBRequest<T>): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    request.onsuccess = () => {
      resolve(request.result);
    };
    request.onerror = () => {
      reject(request.error ?? new Error("IndexedDB request failed."));
    };
  });
}

function openDatabase(factory: IDBFactory): Promise<IDBDatabase> {
  return new Promise<IDBDatabase>((resolve, reject) => {
    const request = factory.open(OFFLINE_READ_SET_DATABASE, 1);

    request.onupgradeneeded = () => {
      const database = request.result;

      if (!database.objectStoreNames.contains(OFFLINE_READ_SET_OBJECT_STORE)) {
        database.createObjectStore(OFFLINE_READ_SET_OBJECT_STORE);
      }
    };

    request.onsuccess = () => {
      resolve(request.result);
    };
    request.onerror = () => {
      reject(request.error ?? new Error("IndexedDB could not be opened."));
    };
    /*
     * Another tab holding an older version open blocks the upgrade. Rejecting
     * rather than waiting is deliberate: the caller falls back to memory and the
     * device keeps working, where waiting would hang a refresh on a tab nobody
     * is looking at.
     */
    request.onblocked = () => {
      reject(new Error("IndexedDB upgrade is blocked by another tab."));
    };
  });
}

/**
 * Run one transaction against the read-set store, closing the connection after.
 *
 * Opened per operation rather than held. The set is written on refresh and read
 * at boot — single-digit operations per session — and a held connection is one
 * more thing a context switch or a session end has to remember to dispose of.
 */
async function withStore<T>(
  factory: IDBFactory,
  mode: IDBTransactionMode,
  work: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
  const database = await openDatabase(factory);

  try {
    const transaction = database.transaction(
      OFFLINE_READ_SET_OBJECT_STORE,
      mode,
    );
    const result = await awaitRequest(
      work(transaction.objectStore(OFFLINE_READ_SET_OBJECT_STORE)),
    );

    return result;
  } finally {
    database.close();
  }
}

/**
 * The durable storage, or null where this device has no IndexedDB.
 *
 * Null rather than a throwing implementation, so the caller chooses the fallback
 * once at construction instead of every operation discovering the same absence.
 */
export function createIndexedDbOfflineReadSetStorage(
  factory: IDBFactory | null = indexedDbFactory(),
): OfflineReadSetStorage | null {
  if (factory === null) {
    return null;
  }

  return {
    async load(): Promise<StoredOfflineReadSet | null> {
      const stored = await withStore(factory, "readonly", (store) =>
        store.get(OFFLINE_READ_SET_RECORD_KEY),
      );

      return (stored as StoredOfflineReadSet | undefined) ?? null;
    },

    async save(record: StoredOfflineReadSet): Promise<void> {
      await withStore(factory, "readwrite", (store) =>
        store.put(record, OFFLINE_READ_SET_RECORD_KEY),
      );
    },

    async clear(): Promise<void> {
      await withStore(factory, "readwrite", (store) =>
        store.delete(OFFLINE_READ_SET_RECORD_KEY),
      );
    },
  };
}

/**
 * The storage this device actually has: IndexedDB where there is one, memory
 * where there is not.
 */
export function createOfflineReadSetStorage(): OfflineReadSetStorage {
  return (
    createIndexedDbOfflineReadSetStorage() ??
    createMemoryOfflineReadSetStorage()
  );
}
