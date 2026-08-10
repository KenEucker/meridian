<script setup lang="ts">
import { computed, ref } from "vue";

import { describeCommand } from "@/outbox/commandCatalog";
import type { OutboxCommand } from "@/outbox/commandOutbox";
import {
  commandOutbox,
  commandOutboxRevision,
} from "@/outbox/commandOutboxRuntime";
import {
  CommandOverrideError,
  dismissCommand,
  overrideCommand,
  retryCommand,
} from "@/outbox/submitCommand";
import { sessionHasCapability } from "@/session/clientSession";

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
 *
 * **A refusal has three answers here since M18.55** (CLIENT-017A): try again,
 * dismiss, and — where the command has an override path, the node named a
 * reason on the allowlist, and this session holds the capability — do it anyway
 * on the reader's own authority. The third is offered rarely and by design.
 * Most commands have no override path at all; most refusals of the one that
 * does are not on its allowlist; and the capability is not held by the role that
 * issues the command. Where it is not offered, nothing is rendered disabled: an
 * absent control says the decision is not this person's, and a greyed one
 * invites them to wonder what they are missing.
 */

const outbox = computed(() => {
  void commandOutboxRevision.value;

  return {
    queued: commandOutbox.unsent(),
    accepted: commandOutbox.byStatus("accepted"),
    /*
     * The refusals still waiting on somebody. One already overridden is left
     * out — it is not resolved until the node answers the override, but it is
     * no longer a decision anybody is being asked for, and listing it beside
     * the ones that are would ask for the same decision twice.
     */
    rejected: commandOutbox.unresolvedRejections(),
    overridden: commandOutbox
      .byStatus("rejected")
      .filter((command) => command.overriddenByIdempotencyKey !== null),
  };
});

/**
 * What went wrong with an override attempt, keyed by the refused command.
 *
 * The three conditions are checked before the control is rendered, so this
 * should stay empty — but "should" is doing work there, because a grant can be
 * withdrawn between the render and the click. Shown rather than swallowed, for
 * the same reason the refusal itself is.
 */
const overrideErrors = ref<Record<string, string>>({});

const visible = computed(
  () =>
    outbox.value.queued.length > 0 ||
    outbox.value.rejected.length > 0 ||
    outbox.value.overridden.length > 0,
);

const tone = computed(() =>
  outbox.value.rejected.length > 0 ? "warning" : "info",
);

const label = computed(() =>
  outbox.value.rejected.length > 0 ? "Rejected" : "Queued",
);

const meaning = computed(() => {
  const { queued, rejected, overridden } = outbox.value;

  if (rejected.length > 0) {
    return rejected.length === 1
      ? "The node refused 1 command. It will not be sent again."
      : `The node refused ${rejected.length} commands. They will not be sent again.`;
  }

  /*
   * Overrides are queued commands and are normally counted below with the rest
   * of the queue. This branch is for the moment they are not — an override sent
   * and settled while its refusal is still held — so the notice never says "0
   * commands are held" while showing a list of them.
   */
  if (queued.length === 0 && overridden.length > 0) {
    return overridden.length === 1
      ? "1 refusal was overridden on your authority."
      : `${overridden.length} refusals were overridden on your authority.`;
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

/**
 * Whether this reader may override this refusal, and what the control says.
 *
 * All three conditions in one answer, because they are one question from the
 * reader's side: either doing it anyway is available to them or it is not. The
 * node re-decides every one of them when the override arrives; this only decides
 * what to render.
 */
function overrideAction(command: OutboxCommand): string | null {
  const { override } = describeCommand(command.commandType);

  if (override === null || command.statusReasonCode === null) {
    return null;
  }

  if (!override.overridableReasonCodes.includes(command.statusReasonCode)) {
    return null;
  }

  return sessionHasCapability(override.capability) ? override.actionLabel : null;
}

/**
 * Issue the override, on this reader's authority.
 *
 * The refusal stays on screen until the node accepts the override, moved into
 * the "override queued" list. That is the honest state: what has happened so far
 * is that somebody decided, not that the node agreed.
 */
function override(command: OutboxCommand): void {
  try {
    overrideCommand(command.idempotencyKey);
    delete overrideErrors.value[command.idempotencyKey];
  } catch (error) {
    overrideErrors.value = {
      ...overrideErrors.value,
      [command.idempotencyKey]:
        error instanceof CommandOverrideError
          ? error.message
          : "The override could not be recorded on this device.",
    };
  }
}

function dismiss(command: OutboxCommand): void {
  dismissCommand(command.idempotencyKey);
}

/**
 * Put a refusal back in the queue.
 *
 * Offered because a rejection is not always the last word — the condition the
 * node refused on can be fixed — and the alternative for the user is retyping
 * work they already did. Nothing retries on their behalf.
 */
function retry(command: OutboxCommand): void {
  retryCommand(command.idempotencyKey);
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
          <span
            v-if="overrideErrors[command.idempotencyKey]"
            class="command-outbox__item-reason"
            data-outbox-override-error
          >
            {{ overrideErrors[command.idempotencyKey] }}
          </span>
          <span class="command-outbox__item-actions">
            <button
              v-if="overrideAction(command)"
              type="button"
              class="command-outbox__action command-outbox__action--override"
              data-outbox-override
              @click="override(command)"
            >
              {{ overrideAction(command) }}
            </button>
            <button
              type="button"
              class="command-outbox__action"
              @click="retry(command)"
            >
              Try again
            </button>
            <button
              type="button"
              class="command-outbox__action"
              @click="dismiss(command)"
            >
              Dismiss
            </button>
          </span>
        </li>
      </ul>

      <!--
        Refusals somebody has already overridden. Kept on screen and stated
        plainly, because the override is queued rather than applied: what has
        happened is that a named person decided, and the node has not answered
        yet. It leaves this list the moment the override is accepted, and if the
        override is itself refused, that refusal appears above with its own
        reason.
      -->
      <ul v-if="outbox.overridden.length > 0" class="command-outbox__list">
        <li
          v-for="command in outbox.overridden"
          :key="command.idempotencyKey"
          class="command-outbox__item"
          data-outbox-overridden
        >
          <span class="command-outbox__item-name">{{ describe(command) }}</span>
          <span class="command-outbox__item-reason">
            Refused, and overridden on your authority. The override is waiting to
            reach the node.
          </span>
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

.command-outbox__item-actions {
  display: flex;
  gap: var(--m-space-2);
  margin-left: auto;
}

.command-outbox__action {
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

/*
 * The override reads as the weightier of the three, because it is: the other two
 * send the same work again or drop it, and this one proceeds past a refusal on
 * the reader's own authority. Weight is carried by the border rather than by a
 * fill, so it stands out among its neighbours without becoming the default
 * action of a warning notice.
 */
.command-outbox__action--override {
  border-color: var(--m-status-warning);
  color: var(--m-text-primary);
}

.command-outbox__action:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
