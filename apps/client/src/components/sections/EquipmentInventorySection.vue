<script setup lang="ts">
import ControlBar from "@/components/ControlBar.vue";
import { computed, reactive, ref } from "vue";

import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  archiveEquipmentItem,
  createEquipmentItem,
  importEquipmentInventory,
  restoreEquipmentItem,
  updateEquipmentItem,
  type EquipmentImportResult,
  type EquipmentInventory,
  type EquipmentItemDraft,
  type ProductEquipmentItem,
} from "@/equipment/equipmentInventoryModel";

/**
 * Equipment inventory setup featureset (M11.18; bound to the node in M16.17;
 * EQUIP-001 through EQUIP-005, EQUIP-007; UI contract 12.4
 * `department.equipment`).
 *
 * Builds the department inventory that the Logistics Window checks out and
 * checks back in. It never performs checkout itself: items that are currently
 * out are shown as read-only state, naming who holds them, with a pointer back
 * to Logistics.
 *
 * The page above reads the department's equipment for its own heading and hands
 * the response down, so one read is behind everything shown here. Every write
 * goes to its command and then asks the page to re-read, rather than patching a
 * row in place: what the node stored is what the table shows next.
 */
const props = withDefaults(
  defineProps<{
    variant?: "page" | "section";
    inventory: EquipmentInventory | null;
  }>(),
  {
    variant: "page",
  },
);

const emit = defineEmits<{ reload: [] }>();

/*
 * Authority is the node's answer, carried on the response, rather than a
 * capability the client interpreted for itself. The commands behind these forms
 * enforce that same answer, so a form offered past it could only be refused
 * (CLIENT-006).
 */
const canManage = computed(() => props.inventory?.access.canManage ?? false);
const eventOptions = computed(() => props.inventory?.events ?? []);
const maintainableStates = computed(
  () => props.inventory?.maintainableStates ?? [],
);
const trackingKinds = computed(() => props.inventory?.trackingKinds ?? []);

/**
 * Whether the form is describing a pool rather than one physical unit
 * (EQUIP-010).
 *
 * The two kinds carry different fields, so the form shows different fields. A
 * pool has no asset tag or serial number to put on it and a tracked unit has no
 * quantity, and offering both to both would invite a maintainer to fill in
 * something the node then silently drops.
 */
const draftIsPooled = computed(() => draft.tracking === "pooled");

/** What the node did with one import row. */
function importRowLabel(status: string): string {
  switch (status) {
    case "imported":
      return "Imported";
    case "updated":
      return "Updated";
    default:
      return "Skipped";
  }
}

type EquipmentStatusFilter = "all" | "active" | "archived";

const statusFilter = ref<EquipmentStatusFilter>("active");

/**
 * The filter is a view of the one response, not a second request.
 *
 * `archived_at` is on every item the node sent, so what the filter hides is the
 * same record the node just described and the two cannot disagree.
 */
const items = computed<readonly ProductEquipmentItem[]>(() =>
  (props.inventory?.equipment ?? []).filter((item) => {
    if (statusFilter.value === "active") {
      return item.archivedAt === null;
    }

    if (statusFilter.value === "archived") {
      return item.archivedAt !== null;
    }

    return true;
  }),
);

const actionError = ref<string | null>(null);
const actionNotice = ref<string | null>(null);
const busy = ref(false);

const editingId = ref<string | null>(null);
const draft = reactive<EquipmentItemDraft>(emptyDraft());

const importCsv = ref("");
const importEventId = ref<string | null>(null);
const importResult = ref<EquipmentImportResult | null>(null);

const description =
  "Create the department equipment the Logistics Window checks out and checks in.";

function emptyDraft(): EquipmentItemDraft {
  return {
    name: "",
    tracking: "individual",
    assetTag: "",
    serialNumber: "",
    quantityTotal: "1",
    eventId: null,
    status: null,
  };
}

/*
 * The item being edited is looked up in the whole response rather than in the
 * filtered rows, so the form survives a filter change while it is open.
 */
const editingItem = computed(() =>
  editingId.value === null
    ? null
    : ((props.inventory?.equipment ?? []).find(
        (item) => item.id === editingId.value,
      ) ?? null),
);

/** An item that is out edits its details only; Logistics owns its state. */
const stateLocked = computed(() => editingItem.value?.openCheckout != null);

function resetDraft(): void {
  editingId.value = null;
  Object.assign(draft, emptyDraft());
}

/**
 * Send one command, then ask the page to re-read.
 *
 * A refusal is shown as the node worded it — a duplicate asset tag, a state
 * change on an item that is out — and nothing on the page moves. The fallback
 * is only for a request that never reached a node at all; inventory setup is
 * not offline-writable work (data/API 7.2), so it is reported rather than
 * queued.
 */
async function run(
  action: () => Promise<void>,
  notice: string,
  fallback: string,
): Promise<boolean> {
  actionError.value = null;
  actionNotice.value = null;
  busy.value = true;

  try {
    await action();
    emit("reload");
    actionNotice.value = notice;

    return true;
  } catch (error) {
    actionError.value = meridianErrorMessage(error, fallback);

    return false;
  } finally {
    busy.value = false;
  }
}

function onEdit(item: ProductEquipmentItem): void {
  actionError.value = null;
  actionNotice.value = null;
  editingId.value = item.id;
  Object.assign(draft, {
    name: item.name,
    tracking: item.tracking,
    assetTag: item.assetTag ?? "",
    serialNumber: item.serialNumber ?? "",
    quantityTotal: String(item.quantityTotal),
    eventId: item.eventId,
    // Checked out and Returned are Logistics states and are never among the
    // states the node offers here. A checked-out item sends no state at all,
    // which is what keeps its details editable while it is out.
    status: item.openCheckout != null ? null : maintainableStatus(item),
  });
}

/** The state to preselect: the item's own, when setup may still set it. */
function maintainableStatus(item: ProductEquipmentItem): string | null {
  return (
    maintainableStates.value.find((state) => state.value === item.status)
      ?.value ??
    maintainableStates.value[0]?.value ??
    null
  );
}

async function onSubmit(): Promise<void> {
  const inventory = props.inventory;

  if (inventory === null) {
    return;
  }

  const editing = editingId.value;
  const name = draft.name.trim();

  const saved =
    editing === null
      ? await run(
          () => createEquipmentItem(inventory.departmentId, { ...draft }),
          `${name} added to the inventory as Available.`,
          "Unable to add equipment. Check the connection to this node and try again.",
        )
      : await run(
          () => updateEquipmentItem(editing, { ...draft }),
          `${name} updated.`,
          "Unable to save equipment. Check the connection to this node and try again.",
        );

  if (saved) {
    resetDraft();
  }
}

async function onArchive(item: ProductEquipmentItem): Promise<void> {
  await run(
    () => archiveEquipmentItem(item.id),
    `${item.name} archived. Its checkout history is preserved.`,
    "Unable to archive equipment. Check the connection to this node and try again.",
  );
}

async function onRestore(item: ProductEquipmentItem): Promise<void> {
  await run(
    () => restoreEquipmentItem(item.id),
    `${item.name} restored to the active inventory.`,
    "Unable to restore equipment. Check the connection to this node and try again.",
  );
}

/**
 * Hand the spreadsheet to the node and report what it made of each row.
 *
 * The per-row results are kept even though the table re-reads, because they say
 * why a row was skipped and the inventory itself cannot.
 */
async function onImport(): Promise<void> {
  const inventory = props.inventory;

  if (inventory === null) {
    return;
  }

  actionError.value = null;
  actionNotice.value = null;
  importResult.value = null;
  busy.value = true;

  try {
    const result = await importEquipmentInventory(
      inventory.departmentId,
      importEventId.value,
      importCsv.value,
    );
    emit("reload");
    importResult.value = result;
    actionNotice.value = `Imported ${result.imported} item(s); updated ${result.updated}; skipped ${result.skipped}.`;
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to import inventory. Check the connection to this node and try again.",
    );
  } finally {
    busy.value = false;
  }
}

function eventLabel(item: ProductEquipmentItem): string {
  if (item.eventId === null) {
    return "Department stock";
  }

  return (
    eventOptions.value.find((option) => option.id === item.eventId)?.name ??
    "Event"
  );
}

/**
 * Who is holding an item that is out.
 *
 * The refusals on that row — no state change, no archive — are answered by
 * naming the person to ask, which a locked row on its own cannot do.
 */
function checkoutHolder(item: ProductEquipmentItem): string {
  const checkout = item.openCheckout;

  if (checkout === null) {
    return "";
  }

  const holder = checkout.staffName ?? "a staff member";

  return checkout.shiftTitle === null
    ? `Out with ${holder}. Return it in Logistics to change this.`
    : `Out with ${holder} for ${checkout.shiftTitle}. Return it in Logistics to change this.`;
}
</script>

<template>
  <WorkflowSection
    :variant="props.variant"
    title="Equipment"
    heading-id="equipment-section-heading"
    :description="description"
  >
    <p v-if="props.inventory === null" class="equipment__restricted" role="status">
      Loading this department's equipment from the node.
    </p>

    <p v-else-if="!canManage" class="equipment__restricted" role="status">
      Equipment inventory is available to department logistics and department
      administration for this department.
    </p>

    <template v-else>
      <p v-if="actionError" class="equipment__error" role="alert">
        {{ actionError }}
      </p>
      <p v-if="actionNotice" class="equipment__notice" role="status">
        {{ actionNotice }}
      </p>

      <form
        class="equipment__form"
        :aria-label="editingId === null ? 'Add equipment' : 'Edit equipment'"
        @submit.prevent="onSubmit"
      >
        <label>
          Name
          <input v-model="draft.name" type="text" required />
        </label>

        <label v-if="trackingKinds.length > 0">
          Tracking
          <select v-model="draft.tracking" aria-label="Equipment tracking kind">
            <option
              v-for="kind in trackingKinds"
              :key="kind.value"
              :value="kind.value"
            >
              {{ kind.label }}
            </option>
          </select>
        </label>

        <label v-if="!draftIsPooled">
          Asset tag
          <input v-model="draft.assetTag" type="text" />
        </label>

        <label v-if="!draftIsPooled">
          Serial number
          <input v-model="draft.serialNumber" type="text" />
        </label>

        <label v-if="draftIsPooled">
          Pool quantity
          <input v-model="draft.quantityTotal" type="number" min="0" />
        </label>

        <label>
          Scope
          <select v-model="draft.eventId">
            <option :value="null">Department stock</option>
            <option
              v-for="option in eventOptions"
              :key="option.id"
              :value="option.id"
            >
              {{ option.name }}
            </option>
          </select>
        </label>

        <label v-if="editingId !== null">
          State
          <select
            v-model="draft.status"
            aria-label="Equipment state"
            :disabled="stateLocked"
          >
            <option v-if="stateLocked" :value="null">
              {{ editingItem!.statusLabel }}
            </option>
            <option
              v-for="state in maintainableStates"
              :key="state.value"
              :value="state.value"
            >
              {{ state.label }}
            </option>
          </select>
        </label>

        <p v-if="stateLocked" class="equipment__hint">
          This equipment is checked out. Its details stay editable, but return it
          from the Logistics Window to change its state.
        </p>
        <!--
          A pool is one record standing for a quantity of interchangeable units
          (EQUIP-010), so it has no state of its own: losses are recorded when
          units come back, as an audited adjustment to the quantity (EQUIP-017).
        -->
        <p v-else-if="draftIsPooled" class="equipment__hint">
          A pooled kind is a quantity of interchangeable units and carries no
          asset tag. It never reads Checked out; what is available is the total
          less whatever is currently out. Missing or damaged units are recorded
          when they are returned, which adjusts the total.
        </p>
        <p v-else class="equipment__hint">
          New equipment starts Available. Checked out and Returned come from the
          Logistics Window.
        </p>

        <div class="equipment__form-actions">
          <button type="submit" :disabled="busy">
            {{ editingId === null ? "Add equipment" : "Save equipment" }}
          </button>
          <button v-if="editingId !== null" type="button" @click="resetDraft">
            Cancel
          </button>
        </div>
      </form>

      <ControlBar label="Equipment filters">
        <label class="equipment__filter">
          Show
          <select v-model="statusFilter" aria-label="Filter equipment by status">
            <option value="active">Active</option>
            <option value="archived">Archived</option>
            <option value="all">All</option>
          </select>
        </label>
      </ControlBar>

      <div class="equipment__table-wrap" role="region" aria-label="Equipment">
        <table class="equipment__table">
          <thead>
            <tr>
              <th scope="col">Equipment</th>
              <th scope="col">Tracking</th>
              <th scope="col">Asset tag</th>
              <th scope="col">Serial</th>
              <th scope="col">Scope</th>
              <th scope="col">State</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="items.length === 0">
              <td colspan="7">
                No equipment matches this filter. Add items or import a CSV to
                build the inventory before operations.
              </td>
            </tr>
            <tr v-for="item in items" :key="item.id">
              <td>
                <strong>{{ item.name }}</strong>
                <p v-if="item.archivedAt" class="equipment__muted">Archived</p>
              </td>
              <td>{{ item.trackingLabel }}</td>
              <td>{{ item.assetTag ?? "—" }}</td>
              <td>{{ item.serialNumber ?? "—" }}</td>
              <td>{{ eventLabel(item) }}</td>
              <td>
                <!--
                  A pool reads its availability rather than a state, because
                  "Available" on its own says nothing about whether there is
                  anything left in it (EQUIP-016; UI contract 9.6).
                -->
                <template v-if="item.tracking === 'pooled'">
                  {{ item.quantityAvailable }} of {{ item.quantityTotal }}
                  available
                </template>
                <template v-else>
                  {{ item.statusLabel }}
                  <p v-if="item.openCheckout" class="equipment__muted">
                    {{ checkoutHolder(item) }}
                  </p>
                </template>
              </td>
              <td class="equipment__actions">
                <button
                  v-if="item.archivedAt === null"
                  type="button"
                  @click="onEdit(item)"
                >
                  Edit
                </button>
                <button
                  v-if="item.archivedAt === null && !item.openCheckout"
                  type="button"
                  :disabled="busy"
                  @click="onArchive(item)"
                >
                  Archive
                </button>
                <button
                  v-if="item.archivedAt !== null"
                  type="button"
                  :disabled="busy"
                  @click="onRestore(item)"
                >
                  Restore
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <form
        class="equipment__import"
        aria-label="Import equipment inventory"
        @submit.prevent="onImport"
      >
        <h3>Bulk import</h3>
        <p class="equipment__hint">
          Paste spreadsheet CSV with a <code>name</code> header column, plus
          optional <code>tracking</code>, <code>asset_tag</code>,
          <code>serial_number</code>, and <code>quantity_total</code> columns.
          Rows are imported independently. A tracked row whose asset tag already
          exists is skipped rather than duplicated; a pooled row is matched by
          name and updated, so re-running a file adjusts a pool rather than
          adding a second one.
        </p>

        <label>
          Scope
          <select v-model="importEventId" aria-label="Import scope">
            <option :value="null">Department stock</option>
            <option
              v-for="option in eventOptions"
              :key="option.id"
              :value="option.id"
            >
              {{ option.name }}
            </option>
          </select>
        </label>

        <label>
          CSV
          <textarea
            v-model="importCsv"
            rows="4"
            aria-label="Equipment CSV"
            placeholder="name,asset_tag,serial_number"
          ></textarea>
        </label>

        <div class="equipment__form-actions">
          <button type="submit" :disabled="busy">Import inventory</button>
        </div>

        <table v-if="importResult" class="equipment__table">
          <caption>
            Import results
          </caption>
          <thead>
            <tr>
              <th scope="col">Line</th>
              <th scope="col">Name</th>
              <th scope="col">Result</th>
              <th scope="col">Reason</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in importResult.rows" :key="row.line">
              <td>{{ row.line }}</td>
              <td>{{ row.name || "—" }}</td>
              <td>{{ importRowLabel(row.status) }}</td>
              <td>{{ row.reason ?? "—" }}</td>
            </tr>
          </tbody>
        </table>
      </form>
    </template>
  </WorkflowSection>
</template>

<style scoped>
.equipment__restricted,
.equipment__error,
.equipment__notice {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.equipment__error {
  border-color: var(--m-status-danger, #cc792f);
  color: var(--m-status-danger, #cc792f);
}

.equipment__form,
.equipment__import {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.equipment__import h3 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
}

.equipment__form label,
.equipment__import label {
  display: grid;
  gap: var(--m-space-1);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.equipment__form input,
.equipment__form select,
.equipment__import select,
.equipment__import textarea {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
}

.equipment__hint,
.equipment__muted {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 400;
}

.equipment__muted {
  margin-top: var(--m-space-1);
}

.equipment__form-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.equipment__toolbar {
  display: flex;
  gap: var(--m-space-3);
}

.equipment__filter {
  display: grid;
  gap: var(--m-space-1);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.equipment__filter select {
  min-height: 2.5rem;
  padding: 0 var(--m-space-2);
}

.equipment__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.equipment__table {
  width: 100%;
  border-collapse: collapse;
}

.equipment__table caption {
  padding: var(--m-space-2) 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  text-align: left;
}

.equipment__table th,
.equipment__table td {
  padding: var(--m-space-3);
  border-bottom: 1px solid var(--m-border-subtle);
  text-align: left;
  vertical-align: top;
}

.equipment__table th {
  background: var(--m-surface-base);
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.equipment__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.equipment__actions button,
.equipment__form-actions button {
  min-height: 2.25rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 900;
  cursor: pointer;
}
</style>
