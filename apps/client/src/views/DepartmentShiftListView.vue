<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";
import ShiftListSection from "@/components/sections/ShiftListSection.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import { selectedSessionDepartment } from "@/session/sessionAccess";
import {
  getDepartmentShifts,
  type ShiftStatusFilter,
  type ShiftWorkspace,
} from "@/shift-admin/shiftAdminModel";

/**
 * `department.shifts` page (M11.17; bound to the node in M16.18; UI contract
 * 12.4).
 *
 * The page owns the one read the surface renders from. Which shell it wears,
 * what its lede says, and what the featureset below lists are three views of one
 * response rather than three answers that could disagree, and the featureset
 * asks for a re-read after every write it makes.
 */
const route = useRoute();
const departmentId = computed(() => String(route.params.departmentId ?? ""));

/** The status filter the featureset writes into the URL, read back for the request. */
const statusFilter = computed<ShiftStatusFilter>(() => {
  const value = route.query.status;

  return value === "active" || value === "cancelled" ? value : "all";
});

const workspace = ref<ShiftWorkspace | null>(null);
const loadError = ref<string | null>(null);

/*
 * Authority is the node's answer, carried on the response, rather than a role
 * the client interpreted for itself (CLIENT-006). Until the read lands the page
 * shows the member shell: an unanswered question about authority is not a lead.
 */
const canManage = computed(() => workspace.value?.access.canManage ?? false);
const canAdminister = computed(
  () => workspace.value?.access.canAdminister ?? false,
);

/*
 * The node's name for the department, falling back to the session's label only
 * until the read lands.
 */
const eyebrow = computed(
  () =>
    workspace.value?.departmentName ||
    selectedSessionDepartment.value?.departmentLabel ||
    "Department",
);

const lede = computed(() => {
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
    eventId: String(route.params.eventId ?? ""),
    departmentId: departmentId.value,
  },
}));

/**
 * Read the department's shifts.
 *
 * A failed read clears the workspace rather than leaving the last one on screen:
 * a department whose shifts could not be read must not look like a department
 * with nothing scheduled.
 */
async function loadWorkspace(): Promise<void> {
  if (departmentId.value === "") {
    workspace.value = null;

    return;
  }

  loadError.value = null;

  try {
    workspace.value = await getDepartmentShifts(
      departmentId.value,
      statusFilter.value,
    );
  } catch (error) {
    workspace.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load shifts. Check the connection to this node and try again.",
    );
  }
}

watch([departmentId, statusFilter], () => {
  void loadWorkspace();
});

void loadWorkspace();
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
    <!--
      A refusal is the node's own sentence, and an unreachable node is stated
      rather than shown as a department with no shifts in it (data/API 7.2).
    -->
    <p v-if="loadError" class="dept-shifts-page__error" role="alert">
      {{ loadError }}
      <button type="button" @click="loadWorkspace">Try again</button>
    </p>

    <ShiftListSection
      v-else
      variant="page"
      :workspace="workspace"
      @reload="loadWorkspace"
    />
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

    <ShiftListSection
      variant="page"
      :workspace="workspace"
      @reload="loadWorkspace"
    />
  </DeptOpsShell>
</template>

<style scoped>
.dept-shifts {
  width: var(--m-content-workflow);
  min-width: 0;
  display: grid;
  gap: var(--m-space-4);
}

.dept-shifts-page__error {
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

.dept-shifts-page__error button {
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
