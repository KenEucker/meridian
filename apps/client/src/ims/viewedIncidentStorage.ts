// Where the viewed-incident cache is kept between runs (INC-017, INC-018;
// technical spec 19.2, 9.3, 12.3).
//
// The same layer, chosen for the same reasons, as
// `offline/offlineReadSetStorage.ts`: plain IndexedDB behind a port, with a
// memory fallback for a device that has none. What differs is the record shape.
// The read set is one composed answer under one fixed key; this cache is one
// entry per incident, keyed by incident id, because entries arrive one view at
// a time and expire one at a time — six weeks after each was *last* viewed —
// and a single blob would rewrite every entry to refresh one.
//
// The incident row itself is stored opaquely, the way read-set rows are. What
// an incident carries is the node's business (data/API 5.1); what this module
// validates is the envelope that makes an entry attributable and expirable —
// whose view stored it, which event it belongs to, and when it was last viewed.
// Validation is refusal: an entry that does not match is dropped, not repaired,
// because "this is the incident you read, or part of it" is not something a
// surface can say to somebody standing in a field.

export const VIEWED_INCIDENT_DATABASE = "meridian-incidents";
export const VIEWED_INCIDENT_OBJECT_STORE = "viewed-incidents";

/**
 * One cached incident, wrapped in what makes it safe to serve later.
 *
 * `userId` is on every entry because the cache is populated only by the user's
 * own views (INC-018): an entry is served back only to the user whose view
 * stored it, so a stale record on a shared device cannot show one person
 * another's reading history.
 */
export interface StoredViewedIncident {
  /** Envelope version. A record that does not match is discarded, not migrated. */
  readonly envelope: 1;
  readonly userId: string;
  readonly eventId: string;
  readonly incidentId: string;
  /** Device time of the most recent view; the six-week expiry counts from it. */
  readonly lastViewedAt: string;
  /** The incident as the surface renders it. Opaque here, on purpose. */
  readonly incident: Readonly<Record<string, unknown>>;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

/** Whether a value read back from storage is an entry this build can serve. */
export function isStoredViewedIncident(
  value: unknown,
): value is StoredViewedIncident {
  if (!isRecord(value)) {
    return false;
  }

  return (
    value.envelope === 1 &&
    typeof value.userId === "string" &&
    typeof value.eventId === "string" &&
    typeof value.incidentId === "string" &&
    typeof value.lastViewedAt === "string" &&
    isRecord(value.incident)
  );
}

export interface ViewedIncidentStorage {
  readonly loadAll: () => Promise<readonly StoredViewedIncident[]>;
  readonly save: (entry: StoredViewedIncident) => Promise<void>;
  readonly remove: (incidentIds: readonly string[]) => Promise<void>;
  readonly clear: () => Promise<void>;
}

/**
 * A storage that keeps the entries for the life of the page.
 *
 * Not durability, and it does not pretend to be — the same trade the read-set
 * storage states: a device with no IndexedDB keeps what its user viewed until
 * the process ends, which still beats holding nothing.
 */
export function createMemoryViewedIncidentStorage(): ViewedIncidentStorage {
  const held = new Map<string, StoredViewedIncident>();

  return {
    loadAll: () => Promise.resolve([...held.values()]),
    save: (entry) => {
      held.set(entry.incidentId, entry);

      return Promise.resolve();
    },
    remove: (incidentIds) => {
      for (const id of incidentIds) {
        held.delete(id);
      }

      return Promise.resolve();
    },
    clear: () => {
      held.clear();

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
    const request = factory.open(VIEWED_INCIDENT_DATABASE, 1);

    request.onupgradeneeded = () => {
      const database = request.result;

      if (!database.objectStoreNames.contains(VIEWED_INCIDENT_OBJECT_STORE)) {
        database.createObjectStore(VIEWED_INCIDENT_OBJECT_STORE);
      }
    };

    request.onsuccess = () => {
      resolve(request.result);
    };
    request.onerror = () => {
      reject(request.error ?? new Error("IndexedDB could not be opened."));
    };
    // Rejecting rather than waiting on a blocked upgrade, as the read-set
    // storage does: the caller falls back to memory and the device keeps
    // working.
    request.onblocked = () => {
      reject(new Error("IndexedDB upgrade is blocked by another tab."));
    };
  });
}

async function withStore<T>(
  factory: IDBFactory,
  mode: IDBTransactionMode,
  work: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
  const database = await openDatabase(factory);

  try {
    return await awaitRequest(
      work(
        database
          .transaction(VIEWED_INCIDENT_OBJECT_STORE, mode)
          .objectStore(VIEWED_INCIDENT_OBJECT_STORE),
      ),
    );
  } finally {
    database.close();
  }
}

/**
 * The durable storage, or null where this device has no IndexedDB.
 *
 * Null rather than a throwing implementation, so the caller chooses the
 * fallback once at construction.
 */
export function createIndexedDbViewedIncidentStorage(
  factory: IDBFactory | null = indexedDbFactory(),
): ViewedIncidentStorage | null {
  if (factory === null) {
    return null;
  }

  return {
    async loadAll(): Promise<readonly StoredViewedIncident[]> {
      const stored = await withStore(factory, "readonly", (store) =>
        store.getAll(),
      );

      // Validation is refusal: an entry this build cannot read is not served.
      // It is left for `clear`/`remove` to dispose of rather than repaired.
      return (stored as unknown[]).filter(isStoredViewedIncident);
    },

    async save(entry: StoredViewedIncident): Promise<void> {
      await withStore(factory, "readwrite", (store) =>
        store.put(entry, entry.incidentId),
      );
    },

    async remove(incidentIds: readonly string[]): Promise<void> {
      for (const id of incidentIds) {
        await withStore(factory, "readwrite", (store) => store.delete(id));
      }
    },

    async clear(): Promise<void> {
      await withStore(factory, "readwrite", (store) => store.clear());
    },
  };
}

/**
 * The storage this device actually has: IndexedDB where there is one, memory
 * where there is not.
 */
export function createViewedIncidentStorage(): ViewedIncidentStorage {
  return (
    createIndexedDbViewedIncidentStorage() ?? createMemoryViewedIncidentStorage()
  );
}
