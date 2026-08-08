import { describe, expect, it, vi } from "vitest";

import {
  addCheckoutLine,
  lookUpEquipment,
  pooledKinds,
  removeCheckoutLine,
  setCheckoutLineQuantity,
} from "@/department-ops/equipmentLookup";
import { getLogisticsDesk } from "@/department-ops/departmentOpsReadModel";
import { configureMeridianApi } from "@/api/meridianApi";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import type { EquipmentCheckoutCandidate } from "@/department-ops/types";

function tracked(
  name: string,
  assetTag: string,
  serialNumber: string | null = null,
): EquipmentCheckoutCandidate {
  return {
    equipmentItemId: `item-${assetTag}`,
    name,
    tracking: "individual",
    trackingLabel: "Tracked",
    assetTag,
    serialNumber,
    quantityTotal: 1,
    quantityAvailable: 1,
  };
}

function pooled(
  name: string,
  quantityAvailable: number,
): EquipmentCheckoutCandidate {
  return {
    equipmentItemId: `pool-${name}`,
    name,
    tracking: "pooled",
    trackingLabel: "Pooled",
    assetTag: null,
    serialNumber: null,
    quantityTotal: quantityAvailable + 3,
    quantityAvailable,
  };
}

const INVENTORY: readonly EquipmentCheckoutCandidate[] = [
  tracked("Radio 12", "RDO-12", "SN-0012"),
  tracked("Radio 13", "RDO-13", "SN-0013"),
  pooled("Safety vest", 37),
];

describe("equipment lookup", () => {
  /**
   * The scanner path (EQUIP-013). A barcode scanner acting as a keyboard types
   * the tag; an exact match resolves without a selection step so the handoff
   * finishes without anybody touching the screen.
   */
  it("resolves an exact asset tag to one item without a selection step", () => {
    const result = lookUpEquipment(INVENTORY, "RDO-13");

    expect(result.outcome).toBe("resolved");
    expect(result.item?.name).toBe("Radio 13");
    expect(result.message).toBeNull();
  });

  it("resolves an exact serial number the same way", () => {
    expect(lookUpEquipment(INVENTORY, "sn-0012").item?.name).toBe("Radio 12");
  });

  /**
   * A name search never resolves on its own, however few hits it has.
   * "Radio" narrowing to one row today and two tomorrow would make the scanner
   * path behave differently depending on the inventory.
   */
  it("offers name matches for selection rather than resolving them", () => {
    const result = lookUpEquipment(INVENTORY, "radio");

    expect(result.outcome).toBe("multiple");
    expect(result.item).toBeNull();
    expect(result.matches.map((match) => match.name)).toEqual([
      "Radio 12",
      "Radio 13",
    ]);
  });

  it("resolves nothing and says so when an identifier matches several", () => {
    const collision = [
      ...INVENTORY,
      tracked("Radio 14", "RDO-14", "RDO-12"),
    ];
    const result = lookUpEquipment(collision, "RDO-12");

    expect(result.outcome).toBe("ambiguous");
    expect(result.item).toBeNull();
    expect(result.matches).toHaveLength(2);
    expect(result.message).toContain("matches more than one item");
  });

  it("reports an unmatched value rather than guessing", () => {
    const result = lookUpEquipment(INVENTORY, "GATE-01");

    expect(result.outcome).toBe("none");
    expect(result.matches).toEqual([]);
    expect(result.message).toContain('No equipment available to hand out matches "GATE-01"');
  });

  /**
   * Pooled kinds never appear in lookup results (UI contract 9.6A).
   *
   * They are chosen from the quantity list beside the field, and letting a name
   * search return one would put the same kind in front of the operator twice
   * with two different ways to add it.
   */
  it("keeps pooled kinds out of the lookup results", () => {
    expect(lookUpEquipment(INVENTORY, "vest").outcome).toBe("none");
    expect(pooledKinds(INVENTORY).map((item) => item.name)).toEqual([
      "Safety vest",
    ]);
  });

  it("offers no pooled kind with nothing left in it", () => {
    expect(pooledKinds([pooled("Safety vest", 0)])).toEqual([]);
  });
});

describe("staged checkout lines", () => {
  it("adds a tracked unit once however many times it is scanned", () => {
    const item = tracked("Radio 12", "RDO-12");
    const once = addCheckoutLine([], item);

    expect(addCheckoutLine(once, item)).toEqual(once);
    expect(once).toHaveLength(1);
    expect(once[0]!.quantity).toBe(1);
  });

  it("caps a pooled line at what is available", () => {
    const vests = pooled("Safety vest", 4);
    const lines = setCheckoutLineQuantity([], vests, 9);

    expect(lines[0]!.quantity).toBe(4);
  });

  it("drops a pooled line set back to zero", () => {
    const vests = pooled("Safety vest", 4);
    const lines = setCheckoutLineQuantity([], vests, 3);

    expect(setCheckoutLineQuantity(lines, vests, 0)).toEqual([]);
  });

  it("removes a line the operator changed their mind about", () => {
    const item = tracked("Radio 12", "RDO-12");
    const lines = addCheckoutLine([], item);

    expect(removeCheckoutLine(lines, item.equipmentItemId)).toEqual([]);
  });
});

/**
 * EQUIP-015's offline half.
 *
 * "Lookup shall resolve against the department inventory the surface already
 * holds, so it works on a node or device with no connectivity." The lookup
 * itself never asks a node: it resolves against the inventory the desk read
 * carried, and scope was applied before that inventory left the node, so nothing
 * outside the department was ever in it to be found.
 *
 * What M18.50 changed is what happens to that inventory when the desk is read
 * again with no node in reach. It is not answered from a stored copy of the
 * desk's own response any more — the desk payload is the node's derived answers
 * rather than records — so a re-read is refused and the surface reports the node
 * it could not reach. The inventory a surface is already holding is unaffected,
 * which is what EQUIP-015 is about, and restoring the re-read is a projection
 * over `logistics_equipment_index` that M18.53's inventory scopes.
 */
describe("lookup with the node unreachable", () => {
  it("resolves a scanned tag against the inventory the desk is holding", async () => {
    clearOfflineReadSet();
    configureMeridianApi({ baseUrl: "http://node.test", bearerToken: "t" });

    const payload = {
      context: {
        event_id: "event-1",
        event_label: "Emberfall",
        department_id: "department-1",
        department_label: "Rangers",
        time_zone: "UTC",
        as_of: "2027-07-04T18:00:00+00:00",
      },
      access: { can_manage_equipment: true },
      searchable_staff: [],
      searchable_equipment: [],
      searchable_shifts: [],
      checkout_inventory: [
        {
          equipment_item_id: "item-radio-12",
          name: "Radio 12",
          tracking: "individual",
          tracking_label: "Tracked",
          asset_tag: "RDO-12",
          serial_number: "SN-0012",
          quantity_total: 1,
          quantity_available: 1,
        },
      ],
      staff_workspaces: {
        "staff-1": {
          staff_id: "staff-1",
          display_name: "Vera Staff",
          handle: "vera",
          team_label: "Dirt",
          presence_state: "on_site",
          can_go_off_site: true,
          off_site_blocked_reason: null,
          shift_cards: [],
          open_equipment: [],
          future_signups: [],
        },
      },
    };

    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(JSON.stringify(payload), {
            status: 200,
            headers: { "content-type": "application/json" },
          }),
      ),
    );

    const live = await getLogisticsDesk("event-1", "department-1");

    expect(live.freshness.source).toBe("node");

    // The node goes away. Re-reading the desk is refused rather than answered
    // from a stored copy of the desk's own response (M18.50).
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    await expect(getLogisticsDesk("event-1", "department-1")).rejects.toThrow(
      /Failed to fetch/,
    );

    // The scan still lands against the inventory the surface is holding, which
    // is what EQUIP-015 asks for and needs no node.
    const inventory = live.checkoutInventory;
    const result = lookUpEquipment(inventory, "RDO-12");

    expect(result.outcome).toBe("resolved");
    expect(result.item?.equipmentItemId).toBe("item-radio-12");

    // And a tag from another department was never in this inventory to be
    // found, so it resolves to nothing without disclosing that it exists
    // anywhere.
    expect(lookUpEquipment(inventory, "GATE-01").outcome).toBe("none");

    vi.unstubAllGlobals();
    clearOfflineReadSet();
  });
});
