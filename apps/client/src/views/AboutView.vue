<script setup lang="ts">
import { RouterLink } from "vue-router";

import { CLIENT_VERSION } from "@/version";
</script>

<template>
  <section class="about" aria-labelledby="about-heading">
    <p class="about__nav">
      <RouterLink :to="{ name: 'home' }">Back to Home</RouterLink>
    </p>

    <h1 id="about-heading" class="about__heading">Settings</h1>
    <h2 class="about__subheading">About Meridian</h2>

    <dl class="about__meta" aria-label="Application version">
      <div>
        <dt>Client version</dt>
        <dd>{{ CLIENT_VERSION }}</dd>
      </div>
    </dl>
  </section>
</template>

<style scoped>
.about {
  width: var(--m-content-narrow);
}

.about__nav {
  margin: 0 0 var(--m-space-4);
}

.about__nav a {
  color: var(--m-text-primary);
}

.about__nav a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.about__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.about__subheading {
  margin: 0 0 var(--m-space-4);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.about__meta {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.about__meta div {
  display: grid;
  gap: var(--m-space-1);
}

.about__meta dt {
  margin: 0;
  color: var(--m-text-secondary);
  font-weight: 600;
}

.about__meta dd {
  margin: 0;
}

@media (min-width: 44rem) {
  .about__meta div {
    grid-template-columns: 8rem minmax(0, 1fr);
    gap: var(--m-space-2);
  }
}
</style>
