// Finding equipment at the desk, with or without a node (M18.24C; EQUIP-012,
// EQUIP-013, EQUIP-015; SLB-011, SLB-012).
//
// The checkout dialog used to render one checkbox per unit of the department's
// inventory. That reads fine for a department with six radios and is unusable
// for one with four hundred, which is what EQUIP-012 says outright: a
// department's tracked equipment "shall not be presented as a list of every unit
// for the operator to read through".
//
// So this module, and one decision worth stating: it resolves against the
// inventory the desk read already carries rather than asking the node. EQUIP-015
// requires that — "Lookup shall resolve against the department inventory the
// surface already holds, so it works on a node or device with no connectivity" —
// and a gate at three in the morning is exactly where connectivity is worst and
// handoffs are most frequent.
//
// That is safe because scope was applied before the copy was made. The node
// decides what goes into `checkoutInventory`: this department, this event, and
// only what is available to hand out. An asset tag belonging to another
// department was never in the cached list, so no local search can surface it and
// no local answer can disclose that it exists. There is a matching server
// endpoint that answers the same question with the same rules; it exists so the
// node remains the authority, not because the desk needs it to type.
//
// Three outcomes and no fourth:
//
//   - an exact asset tag or serial number match resolves straight to that item,
//     which is what lets a barcode scanner acting as a keyboard finish a handoff
//     without anybody touching the screen (EQUIP-013);
//   - several matches, or an identifier matching several, is reported;
//   - nothing matching is reported.
//
// A name search never resolves on its own however few hits it has. "Radio"
// narrowing to one row today and two tomorrow would make the scanner path
// behave differently depending on the inventory, and predictability is the one
// thing that path has to have.

import type {
  EquipmentCheckoutCandidate,
  EquipmentCheckoutLine,
} from "@/department-ops/types";

export type EquipmentLookupOutcome =
  | "resolved"
  | "multiple"
  | "ambiguous"
  | "none"
  | "empty_query";

export interface EquipmentLookupResult {
  readonly outcome: EquipmentLookupOutcome;
  /** Set only for `resolved`: add this and move on. */
  readonly item: EquipmentCheckoutCandidate | null;
  readonly matches: readonly EquipmentCheckoutCandidate[];
  /** What to tell the operator, or null when there is nothing to say. */
  readonly message: string | null;
}

/** Matches the server's cap, so both surfaces truncate at the same point. */
export const EQUIPMENT_LOOKUP_MATCH_LIMIT = 10;

function normalize(value: string): string {
  return value.trim().toLowerCase();
}

function matchesIdentifier(
  item: EquipmentCheckoutCandidate,
  needle: string,
): boolean {
  return [item.assetTag, item.serialNumber].some(
    (identifier) =>
      typeof identifier === "string" &&
      identifier !== "" &&
      identifier.toLowerCase() === needle,
  );
}

/**
 * The pooled kinds, as a short list an operator picks quantities from
 * (EQUIP-014).
 *
 * Never mixed into the lookup results. UI contract 9.6A keeps the two
 * presentations apart because they are two different actions: choosing how many
 * of a thing, and finding which one of a thing.
 */
export function pooledKinds(
  inventory: readonly EquipmentCheckoutCandidate[],
): readonly EquipmentCheckoutCandidate[] {
  return inventory.filter(
    (item) => item.tracking === "pooled" && item.quantityAvailable > 0,
  );
}

export function trackedUnits(
  inventory: readonly EquipmentCheckoutCandidate[],
): readonly EquipmentCheckoutCandidate[] {
  return inventory.filter((item) => item.tracking === "individual");
}

/**
 * Resolve one typed or scanned value against the cached inventory.
 *
 * Tracked units only. A pooled kind is chosen from the quantity list beside the
 * lookup field, so letting a name search return one would put the same kind in
 * front of the operator twice with two different ways to add it.
 */
export function lookUpEquipment(
  inventory: readonly EquipmentCheckoutCandidate[],
  query: string,
): EquipmentLookupResult {
  const needle = normalize(query);

  if (needle.length === 0) {
    return { outcome: "empty_query", item: null, matches: [], message: null };
  }

  const candidates = trackedUnits(inventory);
  const identifierMatches = candidates.filter((item) =>
    matchesIdentifier(item, needle),
  );

  if (identifierMatches.length === 1) {
    return {
      outcome: "resolved",
      item: identifierMatches[0],
      matches: identifierMatches,
      message: null,
    };
  }

  if (identifierMatches.length > 1) {
    return {
      outcome: "ambiguous",
      item: null,
      matches: identifierMatches.slice(0, EQUIPMENT_LOOKUP_MATCH_LIMIT),
      message: `"${query.trim()}" matches more than one item. Choose which one is being handed over.`,
    };
  }

  const matches = candidates
    .filter((item) => item.name.toLowerCase().includes(needle))
    .slice(0, EQUIPMENT_LOOKUP_MATCH_LIMIT);

  if (matches.length === 0) {
    return {
      outcome: "none",
      item: null,
      matches: [],
      message: `No equipment available to hand out matches "${query.trim()}".`,
    };
  }

  return { outcome: "multiple", item: null, matches, message: null };
}

/**
 * Add a candidate to the staged lines, or raise the quantity on the line that
 * is already there.
 *
 * A tracked unit can only ever be one line of one, so a repeated scan of the
 * same tag is a no-op rather than a second line — an operator sweeping a
 * scanner over a pile should not have to notice that they caught one twice.
 */
export function addCheckoutLine(
  lines: readonly EquipmentCheckoutLine[],
  item: EquipmentCheckoutCandidate,
  quantity = 1,
): readonly EquipmentCheckoutLine[] {
  const requested = Math.max(1, Math.trunc(quantity));
  const existing = lines.find(
    (line) => line.equipmentItemId === item.equipmentItemId,
  );

  if (existing !== undefined) {
    if (item.tracking === "individual") {
      return lines;
    }

    return lines.map((line) =>
      line.equipmentItemId === item.equipmentItemId
        ? {
            ...line,
            quantity: Math.min(
              item.quantityAvailable,
              line.quantity + requested,
            ),
          }
        : line,
    );
  }

  return [
    ...lines,
    {
      equipmentItemId: item.equipmentItemId,
      name: item.name,
      tracking: item.tracking,
      assetTag: item.assetTag,
      quantity:
        item.tracking === "individual"
          ? 1
          : Math.min(item.quantityAvailable, requested),
    },
  ];
}

/** Set a pooled line's quantity outright, removing it at zero. */
export function setCheckoutLineQuantity(
  lines: readonly EquipmentCheckoutLine[],
  item: EquipmentCheckoutCandidate,
  quantity: number,
): readonly EquipmentCheckoutLine[] {
  const bounded = Math.max(
    0,
    Math.min(item.quantityAvailable, Math.trunc(quantity)),
  );

  if (bounded === 0) {
    return removeCheckoutLine(lines, item.equipmentItemId);
  }

  if (lines.some((line) => line.equipmentItemId === item.equipmentItemId)) {
    return lines.map((line) =>
      line.equipmentItemId === item.equipmentItemId
        ? { ...line, quantity: bounded }
        : line,
    );
  }

  return [
    ...lines,
    {
      equipmentItemId: item.equipmentItemId,
      name: item.name,
      tracking: item.tracking,
      assetTag: item.assetTag,
      quantity: bounded,
    },
  ];
}

export function removeCheckoutLine(
  lines: readonly EquipmentCheckoutLine[],
  equipmentItemId: string,
): readonly EquipmentCheckoutLine[] {
  return lines.filter((line) => line.equipmentItemId !== equipmentItemId);
}

export function checkoutLineQuantity(
  lines: readonly EquipmentCheckoutLine[],
  equipmentItemId: string,
): number {
  return (
    lines.find((line) => line.equipmentItemId === equipmentItemId)?.quantity ?? 0
  );
}
