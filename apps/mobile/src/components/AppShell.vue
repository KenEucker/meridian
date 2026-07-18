<script setup lang="ts">
import { watch } from "vue";
import { RouterLink } from "vue-router";

import OfflineBanner from "@/components/OfflineBanner.vue";
import { syncFieldReportOutbox } from "@/field-reports/syncFieldReportOutbox";
import { useConnectivity } from "@/offline/useConnectivity";

// Shared offline/sync status display for the field app and kiosk (M8.6). The
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
      <RouterLink class="app-shell__home" :to="{ name: 'home' }">Meridian Field</RouterLink>
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

.app-shell__offline-banner {
  margin: var(--m-space-3) var(--m-space-4) 0;
}

.app-shell__main {
  flex: 1;
  padding: var(--m-space-6) var(--m-space-4);
}
</style>
