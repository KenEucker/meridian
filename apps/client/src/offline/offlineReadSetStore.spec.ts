// The device's offline read set store (M18.48; ADR-0003; CLIENT-021,
// CLIENT-022; technical spec 9.3, 9.5).
//
// No server runs here (CLIENT-024). The store is handed sets in the shape
// `GET /api/offline-read-set` returns and a storage double that can be made to
// fail, which is what lets the two failures worth asserting be asserted: durable
// storage refusing a write, and a session ending while one is in flight.

import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  logisticsEquipmentIndexRows,
  logisticsStaffIndexRows,
  measuredDepartmentReadSet,
  offlineReadSetPayload,
  FIXTURE_EVENT_ID,
  FIXTURE_ORGANIZATION_ID,
  MEASURED_DEPARTMENT_EQUIPMENT,
  MEASURED_DEPARTMENT_STAFF,
} from "@/offline/offlineReadSetFixture";
import {
  createMemoryOfflineReadSetStorage,
  type OfflineReadSetStorage,
  type StoredOfflineReadSet,
} from "@/offline/offlineReadSetStorage";
import { createOfflineReadSetStore } from "@/offline/offlineReadSetStore";

const CONTEXT = {
  organizationId: FIXTURE_ORGANIZATION_ID,
  eventId: FIXTURE_EVENT_ID,
};

/** A storage whose operations can be failed or held open, one at a time. */
function controllableStorage(): OfflineReadSetStorage & {
  record: () => StoredOfflineReadSet | null;
  failSaves: (failing: boolean) => void;
  holdSave: () => () => void;
  cleared: () => number;
} {
  let held: StoredOfflineReadSet | null = null;
  let failing = false;
  let gate: Promise<void> | null = null;
  let openGate: (() => void) | null = null;
  let clears = 0;

  return {
    load: () => Promise.resolve(held),
    async save(record) {
      if (gate !== null) {
        await gate;
      }

      if (failing) {
        throw new Error("No quota.");
      }

      held = record;
    },
    clear() {
      clears += 1;
      held = null;

      return Promise.resolve();
    },
    record: () => held,
    failSaves(value) {
      failing = value;
    },
    /** Hold the next save open. Returns the release. */
    holdSave() {
      gate = new Promise<void>((resolve) => {
        openGate = resolve;
      });

      return () => {
        openGate?.();
        gate = null;
        openGate = null;
      };
    },
    cleared: () => clears,
  };
}

let storage: ReturnType<typeof controllableStorage>;
let store: ReturnType<typeof createOfflineReadSetStore>;

beforeEach(() => {
  storage = controllableStorage();
  store = createOfflineReadSetStore(storage);
});

describe("holding a set", () => {
  it("serves the sections the node composed", async () => {
    await store.replace(offlineReadSetPayload(), CONTEXT);

    expect(store.version()).toBe("version-1");
    expect(store.section("staff")).toHaveLength(1);
    expect(store.section<{ id: string }>("shifts")[0]?.id).toBe("shift-1");
    expect(store.held()?.context).toEqual(CONTEXT);
  });

  it("answers a section this caller's set does not carry with nothing", async () => {
    // A caller holding no Logistics role receives no Logistics section at all,
    // rather than an empty one, and the store keeps that distinction readable:
    // `carries` is how a surface tells "you hold no Logistics standing" from
    // "your department has no staff".
    await store.replace(offlineReadSetPayload(), CONTEXT);

    expect(store.section("logistics_staff_index")).toEqual([]);
    expect(store.carries("logistics_staff_index")).toBe(false);
    expect(store.carries("staff")).toBe(true);
  });

  it("reads a set synchronously in the tick it was stored in", async () => {
    // The read models this replaces are synchronous (M18.50), and a store that
    // resolved a promise for a section would make every surface asynchronous for
    // what is an array reference.
    const stored = store.replace(offlineReadSetPayload(), CONTEXT);

    expect(store.section("staff")).toHaveLength(1);

    await stored;
  });

  it("holds nothing before a set arrives", () => {
    expect(store.held()).toBeNull();
    expect(store.version()).toBeNull();
    expect(store.section("staff")).toEqual([]);
  });

  it("refuses a payload that is not a read set and keeps what it holds", async () => {
    await store.replace(offlineReadSetPayload(), CONTEXT);

    await expect(store.replace({ sections: {} }, CONTEXT)).rejects.toThrow(
      TypeError,
    );

    // Half a set has no honest disclosure. The previous one is still whole.
    expect(store.version()).toBe("version-1");
    expect(store.section("staff")).toHaveLength(1);
  });
});

describe("replacing a set", () => {
  it("replaces whole rather than merging", async () => {
    // CLIENT-022. The node composes from live grants, so a section that was
    // there yesterday and is absent today means the grant behind it is gone —
    // and rows kept from the previous answer would be the device re-granting
    // what the node had just withdrawn.
    await store.replace(
      offlineReadSetPayload({
        sections: {
          staff: [{ id: "staff-self" }],
          logistics_staff_index: logisticsStaffIndexRows(3),
        },
      }),
      CONTEXT,
    );

    expect(store.section("logistics_staff_index")).toHaveLength(3);

    await store.replace(
      offlineReadSetPayload({
        version: "version-2",
        sections: { staff: [{ id: "staff-self" }] },
      }),
      CONTEXT,
    );

    expect(store.carries("logistics_staff_index")).toBe(false);
    expect(store.section("logistics_staff_index")).toEqual([]);
    expect(store.version()).toBe("version-2");
  });

  it("evicts the previous set from durable storage rather than keeping both", async () => {
    await store.replace(offlineReadSetPayload(), CONTEXT);
    await store.replace(
      offlineReadSetPayload({ version: "version-2" }),
      CONTEXT,
    );

    // One record, not a pile: a second stored set would be somebody's roster
    // outliving the session that authorized it.
    expect(storage.record()?.set.version).toBe("version-2");
  });

  it("keeps the set in memory when durable storage refuses it", async () => {
    // Out of quota, private browsing, no implementation: the same answer. A
    // durable write that throws must not throw inside a successful refresh, and
    // the device still holds the set for the life of the process.
    storage.failSaves(true);

    expect(await store.replace(offlineReadSetPayload(), CONTEXT)).toBe(
      "memory_only",
    );
    expect(store.section("staff")).toHaveLength(1);
    expect(storage.record()).toBeNull();
  });

  it("stamps the moment it was stored", async () => {
    await store.replace(
      offlineReadSetPayload(),
      CONTEXT,
      "2027-06-01T12:30:00+00:00",
    );

    expect(store.held()?.storedAt).toBe("2027-06-01T12:30:00+00:00");
  });
});

describe("dropping a set", () => {
  it("forgets everything in memory immediately", async () => {
    await store.replace(offlineReadSetPayload(), CONTEXT);

    store.clear();

    // Synchronously, with no await between the drop and the read: a surface
    // rendering in the tick after a sign-out must not find the previous
    // session's rows.
    expect(store.held()).toBeNull();
    expect(store.version()).toBeNull();
    expect(store.section("staff")).toEqual([]);
  });

  it("deletes the durable copy", async () => {
    await store.replace(offlineReadSetPayload(), CONTEXT);

    store.clear();
    await Promise.resolve();

    expect(storage.record()).toBeNull();
  });

  it("cannot be resurrected by a refresh that was in flight", async () => {
    /*
     * The failure this prevents is specific and would be invisible: a
     * shared workstation locks, the next person signs in, and a moment later the
     * previous occupant's set — requested before they left — lands in the store
     * and renders. The generation stamp is what makes the late arrival belong to
     * a session that has ended.
     */
    const release = storage.holdSave();
    const pending = store.replace(offlineReadSetPayload(), CONTEXT);

    store.clear();
    release();

    expect(await pending).toBe("discarded");
    expect(store.held()).toBeNull();
    expect(storage.record()).toBeNull();
  });

  it("cannot be resurrected by a hydration that was in flight", async () => {
    await store.replace(offlineReadSetPayload(), CONTEXT);

    const restarted = createOfflineReadSetStore(storage);
    const pending = restarted.hydrate();

    restarted.clear();

    expect(await pending).toBeNull();
    expect(restarted.held()).toBeNull();
    expect(storage.record()).toBeNull();
  });
});

describe("hydrating at boot", () => {
  it("comes back holding what the device was closed with", async () => {
    await store.replace(offlineReadSetPayload(), CONTEXT);

    const restarted = createOfflineReadSetStore(storage);

    expect(await restarted.hydrate()).not.toBeNull();
    expect(restarted.version()).toBe("version-1");
    expect(restarted.section("staff")).toHaveLength(1);
    expect(restarted.held()?.context).toEqual(CONTEXT);
  });

  it("discards a record this build cannot read", async () => {
    // A record written by an earlier envelope, or one that has been truncated:
    // dropped rather than repaired, which leaves the device where one that has
    // never been online is — it refreshes, or it waits.
    await storage.save({
      envelope: 1,
      context: CONTEXT,
      storedAt: "2027-06-01T12:00:00+00:00",
      set: { version: "version-1" } as never,
    });

    expect(await store.hydrate()).toBeNull();
    expect(store.held()).toBeNull();
    expect(storage.record()).toBeNull();
  });

  it("discards a record from an envelope it does not know", async () => {
    await storage.save({
      envelope: 2 as never,
      context: CONTEXT,
      storedAt: "2027-06-01T12:00:00+00:00",
      set: offlineReadSetPayload(),
    });

    expect(await store.hydrate()).toBeNull();
    expect(store.held()).toBeNull();
  });

  it("holds nothing when durable storage cannot be read", async () => {
    const failing: OfflineReadSetStorage = {
      load: () => Promise.reject(new Error("No storage.")),
      save: () => Promise.resolve(),
      clear: () => Promise.resolve(),
    };

    expect(await createOfflineReadSetStore(failing).hydrate()).toBeNull();
  });
});

describe("searching the Logistics indexes", () => {
  /*
   * The measurement M18.47 left open, exercised as behavior.
   *
   * It measured the seeded scenario at 74 Logistics rows and said the department
   * still to be measured is four hundred staff and four hundred tracked units.
   * That is what is loaded here, and it is the case that decides the storage
   * layer: if a search over it needed a query engine, plain IndexedDB with
   * in-memory filtering would be the wrong answer and RxDB the right one.
   */
  beforeEach(async () => {
    await store.replace(measuredDepartmentReadSet(), CONTEXT);
  });

  it("holds the whole department index", () => {
    expect(store.section("logistics_staff_index")).toHaveLength(
      MEASURED_DEPARTMENT_STAFF,
    );
    expect(store.section("logistics_equipment_index")).toHaveLength(
      MEASURED_DEPARTMENT_EQUIPMENT,
    );
  });

  it("finds a staff member by name, handle, or team", () => {
    const byName = store.search<{ staff_id: string; legal_name: string }>(
      "logistics_staff_index",
      "Reyes 392",
      ["legal_name", "preferred_name", "handle", "team_label"],
    );

    expect(byName).toHaveLength(1);
    expect(byName[0]?.legal_name).toBe("Dana Reyes 392");

    // Every token has to land somewhere in the searched fields, which is what
    // makes a desk able to type a name and a crew together without knowing which
    // field either is in.
    const byTeam = store.search("logistics_staff_index", "dana gate", [
      "legal_name",
      "handle",
      "team_label",
    ]);

    expect(byTeam.length).toBeGreaterThan(0);
    expect(
      byTeam.every(
        (row) =>
          (row as { team_label: string }).team_label === "Gate Crew" &&
          (row as { legal_name: string }).legal_name.includes("Dana"),
      ),
    ).toBe(true);
  });

  it("finds a name typed without its diacritics", () => {
    // An operator types what is on the keyboard in front of them. "Bjorn" not
    // finding "Björn" reads as the index being wrong rather than the query being
    // unlucky.
    const matches = store.search("logistics_staff_index", "bjorn", [
      "legal_name",
      "handle",
    ]);

    expect(matches.length).toBeGreaterThan(0);
  });

  it("finds equipment by asset tag and serial number", () => {
    const fields = ["name", "asset_tag", "serial_number"];

    expect(
      store.search("logistics_equipment_index", "MRD-0137", fields),
    ).toHaveLength(1);
    // A scanner acting as a keyboard types the serial and nothing else.
    expect(
      store.search<{ id: string }>(
        "logistics_equipment_index",
        "SN000959",
        fields,
      )[0]?.id,
    ).toBe("equipment-137");
  });

  it("answers an empty query with the whole index", () => {
    // An empty search box has narrowed nothing. Answering it with no rows would
    // present a department as having no staff.
    expect(
      store.search("logistics_staff_index", "   ", ["legal_name"]),
    ).toHaveLength(MEASURED_DEPARTMENT_STAFF);
  });

  it("answers a query nothing matches with nothing", () => {
    expect(
      store.search("logistics_staff_index", "nobody-by-that-name", [
        "legal_name",
        "handle",
      ]),
    ).toEqual([]);
  });

  it("answers a search box at department scale without a query engine", () => {
    /*
     * The claim being tested is the storage decision itself: eight hundred rows,
     * one keystroke at a time, filtered in memory.
     *
     * The bound is generous on purpose — this runs on whatever CI machine is
     * free, and the failure worth catching is a search that became quadratic or
     * started re-normalizing every row on every keystroke, which is orders of
     * magnitude rather than a few milliseconds.
     */
    const fields = ["legal_name", "preferred_name", "handle", "team_label"];
    const typed = ["d", "da", "dan", "dana", "dana r", "dana re", "dana rey"];
    const started = performance.now();

    for (let repeat = 0; repeat < 20; repeat += 1) {
      for (const query of typed) {
        store.search("logistics_staff_index", query, fields);
      }
    }

    expect(performance.now() - started).toBeLessThan(1_000);
  });

  it("re-reads the index after the set is replaced", async () => {
    // The normalized search text is built once per set rather than once per
    // keystroke, so the case worth asserting is that a refresh invalidates it.
    await store.replace(
      offlineReadSetPayload({
        version: "version-2",
        sections: {
          logistics_staff_index: logisticsStaffIndexRows(2),
          logistics_equipment_index: logisticsEquipmentIndexRows(1),
        },
      }),
      CONTEXT,
    );

    expect(
      store.search("logistics_staff_index", "", ["legal_name"]),
    ).toHaveLength(2);
    expect(
      store.search("logistics_staff_index", "Reyes 392", ["legal_name"]),
    ).toEqual([]);
  });

  it("searches nothing once the set is dropped", () => {
    store.clear();

    expect(
      store.search("logistics_staff_index", "dana", ["legal_name"]),
    ).toEqual([]);
  });
});

describe("the storage port", () => {
  it("keeps a set for the life of the process in memory", async () => {
    const memory = createMemoryOfflineReadSetStorage();
    const memoryStore = createOfflineReadSetStore(memory);

    await memoryStore.replace(offlineReadSetPayload(), CONTEXT);

    expect((await memory.load())?.set.version).toBe("version-1");

    memoryStore.clear();
    await Promise.resolve();

    expect(await memory.load()).toBeNull();
  });

  it("does not swallow a storage failure into a thrown refresh", async () => {
    const throwing: OfflineReadSetStorage = {
      load: () => Promise.resolve(null),
      save: vi.fn(() => Promise.reject(new Error("No quota."))),
      clear: () => Promise.resolve(),
    };
    const throwingStore = createOfflineReadSetStore(throwing);

    await expect(
      throwingStore.replace(offlineReadSetPayload(), CONTEXT),
    ).resolves.toBe("memory_only");
  });
});
