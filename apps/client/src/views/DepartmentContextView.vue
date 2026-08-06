<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, useRoute } from "vue-router";

import {
  selectSessionDepartment,
  sessionDepartmentAccesses,
  sessionDepartmentRoleSummary,
  sessionEstablished,
  sessionEventContext,
  selectedSessionDepartmentId,
} from "@/session/sessionAccess";

/*
 * `context.departments` — enter available department spaces (M18.29; UI
 * implementation contract 12.2; CLIENT-005).
 *
 * The third context screen, and the one that is unlike the other two. Switching
 * organization or event is connected-only, because only the node can resolve a
 * session at a different event. Entering a department is not: the departments in
 * scope are already in the session the client is holding, cached or live, so
 * this screen opens on a device with no signal — which is the state a lead
 * standing in a field is most likely to be in.
 *
 * Nothing here decides authority. Every department listed is one the session
 * response carries, and what somebody may do inside it is the set of
 * capabilities that same response resolved there. The summary beside each entry
 * is built from the role names the node sent, so it says what the node would
 * say.
 *
 * Entering records the choice the way the router does when a department-scoped
 * URL is followed (M16.6), so the shell, the navigation, and the surface all
 * read the same selection afterwards.
 */

const route = useRoute();

/**
 * The event these department spaces belong to.
 *
 * The route's event rather than the session's, because this address is
 * followable: a deep link naming an event is the reason somebody is here. The
 * session's event is the fallback for a client that reached the screen without
 * one in the path.
 */
const eventId = computed(() =>
  typeof route.params.eventId === "string" && route.params.eventId !== ""
    ? route.params.eventId
    : (sessionEventContext.value?.eventId ?? null),
);

const departments = computed(() => sessionDepartmentAccesses.value);

function enter(departmentId: string): void {
  selectSessionDepartment(departmentId);
}
</script>

<template>
  <section class="context-departments" aria-labelledby="context-departments-heading">
    <h1 id="context-departments-heading" class="context-departments__heading">
      Departments
    </h1>
    <p class="context-departments__lede">
      The departments you are associated with in this event. Choose one to work
      in it.
    </p>

    <!--
      A client holding nothing renders nothing rather than a map of pages it
      would be refused at (CLIENT-005). It is a different sentence from "you
      belong to no departments", which is a fact about a session that resolved.
    -->
    <p v-if="!sessionEstablished" class="context-departments__empty" role="status">
      This device is not holding a session, so there are no departments to enter.
    </p>

    <p
      v-else-if="departments.length === 0"
      class="context-departments__empty"
      role="status"
    >
      This session carries no department associations.
    </p>

    <!--
      A department space is an address inside an event, so without an event
      there is no link to build. Saying so beats rendering entries that cannot
      be followed.
    -->
    <p
      v-else-if="eventId === null"
      class="context-departments__empty"
      role="status"
    >
      This session has not resolved an event, so department spaces cannot be
      opened from here.
    </p>

    <ul v-else class="context-departments__list">
      <li
        v-for="department in departments"
        :key="department.departmentId"
        class="context-departments__item"
        :data-current="department.departmentId === selectedSessionDepartmentId"
      >
        <div class="context-departments__identity">
          <strong>{{ department.departmentLabel }}</strong>
          <span>{{ sessionDepartmentRoleSummary(department) }}</span>
        </div>
        <span
          v-if="department.departmentId === selectedSessionDepartmentId"
          class="context-departments__current"
        >
          Current
        </span>
        <RouterLink
          class="context-departments__enter"
          :to="{
            name: 'events.departments.show',
            params: { eventId, departmentId: department.departmentId },
          }"
          @click="enter(department.departmentId)"
        >
          Enter
        </RouterLink>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.context-departments {
  width: var(--m-content-narrow);
}

.context-departments__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.context-departments__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.context-departments__list {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.context-departments__item {
  display: flex;
  align-items: center;
  gap: var(--m-space-3);
  padding: var(--m-space-3) var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.context-departments__item[data-current="true"] {
  border-color: var(--m-action-secondary-bg);
}

.context-departments__identity {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
  margin-right: auto;
}

.context-departments__identity span {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.context-departments__current {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.context-departments__enter {
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

.context-departments__enter:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.context-departments__empty {
  margin: 0;
  color: var(--m-text-muted);
}
</style>
