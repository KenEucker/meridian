<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import {
  LOCAL_DEPARTMENT_OPS_CONTEXT,
  LOCAL_PLANNING_TABLE,
} from "@/department-ops/fixtures";
import { formatTimestamp } from "@/department-ops/labels";
import {
  fixtureDepartmentHasAdminAccess,
  fixtureDepartmentHasOrganizerDepartmentAccess,
  selectedFixtureDepartment,
  selectedFixtureDepartmentRouteParams,
} from "@/department-teams/fixtureDepartmentAccess";

const departmentRouteParams = computed(
  () => selectedFixtureDepartmentRouteParams.value,
);
const overviewRoute = computed(() => ({
  name: "events.departments.overview",
  params: departmentRouteParams.value,
}));
const logisticsRoute = computed(() => ({
  name: "events.departments.logistics",
  params: departmentRouteParams.value,
}));
const operationsRoute = computed(() => ({
  name: "events.departments.operations",
  params: departmentRouteParams.value,
}));
const planningRoute = computed(() => ({
  name: "events.departments.planning",
  params: departmentRouteParams.value,
}));
const imsIncidentsRoute = {
  name: "ims.incidents.index",
};
const imsFieldReportsRoute = {
  name: "ims.field-reports.index",
};
const organizerDepartmentsRoute = {
  name: "organizer.departments.index",
};
const organizerStaffRoute = {
  name: "organizer.staff.index",
};
const organizerDocumentsRoute = {
  name: "organizer.documents.index",
};
const departmentAdminRoute = computed(() => ({
  name: "events.departments.teams.index",
  params: departmentRouteParams.value,
}));
const departmentDocumentsRoute = computed(() => ({
  name: "events.departments.documents.index",
  params: departmentRouteParams.value,
}));
const showAdminCard = computed(() =>
  fixtureDepartmentHasAdminAccess(selectedFixtureDepartment.value),
);
const showOrganizerDepartmentsCard = computed(() =>
  fixtureDepartmentHasOrganizerDepartmentAccess(selectedFixtureDepartment.value),
);
const showOverviewCard = computed(
  () => selectedFixtureDepartment.value.isDepartmentLead,
);
const showPlanningCard = computed(
  () => selectedFixtureDepartment.value.capabilities.hasPlanning,
);
const showLogisticsCard = computed(
  () => selectedFixtureDepartment.value.capabilities.hasLogistics,
);
const showOperationsCard = computed(
  () => selectedFixtureDepartment.value.capabilities.hasOperations,
);
const showIncidentCommandCards = computed(
  () => selectedFixtureDepartment.value.capabilities.hasIncidentCommand,
);
const eventWindow = computed(() => {
  const sortedStarts = LOCAL_PLANNING_TABLE.rows
    .map((row) => row.startsAt)
    .sort();
  const sortedEnds = LOCAL_PLANNING_TABLE.rows.map((row) => row.endsAt).sort();

  return {
    startsAt: sortedStarts[0] ?? null,
    endsAt: sortedEnds.at(-1) ?? null,
  };
});
const eventStatus = computed(() => {
  const startsAt = eventWindow.value.startsAt;
  const endsAt = eventWindow.value.endsAt;
  const asOf = Date.parse(LOCAL_DEPARTMENT_OPS_CONTEXT.asOf);

  if (!startsAt || !endsAt || Number.isNaN(asOf)) {
    return "upcoming";
  }

  if (asOf < Date.parse(startsAt)) {
    return "upcoming";
  }

  if (asOf > Date.parse(endsAt)) {
    return "ended";
  }

  return "ongoing";
});
const operationsWindowLabel = computed(() => {
  const startsAt = eventWindow.value.startsAt;
  const endsAt = eventWindow.value.endsAt;

  if (!startsAt || !endsAt) {
    return "Operations window not set";
  }

  return `${formatTimestamp(
    startsAt,
    LOCAL_DEPARTMENT_OPS_CONTEXT.timeZone,
  )} to ${formatTimestamp(endsAt, LOCAL_DEPARTMENT_OPS_CONTEXT.timeZone)}`;
});
</script>

<template>
  <section class="home" aria-labelledby="home-heading">
    <header class="home__event-card">
      <div>
        <h1 id="home-heading" class="home__heading">
          {{ LOCAL_DEPARTMENT_OPS_CONTEXT.eventLabel }}
        </h1>
        <p class="home__lede">
          {{ selectedFixtureDepartment.departmentLabel }}
          operations workspace.
        </p>
        <dl class="home__event-details" aria-label="Event information">
          <div>
            <dt>Operations</dt>
            <dd>{{ operationsWindowLabel }}</dd>
          </div>
          <div>
            <dt>Location</dt>
            <dd>Location not set</dd>
          </div>
          <div>
            <dt>Description</dt>
            <dd>Description not set</dd>
          </div>
        </dl>
      </div>
      <span class="home__status" :data-status="eventStatus">
        {{ eventStatus }}
      </span>
    </header>

    <div class="home__grid" aria-label="Current operational surfaces">
      <RouterLink v-if="showOverviewCard" :to="overviewRoute" class="home__card">
        <h2>Overview</h2>
        <p>Shift health, exceptions, and current staffing.</p>
      </RouterLink>
      <RouterLink v-if="showPlanningCard" :to="planningRoute" class="home__card">
        <h2>Planning</h2>
        <p>Position coverage across teams and time.</p>
      </RouterLink>
      <RouterLink v-if="showLogisticsCard" :to="logisticsRoute" class="home__card">
        <h2>Logistics</h2>
        <p>Roster, attendance, and equipment handoff.</p>
      </RouterLink>
      <RouterLink v-if="showOperationsCard" :to="operationsRoute" class="home__card">
        <h2>Operations Center</h2>
        <p>Deployments and capability-based shortcuts.</p>
      </RouterLink>
      <RouterLink
        v-if="showIncidentCommandCards"
        :to="imsIncidentsRoute"
        class="home__card"
      >
        <h2>Incidents</h2>
        <p>Restricted incident workspace for IC roles.</p>
      </RouterLink>
      <RouterLink :to="{ name: 'staff.field-reports.index' }" class="home__card">
        <h2>My Field Reports</h2>
        <p>Field report author workspace.</p>
      </RouterLink>
      <RouterLink
        v-if="showIncidentCommandCards"
        :to="imsFieldReportsRoute"
        class="home__card"
      >
        <h2>Field Reports</h2>
        <p>Submitted field reports for review.</p>
      </RouterLink>
      <RouterLink
        v-if="showAdminCard"
        :to="departmentAdminRoute"
        class="home__card"
      >
        <h2>Admin</h2>
        <p>Department and team administration.</p>
      </RouterLink>
      <RouterLink
        v-if="showAdminCard"
        :to="departmentDocumentsRoute"
        class="home__card"
      >
        <h2>Documents</h2>
        <p>Department and team policies, procedures, and fragments.</p>
      </RouterLink>
      <RouterLink
        v-if="showOrganizerDepartmentsCard"
        :to="organizerStaffRoute"
        class="home__card"
      >
        <h2>Staff</h2>
        <p>Organizer staff intake and lead selection.</p>
      </RouterLink>
      <RouterLink
        v-if="showOrganizerDepartmentsCard"
        :to="organizerDepartmentsRoute"
        class="home__card"
      >
        <h2>Departments</h2>
        <p>Organizer department administration.</p>
      </RouterLink>
      <RouterLink
        v-if="showOrganizerDepartmentsCard"
        :to="organizerDocumentsRoute"
        class="home__card"
      >
        <h2>Documents</h2>
        <p>Organization policies, procedures, fragments, and exports.</p>
      </RouterLink>
      <RouterLink :to="{ name: 'readiness' }" class="home__card">
        <h2>Readiness</h2>
        <p>Device readiness checks.</p>
      </RouterLink>
      <RouterLink :to="{ name: 'settings.about' }" class="home__card">
        <h2>Health</h2>
        <p>Client and local server diagnostics.</p>
      </RouterLink>
    </div>
  </section>
</template>

<style scoped>
.home {
  width: min(100%, 76rem);
}

.home__event-card {
  display: grid;
  gap: var(--m-space-4);
  align-items: start;
  margin-bottom: var(--m-space-4);
  padding: var(--m-space-5);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.home__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.home__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.home__event-details {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.home__event-details div {
  display: grid;
  gap: 0.2rem;
}

.home__event-details dt {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 800;
  text-transform: uppercase;
}

.home__event-details dd {
  margin: 0;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.home__status {
  display: inline-flex;
  align-items: center;
  justify-self: start;
  min-height: 2rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-status-success);
  border-radius: var(--m-radius-pill);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.home__status[data-status="ongoing"] {
  border-color: var(--m-status-success);
}

.home__status[data-status="upcoming"] {
  border-color: var(--m-status-neutral);
}

.home__status[data-status="ended"] {
  border-color: var(--m-text-muted);
}

.home__grid {
  display: grid;
  gap: var(--m-space-3);
  grid-template-columns: minmax(0, 1fr);
}

.home__card {
  display: grid;
  align-content: start;
  gap: var(--m-space-2);
  min-height: 6.25rem;
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  text-decoration: none;
  box-shadow: var(--m-shadow-sm);
}

.home__card h2 {
  margin: 0;
  font-size: var(--m-text-md);
  letter-spacing: 0;
}

.home__card p {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.home__card:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 44rem) {
  .home__event-card {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .home__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
