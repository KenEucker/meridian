<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import { LOCAL_OPERATIONS_CENTER } from "@/department-ops/fixtures";
import {
  assignOperationsDeployment,
  availableOperationsModules,
  composeOperationsModules,
  deploymentName,
} from "@/department-ops/operations";

const center = ref(LOCAL_OPERATIONS_CENTER);
const selectedAssignmentId = ref(
  center.value.deploymentRows[0]?.assignmentId ?? "",
);
const selectedDeploymentId = ref(
  center.value.deploymentOptions[0]?.deploymentId ?? "",
);
const status = ref<string | null>(null);

const availableModules = computed(() =>
  availableOperationsModules(center.value),
);

const modules = computed(() =>
  composeOperationsModules(
    center.value.capabilities,
    center.value.modules.find((module) => module.id === "equipment")?.available
      ? 1
      : 0,
  ),
);

function moveDeployment(): void {
  try {
    const before = center.value.deploymentRows.find(
      (row) => row.assignmentId === selectedAssignmentId.value,
    );
    center.value = assignOperationsDeployment(
      center.value,
      selectedAssignmentId.value,
      selectedDeploymentId.value,
    );
    const after = center.value.deploymentRows.find(
      (row) => row.assignmentId === selectedAssignmentId.value,
    );
    status.value = `${before?.displayName ?? "Staff"} moved to ${deploymentName(
      center.value,
      after?.currentDeploymentId ?? null,
    )}.`;
  } catch (error) {
    status.value =
      error instanceof Error ? error.message : "Unable to move deployment.";
  }
}
</script>

<template>
  <DeptOpsShell
    title="Operations Center"
    :eyebrow="center.context.departmentLabel"
    lede="High-level operational picture composed from capabilities you already hold."
    :freshness="center.context.dataFreshnessLabel"
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back to Home</RouterLink>
    </template>

    <section aria-labelledby="modules-heading" class="ops__section">
      <h2 id="modules-heading">Capability modules</h2>
      <ul class="ops__modules">
        <li v-for="module in modules" :key="module.id">
          <div>
            <strong>{{ module.title }}</strong>
            <span>{{ module.summary }}</span>
          </div>
          <p v-if="!module.available" role="status">
            {{ module.unavailableReason }}
          </p>
          <p v-else role="status">Available</p>
        </li>
      </ul>
    </section>

    <section
      v-if="availableModules.some((module) => module.id === 'deployments')"
      aria-labelledby="deployments-heading"
      class="ops__section"
    >
      <h2 id="deployments-heading">Deployments</h2>
      <form class="ops__form" aria-label="Move current deployment" @submit.prevent="moveDeployment">
        <label>
          <span>Staff on shift</span>
          <select v-model="selectedAssignmentId">
            <option
              v-for="row in center.deploymentRows"
              :key="row.assignmentId"
              :value="row.assignmentId"
            >
              {{ row.displayName }} ·
              {{ deploymentName(center, row.currentDeploymentId) }}
            </option>
          </select>
        </label>
        <label>
          <span>Deployment</span>
          <select v-model="selectedDeploymentId">
            <option
              v-for="option in center.deploymentOptions"
              :key="option.deploymentId"
              :value="option.deploymentId"
            >
              {{ option.name }}
            </option>
          </select>
        </label>
        <button type="submit">Move deployment</button>
      </form>
      <p v-if="status" class="ops__status" role="status">{{ status }}</p>

      <div class="ops__table-frame">
        <table>
          <thead>
            <tr>
              <th scope="col">Staff</th>
              <th scope="col">Shift</th>
              <th scope="col">Current deployment</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in center.deploymentRows"
              :key="row.assignmentId"
            >
              <th scope="row">{{ row.displayName }}</th>
              <td>{{ row.shiftTitle }}</td>
              <td>{{ deploymentName(center, row.currentDeploymentId) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section
      v-if="availableModules.some((module) => module.id === 'incidents')"
      aria-labelledby="incidents-heading"
      class="ops__section"
    >
      <h2 id="incidents-heading">Incidents</h2>
      <p role="status">
        Incident overview will appear here for IC-authorized operators.
      </p>
    </section>

    <section
      v-if="availableModules.some((module) => module.id === 'equipment')"
      aria-labelledby="equipment-overview-heading"
      class="ops__section"
    >
      <h2 id="equipment-overview-heading">Equipment overview</h2>
      <p role="status">
        Compact equipment readiness for this department. Staff-level handoff
        remains on the Logistics Desk.
      </p>
    </section>
  </DeptOpsShell>
</template>

<style scoped>
.ops__section {
  margin: 0 0 var(--m-space-5);
}

.ops__section h2 {
  margin: 0 0 var(--m-space-3);
}

.ops__modules {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: var(--m-space-2);
}

.ops__modules li {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ops__modules span,
.ops__modules p,
.ops__status {
  margin: 0;
  color: var(--m-text-muted);
}

.ops__form {
  display: grid;
  gap: var(--m-space-3);
  margin-bottom: var(--m-space-4);
}

.ops__form label {
  display: grid;
  gap: var(--m-space-2);
  font-weight: 700;
}

.ops__form select,
.ops__form button {
  min-height: 2.75rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
}

.ops__form button {
  font-weight: 700;
  cursor: pointer;
}

.ops__form select:focus-visible,
.ops__form button:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ops__table-frame {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.ops__table-frame table {
  width: 100%;
  border-collapse: collapse;
}

.ops__table-frame th,
.ops__table-frame td {
  padding: var(--m-space-3);
  text-align: left;
  border-bottom: 1px solid var(--m-border-default);
}

@media (min-width: 48rem) {
  .ops__form {
    grid-template-columns: repeat(2, minmax(0, 1fr)) auto;
    align-items: end;
  }
}
</style>
