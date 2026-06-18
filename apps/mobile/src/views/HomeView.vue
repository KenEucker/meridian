<script setup lang="ts">
// Placeholder home surface. No domain workflows, data, or authentication
// are wired up in M2.1; those arrive with later Alpha 1 milestones.
</script>

<template>
  <section class="home" aria-labelledby="home-heading">
    <h1 id="home-heading" class="home__heading">Meridian Field</h1>
    <p class="home__lede">
      Field application shell. Volunteer, department, shift, and field-report
      surfaces are not yet implemented.
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
  margin: 0;
  color: var(--m-text-muted);
}
</style>
