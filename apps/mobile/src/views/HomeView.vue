<script setup lang="ts">
// Placeholder home surface. Field Report author surfaces arrive with M9.4;
// broader volunteer/department/shift workflows remain later Alpha 1 milestones.
import { RouterLink } from "vue-router";
</script>

<template>
  <section class="home" aria-labelledby="home-heading">
    <h1 id="home-heading" class="home__heading">Meridian Field</h1>
    <p class="home__lede">
      Field application shell. Volunteer, department, and shift surfaces are not
      yet implemented. Authors can submit and view their own Field Reports.
    </p>
    <p class="home__links">
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
