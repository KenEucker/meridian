<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import ShiftListSection from "@/components/sections/ShiftListSection.vue";
import {
  canAdministerDepartment,
  getCurrentDepartment,
  resolveDepartmentSelfAdminSession,
} from "@/department-teams/teamAdminModel";
import {
  canViewMemberShifts,
  canViewShiftAdmin,
} from "@/shift-admin/shiftAdminModel";

const session = computed(() => resolveDepartmentSelfAdminSession());
const canManage = computed(() => canViewShiftAdmin(session.value));
const canView = computed(
  () => canManage.value || canViewMemberShifts(session.value),
);
const canAdminister = computed(() => canAdministerDepartment(session.value));
const department = computed(() => getCurrentDepartment(session.value));

const lede = computed(() => {
  if (!canView.value) {
    return "Shifts are not available for your current department role.";
  }

  if (!canManage.value) {
    return "Shifts your teams are eligible for.";
  }

  return canAdminister.value
    ? "Create and maintain department shifts with eligibility and time-window rules."
    : "Create and maintain shifts for teams you lead.";
});

const planningRoute = computed(() => ({
  name: "events.departments.planning",
  params: {
    eventId: session.value?.eventId,
    departmentId: session.value?.departmentId,
  },
}));
</script>

<template>
  <DeptOpsShell
    class="dept-shifts"
    heading-id="dept-shifts-heading"
    title="Shifts"
    :eyebrow="department?.name ?? session?.departmentLabel ?? 'Department'"
    :lede="lede"
  >
    <template #nav>
      <RouterLink v-if="canManage" :to="planningRoute">
        Back To Planning
      </RouterLink>
      <RouterLink v-else :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <ShiftListSection variant="page" />
  </DeptOpsShell>
</template>

<style scoped>
.dept-shifts {
  width: min(100%, 76rem);
  min-width: 0;
  display: grid;
  gap: var(--m-space-4);
}
</style>
