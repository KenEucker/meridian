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
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--m-shell-border, #d7dbe0);
}

.app-shell__home {
  font-weight: 600;
  text-decoration: none;
  color: inherit;
}

.app-shell__main {
  flex: 1;
  padding: 1.5rem 1rem;
}
</style>
