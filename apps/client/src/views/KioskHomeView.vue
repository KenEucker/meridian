<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import ContentGrid from "@/components/ContentGrid.vue";
import DashboardWidgetCard from "@/components/DashboardWidgetCard.vue";
import DashboardSection from "@/components/sections/DashboardSection.vue";
import type { DashboardContext } from "@/dashboard/dashboardModel";
import {
  kioskCurrentUserWidget,
  kioskNodeStatusWidget,
} from "@/dashboard/kioskDeviceWidgets";
import { useNodeConnectionStatus } from "@/offline/useConnectivity";
import { kioskContextState } from "@/session/kioskContext";
import { workstationSessionState } from "@/session/workstationSession";

/*
 * `kiosk.home` — trusted workstation dashboard (UI contract 12.8, 13.6;
 * dashboard widget spec 9; M16.9, M18.28).
 *
 * The frame technical spec 13.3 requires — who is signed in, which workstation
 * this is — with the kiosk widget group under it.
 *
 * Two things about this screen are not like the other dashboards.
 *
 *  1. **The workstation supplies the scope.** No event or department is sent
 *     with the read. The node resolves both from the workstation's own pinned
 *     context, which is the honest source: the machine is at one department's
 *     desk, and a Kiosk that could ask about another department by changing a
 *     URL would be a Kiosk with a scope somebody at the keyboard chose.
 *  2. **Two widgets are answered here.** `kiosk.node_status` and
 *     `kiosk.switch_user` are about this machine — whether its node is answering
 *     and who is standing at it — and the node deliberately compiles neither.
 *     They are built from device state and rendered in the same card.
 *
 * The pinned context still grants nothing. Every widget the node sends back is
 * gated on what the signed-in person may do, which is the distinction widget
 * spec 9 draws between trusted workstation state and individual user authority.
 */

const user = computed(() => workstationSessionState.user);
const workstation = computed(() => workstationSessionState.workstation);
const eventId = computed(() => workstationSessionState.eventId);

const nodeConnection = useNodeConnectionStatus();

/**
 * The device widgets' context.
 *
 * They carry no destination that needs an event or a department — node status
 * opens device readiness, and switching user is a Kiosk screen — so this is the
 * minimum a card needs rather than a second copy of the read's context.
 */
const deviceContext = computed<DashboardContext>(() => ({
  eventId: eventId.value ?? "",
  eventLabel: null,
  organizationId: workstation.value?.organizationId ?? null,
  departmentId: workstation.value?.departmentId ?? null,
  departmentLabel: null,
  timeZone: "UTC",
  asOf: new Date().toISOString(),
}));

const deviceWidgets = computed(() => [
  kioskNodeStatusWidget(nodeConnection.value),
  kioskCurrentUserWidget(user.value?.name ?? null, workstation.value?.name ?? null),
]);

/**
 * The pinned context, as the node named it (M18.32; UI contract 18.1).
 *
 * "Kiosk screens must show current organization, current event, operations-window
 * state, trusted workstation state." The session response carries identifiers;
 * the names come from the pinned-context read, which is the one thing on the
 * machine that knows what those identifiers are called.
 */
const pinnedContext = computed(() => kioskContextState.context);
</script>

<template>
  <section class="kiosk-home" aria-labelledby="kiosk-home-heading">
    <h1 id="kiosk-home-heading" class="kiosk-home__heading">
      {{ user ? `Signed in as ${user.name}` : "Workstation" }}
    </h1>
    <p v-if="workstation" class="kiosk-home__workstation">
      {{ workstation.name }}
    </p>

    <!--
      Where this machine is working, in words rather than identifiers (UI
      contract 18.1). The department line is absent rather than "none" when the
      workstation is the whole site's, because that is a different fact from a
      department nobody recorded.
    -->
    <p v-if="pinnedContext" class="kiosk-home__context" data-testid="kiosk-home-context">
      {{ pinnedContext.organization?.name ?? "Unknown organization" }} ·
      {{ pinnedContext.event?.name ?? "Unknown event"
      }}<template v-if="pinnedContext.department">
        · {{ pinnedContext.department.name }}</template
      >
    </p>

    <!--
      The two Kiosk surfaces this dashboard leads to. Switching users is here
      rather than only in the session bar because it is the act a queue of people
      is waiting on; the shift board is the desk's own work.
    -->
    <nav class="kiosk-home__links" aria-label="Kiosk surfaces">
      <RouterLink class="kiosk-home__link" :to="{ name: 'kiosk.shift-board' }">
        Shift board
      </RouterLink>
      <RouterLink class="kiosk-home__link" :to="{ name: 'kiosk.switch-user' }">
        Switch user
      </RouterLink>
      <RouterLink class="kiosk-home__link" :to="{ name: 'kiosk.setup' }">
        Workstation setup
      </RouterLink>
    </nav>

    <ContentGrid min="tile" label="Workstation widgets">
      <DashboardWidgetCard
        v-for="widget in deviceWidgets"
        :key="widget.id"
        :widget="widget"
        :context="deviceContext"
      />
    </ContentGrid>

    <!--
      No event pinned means no operational scope, and a Kiosk that inferred one
      would be a Kiosk showing another desk's work (UI-019).
    -->
    <p v-if="!eventId" class="kiosk-home__empty">
      This workstation is not pinned to an event, so there are no operational
      tasks to show. Your session ends after 5 minutes without activity, or when
      you end it.
    </p>

    <DashboardSection
      v-else
      :event-id="eventId"
      :groups="['kiosk']"
      unavailable-message="This workstation has no operational tasks for you. Your session ends after 5 minutes without activity, or when you end it."
    />
  </section>
</template>

<style scoped>
.kiosk-home {
  display: grid;
  gap: var(--m-space-4);
  align-content: start;
  width: var(--m-content-workflow);
}

.kiosk-home__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.kiosk-home__workstation {
  margin: calc(var(--m-space-4) * -1 + var(--m-space-1)) 0 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-transform: uppercase;
}

.kiosk-home__context {
  margin: 0;
  color: var(--m-text-secondary);
  font-weight: 700;
}

.kiosk-home__links {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.kiosk-home__link {
  display: inline-flex;
  align-items: center;
  min-height: 3rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font-weight: 900;
  text-decoration: none;
}

.kiosk-home__link:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.kiosk-home__empty {
  margin: 0;
  color: var(--m-text-muted);
}
</style>
