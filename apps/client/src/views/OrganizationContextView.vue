<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import {
  sessionContextOrganizations,
  sessionNodeLock,
  sessionSwitchingAvailable,
  sessionSwitchingUnavailableReason,
} from "@/session/sessionContext";
import { describeSwitchUnavailable } from "@/session/sessionContextCopy";

/*
 * `context.organizations` — select organization (UI implementation contract
 * 12.2; M16.7; CLIENT-011 through CLIENT-013).
 *
 * A dedicated context-switching surface, which is where contract rule 4 puts
 * this: the top bar links here and never becomes the switcher itself.
 *
 * Every organization listed is one the session response says the user holds an
 * association with, so there is no filtering to do here and no id this screen
 * could offer that the node would refuse. Choosing an organization does not
 * itself switch anything — an event is what a context resolves at — so each
 * entry leads to that organization's events.
 */

const organizations = computed(() => sessionContextOrganizations.value);
const unavailable = computed(() => {
  const reason = sessionSwitchingUnavailableReason.value;

  return reason === null
    ? null
    : describeSwitchUnavailable(reason, sessionNodeLock.value?.eventLabel ?? null);
});
</script>

<template>
  <section class="context-organizations" aria-labelledby="context-organizations-heading">
    <h1 id="context-organizations-heading" class="context-organizations__heading">
      Switch organization
    </h1>
    <p class="context-organizations__lede">
      The organizations you hold an association with. Choose one to see its
      events.
    </p>

    <!--
      Absent, not disabled: a disabled switcher invites someone to keep trying
      something that cannot work here (operating guide 8.3A). The reason is
      stated instead, because a control that is simply missing reads as one the
      client lost.
    -->
    <div
      v-if="unavailable"
      class="context-organizations__unavailable"
      :data-reason="sessionSwitchingUnavailableReason"
      role="status"
    >
      <strong>{{ unavailable.label }}</strong>
      <span>{{ unavailable.meaning }}</span>
    </div>

    <ul v-if="organizations.length > 0" class="context-organizations__list">
      <li
        v-for="organization in organizations"
        :key="organization.organizationId"
        class="context-organizations__item"
        :data-current="organization.isCurrent"
      >
        <div class="context-organizations__identity">
          <strong>{{ organization.organizationLabel }}</strong>
          <span>
            {{
              organization.events.length === 1
                ? "1 event"
                : `${organization.events.length} events`
            }}
          </span>
        </div>
        <span v-if="organization.isCurrent" class="context-organizations__current">
          Current
        </span>
        <RouterLink
          v-if="sessionSwitchingAvailable && organization.events.length > 0"
          class="context-organizations__enter"
          :to="{
            name: 'organizations.events.index',
            params: { organizationId: organization.organizationId },
          }"
        >
          View events
        </RouterLink>
      </li>
    </ul>
    <p v-else class="context-organizations__empty">
      This session carries no organization associations.
    </p>
  </section>
</template>

<style scoped>
.context-organizations {
  width: var(--m-content-narrow);
}

.context-organizations__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.context-organizations__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.context-organizations__unavailable {
  display: grid;
  gap: var(--m-space-1);
  margin-bottom: var(--m-space-4);
  padding: var(--m-space-3) var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-left: 4px solid var(--m-status-neutral);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
}

.context-organizations__unavailable span {
  color: var(--m-text-secondary);
}

.context-organizations__list {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.context-organizations__item {
  display: flex;
  align-items: center;
  gap: var(--m-space-3);
  padding: var(--m-space-3) var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.context-organizations__item[data-current="true"] {
  border-color: var(--m-action-secondary-bg);
}

.context-organizations__identity {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
  margin-right: auto;
}

.context-organizations__identity span {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.context-organizations__current {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.context-organizations__enter {
  display: inline-flex;
  align-items: center;
  min-height: 2.25rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 6px;
  color: var(--m-text-primary);
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-decoration: none;
}

.context-organizations__enter:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.context-organizations__empty {
  margin: 0;
  color: var(--m-text-muted);
}
</style>
