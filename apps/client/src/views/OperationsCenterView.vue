<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  deploymentLabel,
  getOperationsCenter,
  setCurrentDeployment,
  type OperationsCenterRead,
} from "@/department-ops/departmentOpsReadModel";
import {
  getEventFieldReports,
  getEventIncidents,
  incidentAccess,
  incidentSessionContext,
} from "@/ims/incidentReadModel";

/**
 * The Operations Center (SLB-009, SLB-010, SLB-013, SLB-014, SLB-022; bound to
 * the node in M16.21).
 *
 * The deployment module is the one this surface owns, and it now reads the
 * department's deployments and the staff on shift from the node and writes
 * through `set-current-deployment`. Its module list is composed from the same
 * `access` block the commands enforce, in place of a fixture's capability flags
 * — the shell grants nothing, which is the whole of SLB-022.
 *
 * The IMS and Field Report counts were bound in M16.20 and are unchanged: one
 * narrow read per number, each the node's total for the filter its card links
 * to.
 */

const route = useRoute();
const eventId = computed(() => String(route.params.eventId ?? ""));
const departmentId = computed(() => String(route.params.departmentId ?? ""));

const center = ref<OperationsCenterRead | null>(null);
const loadError = ref<string | null>(null);
const selectedAssignmentId = ref("");
const selectedDeploymentId = ref("");
const status = ref<string | null>(null);

const departmentLabel = computed(
  () => center.value?.context.departmentLabel ?? "Department",
);
const rows = computed(() => center.value?.rows ?? []);
const deployments = computed(() => center.value?.deployments ?? []);

/**
 * The modules this actor already holds the capability for (SLB-022).
 *
 * Deployments follow `can_assign_deployments`; incidents and Field Reports
 * follow the IMS reads' own access answer, which is event-scoped rather than
 * department-scoped (SLB-014); equipment follows the department's equipment
 * grant. Maintenance stays an extension point until that domain is specified.
 */
const modules = computed(() => {
  const access = center.value?.access;

  return [
    {
      id: "deployments",
      title: "Deployments",
      available: access?.canAssignDeployments ?? false,
      unavailableReason: "Deployments require Department Operations capability.",
      summary: "Current deployment/location assignments for staff on shift.",
    },
    {
      id: "field_reports",
      title: "Field Reports",
      available: incidentAccess.value.canViewFieldReports,
      unavailableReason:
        "Field Report shortcuts require existing Field Report permission.",
      summary:
        "Field Report shortcuts available from the actor's existing permission.",
    },
    {
      id: "incidents",
      title: "Incidents",
      available: incidentAccess.value.canView,
      unavailableReason:
        "Incident overview requires event-scoped Incident Command capability.",
      summary: "Incident overview for the event IC department.",
    },
    {
      id: "equipment",
      title: "Equipment",
      available: access?.canManageEquipment ?? false,
      unavailableReason:
        "Equipment overview requires equipment visibility capability.",
      summary: `${center.value?.equipmentOutCount ?? 0} item${
        center.value?.equipmentOutCount === 1 ? "" : "s"
      } currently checked out in this department.`,
    },
    {
      id: "maintenance",
      title: "Maintenance",
      available: false,
      unavailableReason:
        "Maintenance tickets are an extension point until that domain is specified.",
      summary: "No maintenance module yet.",
    },
  ];
});

const availableModules = computed(() =>
  modules.value.filter((module) => module.available),
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

/**
 * Read the deployment module.
 *
 * A failed read clears the page rather than leaving the last roster on screen,
 * and the two selects fall back to the first row and the first deployment so the
 * form is usable the moment the response lands.
 */
async function loadOperations(): Promise<void> {
  if (eventId.value === "" || departmentId.value === "") {
    center.value = null;

    return;
  }

  loadError.value = null;

  try {
    const read = await getOperationsCenter(eventId.value, departmentId.value);

    center.value = read;

    if (!read.rows.some((row) => row.assignmentId === selectedAssignmentId.value)) {
      selectedAssignmentId.value = read.rows[0]?.assignmentId ?? "";
    }

    if (
      !read.deployments.some(
        (option) => option.deploymentId === selectedDeploymentId.value,
      )
    ) {
      selectedDeploymentId.value = read.deployments[0]?.deploymentId ?? "";
    }
  } catch (error) {
    center.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load this department's operations. Check the connection to this node and try again.",
    );
  }
}

/**
 * Move somebody to a deployment, then read the roster again.
 *
 * The command answers with the assignment it wrote and not with what the rest of
 * the table now says, so the table asks. Connected-only: moving a deployment is
 * refused where it stands rather than queued (CLIENT-018).
 */
async function moveDeployment(): Promise<void> {
  const context = center.value?.context;
  const row = rows.value.find(
    (candidate) => candidate.assignmentId === selectedAssignmentId.value,
  );

  if (context === undefined || row === undefined) {
    return;
  }

  try {
    await setCurrentDeployment(
      context,
      row.shiftId,
      row.staffId,
      selectedDeploymentId.value,
    );
    await loadOperations();
    status.value = `${row.displayName} moved to ${deploymentLabel(
      deployments.value,
      selectedDeploymentId.value,
    )}.`;
  } catch (error) {
    status.value = meridianErrorMessage(error, "Unable to move deployment.");
  }
}

watch([eventId, departmentId], () => {
  void loadOperations();
});

void loadOperations();
</script>

<template>
  <DeptOpsShell
    title="Operations Center"
    :eyebrow="departmentLabel"
    lede="High-level operational picture composed from capabilities you already hold."
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <p v-if="loadError" class="ops__error" role="alert">
      {{ loadError }}
      <button type="button" @click="loadOperations">Try again</button>
    </p>

    <section
      v-if="availableModules.some((module) => module.id === 'deployments')"
      aria-labelledby="deployments-heading"
      class="ops__section"
    >
      <h2 id="deployments-heading">Deployments</h2>
      <p v-if="rows.length === 0" role="status">
        No staff are on a running shift in this department right now.
      </p>
      <form
        v-else
        class="ops__form"
        aria-label="Move current deployment"
        @submit.prevent="moveDeployment"
      >
        <label>
          <span>Staff on shift</span>
          <select v-model="selectedAssignmentId">
            <option
              v-for="row in rows"
              :key="row.assignmentId"
              :value="row.assignmentId"
            >
              {{ row.displayName }} /
              {{ deploymentLabel(deployments, row.currentDeploymentId) }}
            </option>
          </select>
        </label>
        <label>
          <span>Deployment</span>
          <select v-model="selectedDeploymentId">
            <option
              v-for="option in deployments"
              :key="option.deploymentId"
              :value="option.deploymentId"
            >
              {{ option.name }}
            </option>
          </select>
        </label>
        <button type="submit" :disabled="deployments.length === 0">
          Move deployment
        </button>
      </form>
      <p v-if="status" class="ops__status" role="status">{{ status }}</p>

      <div v-if="rows.length > 0" class="ops__table-frame">
        <table>
          <thead>
            <tr>
              <th scope="col">Staff</th>
              <th scope="col">Shift</th>
              <th scope="col">Current deployment</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.assignmentId">
              <th scope="row">{{ row.displayName }}</th>
              <td>{{ row.shiftTitle }}</td>
              <td>
                {{ deploymentLabel(deployments, row.currentDeploymentId) }}
              </td>
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

.ops__error {
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
