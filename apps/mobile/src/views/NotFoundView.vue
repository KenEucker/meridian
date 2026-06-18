<script setup lang="ts">
import { RouterLink } from "vue-router";
</script>

<template>
  <section class="not-found" aria-labelledby="not-found-heading">
    <h1 id="not-found-heading" class="not-found__heading">Page not found</h1>
    <p class="not-found__body">
      This screen does not exist yet.
      <RouterLink :to="{ name: 'home' }">Return to the field home</RouterLink>.
    </p>
  </section>
</template>

<style scoped>
.not-found {
  max-width: 40rem;
}

.not-found__heading {
  margin: 0 0 0.5rem;
  font-size: 1.5rem;
}

.not-found__body {
  margin: 0;
  color: var(--m-shell-muted, #4a5159);
}
</style>
