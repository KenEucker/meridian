<script setup lang="ts">
import { computed } from "vue";

import {
  describeConnectivityState,
  shouldShowOfflineBanner,
  type ConnectivityState,
} from "@/offline/syncStatus";

// Shared offline/sync status banner for the field app and kiosk (M8.6).
//
// Implements UI implementation contract section 11.13 (OfflineBanner) and the
// section 16.1 connectivity labels. Rules honored:
//   - shown only where the state affects current work: the banner renders
//     nothing for `online` (section 16.2), so routine field work is not
//     interrupted with sync noise;
//   - local node connectivity is distinguished from central connectivity via
//     separate states/labels;
//   - it is a non-interruptive status region (polite live region), never a
//     dialog, so it does not block the current action;
//   - state is conveyed by the canonical text label, never by color alone
//     (accessibility checklist); the tone glyph is decorative.
const props = defineProps<{
  /** Current connectivity/sync state (contract section 11.13). */
  state: ConnectivityState;
  /** Optional scope label describing where the state applies. */
  scope?: string;
  /** Number of local actions waiting to sync; shown for the queued state. */
  queuedCount?: number;
  /** Optional repair path for conflict/failure states (advanced mode). */
  repairHref?: string;
}>();

const visible = computed(() => shouldShowOfflineBanner(props.state));
const descriptor = computed(() => describeConnectivityState(props.state));

// Show the queued count only when it is meaningful for the queued state, so the
// user or role can trust that queued actions are accounted for (section 16.2).
const queuedLabel = computed(() => {
  if (props.state !== "sync_queued" || props.queuedCount === undefined) {
    return null;
  }
  const count = props.queuedCount;
  return `${count} ${count === 1 ? "action" : "actions"} queued`;
});

const TONE_GLYPH = {
  ok: "\u2713",
  info: "\u2139",
  warning: "\u26A0",
  critical: "\u2715",
} as const;

const glyph = computed(() => TONE_GLYPH[descriptor.value.tone]);
</script>

<template>
  <div
    v-if="visible"
    class="offline-banner"
    :class="`offline-banner--${descriptor.tone}`"
    :data-state="state"
    role="status"
    aria-live="polite"
  >
    <span class="offline-banner__glyph" aria-hidden="true">{{ glyph }}</span>
    <span class="offline-banner__body">
      <span class="offline-banner__label">{{ descriptor.label }}</span>
      <span class="offline-banner__meaning">{{ descriptor.meaning }}</span>
      <span v-if="scope" class="offline-banner__scope">{{ scope }}</span>
      <span v-if="queuedLabel" class="offline-banner__queued">{{
        queuedLabel
      }}</span>
      <span v-if="$slots.default" class="offline-banner__detail">
        <slot />
      </span>
    </span>
    <a
      v-if="repairHref"
      class="offline-banner__repair"
      :href="repairHref"
      >Resolve</a
    >
  </div>
</template>

<style scoped>
.offline-banner {
  display: flex;
  align-items: flex-start;
  gap: var(--m-space-3);
  padding: var(--m-space-3) var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-left-width: 4px;
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
}

.offline-banner--ok {
  border-left-color: var(--m-status-success);
}

.offline-banner--info {
  border-left-color: var(--m-status-neutral);
}

.offline-banner--warning {
  border-left-color: var(--m-status-warning);
}

.offline-banner--critical {
  border-left-color: var(--m-status-danger);
}

.offline-banner__glyph {
  flex: none;
  width: 1.5rem;
  height: 1.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--m-radius-pill);
  font-weight: 700;
  line-height: 1;
  color: var(--m-text-inverse);
}

.offline-banner--ok .offline-banner__glyph {
  background: var(--m-status-success);
}

.offline-banner--info .offline-banner__glyph {
  background: var(--m-status-neutral);
}

.offline-banner--warning .offline-banner__glyph {
  background: var(--m-status-warning);
}

.offline-banner--critical .offline-banner__glyph {
  background: var(--m-status-danger);
}

.offline-banner__body {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-1);
}

.offline-banner__label {
  font-weight: 600;
}

.offline-banner__meaning {
  color: var(--m-text-secondary);
}

.offline-banner__scope,
.offline-banner__queued,
.offline-banner__detail {
  font-size: var(--m-text-sm);
  color: var(--m-text-muted);
}

.offline-banner__repair {
  margin-left: auto;
  align-self: center;
  font-weight: 600;
  color: var(--m-text-secondary);
}

.offline-banner__repair:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
