<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import StaffCardList from "@/components/StaffCardList.vue";
import StaffListCard from "@/components/StaffListCard.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import {
  canAdministerDepartment,
  resolveDepartmentSelfAdminSession,
} from "@/department-teams/teamAdminModel";
import {
  cancelShift,
  canViewMemberShifts,
  canViewShiftAdmin,
  listDepartmentShifts,
  listMemberShifts,
  listSchedulableTeams,
  memberTeamLabels,
  restoreShift,
  shiftHasStarted,
  type ProductShift,
  type ShiftStatusFilter,
} from "@/shift-admin/shiftAdminModel";

/**
 * Shift create/maintain featureset (M11.17; SHIFT-001 through SHIFT-010).
 *
 * Rendered as its own page at `department.shifts` and embedded as a featureset
 * in the Planning workflow hub. Department administer authority manages every
 * department shift, designated team leads manage shifts for teams they lead,
 * and plain members get a read-only list of shifts their teams are eligible for.
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
const canManage = computed(() => canViewShiftAdmin(session.value));
const canViewAsMember = computed(() => canViewMemberShifts(session.value));
const canView = computed(() => canManage.value || canViewAsMember.value);
const canAdminister = computed(() => canAdministerDepartment(session.value));
const route = useRoute();
const router = useRouter();

const statusFilter = computed<ShiftStatusFilter>(() => {
  const value = route.query.status;
  if (value === "active" || value === "cancelled") {
    return value;
  }

  return "all";
});

const refresh = ref(0);
const shifts = computed(() => {
  void refresh.value;

  return canManage.value
    ? listDepartmentShifts(session.value, statusFilter.value)
    : listMemberShifts(session.value);
});
const teams = computed(() => listSchedulableTeams(session.value));
const teamNameById = computed(() =>
  canManage.value
    ? new Map(teams.value.map((team) => [team.id, team.name]))
    : memberTeamLabels(session.value),
);

const actionError = ref<string | null>(null);
const busyId = ref<string | null>(null);

const description = computed(() => {
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

defineExpose({ canView, canManage, description });

function onStatusChange(event: Event): void {
  const target = event.target as HTMLSelectElement;
  const next = target.value;

  void router.replace({
    ...route,
    query: next === "all" ? {} : { status: next },
  });
}

function shiftStatus(shift: ProductShift): string {
  if (shift.cancelledAt !== null) {
    return "Cancelled";
  }

  return shiftHasStarted(shift) ? "Started" : "Scheduled";
}

function teamName(shift: ProductShift): string {
  return teamNameById.value.get(shift.eligibleTeamId) ?? "Unknown team";
}

function formatWindow(shift: ProductShift): string {
  const start = new Date(shift.startsAt);
  const end = new Date(shift.endsAt);

  return `${start.toLocaleString()} - ${end.toLocaleString()}`;
}

function formatCapacity(shift: ProductShift): string {
  if (shift.capacity === null) {
    return `${shift.activeAssignmentCount} assigned / no cap`;
  }

  return `${shift.activeAssignmentCount} assigned / ${shift.capacity} cap`;
}

function onCancel(shift: ProductShift): void {
  actionError.value = null;
  busyId.value = shift.id;

  try {
    cancelShift(session.value, shift.id);
    refresh.value += 1;
  } catch (error) {
    actionError.value =
      error instanceof Error ? error.message : "Unable to cancel shift.";
  } finally {
    busyId.value = null;
  }
}

function onRestore(shift: ProductShift): void {
  actionError.value = null;
  busyId.value = shift.id;

  try {
    restoreShift(session.value, shift.id);
    refresh.value += 1;
  } catch (error) {
    actionError.value =
      error instanceof Error ? error.message : "Unable to restore shift.";
  } finally {
    busyId.value = null;
  }
}

function shiftEditRoute(shiftId: string) {
  return {
    name: "events.departments.shifts.edit",
    params: {
      eventId: session.value?.eventId,
      departmentId: session.value?.departmentId,
      shiftId,
    },
  };
}

const shiftCreateRoute = computed(() => ({
  name: "events.departments.shifts.create",
  params: {
    eventId: session.value?.eventId,
    departmentId: session.value?.departmentId,
  },
}));
</script>

<template>
  <WorkflowSection
    :variant="props.variant"
    title="Shifts"
    heading-id="shifts-section-heading"
    :description="description"
  >
    <template v-if="canManage && teams.length > 0" #actions>
      <WorkflowActionButton :to="shiftCreateRoute">
        Create shift
      </WorkflowActionButton>
    </template>

    <p v-if="!canView" class="dept-shifts__restricted" role="status">
      Shifts require department membership, or department lead or team lead
      authority for the selected department.
    </p>

    <!--
      Members read this on a phone to find out when they work. A six-column
      admin table scrolls its own headers off screen there, so the read-only
      view is a card per shift with every field labelled.
    -->
    <StaffCardList
      v-else-if="!canManage"
      label="Shifts"
      :empty="shifts.length === 0"
      empty-message="No shifts are scheduled for your teams."
    >
      <StaffListCard
        v-for="shift in shifts"
        :key="shift.id"
        :title="shift.title"
        :eyebrow="teamName(shift)"
        :status="shiftStatus(shift)"
        :meta="[
          { label: 'When', value: formatWindow(shift) },
          { label: 'Capacity', value: formatCapacity(shift) },
        ]"
      />
    </StaffCardList>

    <template v-else>
      <div class="dept-shifts__toolbar">
        <label class="dept-shifts__filter">
          Status
          <select
            :value="statusFilter"
            aria-label="Filter shifts by status"
            @change="onStatusChange"
          >
            <option value="all">All</option>
            <option value="active">Active</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </label>
      </div>

      <p v-if="actionError" class="dept-shifts__error" role="alert">
        {{ actionError }}
      </p>

      <div class="dept-shifts__table-wrap" role="region" aria-label="Shifts">
        <table class="dept-shifts__table">
          <thead>
            <tr>
              <th scope="col">Shift</th>
              <th scope="col">Team</th>
              <th scope="col">Schedule</th>
              <th scope="col">Capacity</th>
              <th scope="col">Status</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="shifts.length === 0">
              <td colspan="6">No shifts match this filter.</td>
            </tr>
            <tr v-for="shift in shifts" :key="shift.id">
              <td>
                <RouterLink :to="shiftEditRoute(shift.id)">
                  {{ shift.title }}
                </RouterLink>
              </td>
              <td>{{ teamName(shift) }}</td>
              <td>{{ formatWindow(shift) }}</td>
              <td>{{ formatCapacity(shift) }}</td>
              <td>{{ shiftStatus(shift) }}</td>
              <td class="dept-shifts__actions">
                <RouterLink :to="shiftEditRoute(shift.id)">Edit</RouterLink>
                <button
                  v-if="shift.cancelledAt === null && !shiftHasStarted(shift)"
                  type="button"
                  class="dept-shifts__cancel"
                  :disabled="busyId === shift.id"
                  @click="onCancel(shift)"
                >
                  Cancel shift
                </button>
                <button
                  v-else-if="shift.cancelledAt !== null"
                  type="button"
                  :disabled="busyId === shift.id"
                  @click="onRestore(shift)"
                >
                  Restore
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </WorkflowSection>
</template>

<style scoped>
.dept-shifts__restricted,
.dept-shifts__error {
  margin: 0;
  padding: var(--m-space-3);
  border-radius: var(--m-radius-sm);
  border: 1px solid var(--m-border-default);
}

.dept-shifts__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.dept-shifts__toolbar {
  display: flex;
  gap: var(--m-space-3);
}

.dept-shifts__filter {
  display: grid;
  gap: var(--m-space-1);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.dept-shifts__filter select {
  min-height: 2.5rem;
  padding: 0 var(--m-space-2);
}

.dept-shifts__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.dept-shifts__table {
  width: 100%;
  border-collapse: collapse;
}

.dept-shifts__table th,
.dept-shifts__table td {
  padding: var(--m-space-3);
  border-bottom: 1px solid var(--m-border-default);
  text-align: left;
  vertical-align: top;
}

.dept-shifts__table th {
  background: var(--m-surface-raised);
  font-size: var(--m-text-sm);
}

.dept-shifts__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-2);
}

.dept-shifts__actions a,
.dept-shifts__actions button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-height: 2.25rem;
  padding: 0 var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  line-height: 1;
  text-decoration: none;
  cursor: pointer;
}

.dept-shifts__actions button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.dept-shifts__actions .dept-shifts__cancel {
  border-color: var(--m-action-destructive-bg);
  background: var(--m-action-destructive-bg);
  color: var(--m-action-destructive-text);
}
</style>
