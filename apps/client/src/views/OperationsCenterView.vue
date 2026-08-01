<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import { LOCAL_OPERATIONS_CENTER } from "@/department-ops/fixtures";
import {
  assignOperationsDeployment,
  availableOperationsModules,
  composeOperationsModules,
  deploymentName,
} from "@/department-ops/operations";
import {
  getEventFieldReports,
  getEventIncidents,
  incidentAccess,
  incidentSessionContext,
} from "@/ims/incidentReadModel";

/**
 * The Operations Center's IMS modules (SLB-013, SLB-014; M10.10), counting the
 * node's incidents since M16.20.
 *
 * These numbers used to be computed over the IMS fixture. Each is now asked of
 * the incident list read, one narrow request per number, and each count is the
 * `pagination.total` for the same filter the card links to -- so the number on
 * the card and the list behind it are the same answer to the same question
 * rather than two counts that can disagree.
 *
 * The "current shift" cards are gone. They matched creation times against a
 * shift table compiled into the client; an incident carries no shift, and the
 * rest of this surface stays fixture-driven until M16.21 binds it.
 */

const center = ref(LOCAL_OPERATIONS_CENTER);
const selectedAssignmentId = ref(
  center.value.deploymentRows[0]?.assignmentId ?? "",
);
const selectedDeploymentId = ref(
  center.value.deploymentOptions[0]?.deploymentId ?? "",
);
const status = ref<string | null>(null);

const modules = computed(() =>
  composeOperationsModules(
    center.value.capabilities,
    center.value.modules.find((module) => module.id === "equipment")?.available
      ? 1
      : 0,
  ),
);

const availableModules = computed(() =>
  availableOperationsModules({ ...center.value, modules: modules.value }),
);
const incidentCounts = ref({ total: 0, active: 0, critical: 0 });
const fieldReportCounts = ref({ total: 0, linked: 0, unlinked: 0 });

const incidentCards = computed(() => [
  {
    id: "event-total",
    label: "Event total",
    value: incidentCounts.value.total,
    hint: "Incidents created for this event",
    to: { name: "ims.incidents.index", query: { state: "all" } },
  },
  {
    id: "active",
    label: "Active",
    value: incidentCounts.value.active,
    hint: "Incidents not yet closed",
    to: { name: "ims.incidents.index", query: { state: "active" } },
  },
  {
    id: "critical",
    label: "Critical priority",
    value: incidentCounts.value.critical,
    hint: "Incidents marked critical",
    to: {
      name: "ims.incidents.index",
      query: { state: "all", priority: "Critical" },
    },
  },
]);
const fieldReportCards = computed(() => [
  {
    id: "linked",
    label: "Linked",
    value: fieldReportCounts.value.linked,
    hint: "Field Reports linked to incidents",
    to: {
      name: "ims.field-reports.index",
      query: { state: "all", link: "linked" },
    },
  },
  {
    id: "unlinked",
    label: "Unlinked",
    value: fieldReportCounts.value.unlinked,
    hint: "Field Reports not linked to an incident",
    to: {
      name: "ims.field-reports.index",
      query: { state: "all", link: "not_linked" },
    },
  },
  {
    id: "event-total",
    label: "Event total",
    value: fieldReportCounts.value.total,
    hint: "Field Reports submitted for this event",
    to: { name: "ims.field-reports.index", query: { state: "all" } },
  },
]);

watch(
  () => incidentSessionContext.value?.eventId ?? null,
  () => {
    void loadImsCounts();
  },
  { immediate: true },
);

/**
 * Count the event's incidents and Field Reports.
 *
 * Each incident count is one narrow read: `per_page=1` so the node returns a
 * page of one and the count comes from `pagination.total`, which is the total
 * for that filter rather than the size of a page. A failed read shows zero
 * rather than a stale number, and the links still work.
 */
async function loadImsCounts(): Promise<void> {
  const eventId = incidentSessionContext.value?.eventId;

  if (eventId === undefined || !incidentAccess.value.canView) {
    incidentCounts.value = { total: 0, active: 0, critical: 0 };
    fieldReportCounts.value = { total: 0, linked: 0, unlinked: 0 };

    return;
  }

  try {
    const [total, active, critical] = await Promise.all([
      getEventIncidents(eventId, { state: "all", per_page: "1" }),
      getEventIncidents(eventId, { state: "active", per_page: "1" }),
      getEventIncidents(eventId, {
        state: "all",
        priority: "Critical",
        per_page: "1",
      }),
    ]);

    incidentCounts.value = {
      total: total.pagination.total,
      active: active.pagination.total,
      critical: critical.pagination.total,
    };
  } catch {
    incidentCounts.value = { total: 0, active: 0, critical: 0 };
  }

  if (!incidentAccess.value.canViewFieldReports) {
    fieldReportCounts.value = { total: 0, linked: 0, unlinked: 0 };

    return;
  }

  try {
    const reports = await getEventFieldReports(eventId);

    fieldReportCounts.value = {
      total: reports.length,
      linked: reports.filter((report) => report.relatedIncidents.length > 0)
        .length,
      unlinked: reports.filter((report) => report.relatedIncidents.length === 0)
        .length,
    };
  } catch {
    fieldReportCounts.value = { total: 0, linked: 0, unlinked: 0 };
  }
}

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

function addDeployment(): void {
  status.value =
    "Add Deployment is scaffolded for the Operations Center workflow.";
}
</script>

<template>
  <DeptOpsShell
    title="Operations Center"
    :eyebrow="center.context.departmentLabel"
    lede="High-level operational picture composed from capabilities you already hold."
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <template #actions>
      <WorkflowActionButton @click="addDeployment">
        Add Deployment
      </WorkflowActionButton>
    </template>

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
              {{ row.displayName }} /
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
        Incident overview is available through the restricted IMS workspace.
      </p>
      <div class="ops__metric-cards" aria-label="Incident counts">
        <RouterLink
          v-for="card in incidentCards"
          :key="card.id"
          class="ops__metric-card"
          :to="card.to"
        >
          <span>{{ card.label }}</span>
          <strong>{{ card.value }}</strong>
          <small>{{ card.hint }}</small>
        </RouterLink>
      </div>
      <div class="ops__actions" aria-label="Incident Management System shortcuts">
        <RouterLink :to="{ name: 'ims.incidents.index' }">
          Open IMS incidents
        </RouterLink>
      </div>
    </section>

    <section
      v-if="availableModules.some((module) => module.id === 'field_reports')"
      aria-labelledby="field-reports-heading"
      class="ops__section"
    >
      <h2 id="field-reports-heading">Field Reports</h2>
      <p role="status">
        Field Report overview is available only from the existing Field Report
        permission. The Operations Center does not grant Field Report access.
      </p>
      <div class="ops__metric-cards" aria-label="Field Report counts">
        <RouterLink
          v-for="card in fieldReportCards"
          :key="card.id"
          class="ops__metric-card"
          :to="card.to"
        >
          <span>{{ card.label }}</span>
          <strong>{{ card.value }}</strong>
          <small>{{ card.hint }}</small>
        </RouterLink>
      </div>
      <div class="ops__actions" aria-label="Field Report shortcuts">
        <RouterLink :to="{ name: 'staff.field-reports.create' }">
          Submit Field Report
        </RouterLink>
      </div>
    </section>

    <section
      v-if="availableModules.some((module) => module.id === 'equipment')"
      aria-labelledby="equipment-overview-heading"
      class="ops__section"
    >
      <h2 id="equipment-overview-heading">Equipment overview</h2>
      <p role="status">
        Compact equipment readiness for this department. Staff-level handoff
        remains on the Logistics Window.
      </p>
    </section>

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
  </DeptOpsShell>
</template>

<style scoped>
.ops__section {
  margin: 0 0 var(--m-space-5);
}

.ops__section h2 {
  margin: 0 0 var(--m-space-3);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  letter-spacing: 0;
  text-transform: uppercase;
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
  min-height: 7rem;
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.ops__modules strong {
  display: block;
  margin-bottom: var(--m-space-1);
  color: var(--m-text-primary);
}

.ops__modules span,
.ops__modules p,
.ops__status {
  margin: 0;
  color: var(--m-text-muted);
}

.ops__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  margin-top: var(--m-space-3);
}

.ops__metric-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: var(--m-space-3);
  margin-top: var(--m-space-3);
}

.ops__metric-card {
  display: grid;
  gap: var(--m-space-1);
  min-height: 7rem;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  text-decoration: none;
  box-shadow: var(--m-shadow-sm);
}

.ops__metric-card span {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ops__metric-card strong {
  color: var(--m-text-primary);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.ops__metric-card small {
  color: var(--m-text-muted);
  line-height: 1.35;
}

.ops__actions a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-height: 2.75rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font-weight: 700;
  text-decoration: none;
}

.ops__form {
  display: grid;
  gap: var(--m-space-3);
  margin-bottom: var(--m-space-4);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
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
  border-radius: 6px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
}

.ops__form button {
  font-weight: 700;
  cursor: pointer;
}

.ops__form select:focus-visible,
.ops__form button:focus-visible,
.ops__metric-card:focus-visible,
.ops__actions a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ops__table-frame {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
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

.ops__table-frame thead th {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  text-transform: uppercase;
}

@media (min-width: 48rem) {
  .ops__modules {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .ops__form {
    grid-template-columns: repeat(2, minmax(0, 1fr)) auto;
    align-items: end;
  }
}
</style>
