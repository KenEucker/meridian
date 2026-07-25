<script setup lang="ts">
import { computed, reactive, ref } from "vue";

import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import { resolveDepartmentSelfAdminSession } from "@/department-teams/teamAdminModel";
import {
  archiveEquipmentItem,
  canManageEquipmentInventory,
  createEquipmentItem,
  equipmentStateLabel,
  importEquipmentInventoryCsv,
  listDepartmentEquipment,
  listEquipmentEventOptions,
  MAINTAINABLE_EQUIPMENT_STATES,
  restoreEquipmentItem,
  updateEquipmentItem,
  type EquipmentImportResult,
  type EquipmentItemDraft,
  type EquipmentStatusFilter,
  type MaintainableEquipmentState,
  type ProductEquipmentItem,
} from "@/equipment/equipmentInventoryModel";

/**
 * Equipment inventory setup featureset (M11.18; EQUIP-001 through EQUIP-005,
 * EQUIP-007; UI contract 12.4 `department.equipment`).
 *
 * Builds the department inventory that the Logistics Window checks out and
 * checks back in. It never performs checkout itself: items that are currently
 * out are shown as read-only state with a pointer back to Logistics.
 */
const props = withDefaults(
  defineProps<{
    variant?: "page" | "section";
  }>(),
  {
    variant: "page",
  },
);

const session = computed(() => resolveDepartmentSelfAdminSession());
const canManage = computed(() => canManageEquipmentInventory(session.value));

const statusFilter = ref<EquipmentStatusFilter>("active");
const refreshKey = ref(0);

const items = computed(() => {
  void refreshKey.value;

  return listDepartmentEquipment(session.value, statusFilter.value);
});

const eventOptions = computed(() => listEquipmentEventOptions(session.value));

const actionError = ref<string | null>(null);
const actionNotice = ref<string | null>(null);

const editingId = ref<string | null>(null);
const draft = reactive<EquipmentItemDraft>(emptyDraft());

const importCsv = ref("");
const importEventId = ref<string | null>(null);
const importResult = ref<EquipmentImportResult | null>(null);

const description =
  "Create the department equipment the Logistics Window checks out and checks in.";

defineExpose({ canManage, items, description });

function emptyDraft(): EquipmentItemDraft {
  return {
    name: "",
    assetTag: "",
    serialNumber: "",
    eventId: null,
    status: null,
  };
}

const editingItem = computed(() =>
  editingId.value === null
    ? null
    : (items.value.find((item) => item.id === editingId.value) ?? null),
);

/** An item that is out edits its details only; Logistics owns its state. */
const stateLocked = computed(() => editingItem.value?.hasOpenCheckout === true);

function resetDraft(): void {
  editingId.value = null;
  Object.assign(draft, emptyDraft());
}

function run(action: () => void, notice: string): void {
  actionError.value = null;
  actionNotice.value = null;

  try {
    action();
    actionNotice.value = notice;
    refreshKey.value++;
  } catch (error) {
    actionError.value =
      error instanceof Error ? error.message : "Unable to update equipment.";
  }
}

function onEdit(item: ProductEquipmentItem): void {
  actionError.value = null;
  actionNotice.value = null;
  editingId.value = item.id;
  Object.assign(draft, {
    name: item.name,
    assetTag: item.assetTag ?? "",
    serialNumber: item.serialNumber ?? "",
    eventId: item.eventId,
    // Checked out and Returned are Logistics states and cannot be chosen here.
    // A checked-out item leaves its state alone so details stay editable.
    status: item.hasOpenCheckout ? null : maintainableStatus(item),
  });
}

function maintainableStatus(
  item: ProductEquipmentItem,
): MaintainableEquipmentState {
  return (
    MAINTAINABLE_EQUIPMENT_STATES.find((state) => state === item.status) ??
    "available"
  );
}

function onSubmit(): void {
  const editing = editingId.value;

  if (editing === null) {
    const name = draft.name.trim();
    run(
      () => createEquipmentItem(session.value, { ...draft }),
      `${name} added to the inventory as Available.`,
    );
  } else {
    const name = draft.name.trim();
    run(
      () => updateEquipmentItem(session.value, editing, { ...draft }),
      `${name} updated.`,
    );
  }

  if (actionError.value === null) {
    resetDraft();
  }
}

function onArchive(item: ProductEquipmentItem): void {
  run(
    () => archiveEquipmentItem(session.value, item.id),
    `${item.name} archived. Its checkout history is preserved.`,
  );
}

function onRestore(item: ProductEquipmentItem): void {
  run(
    () => restoreEquipmentItem(session.value, item.id),
    `${item.name} restored to the active inventory.`,
  );
}

function onImport(): void {
  actionError.value = null;
  actionNotice.value = null;
  importResult.value = null;

  try {
    const result = importEquipmentInventoryCsv(
      session.value,
      importCsv.value,
      importEventId.value,
    );
    importResult.value = result;
    actionNotice.value = `Imported ${result.imported} item(s); skipped ${result.skipped}.`;
    refreshKey.value++;
  } catch (error) {
    actionError.value =
      error instanceof Error ? error.message : "Unable to import inventory.";
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
</script>

<template>
  <WorkflowSection
    :variant="props.variant"
    title="Equipment"
    heading-id="equipment-section-heading"
    :description="description"
  >
    <p v-if="!canManage" class="equipment__restricted" role="status">
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

        <label>
          Asset tag
          <input v-model="draft.assetTag" type="text" />
        </label>

        <label>
          Serial number
          <input v-model="draft.serialNumber" type="text" />
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
              {{ equipmentStateLabel(editingItem!.status) }}
            </option>
            <option
              v-for="state in MAINTAINABLE_EQUIPMENT_STATES"
              :key="state"
              :value="state"
            >
              {{ equipmentStateLabel(state) }}
            </option>
          </select>
        </label>

        <p v-if="stateLocked" class="equipment__hint">
          This equipment is checked out. Its details stay editable, but return it
          from the Logistics Window to change its state.
        </p>
        <p v-else class="equipment__hint">
          New equipment starts Available. Checked out and Returned come from the
          Logistics Window.
        </p>

        <div class="equipment__form-actions">
          <button type="submit">
            {{ editingId === null ? "Add equipment" : "Save equipment" }}
          </button>
          <button v-if="editingId !== null" type="button" @click="resetDraft">
            Cancel
          </button>
        </div>
      </form>

      <div class="equipment__toolbar">
        <label class="equipment__filter">
          Show
          <select v-model="statusFilter" aria-label="Filter equipment by status">
            <option value="active">Active</option>
            <option value="archived">Archived</option>
            <option value="all">All</option>
          </select>
        </label>
      </div>

      <div class="equipment__table-wrap" role="region" aria-label="Equipment">
        <table class="equipment__table">
          <thead>
            <tr>
              <th scope="col">Equipment</th>
              <th scope="col">Asset tag</th>
              <th scope="col">Serial</th>
              <th scope="col">Scope</th>
              <th scope="col">State</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="items.length === 0">
              <td colspan="6">
                No equipment matches this filter. Add items or import a CSV to
                build the inventory before operations.
              </td>
            </tr>
            <tr v-for="item in items" :key="item.id">
              <td>
                <strong>{{ item.name }}</strong>
                <p v-if="item.archivedAt" class="equipment__muted">Archived</p>
              </td>
              <td>{{ item.assetTag ?? "—" }}</td>
              <td>{{ item.serialNumber ?? "—" }}</td>
              <td>{{ eventLabel(item) }}</td>
              <td>
                {{ equipmentStateLabel(item.status) }}
                <p v-if="item.hasOpenCheckout" class="equipment__muted">
                  Return it in Logistics to change this.
                </p>
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
                  v-if="item.archivedAt === null && !item.hasOpenCheckout"
                  type="button"
                  @click="onArchive(item)"
                >
                  Archive
                </button>
                <button
                  v-if="item.archivedAt !== null"
                  type="button"
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
          optional <code>asset_tag</code> and <code>serial_number</code>
          columns. Rows are imported independently, and rows whose asset tag
          already exists are skipped rather than duplicated.
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
          <button type="submit">Import inventory</button>
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
              <td>{{ row.status === "imported" ? "Imported" : "Skipped" }}</td>
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
