<script setup lang="ts">
import ContentGrid from "@/components/ContentGrid.vue";
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import ShiftSelector from "@/components/department-ops/ShiftSelector.vue";
import WorkflowHeadingCard from "@/components/WorkflowHeadingCard.vue";
import WorkflowHeadingCardGrid from "@/components/WorkflowHeadingCardGrid.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  attendanceStateLabel,
  formatTimestamp,
} from "@/department-ops/labels";
import {
  checkedInAssignments,
  deploymentLabel,
  getDepartmentOverview,
  overviewSummary,
  type DepartmentOverviewRead,
} from "@/department-ops/departmentOpsReadModel";

/**
 * Department Overview (SLB-001, SLB-002; bound to the node in M16.21).
 *
 * One read fills the page, and selecting a shift is another one rather than a
 * filter over what is already here: the exceptions, the checked-in list, and the
 * counts are all answers about the selected shift, so they come from the node
 * together and cannot disagree with each other.
 */
const route = useRoute();
const eventId = computed(() => String(route.params.eventId ?? ""));
const departmentId = computed(() => String(route.params.departmentId ?? ""));

const overview = ref<DepartmentOverviewRead | null>(null);
const loadError = ref<string | null>(null);

const shift = computed(
  () =>
    overview.value?.shifts.find(
      (candidate) => candidate.shiftId === overview.value?.selectedShiftId,
    ) ?? null,
);
const summary = computed(() =>
  overview.value === null
    ? {
        assignmentCount: 0,
        checkedInCount: 0,
        onSiteCount: 0,
        equipmentOutCount: 0,
      }
    : overviewSummary(overview.value),
);
const checkedIn = computed(() =>
  overview.value === null ? [] : checkedInAssignments(overview.value),
);
const routeParams = computed(() => ({
  eventId: eventId.value,
  departmentId: departmentId.value,
}));
const departmentLabel = computed(
  () => overview.value?.context.departmentLabel ?? "Department",
);
const timeZone = computed(() => overview.value?.context.timeZone ?? "UTC");

/**
 * Read the overview for a shift, or for whichever one the node picks.
 *
 * A failed read clears the page rather than leaving the last shift on screen: a
 * department whose overview could not be read must not look like a department
 * with nothing happening in it.
 */
async function loadOverview(shiftId: string | null = null): Promise<void> {
  if (eventId.value === "" || departmentId.value === "") {
    overview.value = null;

    return;
  }

  loadError.value = null;

  try {
    overview.value = await getDepartmentOverview(
      eventId.value,
      departmentId.value,
      shiftId,
    );
  } catch (error) {
    overview.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load this department's overview. Check the connection to this node and try again.",
    );
  }
}

watch([eventId, departmentId], () => {
  void loadOverview();
});

void loadOverview();
</script>

<template>
  <DeptOpsShell
    title="Department Overview"
    :eyebrow="departmentLabel"
    lede="Lead situational awareness for the selected shift."
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <template #navigation>
      <RouterLink
        :to="{ name: 'events.departments.logistics', params: routeParams }"
      >
        Open Logistics Window
      </RouterLink>
      <RouterLink
        :to="{ name: 'events.departments.operations', params: routeParams }"
      >
        Open Operations Center
      </RouterLink>
      <RouterLink
        :to="{ name: 'events.departments.planning', params: routeParams }"
      >
        Open Planning Table
      </RouterLink>
    </template>

    <template #heading-cards>
      <WorkflowHeadingCardGrid>
        <WorkflowHeadingCard
          label="Shift assignments"
          :value="String(summary.assignmentCount)"
        />
        <WorkflowHeadingCard
          label="Checked in"
          :value="String(summary.checkedInCount)"
        />
        <WorkflowHeadingCard
          label="On-site"
          :value="String(summary.onSiteCount)"
        />
        <WorkflowHeadingCard
          label="Equipment out"
          :value="String(summary.equipmentOutCount)"
        />
      </WorkflowHeadingCardGrid>
    </template>

    <!--
      A refusal is the node's own sentence, and an unreachable node is stated
      rather than shown as a quiet shift. Nothing on this page is a write, so
      there is nothing to queue.
    -->
    <p v-if="loadError" class="overview__error" role="alert">
      {{ loadError }}
      <button type="button" @click="loadOverview()">Try again</button>
    </p>

    <template v-else-if="overview">
      <ShiftSelector
        v-if="overview.selectedShiftId"
        :shifts="overview.shifts"
        :model-value="overview.selectedShiftId"
        :time-zone="timeZone"
        @update:model-value="(shiftId: string) => loadOverview(shiftId)"
      />
      <p v-else class="overview__window" role="status">
        No shifts are scheduled for this department in the current window.
      </p>

      <p v-if="shift" class="overview__window" aria-label="Selected shift window">
        {{ formatTimestamp(shift.startsAt, timeZone) }} -
        {{ formatTimestamp(shift.endsAt, timeZone) }}
      </p>

      <!--
        Overview keeps its documented content order — exceptions, then working
        staff, then assignments, then summaries — while reading left to right and
        top to bottom instead of straight down. A lead scanning for an exception
        should not have to scroll past it to see who is on shift.
      -->
      <ContentGrid min="region" :stretch="false">
      <section aria-labelledby="exceptions-heading" class="overview__section">
        <h2 id="exceptions-heading">Exceptions needing attention</h2>
        <p v-if="overview.exceptions.length === 0" role="status">
          No exceptions for this shift.
        </p>
        <ul v-else class="overview__exceptions">
          <li
            v-for="item in overview.exceptions"
            :key="item.id"
            :data-severity="item.severity"
          >
            <strong>{{ item.label }}</strong>
            <span>{{ item.detail }}</span>
          </li>
        </ul>
      </section>

      <section aria-labelledby="checked-in-heading" class="overview__section">
        <h2 id="checked-in-heading">Checked-in staff currently working</h2>
        <p v-if="checkedIn.length === 0" role="status">
          No staff are checked in for this shift.
        </p>
        <ul v-else class="overview__list">
          <li v-for="member in checkedIn" :key="member.assignmentId">
            <span>{{ member.displayName }}</span>
            <span>
              {{ attendanceStateLabel(member.attendanceState) }} /
              {{ deploymentLabel(overview.deployments, member.currentDeploymentId) }}
            </span>
          </li>
        </ul>
      </section>

      <section aria-labelledby="assignments-heading" class="overview__section">
        <h2 id="assignments-heading">Shift assignments</h2>
        <div class="overview__table-frame">
          <table>
            <thead>
              <tr>
                <th scope="col">Staff</th>
                <th scope="col">Team</th>
                <th scope="col">Attendance</th>
                <th scope="col">Deployment</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="member in overview.assignments"
                :key="member.assignmentId"
              >
                <th scope="row">{{ member.displayName }}</th>
                <td>{{ member.teamLabel }}</td>
                <td>{{ attendanceStateLabel(member.attendanceState) }}</td>
                <td>
                  {{ deploymentLabel(overview.deployments, member.currentDeploymentId) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section aria-labelledby="equipment-heading" class="overview__section">
        <h2 id="equipment-heading">Equipment out</h2>
        <p v-if="overview.equipmentOut.length === 0" role="status">
          No equipment is checked out.
        </p>
        <ul v-else class="overview__list">
          <li
            v-for="item in overview.equipmentOut"
            :key="item.checkoutId"
          >
            <span>
              {{ item.itemName }}
              <template v-if="item.assetTag">({{ item.assetTag }})</template>
            </span>
            <span>with {{ item.staffName }}</span>
          </li>
        </ul>
      </section>
      </ContentGrid>
    </template>
  </DeptOpsShell>
</template>

<style scoped>
.overview__window {
  margin: 0 0 var(--m-space-5);
  color: var(--m-text-muted);
}

.overview__error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
  padding: var(--m-space-3);
  border: 1px solid var(--m-status-danger, #cc792f);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-status-danger, #cc792f);
}

.overview__section {
  margin: 0 0 var(--m-space-5);
}

.overview__section h2 {
  margin: 0 0 var(--m-space-3);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  letter-spacing: 0;
  text-transform: uppercase;
}

.overview__exceptions,
.overview__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: var(--m-space-2);
}

.overview__exceptions li,
.overview__list li {
  display: grid;
  gap: 0.25rem;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.overview__exceptions li[data-severity="warning"] {
  border-color: color-mix(
    in srgb,
    var(--m-status-warning) 55%,
    var(--m-border-default)
  );
}

.overview__table-frame {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.overview__table-frame table {
  width: 100%;
  border-collapse: collapse;
}

.overview__table-frame th,
.overview__table-frame td {
  padding: var(--m-space-3);
  text-align: left;
  border-bottom: 1px solid var(--m-border-default);
}

.overview__table-frame thead th {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  text-transform: uppercase;
}

.overview__table-frame tbody th {
  color: var(--m-text-primary);
}

@media (min-width: 48rem) {
}
</style>
