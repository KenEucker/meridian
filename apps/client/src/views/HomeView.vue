<script setup lang="ts">
import { RouterLink } from "vue-router";

import { LOCAL_CURRENT_SHIFT_BOARD } from "@/shift-board/currentShiftBoard";

// Placeholder home surface. Field Report author surfaces arrived with M9.4;
// department operations surfaces are split by role workflow for M10.
const departmentRouteParams = {
  eventId: LOCAL_CURRENT_SHIFT_BOARD.eventId,
  departmentId: LOCAL_CURRENT_SHIFT_BOARD.departmentId,
};
const departmentBoardRoute = {
  name: "events.departments.shift-board.current",
  params: departmentRouteParams,
};
const logisticsRoute = {
  name: "events.departments.shift-board.logistics",
  params: departmentRouteParams,
};
const operationsRoute = {
  name: "events.departments.shift-board.operations",
  params: departmentRouteParams,
};
const planningRoute = {
  name: "events.departments.shift-board.planning",
  params: departmentRouteParams,
};
</script>

<template>
  <section class="home" aria-labelledby="home-heading">
    <h1 id="home-heading" class="home__heading">Meridian Field</h1>
    <p class="home__lede">
      Shared operational client. Select a current operational surface.
    </p>
    <p class="home__links">
      <RouterLink :to="departmentBoardRoute">Department board</RouterLink>
      <RouterLink :to="logisticsRoute">Logistics desk</RouterLink>
      <RouterLink :to="operationsRoute">Operations board</RouterLink>
      <RouterLink :to="planningRoute">Planning board</RouterLink>
      <RouterLink :to="{ name: 'staff.field-reports.index' }"
        >My Field Reports</RouterLink
      >
      <RouterLink :to="{ name: 'readiness' }">Check device readiness</RouterLink>
    </p>
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

.home__links {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
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

.home__links a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
