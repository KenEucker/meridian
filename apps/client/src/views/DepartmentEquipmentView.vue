<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import EquipmentInventorySection from "@/components/sections/EquipmentInventorySection.vue";
import {
  getCurrentDepartment,
  resolveDepartmentSelfAdminSession,
} from "@/department-teams/teamAdminModel";
import { canManageEquipmentInventory } from "@/equipment/equipmentInventoryModel";

/**
 * `department.equipment` page (M11.18; UI contract 12.4). Equipment inventory
 * is set up here before operations; the Logistics Window checks it out.
 */
const session = computed(() => resolveDepartmentSelfAdminSession());
const canManage = computed(() => canManageEquipmentInventory(session.value));
const department = computed(() => getCurrentDepartment(session.value));

const lede = computed(() =>
  canManage.value
    ? "Create and maintain the equipment inventory the Logistics Window checks out and checks in."
    : "Equipment inventory is not available for your current department role.",
);

const logisticsRoute = computed(() => ({
  name: "events.departments.logistics",
  params: {
    eventId: session.value?.eventId,
    departmentId: session.value?.departmentId,
  },
}));
</script>

<template>
  <DeptOpsShell
    class="dept-equipment"
    heading-id="dept-equipment-heading"
    title="Equipment"
    :eyebrow="department?.name ?? session?.departmentLabel ?? 'Department'"
    :lede="lede"
  >
    <template #nav>
      <RouterLink v-if="canManage" :to="logisticsRoute">
        Back To Logistics
      </RouterLink>
      <RouterLink v-else :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <EquipmentInventorySection variant="page" />
  </DeptOpsShell>
</template>

<style scoped>
.dept-equipment {
  width: min(100%, 76rem);
  min-width: 0;
  display: grid;
  gap: var(--m-space-4);
}
</style>
