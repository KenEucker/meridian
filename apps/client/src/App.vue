<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRoute } from "vue-router";

import { meridianAppConfig } from "@/app/appConfig";
import AdminAppShell from "@/components/shells/AdminAppShell.vue";
import FieldAppShell from "@/components/shells/FieldAppShell.vue";
import KioskAppShell from "@/components/shells/KioskAppShell.vue";

document.title = meridianAppConfig.productName;

const route = useRoute();
const isRouteLoading = ref(false);
let routeLoadingTimeout: ReturnType<typeof window.setTimeout> | null = null;

const shellComponent = computed(() => {
  switch (meridianAppConfig.uiMode) {
    case "field":
      return FieldAppShell;
    case "kiosk":
      return KioskAppShell;
    case "admin":
      return AdminAppShell;
  }
});

function beginRouteLoading(): void {
  if (typeof window === "undefined") {
    return;
  }

  isRouteLoading.value = true;

  if (routeLoadingTimeout !== null) {
    window.clearTimeout(routeLoadingTimeout);
  }

  routeLoadingTimeout = window.setTimeout(() => {
    isRouteLoading.value = false;
    routeLoadingTimeout = null;
  }, 180);
}

watch(() => route.fullPath, beginRouteLoading, { immediate: true });

onBeforeUnmount(() => {
  if (routeLoadingTimeout !== null) {
    window.clearTimeout(routeLoadingTimeout);
  }
});
</script>

<template>
  <component :is="shellComponent">
    <div class="route-stage">
      <div
        v-if="isRouteLoading"
        class="route-loader"
        role="status"
        aria-live="polite"
        aria-label="Loading page data"
      >
        <div class="route-loader__header">
          <span class="route-loader__line route-loader__line--eyebrow"></span>
          <span class="route-loader__line route-loader__line--title"></span>
          <span class="route-loader__line route-loader__line--lede"></span>
        </div>
        <div class="route-loader__meta" aria-hidden="true">
          <span></span>
          <span></span>
          <span></span>
        </div>
        <div class="route-loader__actions" aria-hidden="true">
          <span></span>
          <span></span>
          <span></span>
        </div>
        <div class="route-loader__cards" aria-hidden="true">
          <span></span>
          <span></span>
          <span></span>
          <span></span>
        </div>
        <div class="route-loader__table" aria-hidden="true">
          <span v-for="index in 5" :key="index"></span>
        </div>
      </div>
      <RouterView />
    </div>
  </component>
</template>

<style scoped>
.route-stage {
  display: grid;
  justify-items: center;
  gap: var(--m-space-4);
  width: min(100%, 76rem);
}

.route-loader {
  display: grid;
  gap: var(--m-space-3);
  width: min(100%, 72rem);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-subtle);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.route-loader__header,
.route-loader__meta,
.route-loader__actions,
.route-loader__cards,
.route-loader__table {
  display: grid;
  gap: var(--m-space-2);
}

.route-loader__line,
.route-loader__meta span,
.route-loader__actions span,
.route-loader__cards span,
.route-loader__table span {
  display: block;
  min-height: 0.875rem;
  border-radius: var(--m-radius-sm);
  background:
    linear-gradient(
      90deg,
      color-mix(in srgb, var(--m-border-default) 45%, transparent),
      color-mix(in srgb, var(--m-surface-base) 70%, transparent),
      color-mix(in srgb, var(--m-border-default) 45%, transparent)
    );
  background-size: 240% 100%;
  animation: route-loader-shimmer 1200ms ease-in-out infinite;
}

.route-loader__line--eyebrow {
  width: 8rem;
}

.route-loader__line--title {
  width: min(20rem, 70%);
  min-height: 1.75rem;
}

.route-loader__line--lede {
  width: min(32rem, 100%);
}

.route-loader__meta {
  grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
}

.route-loader__meta span {
  min-height: 4rem;
}

.route-loader__actions {
  grid-template-columns: repeat(3, minmax(0, 8rem));
}

.route-loader__actions span {
  min-height: 2.5rem;
}

.route-loader__cards {
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
}

.route-loader__cards span {
  min-height: 5.5rem;
}

.route-loader__table span {
  min-height: 2.75rem;
}

@keyframes route-loader-shimmer {
  from {
    background-position: 120% 0;
  }

  to {
    background-position: -120% 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .route-loader__line,
  .route-loader__meta span,
  .route-loader__actions span,
  .route-loader__cards span,
  .route-loader__table span {
    animation: none;
  }
}

@media (max-width: 40rem) {
  .route-loader {
    padding: var(--m-space-3);
  }

  .route-loader__actions {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
