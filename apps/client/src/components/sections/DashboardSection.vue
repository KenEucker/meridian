<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import ContentGrid from "@/components/ContentGrid.vue";
import DashboardWidgetCard from "@/components/DashboardWidgetCard.vue";
import StaleReadNotice from "@/components/StaleReadNotice.vue";
import {
  fetchDashboard,
  orderedWidgets,
  type Dashboard,
  type DashboardGroupId,
} from "@/dashboard/dashboardModel";

/**
 * The widget groups of one dashboard surface (M18.28; UI contract 13).
 *
 * Every dashboard renders through this: `staff.dashboard` asks for the staff
 * group, `department.dashboard` for the two department groups,
 * `organizer.dashboard` for the organizer group, `ims.dashboard` for IC, and the
 * Kiosk home screen for the kiosk group. One read fills all of them, so a person
 * who is a lead and an organizer sees the same numbers on both of their pages.
 *
 * A group the reader does not hold does not come back, and the surface says so
 * rather than rendering an empty region: "you have no organizer dashboard" and
 * "the event is quiet" are different answers and a blank area says neither.
 */
const props = withDefaults(
  defineProps<{
    readonly eventId: string;
    readonly departmentId?: string | null;
    /** Which of the six groups this surface renders. */
    readonly groups: readonly DashboardGroupId[];
    /**
     * What to say when the reader holds none of them. Named by the surface
     * because the answer differs: an organizer page explains organizer standing,
     * a department page explains department standing.
     */
    readonly unavailableMessage: string;
  }>(),
  { departmentId: null },
);

const dashboard = ref<Dashboard | null>(null);
const failure = ref<string | null>(null);
const loading = ref(true);

async function load(): Promise<void> {
  loading.value = true;
  failure.value = null;

  try {
    dashboard.value = await fetchDashboard(props.eventId, props.departmentId);
  } catch (error) {
    dashboard.value = null;
    failure.value = meridianErrorMessage(
      error,
      "This dashboard could not be read.",
    );
  } finally {
    loading.value = false;
  }
}

onMounted(load);
watch(() => [props.eventId, props.departmentId], load);

/**
 * The groups this surface renders, in the order the surface asked for them,
 * narrowed to the ones the reader actually holds.
 */
const shown = computed(() => {
  const held = dashboard.value?.groups ?? [];

  return props.groups
    .map((id) => held.find((group) => group.group === id))
    .filter((group): group is NonNullable<typeof group> => group !== undefined);
});

const widgetsFor = (group: DashboardGroupId) =>
  orderedWidgets(dashboard.value?.widgets ?? [], group);

/**
 * The widgets this node could not compile, for the groups on screen.
 *
 * Named rather than left out. UI contract 13 lists them and somebody comparing
 * the contract against the screen should find the difference explained here
 * instead of concluding the widget is broken.
 *
 * Silent when the reader holds none of the groups. Telling somebody what is
 * missing from a dashboard they may not read describes a page they are not
 * looking at, and reads as an excuse for the refusal above it.
 */
const deferred = computed(() =>
  shown.value.length === 0
    ? []
    : (dashboard.value?.inventory ?? []).filter(
        (entry) => entry.deferredTo !== null && props.groups.includes(entry.group),
      ),
);
</script>

<template>
  <div class="dashboard">
    <p v-if="loading" class="dashboard__state" role="status">
      Reading this event's dashboard…
    </p>

    <p v-else-if="failure" class="dashboard__state" role="alert">
      {{ failure }}
    </p>

    <template v-else-if="dashboard">
      <StaleReadNotice
        :freshness="dashboard.freshness"
        :time-zone="dashboard.context.timeZone"
        label="This dashboard"
      />

      <p v-if="shown.length === 0" class="dashboard__state" role="status">
        {{ unavailableMessage }}
      </p>

      <section
        v-for="group in shown"
        :key="group.group"
        class="dashboard__group"
        :aria-label="group.label"
      >
        <!--
          One group is the whole page on most of these surfaces, so its heading
          is only worth printing when a surface renders more than one — the
          department dashboard, which carries 13.2 above 13.3.
        -->
        <h2 v-if="shown.length > 1" class="dashboard__group-heading">
          {{ group.label }}
        </h2>

        <ContentGrid min="tile" :label="`${group.label} widgets`">
          <DashboardWidgetCard
            v-for="widget in widgetsFor(group.group)"
            :key="widget.id"
            :widget="widget"
            :context="dashboard.context"
          />
        </ContentGrid>
      </section>

      <p v-if="deferred.length > 0" class="dashboard__deferred">
        Not yet on this dashboard:
        <span v-for="(entry, index) in deferred" :key="entry.id">
          <template v-if="index > 0">, </template>{{ entry.title }} ({{
            entry.deferredTo
          }})</span
        >.
      </p>
    </template>
  </div>
</template>

<style scoped>
.dashboard {
  display: grid;
  gap: var(--m-space-5);
  min-width: 0;
}

.dashboard__group {
  display: grid;
  gap: var(--m-space-3);
  min-width: 0;
}

.dashboard__group-heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
  letter-spacing: 0;
}

.dashboard__state {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  color: var(--m-text-secondary);
}

.dashboard__deferred {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}
</style>
