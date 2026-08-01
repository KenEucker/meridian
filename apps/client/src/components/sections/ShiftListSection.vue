<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import StaffCardList from "@/components/StaffCardList.vue";
import StaffListCard from "@/components/StaffListCard.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import ControlBar from "@/components/ControlBar.vue";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  cancelShift,
  getDepartmentShifts,
  restoreShift,
  shiftCapacityLabel,
  shiftStatusLabel,
  shiftTeamLabel,
  shiftWindowLabel,
  type ProductShift,
  type ShiftStatusFilter,
  type ShiftWorkspace,
} from "@/shift-admin/shiftAdminModel";

/**
 * Shift create/maintain featureset (M11.17; bound to the node in M16.18;
 * SHIFT-001 through SHIFT-010).
 *
 * Rendered as its own page at `department.shifts` and embedded as a featureset
 * in the Planning workflow hub.
 *
 * The page above it already reads the department's shifts for its shell and
 * heading, so it hands that response down through `workspace` and re-reads on
 * `reload`; embedded on its own the section makes the read itself. Either way
 * one read is behind what is shown rather than two that could disagree about
 * which shifts exist.
 *
 * Who sees what is the node's answer, carried on that response. Department
 * administer authority manages every department shift, a designated team lead
 * manages the shifts of teams they lead, and a member reads the shifts their
 * teams are eligible for and manages none — and the read already narrowed the
 * list accordingly, so nothing here re-derives it.
 */
const props = withDefaults(
  defineProps<{
    variant?: "page" | "section";
    departmentId?: string | null;
    workspace?: ShiftWorkspace | null;
  }>(),
  {
    variant: "page",
    departmentId: null,
    workspace: undefined,
  },
);

const emit = defineEmits<{ reload: [] }>();

const route = useRoute();
const router = useRouter();

const departmentId = computed(
  () =>
    props.departmentId ??
    (typeof route.params.departmentId === "string"
      ? route.params.departmentId
      : ""),
);

/** Whether this section is responsible for its own read. */
const ownsRead = computed(() => props.workspace === undefined);

const ownWorkspace = ref<ShiftWorkspace | null>(null);
const workspace = computed<ShiftWorkspace | null>(() =>
  ownsRead.value ? ownWorkspace.value : (props.workspace ?? null),
);

const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const busyId = ref<string | null>(null);

const statusFilter = computed<ShiftStatusFilter>(() => {
  const value = route.query.status;

  return value === "active" || value === "cancelled" ? value : "all";
});

/*
 * Authority is the node's answer on the response rather than a role the client
 * interpreted for itself. The commands behind these actions enforce the same
 * answer, so an action offered past it could only be refused (CLIENT-006).
 */
const canAccess = computed(() => workspace.value !== null);
const canManage = computed(() => workspace.value?.access.canManage ?? false);
const canAdminister = computed(
  () => workspace.value?.access.canAdminister ?? false,
);
const shifts = computed<readonly ProductShift[]>(
  () => workspace.value?.shifts ?? [],
);
const teams = computed(() => workspace.value?.teams ?? []);

const description = computed(() => {
  if (!canAccess.value) {
    return "Department shift schedule.";
  }

  if (!canManage.value) {
    return "Shifts your teams are eligible for.";
  }

  return canAdminister.value
    ? "Create and maintain department shifts with eligibility and time-window rules."
    : "Create and maintain shifts for teams you lead.";
});

const routeParams = computed(() => ({
  eventId: String(route.params.eventId ?? ""),
  departmentId: departmentId.value,
}));

const shiftCreateRoute = computed(() => ({
  name: "events.departments.shifts.create",
  params: routeParams.value,
}));

function shiftEditRoute(shiftId: string) {
  return {
    name: "events.departments.shifts.edit",
    params: { ...routeParams.value, shiftId },
  };
}

async function loadOwnWorkspace(): Promise<void> {
  if (!ownsRead.value) {
    return;
  }

  if (departmentId.value === "") {
    ownWorkspace.value = null;

    return;
  }

  loadError.value = null;

  try {
    ownWorkspace.value = await getDepartmentShifts(
      departmentId.value,
      statusFilter.value,
    );
  } catch (error) {
    ownWorkspace.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load shifts. Check the connection to this node and try again.",
    );
  }
}

watch([departmentId, statusFilter], () => {
  actionError.value = null;
  void loadOwnWorkspace();
});

void loadOwnWorkspace();

defineExpose({ canAccess, canManage, description });

/**
 * Move the status filter into the URL.
 *
 * The filter is the read's, not the rendered list's: the page or this section
 * watches the query and asks the node again, so the table is always the answer
 * to the filter that is showing.
 */
function onStatusChange(event: Event): void {
  const next = (event.target as HTMLSelectElement).value;

  void router.replace({
    ...route,
    query: next === "all" ? {} : { status: next },
  });
}

/**
 * Run one command and then take the surface's state from the node again.
 *
 * Nothing is patched in place: a cancellation that changes which actions a row
 * offers, and a refusal that changes nothing at all, are both read back rather
 * than guessed at.
 */
async function run(
  shift: ProductShift,
  action: () => Promise<void>,
  fallback: string,
): Promise<void> {
  actionError.value = null;
  busyId.value = shift.id;

  try {
    await action();

    if (ownsRead.value) {
      await loadOwnWorkspace();
    } else {
      emit("reload");
    }
  } catch (error) {
    actionError.value = meridianErrorMessage(error, fallback);
  } finally {
    busyId.value = null;
  }
}

async function onCancel(shift: ProductShift): Promise<void> {
  await run(shift, () => cancelShift(shift.id), "Unable to cancel shift.");
}

async function onRestore(shift: ProductShift): Promise<void> {
  await run(shift, () => restoreShift(shift.id), "Unable to restore shift.");
}
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

    <!--
      A refusal is the node's own sentence, not a second copy of its rules. The
      403 this endpoint answers with when the caller holds neither lead
      authority nor team membership already says so, and saying it here keeps
      one account of who may see this page (CLIENT-006). An unreachable node
      reads the same way rather than as a department with no shifts in it.
    -->
    <p v-if="loadError" class="dept-shifts__restricted" role="alert">
      {{ loadError }}
    </p>

    <p v-else-if="!canAccess" class="dept-shifts__restricted" role="status">
      Loading shifts…
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
        :eyebrow="shiftTeamLabel(shift)"
        :status="shiftStatusLabel(shift)"
        :meta="[
          { label: 'When', value: shiftWindowLabel(shift) },
          { label: 'Capacity', value: shiftCapacityLabel(shift) },
        ]"
      />
    </StaffCardList>

    <template v-else>
      <ControlBar label="Shift filters">
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
      </ControlBar>

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
                <RouterLink v-if="shift.canManage" :to="shiftEditRoute(shift.id)">
                  {{ shift.title }}
                </RouterLink>
                <span v-else>{{ shift.title }}</span>
              </td>
              <td>{{ shiftTeamLabel(shift) }}</td>
              <td>{{ shiftWindowLabel(shift) }}</td>
              <td>{{ shiftCapacityLabel(shift) }}</td>
              <td>{{ shiftStatusLabel(shift) }}</td>
              <!--
                Cancel is offered only before the start the node reported, and
                restore only on a shift it reported cancelled. Both refusals
                exist on the server either way; not offering them keeps the row
                from proposing work that can only come back as an error.
              -->
              <td class="dept-shifts__actions">
                <template v-if="shift.canManage">
                  <RouterLink :to="shiftEditRoute(shift.id)">Edit</RouterLink>
                  <button
                    v-if="shift.cancelledAt === null && !shift.hasStarted"
                    type="button"
                    class="dept-shifts__cancel"
                    :disabled="busyId === shift.id"
                    @click="onCancel(shift)"
                  >
                    Cancel shift
                  </button>
                  <button
                    v-else-if="shift.cancelledAt !== null && !shift.hasStarted"
                    type="button"
                    :disabled="busyId === shift.id"
                    @click="onRestore(shift)"
                  >
                    Restore
                  </button>
                </template>
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
