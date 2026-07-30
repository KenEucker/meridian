<script setup lang="ts">
import { computed } from "vue";

import { describeCommand } from "@/outbox/commandCatalog";
import type { OutboxCommand } from "@/outbox/commandOutbox";
import {
  commandOutbox,
  commandOutboxRevision,
} from "@/outbox/commandOutboxRuntime";
import { dismissCommand } from "@/outbox/submitCommand";

/*
 * What the client says about the commands it is holding (M16.10; CLIENT-017;
 * UI implementation contract 16.3; UI operating guide 17.4, 17.7).
 *
 * The states and their labels are the contract's four, used verbatim, so the
 * same work reads the same way wherever it is reported.
 *
 * Two of them bring the notice on screen:
 *
 *   Queued    work this device is holding and will send. Stated as information:
 *             a device working offline during its event is not in a failure
 *             state, and dressing it as one is how an indicator gets trained out
 *             of a user's attention (contract 16.2).
 *   Rejected  the node refused it and it will not retry. A warning, listed one
 *             by one with the node's reason, and dismissed only by a person
 *             saying so. "A user who recorded a check-in and walked away must be
 *             able to find out that it failed" (operating guide 17.7).
 *
 * Accepted commands are reported alongside them rather than on their own. They
 * are the ordinary outcome, and a standing "everything sent" banner on every
 * screen is exactly the sync noise routine field work is not to be interrupted
 * with (contract 16.2). The full list, accepted included, is on the device
 * diagnostics surface, which is where sync detail belongs (operating guide 17.4).
 */

const outbox = computed(() => {
  void commandOutboxRevision.value;

  return {
    queued: commandOutbox.unsent(),
    accepted: commandOutbox.byStatus("accepted"),
    rejected: commandOutbox.byStatus("rejected"),
  };
});

const visible = computed(
  () => outbox.value.queued.length > 0 || outbox.value.rejected.length > 0,
);

const tone = computed(() =>
  outbox.value.rejected.length > 0 ? "warning" : "info",
);

const label = computed(() =>
  outbox.value.rejected.length > 0 ? "Rejected" : "Queued",
);

const meaning = computed(() => {
  const { queued, rejected } = outbox.value;

  if (rejected.length > 0) {
    return rejected.length === 1
      ? "The node refused 1 command. It will not be sent again."
      : `The node refused ${rejected.length} commands. They will not be sent again.`;
  }

  return queued.length === 1
    ? "1 command is held on this device, waiting to reach the node."
    : `${queued.length} commands are held on this device, waiting to reach the node.`;
});

const acceptedNote = computed(() => {
  const accepted = outbox.value.accepted.length;

  if (accepted === 0) {
    return null;
  }

  return accepted === 1
    ? "1 command has been accepted by the node."
    : `${accepted} commands have been accepted by the node.`;
});

function describe(command: OutboxCommand): string {
  const { label: commandLabel } = describeCommand(command.commandType);

  return command.detail === null
    ? commandLabel
    : `${commandLabel} — ${command.detail}`;
}

function dismiss(command: OutboxCommand): void {
  dismissCommand(command.idempotencyKey);
}
</script>

<template>
  <div
    v-if="visible"
    class="command-outbox"
    :class="`command-outbox--${tone}`"
    :data-outbox-state="tone === 'warning' ? 'rejected' : 'queued'"
    role="status"
    aria-live="polite"
  >
    <div class="command-outbox__body">
      <span class="command-outbox__label">{{ label }}</span>
      <span class="command-outbox__meaning">{{ meaning }}</span>
      <span v-if="acceptedNote" class="command-outbox__accepted">
        {{ acceptedNote }}
      </span>

      <ul v-if="outbox.rejected.length > 0" class="command-outbox__list">
        <li
          v-for="command in outbox.rejected"
          :key="command.idempotencyKey"
          class="command-outbox__item"
        >
          <span class="command-outbox__item-name">{{ describe(command) }}</span>
          <span class="command-outbox__item-reason">
            {{ command.statusReason ?? "The node gave no reason." }}
          </span>
          <button
            type="button"
            class="command-outbox__dismiss"
            @click="dismiss(command)"
          >
            Dismiss
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
/*
 * The same restrained shape as the OfflineBanner and the session permissions
 * notice: one line of state, one line of meaning, a left edge carrying tone.
 * They are different indicators sitting in the same place, and a user should not
 * have to learn a third visual language to read this one. State is carried by
 * the label as well as by color (accessibility checklist).
 */
.command-outbox {
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

.command-outbox--info {
  border-left-color: var(--m-status-neutral);
}

.command-outbox--warning {
  border-left-color: var(--m-status-warning);
}

.command-outbox__body {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-1);
  width: 100%;
}

.command-outbox__label {
  font-weight: 600;
}

.command-outbox__meaning {
  color: var(--m-text-secondary);
}

.command-outbox__accepted {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.command-outbox__list {
  margin: var(--m-space-2) 0 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2);
}

.command-outbox__item {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.command-outbox__item-name {
  font-weight: 600;
}

.command-outbox__item-reason {
  color: var(--m-text-secondary);
}

.command-outbox__dismiss {
  margin-left: auto;
  min-height: 2.25rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 6px;
  background: var(--m-surface-base);
  color: var(--m-text-secondary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 800;
  cursor: pointer;
}

.command-outbox__dismiss:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
