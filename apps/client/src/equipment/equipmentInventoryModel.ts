import { shallowRef } from "vue";

import {
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  fixtureDepartmentById,
  selectedFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import type { DepartmentSelfAdminSession } from "@/department-teams/teamAdminModel";
import { canAdministerDepartment } from "@/department-teams/teamAdminModel";
import type { EquipmentState } from "@/department-ops/types";
import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";

/**
 * Product equipment inventory setup model (M11.18; EQUIP-001 through
 * EQUIP-005, EQUIP-007).
 *
 * Mirrors the server rules in `EquipmentInventoryService` so the shared client
 * behaves the same before the product surfaces move off development fixtures:
 * - inventory is department-scoped. Department-to-department allotments are
 *   excluded from MVP by EQUIP-006 and are not modeled;
 * - new equipment always starts Available; Logistics owns checkout state;
 * - Checked out and Returned can never be set from inventory setup, and no
 *   state change or archive is allowed while an item is checked out;
 * - asset tags are unique within the department, which is what makes a
 *   re-imported CSV skip rows instead of duplicating equipment;
 * - equipment is archived, never deleted.
 */
export interface ProductEquipmentItem {
  readonly id: string;
  readonly departmentId: string;
  readonly eventId: string | null;
  readonly name: string;
  readonly assetTag: string | null;
  readonly serialNumber: string | null;
  readonly status: EquipmentState;
  /** True while Logistics holds an open checkout for this item. */
  readonly hasOpenCheckout: boolean;
  readonly archivedAt: string | null;
  readonly updatedAt: string;
}

export interface EquipmentItemDraft {
  name: string;
  assetTag: string;
  serialNumber: string;
  eventId: string | null;
  /** `null` leaves the current state alone, matching the optional server field. */
  status: MaintainableEquipmentState | null;
}

export type MaintainableEquipmentState = Extract<
  EquipmentState,
  "available" | "missing" | "damaged"
>;

export type EquipmentStatusFilter = "all" | "active" | "archived";

export interface EquipmentImportRowResult {
  readonly line: number;
  readonly name: string;
  readonly assetTag: string | null;
  readonly status: "imported" | "skipped";
  readonly reason: string | null;
}

export interface EquipmentImportResult {
  readonly imported: number;
  readonly skipped: number;
  readonly rows: readonly EquipmentImportRowResult[];
}

/** States a maintainer may set from inventory setup (EQUIP-005 subset). */
export const MAINTAINABLE_EQUIPMENT_STATES: readonly MaintainableEquipmentState[] =
  ["available", "missing", "damaged"];

const EQUIPMENT_STATE_LABELS: Record<EquipmentState, string> = {
  available: "Available",
  checked_out: "Checked out",
  returned: "Returned",
  missing: "Missing",
  damaged: "Damaged",
};

const FIXTURE_TIMESTAMP = "2026-07-01T12:00:00.000Z";

const INITIAL_EQUIPMENT: ProductEquipmentItem[] = [
  {
    id: "eeeeeee1-0000-4000-8000-000000000001",
    departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
    eventId: LOCAL_FIELD_FIXTURE.eventId,
    name: "Radio 12",
    assetTag: "RAD-012",
    serialNumber: "SN-0012",
    status: "checked_out",
    hasOpenCheckout: true,
    archivedAt: null,
    updatedAt: FIXTURE_TIMESTAMP,
  },
  {
    id: "eeeeeee1-0000-4000-8000-000000000002",
    departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
    eventId: LOCAL_FIELD_FIXTURE.eventId,
    name: "Radio 13",
    assetTag: "RAD-013",
    serialNumber: "SN-0013",
    status: "available",
    hasOpenCheckout: false,
    archivedAt: null,
    updatedAt: FIXTURE_TIMESTAMP,
  },
  {
    id: "eeeeeee1-0000-4000-8000-000000000003",
    departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
    eventId: LOCAL_FIELD_FIXTURE.eventId,
    name: "Radio 14",
    assetTag: "RAD-014",
    serialNumber: null,
    status: "damaged",
    hasOpenCheckout: false,
    archivedAt: null,
    updatedAt: FIXTURE_TIMESTAMP,
  },
  {
    id: "eeeeeee1-0000-4000-8000-000000000004",
    departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
    eventId: null,
    name: "Retired Vest",
    assetTag: "VEST-001",
    serialNumber: null,
    status: "available",
    hasOpenCheckout: false,
    archivedAt: FIXTURE_TIMESTAMP,
    updatedAt: FIXTURE_TIMESTAMP,
  },
  {
    id: "eeeeeee1-0000-4000-8000-000000000005",
    departmentId: FIXTURE_DPW_DEPARTMENT_ID,
    eventId: LOCAL_FIELD_FIXTURE.eventId,
    name: "Bike Repair Stand",
    assetTag: "DPW-001",
    serialNumber: null,
    status: "available",
    hasOpenCheckout: false,
    archivedAt: null,
    updatedAt: FIXTURE_TIMESTAMP,
  },
  {
    id: "eeeeeee1-0000-4000-8000-000000000006",
    departmentId: FIXTURE_GATE_DEPARTMENT_ID,
    eventId: LOCAL_FIELD_FIXTURE.eventId,
    name: "Gate Scanner",
    assetTag: "GATE-001",
    serialNumber: null,
    status: "available",
    hasOpenCheckout: false,
    archivedAt: null,
    updatedAt: FIXTURE_TIMESTAMP,
  },
];

const equipment = shallowRef<ProductEquipmentItem[]>(
  INITIAL_EQUIPMENT.map((item) => ({ ...item })),
);

export function equipmentStateLabel(status: EquipmentState): string {
  return EQUIPMENT_STATE_LABELS[status];
}

/**
 * Inventory setup is "department logistics/administration as permitted"
 * (UI contract 12.4 `department.equipment`): the department logistics
 * capability, or department administer authority.
 */
export function canManageEquipmentInventory(
  session: DepartmentSelfAdminSession | null,
): boolean {
  if (session === null) {
    return false;
  }

  if (canAdministerDepartment(session)) {
    return true;
  }

  const department = fixtureDepartmentById(session.departmentId);

  return department?.capabilities.hasLogistics === true;
}

export function listDepartmentEquipment(
  session: DepartmentSelfAdminSession | null,
  status: EquipmentStatusFilter = "all",
): ProductEquipmentItem[] {
  if (session === null || !canManageEquipmentInventory(session)) {
    return [];
  }

  return equipment.value
    .filter((item) => item.departmentId === session.departmentId)
    .filter((item) => {
      if (status === "active") {
        return item.archivedAt === null;
      }

      if (status === "archived") {
        return item.archivedAt !== null;
      }

      return true;
    })
    .slice()
    .sort((left, right) => left.name.localeCompare(right.name));
}

export function getEquipmentItem(
  session: DepartmentSelfAdminSession | null,
  equipmentItemId: string,
): ProductEquipmentItem | null {
  if (session === null || !canManageEquipmentInventory(session)) {
    return null;
  }

  return (
    equipment.value.find(
      (item) =>
        item.id === equipmentItemId &&
        item.departmentId === session.departmentId,
    ) ?? null
  );
}

/** Events the department may scope equipment to. */
export function listEquipmentEventOptions(
  session: DepartmentSelfAdminSession | null,
): { id: string; name: string }[] {
  if (session === null) {
    return [];
  }

  const department =
    fixtureDepartmentById(session.departmentId) ??
    selectedFixtureDepartment.value;

  return [{ id: department.eventId, name: department.eventLabel }];
}

export function createEquipmentItem(
  session: DepartmentSelfAdminSession | null,
  draft: EquipmentItemDraft,
): ProductEquipmentItem {
  const current = requireManager(session);
  const normalized = normalizeDraft(current, draft, null);

  const item: ProductEquipmentItem = {
    id: crypto.randomUUID(),
    departmentId: current.departmentId,
    eventId: normalized.eventId,
    name: normalized.name,
    assetTag: normalized.assetTag,
    serialNumber: normalized.serialNumber,
    // New inventory always starts Available (EQUIP-005).
    status: "available",
    hasOpenCheckout: false,
    archivedAt: null,
    updatedAt: new Date().toISOString(),
  };

  equipment.value = [...equipment.value, item];

  return item;
}

export function updateEquipmentItem(
  session: DepartmentSelfAdminSession | null,
  equipmentItemId: string,
  draft: EquipmentItemDraft,
): ProductEquipmentItem {
  const current = requireManager(session);
  const existing = requireItem(current, equipmentItemId);

  if (existing.archivedAt !== null) {
    throw new Error("Restore this equipment before editing it.");
  }

  const normalized = normalizeDraft(current, draft, existing.id);
  const status = draft.status ?? existing.status;

  // Checkout state belongs to the Logistics workflow. A name-only edit is
  // still allowed while an item is out, exactly as the server allows.
  if (status !== existing.status && existing.hasOpenCheckout) {
    throw new Error(
      "This equipment is checked out. Return it from the Logistics Window to change its state.",
    );
  }

  const updated: ProductEquipmentItem = {
    ...existing,
    name: normalized.name,
    assetTag: normalized.assetTag,
    serialNumber: normalized.serialNumber,
    eventId: normalized.eventId,
    status,
    updatedAt: new Date().toISOString(),
  };

  equipment.value = equipment.value.map((item) =>
    item.id === equipmentItemId ? updated : item,
  );

  return updated;
}

export function archiveEquipmentItem(
  session: DepartmentSelfAdminSession | null,
  equipmentItemId: string,
): ProductEquipmentItem {
  const current = requireManager(session);
  const existing = requireItem(current, equipmentItemId);

  if (existing.archivedAt !== null) {
    throw new Error("This equipment is already archived.");
  }

  if (existing.hasOpenCheckout) {
    throw new Error(
      "This equipment is checked out. Return it from the Logistics Window before archiving it.",
    );
  }

  return replace(equipmentItemId, {
    ...existing,
    archivedAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  });
}

export function restoreEquipmentItem(
  session: DepartmentSelfAdminSession | null,
  equipmentItemId: string,
): ProductEquipmentItem {
  const current = requireManager(session);
  const existing = requireItem(current, equipmentItemId);

  if (existing.archivedAt === null) {
    throw new Error("This equipment is not archived.");
  }

  return replace(equipmentItemId, {
    ...existing,
    archivedAt: null,
    updatedAt: new Date().toISOString(),
  });
}

/**
 * Bulk inventory import entry point (EQUIP-001). Expects a `name` header
 * column with optional `asset_tag` and `serial_number` columns; other columns
 * are ignored and each row is processed independently. Event scope comes from
 * the form, not the file.
 */
export function importEquipmentInventoryCsv(
  session: DepartmentSelfAdminSession | null,
  csvText: string,
  eventId: string | null,
): EquipmentImportResult {
  const current = requireManager(session);

  const lines = csvText.split(/\r\n|\r|\n/).map((line) => line.trim());

  if (lines.length === 0 || lines[0] === "") {
    throw new Error("The CSV is empty.");
  }

  const header = lines[0]!
    .split(",")
    .map((column) => column.trim().toLowerCase());
  const nameIndex = header.indexOf("name");

  if (nameIndex === -1) {
    throw new Error('The CSV must include a "name" header column.');
  }

  const assetTagIndex = header.indexOf("asset_tag");
  const serialNumberIndex = header.indexOf("serial_number");
  const rows: EquipmentImportRowResult[] = [];
  let imported = 0;

  for (let index = 1; index < lines.length; index++) {
    const line = lines[index]!;
    if (line === "") {
      continue;
    }

    const lineNumber = index + 1;
    const columns = line.split(",").map((column) => column.trim());
    const name = columns[nameIndex] ?? "";
    const assetTag =
      assetTagIndex === -1 ? "" : (columns[assetTagIndex] ?? "");

    if (name === "") {
      rows.push({
        line: lineNumber,
        name,
        assetTag: assetTag === "" ? null : assetTag,
        status: "skipped",
        reason: "Missing name.",
      });
      continue;
    }

    try {
      createEquipmentItem(current, {
        name,
        assetTag,
        serialNumber:
          serialNumberIndex === -1 ? "" : (columns[serialNumberIndex] ?? ""),
        eventId,
        status: null,
      });
    } catch (error) {
      rows.push({
        line: lineNumber,
        name,
        assetTag: assetTag === "" ? null : assetTag,
        status: "skipped",
        reason:
          error instanceof Error ? error.message : "Unable to import this row.",
      });
      continue;
    }

    imported++;
    rows.push({
      line: lineNumber,
      name,
      assetTag: assetTag === "" ? null : assetTag,
      status: "imported",
      reason: null,
    });
  }

  return { imported, skipped: rows.length - imported, rows };
}

export function resetEquipmentInventoryFixtures(): void {
  equipment.value = INITIAL_EQUIPMENT.map((item) => ({ ...item }));
}

function replace(
  equipmentItemId: string,
  next: ProductEquipmentItem,
): ProductEquipmentItem {
  equipment.value = equipment.value.map((item) =>
    item.id === equipmentItemId ? next : item,
  );

  return next;
}

function requireManager(
  session: DepartmentSelfAdminSession | null,
): DepartmentSelfAdminSession {
  if (session === null || !canManageEquipmentInventory(session)) {
    throw new Error(
      "You do not have permission to manage equipment inventory for this department.",
    );
  }

  return session;
}

function requireItem(
  session: DepartmentSelfAdminSession,
  equipmentItemId: string,
): ProductEquipmentItem {
  const item = getEquipmentItem(session, equipmentItemId);

  if (item === null) {
    throw new Error("Equipment not found.");
  }

  return item;
}

function normalizeDraft(
  session: DepartmentSelfAdminSession,
  draft: EquipmentItemDraft,
  ignoreItemId: string | null,
): {
  name: string;
  assetTag: string | null;
  serialNumber: string | null;
  eventId: string | null;
} {
  const name = draft.name.trim();

  if (name === "") {
    throw new Error("Equipment name is required.");
  }

  const assetTag = draft.assetTag.trim();
  const serialNumber = draft.serialNumber.trim();

  if (assetTag !== "") {
    const taken = equipment.value.some(
      (item) =>
        item.departmentId === session.departmentId &&
        item.assetTag === assetTag &&
        item.id !== ignoreItemId,
    );

    if (taken) {
      throw new Error(
        `Asset tag "${assetTag}" already exists in this department.`,
      );
    }
  }

  const eventOptions = listEquipmentEventOptions(session).map(
    (option) => option.id,
  );

  return {
    name,
    assetTag: assetTag === "" ? null : assetTag,
    serialNumber: serialNumber === "" ? null : serialNumber,
    eventId:
      draft.eventId !== null && eventOptions.includes(draft.eventId)
        ? draft.eventId
        : null,
  };
}
