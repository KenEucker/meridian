<script setup lang="ts">
import { RouterLink } from "vue-router";

import { LOCAL_CURRENT_SHIFT_BOARD } from "@/shift-board/currentShiftBoard";

// Placeholder home surface. Field Report author surfaces arrived with M9.4;
// the read-only current Shift Lead Board roster is present for M10.1.
const shiftBoardRoute = {
  name: "events.departments.shift-board.current",
  params: {
    eventId: LOCAL_CURRENT_SHIFT_BOARD.eventId,
    departmentId: LOCAL_CURRENT_SHIFT_BOARD.departmentId,
  },
};
</script>

<template>
  <section class="home" aria-labelledby="home-heading">
    <h1 id="home-heading" class="home__heading">Meridian Field</h1>
    <p class="home__lede">
      Field application shell. Select a current operational surface.
    </p>
    <p class="home__links">
      <RouterLink :to="shiftBoardRoute">Current shift board</RouterLink>
      <RouterLink :to="{ name: 'staff.field-reports.index' }"
        >My Field Reports</RouterLink
      >
      <RouterLink :to="{ name: 'readiness' }">Check device readiness</RouterLink>
    </p>
  </section>
</template>

<style scoped>
.home {
  max-width: 40rem;
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
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2);
}
</style>
