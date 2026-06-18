<script setup lang="ts">
import { RouterLink } from "vue-router";
</script>

<template>
  <div class="app-shell">
    <!--
      Placeholder app shell. Follows UI Implementation Contract section 4
      (use the app shell; no persistent left sidebar as primary navigation).
      The full AppTopBar / ContextBar contract (section 6) and command palette
      arrive with their owning Alpha 1 milestones.
    -->
    <header class="app-shell__top-bar">
      <RouterLink class="app-shell__home" :to="{ name: 'home' }">Meridian Field</RouterLink>
    </header>
    <main class="app-shell__main">
      <slot />
    </main>
  </div>
</template>

<style scoped>
.app-shell {
  display: flex;
  min-height: 100vh;
  flex-direction: column;
}

.app-shell__top-bar {
  display: flex;
  align-items: center;
  padding: var(--m-space-3) var(--m-space-4);
  background: var(--m-surface-raised);
  border-bottom: 1px solid var(--m-border-default);
}

.app-shell__home {
  font-weight: 600;
  text-decoration: none;
  color: var(--m-text-primary);
}

.app-shell__home:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.app-shell__main {
  flex: 1;
  padding: var(--m-space-6) var(--m-space-4);
}
</style>
