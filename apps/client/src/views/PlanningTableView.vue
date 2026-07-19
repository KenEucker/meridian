<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import { LOCAL_PLANNING_TABLE } from "@/department-ops/fixtures";
import {
  formatTimestamp,
  lifecycleLabel,
} from "@/department-ops/labels";
import {
  assertNoStaffIdentities,
  capacityLabel,
  planningSummary,
} from "@/department-ops/planning";

assertNoStaffIdentities(LOCAL_PLANNING_TABLE);

const table = LOCAL_PLANNING_TABLE;
const summary = computed(() => planningSummary(table));
</script>

<template>
  <DeptOpsShell
    title="Planning Table"
    :eyebrow="table.context.departmentLabel"
    lede="Identity-free comparison of what was planned and how the department is tracking."
    :freshness="table.context.dataFreshnessLabel"
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back to Home</RouterLink>
    </template>

    <dl class="planning__context" aria-label="Planning context">
      <div>
        <dt>Event</dt>
        <dd>{{ table.context.eventLabel }}</dd>
      </div>
      <div>
        <dt>Department</dt>
        <dd>{{ table.context.departmentLabel }}</dd>
      </div>
      <div>
        <dt>Scope</dt>
        <dd>Department (all teams)</dd>
      </div>
    </dl>

    <dl class="planning__summary" aria-label="Planning summary">
      <div>
        <dt>Shift windows</dt>
        <dd>{{ summary.shiftCount }}</dd>
      </div>
      <div>
        <dt>Active now</dt>
        <dd>{{ summary.activeCount }}</dd>
      </div>
      <div>
        <dt>Under target</dt>
        <dd>{{ summary.underTargetCount }}</dd>
      </div>
    </dl>

    <section aria-labelledby="planning-table-heading">
      <h2 id="planning-table-heading">Plan versus actual</h2>
      <div class="planning__table-frame">
        <table>
          <thead>
            <tr>
              <th scope="col">Shift / team</th>
              <th scope="col">Lifecycle</th>
              <th scope="col">Capacity</th>
              <th scope="col">Signed up / assigned</th>
              <th scope="col">Checked in</th>
              <th scope="col">No-show</th>
              <th scope="col">Unscheduled</th>
              <th scope="col">Planned hours</th>
              <th scope="col">Actual hours</th>
              <th scope="col">Variance</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in table.rows" :key="row.shiftId">
              <th scope="row">
                <span>{{ row.title }}</span>
                <span class="planning__meta">
                  {{ row.teamLabel }} ·
                  {{ formatTimestamp(row.startsAt, table.context.timeZone) }}
                </span>
              </th>
              <td>{{ lifecycleLabel(row.lifecycle) }}</td>
              <td>{{ capacityLabel(row.capacity) }}</td>
              <td>{{ row.signedUpOrAssignedCount }}</td>
              <td>{{ row.checkedInCount }}</td>
              <td>{{ row.noShowCount }}</td>
              <td>{{ row.unscheduledCount }}</td>
              <td>{{ row.plannedHours }}</td>
              <td>{{ row.actualHours }}</td>
              <td>{{ row.varianceHours }}</td>
              <td>{{ row.statusLabel }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="planning__note" role="status">
        This table does not show individual staff identities, signup lists, or
        team-member lists.
      </p>
    </section>
  </DeptOpsShell>
</template>

<style scoped>
.planning__context,
.planning__summary {
  display: grid;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
}

.planning__context div,
.planning__summary div {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  padding: var(--m-space-3);
}

.planning__context dt,
.planning__summary dt {
  margin: 0 0 var(--m-space-1);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.planning__context dd,
.planning__summary dd {
  margin: 0;
  font-weight: 700;
}

.planning__table-frame {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

table {
  width: 100%;
  border-collapse: collapse;
  min-width: 56rem;
}

th,
td {
  padding: var(--m-space-3);
  text-align: left;
  border-bottom: 1px solid var(--m-border-default);
  vertical-align: top;
}

.planning__meta {
  display: block;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 400;
}

.planning__note {
  margin: var(--m-space-3) 0 0;
  color: var(--m-text-muted);
}

h2 {
  margin: 0 0 var(--m-space-3);
}

@media (min-width: 48rem) {
  .planning__context,
  .planning__summary {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
