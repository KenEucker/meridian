// The department equipment inventory surface's data layer (M16.17;
// CLIENT-023, EQUIP-001 through EQUIP-005, EQUIP-007; data/API 10.13).
//
// Until this task this module was the inventory. Six items were compiled into
// the client, a `shallowRef` held them, and the rules the server enforces in
// `EquipmentInventoryService` were written out a second time in the browser:
// asset-tag uniqueness, "new equipment starts Available", the refusal to change
// state or archive while an item is out, and a CSV parser. Authority was read
// off a fixture department's capability flags. None of it reached a node, so an
// item it accepted was not inventory and a duplicate tag it refused was not the
// node's refusal — and the second copy of each rule could only drift from the
// first.
//
// This module is now a translation of the endpoints in data/API 10.13. Four
// choices in it are deliberate:
//
//  1. **One read per surface.** `GET /api/departments/{id}/equipment` answers
//     with the department, the caller's authority over it, the events the
//     inventory may be scoped to, the states setup may set, and every item with
//     its state label and its open checkout. The page renders off that one
//     response.
//  2. **Authority comes from the response.** `access.can_manage` is the node's
//     own answer and it is the same answer the commands enforce. The old
//     `canManageEquipmentInventory` predicate over fixture capability flags is
//     gone; all it could do was disagree with the server (CLIENT-006).
//  3. **No cache and no client-side rules.** Nothing is held between calls and
//     every write is followed by a re-read. Asset-tag uniqueness, the
//     maintainable state set, the checked-out lock, and CSV parsing are the
//     server's to decide, and its refusals are shown as it worded them.
//  4. **States are strings the node labels.** The client no longer carries the
//     five-state union or a label table for it. `status_label` on each item and
//     the maintainable state list on the response say what to render, so
//     UI contract 9.6's canonical labels have one source (EQUIP-005).
//
// This is a connected-only surface. Inventory setup is not in the closed set of
// offline-writable work (data/API 7.2), so a request made with no node
// reachable fails and says so rather than queueing.

import { meridianCachedJson, meridianJson } from "@/api/meridianApi";

/**
 * The checkout holding an item, when Logistics has one open.
 *
 * Setup cannot change the state of an item that is out, or archive it, and the
 * person who can hand it back is the one holding it — so the row names them
 * rather than reporting only that it is locked. `shiftId` tells a
 * shift-assigned checkout from an event-assigned one (EQUIP-009).
 */
export interface EquipmentOpenCheckout {
  readonly id: string;
  readonly staffId: string;
  readonly staffName: string | null;
  readonly checkedOutAt: string | null;
  readonly shiftId: string | null;
  readonly shiftTitle: string | null;
}

export interface ProductEquipmentItem {
  readonly id: string;
  readonly departmentId: string | null;
  readonly eventId: string | null;
  readonly name: string;
  readonly assetTag: string | null;
  readonly serialNumber: string | null;
  readonly status: string;
  /** The canonical label for `status`, as the node names it (UI contract 9.6). */
  readonly statusLabel: string;
  readonly openCheckout: EquipmentOpenCheckout | null;
  readonly archivedAt: string | null;
  readonly updatedAt: string | null;
}

/** One state inventory setup may set, with the label to show for it. */
export interface EquipmentStateOption {
  readonly value: string;
  readonly label: string;
}

/** An event in this department's organization the inventory may be scoped to. */
export interface EquipmentEventOption {
  readonly id: string;
  readonly name: string;
}

/**
 * The whole `department.equipment` surface in one response.
 *
 * `maintainableStates` is the Available/Missing/Damaged subset of EQUIP-005;
 * Checked out and Returned are produced by the Logistics commands and are
 * therefore never offered here. That subset is the node's answer, not a list
 * the client keeps.
 */
export interface EquipmentInventory {
  readonly departmentId: string;
  readonly departmentName: string;
  readonly access: { readonly canManage: boolean };
  readonly events: readonly EquipmentEventOption[];
  readonly maintainableStates: readonly EquipmentStateOption[];
  readonly equipment: readonly ProductEquipmentItem[];
}

/** The equipment form, as edited. */
export interface EquipmentItemDraft {
  name: string;
  assetTag: string;
  serialNumber: string;
  eventId: string | null;
  /** `null` leaves the current state alone, matching the optional server field. */
  status: string | null;
}

export interface EquipmentImportRowResult {
  readonly line: number;
  readonly name: string;
  readonly assetTag: string | null;
  readonly status: string;
  readonly reason: string | null;
}

export interface EquipmentImportResult {
  readonly imported: number;
  readonly skipped: number;
  readonly rows: readonly EquipmentImportRowResult[];
}

interface OpenCheckoutPayload {
  readonly id: string;
  readonly staff_id: string;
  readonly staff_name: string | null;
  readonly checked_out_at: string | null;
  readonly shift_id: string | null;
  readonly shift_title: string | null;
}

interface EquipmentItemPayload {
  readonly id: string;
  readonly department_id: string | null;
  readonly event_id: string | null;
  readonly name: string;
  readonly asset_tag: string | null;
  readonly serial_number: string | null;
  readonly status: string;
  readonly status_label?: string;
  readonly open_checkout?: OpenCheckoutPayload | null;
  readonly archived_at: string | null;
  readonly updated_at?: string | null;
}

interface EquipmentIndexPayload {
  readonly department?: { readonly id: string; readonly name: string };
  readonly access?: { readonly can_manage?: boolean };
  readonly events?: { readonly id: string; readonly name: string }[];
  readonly maintainable_statuses?: string[];
  readonly status_labels?: Record<string, string>;
  readonly equipment?: EquipmentItemPayload[];
}

interface EquipmentImportPayload {
  readonly imported?: number;
  readonly skipped?: number;
  readonly rows?: {
    readonly line: number;
    readonly name: string;
    readonly asset_tag: string | null;
    readonly status: string;
    readonly reason: string | null;
  }[];
}

function toItem(
  payload: EquipmentItemPayload,
  statusLabels: Record<string, string>,
): ProductEquipmentItem {
  return {
    id: payload.id,
    departmentId: payload.department_id,
    eventId: payload.event_id,
    name: payload.name,
    assetTag: payload.asset_tag,
    serialNumber: payload.serial_number,
    status: payload.status,
    statusLabel:
      payload.status_label ?? statusLabels[payload.status] ?? payload.status,
    openCheckout: payload.open_checkout
      ? {
          id: payload.open_checkout.id,
          staffId: payload.open_checkout.staff_id,
          staffName: payload.open_checkout.staff_name,
          checkedOutAt: payload.open_checkout.checked_out_at,
          shiftId: payload.open_checkout.shift_id,
          shiftTitle: payload.open_checkout.shift_title,
        }
      : null,
    archivedAt: payload.archived_at,
    updatedAt: payload.updated_at ?? null,
  };
}

/**
 * The submitted form, trimmed.
 *
 * The server trims too, so this changes nothing it stores. It changes what a
 * whitespace-only entry does: sent as typed it passes `required` and comes back
 * as a domain refusal, which reads oddly next to a field that visibly has
 * something in it.
 */
function toAttributes(draft: EquipmentItemDraft): Record<string, unknown> {
  return {
    name: draft.name.trim(),
    asset_tag: emptyToNull(draft.assetTag),
    serial_number: emptyToNull(draft.serialNumber),
    event_id: draft.eventId,
  };
}

function emptyToNull(value: string): string | null {
  const trimmed = value.trim();

  return trimmed === "" ? null : trimmed;
}

/**
 * Read the whole equipment surface for one department.
 *
 * Archived equipment arrives with the rest and is marked, so the page's Active
 * filter is a view of this one response rather than a second request. What the
 * filter hides is the same record the node just described.
 */
export async function getDepartmentEquipment(
  departmentId: string,
): Promise<EquipmentInventory> {
  const result = (await meridianCachedJson<EquipmentIndexPayload>(
    `/api/departments/${departmentId}/equipment`,
  )).data;

  const statusLabels = result.status_labels ?? {};

  return {
    departmentId: result.department?.id ?? departmentId,
    departmentName: result.department?.name ?? "",
    access: { canManage: result.access?.can_manage ?? false },
    events: result.events ?? [],
    maintainableStates: (result.maintainable_statuses ?? []).map((status) => ({
      value: status,
      label: statusLabels[status] ?? status,
    })),
    equipment: (result.equipment ?? []).map((item) =>
      toItem(item, statusLabels),
    ),
  };
}

/**
 * Add one item to the department's inventory.
 *
 * No state is sent. New equipment is always created Available (EQUIP-005), and
 * that is the server's rule rather than a default this form fills in.
 */
export async function createEquipmentItem(
  departmentId: string,
  draft: EquipmentItemDraft,
): Promise<void> {
  await meridianJson("/api/commands/create-equipment-item", {
    method: "POST",
    body: JSON.stringify({
      department_id: departmentId,
      ...toAttributes(draft),
    }),
  });
}

/**
 * Save an item's details, and its state when the form offered one.
 *
 * `status` is left out for an item Logistics holds, which is what lets a
 * name-only edit through while the checkout is open; sending the state back
 * would be refused.
 */
export async function updateEquipmentItem(
  equipmentItemId: string,
  draft: EquipmentItemDraft,
): Promise<void> {
  await meridianJson("/api/commands/update-equipment-item", {
    method: "POST",
    body: JSON.stringify({
      equipment_item_id: equipmentItemId,
      ...toAttributes(draft),
      ...(draft.status === null ? {} : { status: draft.status }),
    }),
  });
}

export async function archiveEquipmentItem(
  equipmentItemId: string,
): Promise<void> {
  await meridianJson("/api/commands/archive-equipment-item", {
    method: "POST",
    body: JSON.stringify({ equipment_item_id: equipmentItemId }),
  });
}

export async function restoreEquipmentItem(
  equipmentItemId: string,
): Promise<void> {
  await meridianJson("/api/commands/restore-equipment-item", {
    method: "POST",
    body: JSON.stringify({ equipment_item_id: equipmentItemId }),
  });
}

/**
 * Hand an inventory spreadsheet to the node (EQUIP-001).
 *
 * The CSV is sent as typed. Which column is the name, which row named nothing,
 * and which asset tag was already taken are the server's findings, and its
 * per-row results are what the screen reports. Event scope comes from the form
 * rather than from the file.
 */
export async function importEquipmentInventory(
  departmentId: string,
  eventId: string | null,
  csv: string,
): Promise<EquipmentImportResult> {
  const result = await meridianJson<EquipmentImportPayload>(
    "/api/commands/import-equipment-inventory",
    {
      method: "POST",
      body: JSON.stringify({
        department_id: departmentId,
        event_id: eventId,
        csv,
      }),
    },
  );

  return {
    imported: result.imported ?? 0,
    skipped: result.skipped ?? 0,
    rows: (result.rows ?? []).map((row) => ({
      line: row.line,
      name: row.name,
      assetTag: row.asset_tag,
      status: row.status,
      reason: row.reason,
    })),
  };
}
