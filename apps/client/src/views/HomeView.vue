<script setup lang="ts">
import { RouterLink } from "vue-router";

import { meridianAppConfig } from "@/app/appConfig";
import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";

// Placeholder home surface. Field Report author surfaces arrived with M9.4;
// department operations surfaces are workflow-specific for M10.
const departmentRouteParams = {
  eventId: LOCAL_DEPARTMENT_OPS_CONTEXT.eventId,
  departmentId: LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId,
};
const overviewRoute = {
  name: "events.departments.overview",
  params: departmentRouteParams,
};
const logisticsRoute = {
  name: "events.departments.logistics",
  params: departmentRouteParams,
};
const operationsRoute = {
  name: "events.departments.operations",
  params: departmentRouteParams,
};
const planningRoute = {
  name: "events.departments.planning",
  params: departmentRouteParams,
};
const imsIncidentsRoute = {
  name: "ims.incidents.index",
};
const imsFieldReportsRoute = {
  name: "ims.field-reports.index",
};
</script>

<template>
  <section class="home" aria-labelledby="home-heading">
    <h1 id="home-heading" class="home__heading">
      {{ meridianAppConfig.productName }}
    </h1>
    <p class="home__lede">
      Shared operational client. Select a current operational surface.
    </p>
    <div class="home__sections">
      <section class="home__section" aria-labelledby="home-primary-heading">
        <h2 id="home-primary-heading" class="home__section-heading">
          Department operations
        </h2>
        <div class="home__links home__links--primary">
          <RouterLink :to="overviewRoute">Department overview</RouterLink>
          <RouterLink :to="logisticsRoute">Logistics desk</RouterLink>
          <RouterLink :to="operationsRoute">Operations center</RouterLink>
          <RouterLink :to="planningRoute">Planning table</RouterLink>
        </div>
      </section>

      <section class="home__section" aria-labelledby="home-secondary-heading">
        <h2 id="home-secondary-heading" class="home__section-heading">
          Incident Management
        </h2>
        <div class="home__links home__links--secondary">
          <RouterLink :to="{ name: 'staff.field-reports.index' }"
            >My Field Reports</RouterLink
          >
          <RouterLink :to="imsIncidentsRoute">Incidents</RouterLink>
          <RouterLink :to="imsFieldReportsRoute">Field Reports</RouterLink>
        </div>
      </section>

      <section class="home__section" aria-labelledby="home-tools-heading">
        <h2 id="home-tools-heading" class="home__section-heading">
          Supporting tools
        </h2>
        <div class="home__links home__links--secondary">
          <RouterLink :to="{ name: 'readiness' }"
            >Check device readiness</RouterLink
          >
          <RouterLink :to="{ name: 'settings.about' }">Health</RouterLink>
        </div>
      </section>
    </div>
  </section>
</template>

<style scoped>
.home {
  width: var(--m-content-narrow);
}

.home__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.home__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.home__sections {
  display: grid;
  gap: var(--m-space-6);
}

.home__section {
  display: grid;
  gap: var(--m-space-3);
}

.home__section-heading {
  display: flex;
  align-items: center;
  gap: var(--m-space-2);
  margin: 0;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.home__section-heading::before {
  display: block;
  width: 0.75rem;
  height: 0.75rem;
  border-radius: var(--m-radius-sm);
  background: var(--m-action-secondary-bg);
  content: "";
}

.home__links {
  display: grid;
  gap: var(--m-space-3);
}

.home__links a {
  display: block;
  min-height: 2.75rem;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font-weight: 600;
  text-decoration: none;
}

.home__links--primary a {
  border-color: var(--m-border-strong);
  border-left: 4px solid var(--m-action-primary-bg);
  box-shadow: var(--m-shadow-sm);
}

.home__links--secondary a {
  border-color: color-mix(
    in srgb,
    var(--m-action-secondary-bg) 55%,
    var(--m-border-default)
  );
  border-left: 4px solid var(--m-action-secondary-bg);
  background: color-mix(
    in srgb,
    var(--m-action-secondary-bg) 8%,
    var(--m-surface-base)
  );
  color: var(--m-text-secondary);
}

.home__links a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
