<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import ShiftSelector from "@/components/department-ops/ShiftSelector.vue";
import { LOCAL_DEPARTMENT_OVERVIEW } from "@/department-ops/fixtures";
import {
  attendanceStateLabel,
  formatTimestamp,
} from "@/department-ops/labels";
import {
  checkedInAssignments,
  deploymentLabel,
  overviewSummary,
  selectOverviewShift,
  selectedShift,
} from "@/department-ops/overview";

const route = useRoute();
const overview = ref(LOCAL_DEPARTMENT_OVERVIEW);
const shift = computed(() => selectedShift(overview.value));
const summary = computed(() => overviewSummary(overview.value));
const checkedIn = computed(() => checkedInAssignments(overview.value));

const routeParams = computed(() => ({
  eventId: String(route.params.eventId ?? overview.value.context.eventId),
  departmentId: String(
    route.params.departmentId ?? overview.value.context.departmentId,
  ),
}));

function onShiftChange(shiftId: string): void {
  overview.value = selectOverviewShift(overview.value, shiftId);
}
</script>

<template>
  <DeptOpsShell
    title="Department Overview"
    :eyebrow="overview.context.departmentLabel"
    lede="Lead situational awareness for the selected shift."
    :freshness="overview.context.dataFreshnessLabel"
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back to Home</RouterLink>
    </template>

    <dl class="overview__context" aria-label="Department context">
      <div>
        <dt>Event</dt>
        <dd>{{ overview.context.eventLabel }}</dd>
      </div>
      <div>
        <dt>Department</dt>
        <dd>{{ overview.context.departmentLabel }}</dd>
      </div>
      <div>
        <dt>Scope</dt>
        <dd>
          {{
            overview.context.selectedTeamLabel ?? "Department (all teams)"
          }}
        </dd>
      </div>
    </dl>

    <ShiftSelector
      :shifts="overview.shifts"
      :model-value="overview.selectedShiftId"
      :time-zone="overview.context.timeZone"
      @update:model-value="onShiftChange"
    />

    <p v-if="shift" class="overview__window" aria-label="Selected shift window">
      {{ formatTimestamp(shift.startsAt, overview.context.timeZone) }} -
      {{ formatTimestamp(shift.endsAt, overview.context.timeZone) }}
    </p>

    <dl class="overview__summary" aria-label="Overview summary">
      <div>
        <dt>Shift assignments</dt>
        <dd>{{ summary.assignmentCount }}</dd>
      </div>
      <div>
        <dt>Checked in</dt>
        <dd>{{ summary.checkedInCount }}</dd>
      </div>
      <div>
        <dt>On-site</dt>
        <dd>{{ summary.onSiteCount }}</dd>
      </div>
      <div>
        <dt>Equipment out</dt>
        <dd>{{ summary.equipmentOutCount }}</dd>
      </div>
    </dl>

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
            {{ attendanceStateLabel(member.attendanceState) }} ·
            {{ deploymentLabel(overview, member.currentDeploymentId) }}
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
                {{ deploymentLabel(overview, member.currentDeploymentId) }}
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

    <nav class="overview__drill" aria-label="Drill-through workflows">
      <RouterLink
        :to="{
          name: overview.drillThrough.logisticsRouteName,
          params: routeParams,
        }"
      >
        Open Logistics Desk
      </RouterLink>
      <RouterLink
        :to="{
          name: overview.drillThrough.operationsRouteName,
          params: routeParams,
        }"
      >
        Open Operations Center
      </RouterLink>
      <RouterLink
        :to="{
          name: overview.drillThrough.planningRouteName,
          params: routeParams,
        }"
      >
        Open Planning Table
      </RouterLink>
    </nav>
  </DeptOpsShell>
</template>

<style scoped>
.overview__context,
.overview__summary {
  display: grid;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
  grid-template-columns: minmax(0, 1fr);
}

.overview__context div,
.overview__summary div {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  padding: var(--m-space-3);
}

.overview__context dt,
.overview__summary dt {
  margin: 0 0 var(--m-space-1);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.overview__context dd,
.overview__summary dd {
  margin: 0;
  font-weight: 700;
}

.overview__window {
  margin: 0 0 var(--m-space-5);
  color: var(--m-text-muted);
}

.overview__section {
  margin: 0 0 var(--m-space-5);
}

.overview__section h2 {
  margin: 0 0 var(--m-space-3);
  font-size: var(--m-text-lg);
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
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.overview__exceptions li[data-severity="warning"] {
  border-color: var(--m-text-primary);
}

.overview__table-frame {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
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

.overview__drill {
  display: grid;
  gap: var(--m-space-2);
}

.overview__drill a {
  display: block;
  min-height: 2.75rem;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font-weight: 700;
  text-decoration: none;
}

.overview__drill a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 48rem) {
  .overview__context,
  .overview__summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .overview__drill {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
