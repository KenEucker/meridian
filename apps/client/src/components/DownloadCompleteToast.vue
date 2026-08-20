<script setup lang="ts">
/*
 * The one-sentence completion notice for offline downloads (CLIENT-027;
 * technical spec 9.7; UI implementation contract 16.4, 11.14 Toast).
 *
 * Rendering only. When to show — the transition from holding less than the
 * node named to holding all of it, once, never for a refresh that downloads
 * nothing — is decided in `offline/downloadStatus.ts`, and the self-dismissal
 * timer lives there with it, so this component cannot be persistent by
 * accident: it has no dismiss control because there is nothing to dismiss
 * (11.14 — brief feedback for a routine result, never essential information
 * that disappears before action; the standing readout is on Settings).
 */
import {
  DOWNLOAD_COMPLETE_NOTICE,
  downloadCompleteNoticeVisible,
} from "@/offline/downloadStatus";
</script>

<template>
  <Transition name="download-toast">
    <div
      v-if="downloadCompleteNoticeVisible"
      class="download-toast"
      role="status"
      aria-live="polite"
    >
      {{ DOWNLOAD_COMPLETE_NOTICE }}
    </div>
  </Transition>
</template>

<style scoped>
.download-toast {
  position: fixed;
  bottom: var(--m-space-6);
  left: 50%;
  transform: translateX(-50%);
  z-index: 40;
  max-width: min(28rem, calc(100vw - 2 * var(--m-space-4)));
  padding: var(--m-space-3) var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-left: 4px solid var(--m-status-success);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  box-shadow: var(--m-shadow-sm);
}

.download-toast-enter-active,
.download-toast-leave-active {
  transition: opacity 200ms ease;
}

.download-toast-enter-from,
.download-toast-leave-to {
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .download-toast-enter-active,
  .download-toast-leave-active {
    transition: none;
  }
}
</style>
