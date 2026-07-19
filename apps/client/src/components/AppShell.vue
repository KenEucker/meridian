<script setup lang="ts">
import { watch } from "vue";
import { RouterLink } from "vue-router";

import OfflineBanner from "@/components/OfflineBanner.vue";
import { syncFieldReportOutbox } from "@/field-reports/syncFieldReportOutbox";
import { useConnectivity } from "@/offline/useConnectivity";

// Shared offline/sync status display for the shared client surfaces (M8.6). The
// banner is driven by the coarse device-connectivity view-model and is silent
// while online, so it appears only where offline state affects current work
// (UI implementation contract section 16.2). Richer sync states are fed through
// the same OfflineBanner view-model by later Alpha 1 milestones.
//
// M9.8: when the device reports online, drain pending Field Report text and
// photo uploads to the local Meridian server command API.
const connectivity = useConnectivity();

watch(
  connectivity,
  (state) => {
    if (state === "online") {
      void syncFieldReportOutbox();
    }
  },
  { immediate: true },
);
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
      <nav class="app-shell__nav" aria-label="Application">
        <RouterLink class="app-shell__home" :to="{ name: 'home' }"
          >Meridian Field</RouterLink
        >
        <RouterLink class="app-shell__about" :to="{ name: 'settings.about' }"
          >About</RouterLink
        >
      </nav>
    </header>
    <OfflineBanner class="app-shell__offline-banner" :state="connectivity" />
    <main class="app-shell__main">
      <slot />
    </main>
  </div>
</template>

<style scoped>
.app-shell {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  min-height: 100dvh;
}

.app-shell__top-bar {
  display: grid;
  grid-template-columns: minmax(0, var(--m-content-wide));
  justify-content: center;
  align-items: center;
  padding: calc(var(--m-space-3) + env(safe-area-inset-top)) 0
    var(--m-space-3);
  background: var(--m-surface-raised);
  border-bottom: 1px solid var(--m-border-default);
}

.app-shell__nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.app-shell__home {
  display: block;
  font-weight: 600;
  text-decoration: none;
  color: var(--m-text-primary);
}

.app-shell__about {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.app-shell__home:focus-visible,
.app-shell__about:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.app-shell__offline-banner {
  width: var(--m-content-wide);
  margin: var(--m-space-3) auto 0;
}

.app-shell__main {
  display: grid;
  flex: 1;
  align-content: start;
  justify-items: center;
  gap: var(--m-space-6);
  padding: var(--m-space-6) 0
    max(var(--m-space-8), env(safe-area-inset-bottom));
}
</style>
