<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import {
  sessionContextOrganization,
  sessionNodeLock,
  sessionSwitchingAvailable,
  sessionSwitchingUnavailableReason,
  switchSessionContext,
} from "@/session/sessionContext";
import {
  describeSwitchFailure,
  describeSwitchUnavailable,
} from "@/session/sessionContextCopy";

/*
 * `context.events` — select event within organization (UI implementation
 * contract 12.2; M16.7; CLIENT-012 through CLIENT-014).
 *
 * Choosing here is what actually moves the client: the node resolves the
 * session at the named event, and the organization, the permissions, the
 * navigation, and the branding all follow from the document it answers with.
 * Nothing on this screen decides any of them.
 *
 * A switch that lands sends the user home rather than back to the screen they
 * came from. The previous screen was a screen of the previous context, and a
 * department or shift id in its route means nothing in the new one.
 */

const route = useRoute();
const router = useRouter();

const organizationId = computed(() =>
  typeof route.params.organizationId === "string"
    ? route.params.organizationId
    : "",
);
const organization = computed(() =>
  sessionContextOrganization(organizationId.value),
);
const events = computed(() => organization.value?.events ?? []);

const unavailable = computed(() => {
  const reason = sessionSwitchingUnavailableReason.value;

  return reason === null
    ? null
    : describeSwitchUnavailable(reason, sessionNodeLock.value?.eventLabel ?? null);
});

/** The event a switch is in flight for, so only its own control says so. */
const switchingEventId = ref<string | null>(null);
const failure = ref<string | null>(null);

function formatWindow(startsAt: string | null, endsAt: string | null): string {
  if (startsAt === null && endsAt === null) {
    return "Dates not set";
  }

  const format = (value: string | null): string => {
    if (value === null) {
      return "not set";
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime())
      ? "not set"
      : new Intl.DateTimeFormat("en-US", {
          month: "short",
          day: "numeric",
          year: "numeric",
          timeZone: "UTC",
        }).format(parsed);
  };

  return `${format(startsAt)} to ${format(endsAt)}`;
}

async function switchTo(eventId: string): Promise<void> {
  switchingEventId.value = eventId;
  failure.value = null;

  const outcome = await switchSessionContext(eventId);

  switchingEventId.value = null;

  if (outcome !== "switched") {
    failure.value = describeSwitchFailure(outcome);

    return;
  }

  await router.push({ name: "home" });
}
</script>

<template>
  <section class="context-events" aria-labelledby="context-events-heading">
    <h1 id="context-events-heading" class="context-events__heading">
      Switch event
    </h1>
    <p class="context-events__lede">
      <template v-if="organization">
        Events you hold an association with in
        {{ organization.organizationLabel }}.
      </template>
      <template v-else>
        This session carries no association with that organization.
      </template>
    </p>
    <p class="context-events__back">
      <RouterLink :to="{ name: 'organizations.index' }">
        All organizations
      </RouterLink>
    </p>

    <div
      v-if="unavailable"
      class="context-events__unavailable"
      :data-reason="sessionSwitchingUnavailableReason"
      role="status"
    >
      <strong>{{ unavailable.label }}</strong>
      <span>{{ unavailable.meaning }}</span>
    </div>

    <p v-if="failure" class="context-events__failure" role="alert">
      {{ failure }}
    </p>

    <ul v-if="events.length > 0" class="context-events__list">
      <li
        v-for="event in events"
        :key="event.eventId"
        class="context-events__item"
        :data-current="event.isCurrent"
      >
        <div class="context-events__identity">
          <strong>{{ event.eventLabel }}</strong>
          <span>{{ formatWindow(event.startsAt, event.endsAt) }}</span>
        </div>
        <span v-if="event.isCurrent" class="context-events__current">
          Current
        </span>
        <!--
          The current event gets no control: switching to where you already are
          is a button with nothing to do. Everything else is offered only while
          switching is available at all (CLIENT-013).
        -->
        <button
          v-else-if="sessionSwitchingAvailable"
          type="button"
          class="context-events__switch"
          :disabled="switchingEventId !== null"
          @click="switchTo(event.eventId)"
        >
          {{
            switchingEventId === event.eventId ? "Switching" : "Work this event"
          }}
        </button>
      </li>
    </ul>
    <p v-else class="context-events__empty">
      There are no events to work in this organization.
    </p>
  </section>
</template>

<style scoped>
.context-events {
  width: var(--m-content-narrow);
}

.context-events__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.context-events__lede {
  margin: 0 0 var(--m-space-2);
  color: var(--m-text-muted);
}

.context-events__back {
  margin: 0 0 var(--m-space-4);
  font-size: var(--m-text-sm);
}

.context-events__unavailable {
  display: grid;
  gap: var(--m-space-1);
  margin-bottom: var(--m-space-4);
  padding: var(--m-space-3) var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-left: 4px solid var(--m-status-neutral);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
}

.context-events__unavailable span {
  color: var(--m-text-secondary);
}

.context-events__failure {
  margin: 0 0 var(--m-space-4);
  padding: var(--m-space-3) var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-left: 4px solid var(--m-status-warning);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
  color: var(--m-text-secondary);
}

.context-events__list {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.context-events__item {
  display: flex;
  align-items: center;
  gap: var(--m-space-3);
  padding: var(--m-space-3) var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.context-events__item[data-current="true"] {
  border-color: var(--m-action-secondary-bg);
}

.context-events__identity {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
  margin-right: auto;
}

.context-events__identity span {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.context-events__current {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.context-events__switch {
  min-height: 2.25rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 6px;
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 800;
  cursor: pointer;
}

.context-events__switch[disabled] {
  cursor: default;
  opacity: 0.6;
}

.context-events__switch:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.context-events__empty {
  margin: 0;
  color: var(--m-text-muted);
}
</style>
