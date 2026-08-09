<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import StaffPageShell from "@/components/StaffPageShell.vue";
import StaleReadNotice from "@/components/StaleReadNotice.vue";
import {
  fetchEventHorizon,
  itemDestination,
  itemDueLabel,
  setEventHorizonHidden,
  type EventHorizon,
  type EventHorizonItem,
} from "@/event-horizon/eventHorizonModel";
import {
  sessionEventContext,
  sessionEventTimeZone,
} from "@/session/sessionAccess";

/**
 * `staff.event-horizon` — one staff member's readiness list for one event
 * (M18.43; HORIZON-001 through HORIZON-008; UI contract 19C).
 *
 * It is a report, not a workflow: it lists, it explains, and it links out
 * (19C.1). No control on it changes an operational record — the one write it
 * offers is the whole-surface preference of 19C.7, and that is not a dismissal
 * of anything outstanding.
 *
 * Three rules from the contract shape everything here:
 *
 *  1. **The server's order is the order** (19C.4). Outstanding before
 *     complete, soonest deadline first, catalogue order breaking ties — all
 *     decided by the node, rendered as sent. There is no sort control, no
 *     filter, and no search.
 *  2. **A completed item stays listed** (19C.5), marked by state text rather
 *     than by color or dimming alone, and its action link remains followable —
 *     re-reading a policy you already acknowledged is not an error.
 *  3. **A stored copy never reads as "you are ready"** (19C.9). When the list
 *     is rendered from cache, the all-clear is withheld and the page says
 *     which copy it is showing instead.
 */
const router = useRouter();
const eventContext = computed(() => sessionEventContext.value);
const timeZone = computed(() => sessionEventTimeZone.value);

const horizon = ref<EventHorizon | null>(null);
const loadError = ref<string | null>(null);
const hideBusy = ref(false);
const hideError = ref<string | null>(null);

const items = computed<readonly EventHorizonItem[]>(
  () => horizon.value?.items ?? [],
);
const outstanding = computed(() =>
  items.value.filter((item) => item.state === "outstanding"),
);
const completed = computed(() =>
  items.value.filter((item) => item.state === "complete"),
);
const fromCache = computed(
  () => horizon.value?.freshness.source === "cache",
);

/**
 * The kinds a device-compiled list could not evaluate, as a sentence fragment
 * (HORIZON-016).
 *
 * Named rather than counted. "Waivers, Trainings and Team coverage could not be
 * checked" tells somebody which parts of their readiness they still have to
 * find out about; "3 kinds were not checked" tells them only that they should
 * worry. Empty on a live read, where the node evaluated everything it offered.
 */
const unevaluatedLabels = computed(() => {
  const labels = (horizon.value?.unevaluatedKinds ?? []).map(
    (kind) => kind.label,
  );

  if (labels.length === 0) {
    return "";
  }

  return labels.length === 1
    ? labels[0]
    : `${labels.slice(0, -1).join(", ")} and ${labels[labels.length - 1]}`;
});

/**
 * The all-clear is earned, never assumed (19C.9): only a live read with zero
 * outstanding items may say it, because a stored copy cannot establish that
 * nothing appeared since it was taken.
 */
const showAllClear = computed(
  () =>
    horizon.value !== null &&
    !fromCache.value &&
    outstanding.value.length === 0 &&
    items.value.length > 0,
);

/**
 * The 19C.7 hide control: offered only at zero outstanding items — absent
 * otherwise, not disabled, because the answer to "how do I enable this" is
 * "finish your items", which the page already says.
 */
const offersHide = computed(
  () => horizon.value !== null && horizon.value.canHide && !fromCache.value,
);

const lede = computed(() =>
  eventContext.value === null
    ? ""
    : `What you still have outstanding for ${
        horizon.value?.eventLabel ?? eventContext.value.eventLabel ?? "this event"
      }, and where each item is resolved.`,
);

async function load(): Promise<void> {
  const event = eventContext.value;

  if (event === null) {
    horizon.value = null;

    return;
  }

  loadError.value = null;

  try {
    const answer = await fetchEventHorizon(event.eventId);

    /*
     * Outside its window, or with no kind available, the surface is absent —
     * no route, no empty state explaining itself (19C.2, HORIZON-017). A
     * typed address lands at home rather than on a page the menu would never
     * have offered.
     */
    if (!answer.window.applies || !answer.presentable) {
      void router.replace({ name: "home" });

      return;
    }

    horizon.value = answer;
  } catch (error) {
    horizon.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load the Event Horizon. Check the connection to this node and try again.",
    );
  }
}

async function toggleHidden(event: globalThis.Event): Promise<void> {
  const current = horizon.value;
  const context = eventContext.value;

  if (current === null || context === null || hideBusy.value) {
    return;
  }

  const hidden = (event.target as HTMLInputElement).checked;
  hideBusy.value = true;
  hideError.value = null;

  try {
    await setEventHorizonHidden(context.eventId, hidden);
    horizon.value = { ...current, hidden };
  } catch (error) {
    hideError.value = meridianErrorMessage(
      error,
      "Unable to change this preference right now.",
    );
  } finally {
    hideBusy.value = false;
  }
}

function destinationFor(item: EventHorizonItem) {
  const context = eventContext.value;

  return context === null ? null : itemDestination(item, context.eventId);
}

watch(eventContext, () => {
  void load();
});

void load();
</script>

<template>
  <StaffPageShell
    heading-id="event-horizon-heading"
    title="Event Horizon"
    eyebrow="Your readiness"
    :lede="lede"
  >
    <p
      v-if="eventContext === null"
      class="event-horizon__notice"
      role="status"
    >
      The Event Horizon opens once this device is working in an event.
    </p>

    <template v-else>
      <p v-if="loadError" class="event-horizon__error" role="alert">
        {{ loadError }}
        <button type="button" @click="load">Try again</button>
      </p>

      <template v-if="horizon">
        <StaleReadNotice
          :freshness="horizon.freshness"
          :time-zone="timeZone"
          label="This list"
        />

        <!--
          19C.9: a stored copy is disclosed as one, and the page never presents
          a partial list as complete. What could not be evaluated is exactly
          what a stored copy cannot re-ask, so the disclosure names the copy's
          age rather than pretending to a live answer.
        -->
        <p v-if="fromCache" class="event-horizon__notice" role="status">
          This device could not reach the node, so this list was compiled from
          what it is holding. Anything that changed since is not reflected, and
          "nothing outstanding" cannot be established from it.
          <template v-if="unevaluatedLabels">
            {{ unevaluatedLabels }} could not be checked at all — this device
            does not hold what they are decided from.
          </template>
        </p>

        <p v-if="showAllClear" class="event-horizon__all-clear" role="status">
          Nothing outstanding — everything below is complete.
        </p>

        <section
          v-if="outstanding.length > 0"
          class="event-horizon__group"
          aria-labelledby="event-horizon-outstanding-heading"
        >
          <h2 id="event-horizon-outstanding-heading">Outstanding</h2>

          <article
            v-for="item in outstanding"
            :key="item.identity"
            class="event-horizon__item"
            data-state="outstanding"
          >
            <header class="event-horizon__item-header">
              <h3 class="event-horizon__item-title">{{ item.title }}</h3>
              <span class="event-horizon__state">Outstanding</span>
            </header>

            <p class="event-horizon__evaluation">{{ item.evaluation }}</p>
            <p class="event-horizon__completion">{{ item.completion }}</p>

            <p v-if="itemDueLabel(item)" class="event-horizon__due">
              Due {{ itemDueLabel(item) }}
            </p>

            <RouterLink
              v-if="destinationFor(item)"
              class="event-horizon__action"
              :to="destinationFor(item)!"
            >
              {{ item.actionLabel }}
            </RouterLink>
          </article>
        </section>

        <section
          v-if="completed.length > 0"
          class="event-horizon__group"
          aria-labelledby="event-horizon-completed-heading"
        >
          <h2 id="event-horizon-completed-heading">Completed</h2>

          <!--
            Completed items stay listed, de-emphasized and marked by the state
            text rather than by color alone (19C.5), and the action link stays
            followable.
          -->
          <article
            v-for="item in completed"
            :key="item.identity"
            class="event-horizon__item"
            data-state="complete"
          >
            <header class="event-horizon__item-header">
              <h3 class="event-horizon__item-title">{{ item.title }}</h3>
              <span class="event-horizon__state">Complete</span>
            </header>

            <p class="event-horizon__evaluation">{{ item.evaluation }}</p>

            <RouterLink
              v-if="destinationFor(item)"
              class="event-horizon__action"
              :to="destinationFor(item)!"
            >
              {{ item.actionLabel }}
            </RouterLink>
          </article>
        </section>

        <p
          v-if="!loadError && items.length === 0 && !fromCache"
          class="event-horizon__notice"
          role="status"
        >
          Nothing is registered against you for this event yet.
        </p>

        <!--
          The whole-surface preference (19C.7; HORIZON-012, HORIZON-013).
          Offered only at zero outstanding items; the node refuses it
          otherwise, so a control rendered early would not succeed either.
        -->
        <section
          v-if="offersHide"
          class="event-horizon__hide"
          aria-labelledby="event-horizon-hide-heading"
        >
          <h2 id="event-horizon-hide-heading">Done with this list?</h2>

          <label class="event-horizon__hide-control">
            <input
              type="checkbox"
              :checked="horizon.hidden"
              :disabled="hideBusy"
              @change="toggleHidden"
            />
            Hide the Event Horizon from my workflow menu for this event. This
            is a personal preference nobody else sees, and it signs nothing
            off.
          </label>

          <p class="event-horizon__hide-note">
            It returns on its own if something becomes outstanding again, and
            you can restore it any time from
            <RouterLink :to="{ name: 'staff.me' }">Me</RouterLink>.
          </p>

          <p v-if="hideError" class="event-horizon__error" role="alert">
            {{ hideError }}
          </p>
        </section>
      </template>
    </template>
  </StaffPageShell>
</template>

<style scoped>
.event-horizon__notice,
.event-horizon__error,
.event-horizon__all-clear {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.event-horizon__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.event-horizon__all-clear {
  border-color: var(--m-status-success, var(--m-border-strong, currentColor));
}

.event-horizon__group {
  display: grid;
  gap: var(--m-space-3);
}

.event-horizon__group h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.event-horizon__item {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

/* De-emphasis rides with the state marker, never instead of it (19C.5). */
.event-horizon__item[data-state="complete"] {
  opacity: 0.75;
}

.event-horizon__item-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.event-horizon__item-title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
}

.event-horizon__state {
  padding: 0.2rem 0.55rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.event-horizon__evaluation,
.event-horizon__completion,
.event-horizon__due,
.event-horizon__hide-note {
  margin: 0;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.event-horizon__due {
  font-weight: 700;
}

.event-horizon__action {
  justify-self: start;
  color: var(--m-text-primary);
  font-weight: 700;
}

.event-horizon__hide {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-4);
  border: 1px dashed var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.event-horizon__hide h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
}

.event-horizon__hide-control {
  display: flex;
  gap: var(--m-space-2);
  align-items: flex-start;
}

.event-horizon__hide-control input {
  margin-top: 0.2rem;
}
</style>
