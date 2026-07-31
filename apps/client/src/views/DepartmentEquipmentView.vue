<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import EquipmentInventorySection from "@/components/sections/EquipmentInventorySection.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import { selectedSessionDepartment } from "@/session/sessionAccess";
import {
  getDepartmentEquipment,
  type EquipmentInventory,
} from "@/equipment/equipmentInventoryModel";

/**
 * `department.equipment` page (M11.18; bound to the node in M16.17; UI contract
 * 12.4). Equipment inventory is set up here before operations; the Logistics
 * Window checks it out.
 *
 * The page owns the one read the surface renders from. The heading and the
 * inventory table are two views of one response rather than two requests that
 * could disagree about what the department owns, and the featureset below asks
 * for a re-read after every write it makes.
 */
const route = useRoute();
const departmentId = computed(() => String(route.params.departmentId ?? ""));

const inventory = ref<EquipmentInventory | null>(null);
const loadError = ref<string | null>(null);

/*
 * Authority is the node's answer on the read, not a capability the client
 * decided for itself (CLIENT-006). The endpoint refuses a caller who may not
 * manage this department's inventory, and that refusal is what the page shows.
 */
const canManage = computed(() => inventory.value?.access.canManage ?? false);

/*
 * The node's name for the department, falling back to the session's label only
 * until the read lands.
 */
const eyebrow = computed(
  () =>
    inventory.value?.departmentName ||
    selectedSessionDepartment.value?.departmentLabel ||
    "Department",
);

const lede = computed(() =>
  canManage.value
    ? "Create and maintain the equipment inventory the Logistics Window checks out and checks in."
    : "Equipment inventory is not available for your current department role.",
);

const logisticsRoute = computed(() => ({
  name: "events.departments.logistics",
  params: {
    eventId: String(route.params.eventId ?? ""),
    departmentId: departmentId.value,
  },
}));

/**
 * Read the department's equipment.
 *
 * A failed read clears the inventory rather than leaving the last one on
 * screen: a department whose equipment could not be read must not look like a
 * department with no equipment in it.
 */
async function loadInventory(): Promise<void> {
  if (departmentId.value === "") {
    inventory.value = null;

    return;
  }

  loadError.value = null;

  try {
    inventory.value = await getDepartmentEquipment(departmentId.value);
  } catch (error) {
    inventory.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load equipment. Check the connection to this node and try again.",
    );
  }
}

watch(departmentId, () => {
  void loadInventory();
});

void loadInventory();
</script>

<template>
  <DeptOpsShell
    class="dept-equipment"
    heading-id="dept-equipment-heading"
    title="Equipment"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <template #nav>
      <RouterLink v-if="canManage" :to="logisticsRoute">
        Back To Logistics
      </RouterLink>
      <RouterLink v-else :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <!--
      A refusal is the node's own sentence, and an unreachable node is stated
      rather than shown as a department with no equipment in it. Inventory setup
      is not offline-writable work (data/API 7.2), so there is nothing to queue.
    -->
    <p v-if="loadError" class="dept-equipment__error" role="alert">
      {{ loadError }}
      <button type="button" @click="loadInventory">Try again</button>
    </p>

    <EquipmentInventorySection
      v-else
      variant="page"
      :inventory="inventory"
      @reload="loadInventory"
    />
  </DeptOpsShell>
</template>

<style scoped>
.dept-equipment {
  width: var(--m-content-workflow);
  min-width: 0;
  display: grid;
  gap: var(--m-space-4);
}

.dept-equipment__error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-3);
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-status-danger, #cc792f);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-status-danger, #cc792f);
}

.dept-equipment__error button {
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
