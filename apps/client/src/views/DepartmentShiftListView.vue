<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";
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
const eyebrow = computed(
  () => department.value?.name ?? session.value?.departmentLabel ?? "Department",
);

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
  <!--
    Members read their schedule here, usually on a phone, so they get the
    narrow touch-first staff shell. Shift administration keeps the wide
    workflow shell, where the comparison table earns its width.
  -->
  <StaffPageShell
    v-if="!canManage"
    heading-id="dept-shifts-heading"
    title="Shifts"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <ShiftListSection variant="page" />
  </StaffPageShell>

  <DeptOpsShell
    v-else
    class="dept-shifts"
    heading-id="dept-shifts-heading"
    title="Shifts"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <template #nav>
      <RouterLink :to="planningRoute">Back To Planning</RouterLink>
    </template>

    <ShiftListSection variant="page" />
  </DeptOpsShell>
</template>

<style scoped>
.dept-shifts {
  width: var(--m-content-workflow);
  min-width: 0;
  display: grid;
  gap: var(--m-space-4);
}
</style>
