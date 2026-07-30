<script setup lang="ts">
import { computed } from "vue";

import { clientSessionState, refreshClientSession } from "@/session/clientSession";
import { sessionContextEvent } from "@/session/sessionDocument";

/*
 * What the client says about the permissions it is working from (M16.5;
 * CLIENT-009; UI implementation contract 19A.2; UI operating guide 18.2A).
 *
 * Two states are worth saying out loud and no others:
 *
 *   cached  — working from the last answer the node gave, inside the event
 *             window. Stated as information. A device operating normally offline
 *             during its event is not in a failure state, and dressing it as one
 *             is how an indicator gets trained out of a user's attention.
 *   expired — the window has ended, or there is no event context, so the client
 *             will not act on what it holds until the node answers again. This
 *             one is a warning, because work is actually blocked.
 *
 * A live session says nothing: it is the ordinary case, and a banner reading
 * "permissions are current" on every screen is noise. A client with no session
 * at all also says nothing here — that is a sign-in state, and this component
 * would be guessing at a story that belongs to login.
 *
 * This is not the OfflineBanner and must not be merged with it (contract 19A.2).
 * A device can be online with stale permissions or offline with fresh ones, and
 * collapsing the two indicators loses exactly the distinction that matters when
 * someone is trying to work out why an action is missing.
 */

const status = computed(() => clientSessionState.status);
const visible = computed(
  () => status.value === "cached" || status.value === "expired",
);

/**
 * The event's own clock, because that is the clock the people reading this are
 * working on. UTC is the fallback rather than the device's zone: a timestamp
 * whose zone is unstated and unknowable is worse than one plainly labelled.
 */
const timeZone = computed(
  () => {
    const document = clientSessionState.document;

    return (document === null ? null : sessionContextEvent(document)?.timezone) ?? "UTC";
  },
);

function formatMoment(timestamp: string | null): string | null {
  if (timestamp === null) {
    return null;
  }

  const parsed = new Date(timestamp);

  if (Number.isNaN(parsed.getTime())) {
    return null;
  }

  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
    timeZone: timeZone.value,
    timeZoneName: "short",
  }).format(parsed);
}

const lastRefreshed = computed(() => formatMoment(clientSessionState.refreshedAt));

const label = computed(() =>
  status.value === "expired"
    ? "Permissions need a refresh"
    : "Permissions are cached",
);

const meaning = computed(() => {
  if (status.value === "cached") {
    return "This device is working from the permissions it last received.";
  }

  switch (clientSessionState.refreshReason) {
    case "event_window_ended":
      return "The event this device cached its permissions for has ended. Reconnect to the node to continue.";
    case "no_event_context":
      return "This device holds no event context. Reconnect to the node to continue.";
    default:
      return "This device cannot confirm the event its permissions were cached for. Reconnect to the node to continue.";
  }
});

const refreshLabel = computed(() =>
  clientSessionState.refreshing ? "Refreshing" : "Refresh permissions",
);

async function refresh(): Promise<void> {
  await refreshClientSession();
}
</script>

<template>
  <div
    v-if="visible"
    class="session-permissions"
    :class="`session-permissions--${status === 'expired' ? 'warning' : 'info'}`"
    :data-session-status="status"
    role="status"
    aria-live="polite"
  >
    <span class="session-permissions__body">
      <span class="session-permissions__label">{{ label }}</span>
      <span class="session-permissions__meaning">{{ meaning }}</span>
      <span v-if="lastRefreshed" class="session-permissions__refreshed">
        Last refreshed {{ lastRefreshed }}
      </span>
      <span v-else class="session-permissions__refreshed">
        Last refresh time is not recorded.
      </span>
    </span>
    <button
      type="button"
      class="session-permissions__refresh"
      :disabled="clientSessionState.refreshing"
      @click="refresh"
    >
      {{ refreshLabel }}
    </button>
  </div>
</template>

<style scoped>
/*
 * Deliberately the same restrained shape as the OfflineBanner: one line of
 * state, one line of meaning, a left edge carrying tone. They are different
 * indicators, but they sit in the same place on the page and a user should not
 * have to learn two visual languages to read them.
 *
 * Tone is on the border and never on the text alone, and every state is carried
 * by its label as well as by its color (accessibility checklist).
 */
.session-permissions {
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

.session-permissions--info {
  border-left-color: var(--m-status-neutral);
}

.session-permissions--warning {
  border-left-color: var(--m-status-warning);
}

.session-permissions__body {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-1);
}

.session-permissions__label {
  font-weight: 600;
}

.session-permissions__meaning {
  color: var(--m-text-secondary);
}

.session-permissions__refreshed {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.session-permissions__refresh {
  margin-left: auto;
  align-self: center;
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

.session-permissions__refresh[disabled] {
  cursor: default;
  opacity: 0.6;
}

.session-permissions__refresh:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
