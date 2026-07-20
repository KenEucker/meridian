<script setup lang="ts">
import { computed, watch } from "vue";
import { RouterLink } from "vue-router";

import { meridianAppConfig, type MeridianAppConfig } from "@/app/appConfig";
import OfflineBanner from "@/components/OfflineBanner.vue";
import { syncFieldReportOutbox } from "@/field-reports/syncFieldReportOutbox";
import { useConnectivity } from "@/offline/useConnectivity";
import { syncAttendanceOutbox } from "@/shift-board/syncAttendanceOutbox";

const meridianWordmarkUrl = "/assets/brand/meridian-signal-camp-wordmark.webp";

const props = defineProps<{
  readonly config?: MeridianAppConfig;
}>();

const appConfig = computed(() => props.config ?? meridianAppConfig);

// Shared offline/sync status display for the shared client surfaces (M8.6). The
// banner is driven by the coarse device-connectivity view-model and is silent
// while online, so it appears only where offline state affects current work
// (UI implementation contract section 16.2). Richer sync states are fed through
// the same OfflineBanner view-model by later Alpha 1 milestones.
//
// When the device reports online, drain supported local operation outboxes to
// the local Meridian server command API.
const connectivity = useConnectivity();

watch(
  connectivity,
  (state) => {
    if (state === "online") {
      void syncFieldReportOutbox();
      void syncAttendanceOutbox();
    }
  },
  { immediate: true },
);
</script>

<template>
  <div
    class="app-shell"
    :class="`app-shell--${appConfig.uiMode}`"
    :data-ui-mode="appConfig.uiMode"
    :data-deployment-target="appConfig.deploymentTarget"
    :aria-label="`${appConfig.productName} application shell`"
  >
    <!--
      Placeholder app shell. Follows UI Implementation Contract section 4
      (use the app shell; no persistent left sidebar as primary navigation).
      The full AppTopBar / ContextBar contract (section 6) and command palette
      arrive with their owning Alpha 1 milestones.
    -->
    <header class="app-shell__top-bar">
      <nav class="app-shell__nav" aria-label="Application">
        <RouterLink
          class="app-shell__home"
          :to="{ name: 'home' }"
          :aria-label="appConfig.productName"
        >
          <img
            class="app-shell__wordmark"
            :src="meridianWordmarkUrl"
            alt=""
            width="248"
            height="64"
            aria-hidden="true"
          />
          <span class="app-shell__mode-name">{{
            appConfig.modeDisplayName
          }}</span>
        </RouterLink>
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
  padding: calc(var(--m-space-2) + env(safe-area-inset-top)) 0
    var(--m-space-2);
  background:
    linear-gradient(
      90deg,
      color-mix(in srgb, var(--m-action-secondary-bg) 12%, transparent),
      transparent 38%
    ),
    var(--m-surface-raised);
  border-bottom: 2px solid var(--m-action-secondary-bg);
  box-shadow: inset 0 -1px 0 var(--m-border-default);
}

.app-shell__nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-3);
  min-width: 0;
}

.app-shell__home {
  display: inline-grid;
  grid-template-columns: minmax(8.5rem, 13rem) minmax(0, auto);
  align-items: center;
  gap: var(--m-space-3);
  min-width: 0;
  font-weight: 600;
  text-decoration: none;
  color: var(--m-text-primary);
}

.app-shell__wordmark {
  display: block;
  width: clamp(8.5rem, 28vw, 13rem);
  height: auto;
  object-fit: contain;
}

.app-shell__mode-name {
  min-width: 0;
  padding: var(--m-space-1) var(--m-space-2);
  border-left: 3px solid var(--m-action-primary-bg);
  color: var(--m-action-primary-bg);
  font-family: Georgia, "Times New Roman", serif;
  font-size: var(--m-text-lg);
  font-style: italic;
  font-weight: 700;
  letter-spacing: 0;
  line-height: 1;
  overflow: hidden;
  text-shadow: 0 1px 0 color-mix(in srgb, var(--m-surface-raised) 85%, transparent);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.app-shell__about {
  flex: 0 0 auto;
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
  padding: var(--m-space-6) var(--m-space-4)
    max(var(--m-space-8), env(safe-area-inset-bottom));
}
</style>
