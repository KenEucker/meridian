<script setup lang="ts">
export type AutosaveStatusState =
  | "saved"
  | "saving"
  | "failed"
  | "blocked_offline";

const props = defineProps<{
  readonly state: AutosaveStatusState;
  readonly lastSavedAt?: string | null;
  readonly message?: string | null;
  readonly repairHref?: string | null;
}>();

function statusLabel(): string {
  return {
    saved: "Saved",
    saving: "Saving",
    failed: "Autosave failed",
    blocked_offline: "Offline",
  }[props.state];
}

function statusMessage(): string {
  if (props.message) {
    return props.message;
  }

  if (props.state === "saved" && props.lastSavedAt) {
    return `Last saved ${props.lastSavedAt}`;
  }

  return {
    saved: "All changes are saved.",
    saving: "Saving changes.",
    failed: "Changes could not be saved.",
    blocked_offline: "Incident create/edit requires server connection.",
  }[props.state];
}
</script>

<template>
  <div
    class="autosave-status"
    :class="`autosave-status--${state}`"
    role="status"
    aria-live="polite"
  >
    <span class="autosave-status__label">{{ statusLabel() }}</span>
    <span class="autosave-status__message">{{ statusMessage() }}</span>
    <a
      v-if="state === 'failed' && repairHref"
      class="autosave-status__repair"
      :href="repairHref"
    >
      Repair
    </a>
  </div>
</template>

<style scoped>
.autosave-status {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-2);
  min-height: 2.5rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-left-width: 4px;
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-secondary);
}

.autosave-status--saved {
  border-left-color: var(--m-status-success);
}

.autosave-status--saving {
  border-left-color: var(--m-status-neutral);
}

.autosave-status--failed,
.autosave-status--blocked_offline {
  border-left-color: var(--m-status-danger);
}

.autosave-status__label {
  color: var(--m-text-primary);
  font-weight: 800;
}

.autosave-status__message {
  font-size: var(--m-text-sm);
}

.autosave-status__repair {
  color: var(--m-action-secondary-bg);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.autosave-status__repair:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
