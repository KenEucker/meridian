<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRoute } from "vue-router";

import HeroCenterLayout from "@/components/HeroCenterLayout.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import { formatTimestamp } from "@/department-ops/labels";
import { getEventInfo, type EventInfoView } from "@/event-info/eventInfoModel";
import {
  selectedSessionDepartment,
  sessionEventContext,
} from "@/session/sessionAccess";
import { sessionOrganizationLabel } from "@/session/sessionContext";

/**
 * `event.info` (M11.20; bound to the node in M16.19; UI contract 12.3;
 * data/API 11.4A).
 *
 * One read fills the page. The node assembles the six sections, decides which
 * published documents this staff member may read in each, names the gap in the
 * ones that are empty, and renders each document's Markdown with its fragments
 * resolved. Nothing here re-derives any of it: Event Info grants no visibility
 * of its own, so a second copy of the assembly rule in the browser could only
 * disagree with the one that governs.
 */
const route = useRoute();
const eventId = computed(
  () =>
    (typeof route.params.eventId === "string" ? route.params.eventId : "") ||
    (sessionEventContext.value?.eventId ?? ""),
);

const eventInfo = ref<EventInfoView | null>(null);
const loadError = ref<string | null>(null);

const sections = computed(() => eventInfo.value?.sections ?? []);
const eventLabel = computed(
  () => eventInfo.value?.event.name || sessionEventContext.value?.eventLabel || "Event",
);
const departmentLabel = computed(
  () => selectedSessionDepartment.value?.departmentLabel ?? "Not assigned",
);
const organizationLabel = computed(
  () => sessionOrganizationLabel.value ?? "Not resolved",
);

/**
 * The event's own window, in the event's own timezone.
 *
 * Both come from the read rather than from a department operations fixture, so
 * a page that answers "how do I get there" is not describing a different event
 * than the one it names.
 */
const operationsWindowLabel = computed(() => {
  const event = eventInfo.value?.event;

  if (!event?.startsAt || !event.endsAt) {
    return "Operations window not set";
  }

  const timeZone = event.timezone ?? "UTC";

  return `${formatTimestamp(event.startsAt, timeZone)} to ${formatTimestamp(
    event.endsAt,
    timeZone,
  )}`;
});

async function loadEventInfo(): Promise<void> {
  if (eventId.value === "") {
    eventInfo.value = null;

    return;
  }

  loadError.value = null;

  try {
    eventInfo.value = await getEventInfo(eventId.value);
  } catch (error) {
    eventInfo.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load event information. Check the connection to this node and try again.",
    );
  }
}

watch(eventId, () => {
  void loadEventInfo();
});

void loadEventInfo();
</script>

<template>
  <StaffPageShell
    heading-id="event-info-heading"
    eyebrow="Event info"
    :title="eventLabel"
  >
    <!--
      A refusal is the node's own sentence, and an unreachable node is stated
      rather than shown as an event with no guidance at all (data/API 7.2).
    -->
    <p v-if="loadError" class="event-info__error" role="alert">
      {{ loadError }}
      <button type="button" @click="loadEventInfo">Try again</button>
    </p>

    <!--
      The six sections all answer one question, so the summary they belong to
      sits among them rather than above them once there is room for a card
      column either side of it. One hero row with six cards puts three above it,
      one either side, and one below.
    -->
    <HeroCenterLayout v-else label="Event information" :hero-rows="1">
      <template #hero>
        <article class="event-info__hero">
          <h2>At a glance</h2>
          <dl>
            <div>
              <dt>Department</dt>
              <dd>{{ departmentLabel }}</dd>
            </div>
            <div>
              <dt>Operations</dt>
              <dd>{{ operationsWindowLabel }}</dd>
            </div>
            <div>
              <dt>Organization</dt>
              <dd>{{ organizationLabel }}</dd>
            </div>
            <div>
              <dt>Published documents</dt>
              <dd>{{ eventInfo?.documentCount ?? 0 }} visible to you</dd>
            </div>
          </dl>
          <p class="event-info__source" role="note">
            Every section here is the published policy and procedure content you
            are permitted to see. Sections without a published document say so
            instead of standing in for one.
          </p>
        </article>
      </template>

      <article
        v-for="section in sections"
        :key="section.section"
        class="event-info__section"
        :data-section="section.section"
        :data-empty="section.documents.length === 0 ? 'true' : 'false'"
      >
        <h2>{{ section.label }}</h2>

        <p v-if="section.emptyDescription" class="event-info__empty" role="status">
          {{ section.emptyDescription }}
        </p>

        <section
          v-for="document in section.documents"
          :key="document.id"
          class="event-info__document"
        >
          <h3>{{ document.title }}</h3>
          <!--
            The node's render, with fragment text inline and raw HTML stripped
            (POL-022, POL-034), so staff read the published document rather than
            the browser's approximation of it.
          -->
          <div class="event-info__document-body" v-html="document.renderedHtml" />
          <p class="event-info__document-meta">
            {{ document.documentType === "policy" ? "Policy" : "Procedure" }} /
            {{ document.scopeLabel }} / version
            {{ document.version }}
          </p>
        </section>
      </article>
    </HeroCenterLayout>
  </StaffPageShell>
</template>

<style scoped>
/*
 * The hero is the one card on this page that is not a document section, so it
 * is drawn as a different kind of thing rather than a slightly larger sibling:
 * an accent rule down its leading edge, a tinted surface, and a heavier border.
 * Colour is mixed from the action accent against the page surface so it holds
 * up in both themes instead of hard-coding a light-mode tint.
 */
.event-info__hero {
  display: grid;
  align-content: start;
  gap: var(--m-space-3);
  min-width: 0;
  padding: var(--m-pad-block) var(--m-pad-inline);
  border: 2px solid color-mix(in srgb, var(--m-action-primary-bg) 45%, var(--m-border-default));
  border-inline-start-width: 6px;
  border-radius: var(--m-radius-sm);
  background: color-mix(in srgb, var(--m-action-primary-bg) 8%, var(--m-surface-raised));
  box-shadow: var(--m-shadow-md, var(--m-shadow-sm));
}

.event-info__hero h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
  color: var(--m-text-primary);
}

.event-info__hero dt {
  color: var(--m-text-secondary);
}

/*
 * Honeycomb treatment, wide layout only.
 *
 * This is scoped to the breakpoint where the hero actually sits in the middle
 * of the grid. A hexagon in a single stacked column is just a card with its
 * corners cut off, and the angled edges on its neighbours would point at
 * nothing, so below this width everything stays rectangular.
 *
 * The six sections are a fixed vocabulary, so they are placed by name rather
 * than left to auto-flow. Explicit placement is what lets the two cards flanking
 * the hero take the matching angles: with auto-placement there is no way to say
 * "the card to the left of the hero".
 *
 * `--m-hex-point` is the horizontal reach of the hexagon's points and is shared
 * by all three shapes, and every angled edge spans exactly half its box height.
 * Equal insets over equal vertical spans is what makes the lines parallel — so
 * the row is also forced to equal heights, since a shorter neighbour would tilt
 * its edge off the hero's angle.
 */
@media (min-width: 94rem) {
  .event-info {
    --m-hex-point: 2.75rem;
    --m-hex-rim: 3px;
  }

  .event-info :deep(.hero-center) {
    align-items: stretch;
  }

  .event-info__section[data-section="directions"] {
    grid-column: 1;
    grid-row: 1;
  }

  .event-info__section[data-section="arrival"] {
    grid-column: 2;
    grid-row: 1;
  }

  .event-info__section[data-section="packing"] {
    grid-column: 3;
    grid-row: 1;
  }

  .event-info__section[data-section="food"] {
    grid-column: 1;
    grid-row: 2;
  }

  .event-info__section[data-section="housing"] {
    grid-column: 3;
    grid-row: 2;
  }

  .event-info__section[data-section="requirements"] {
    grid-column: 2;
    grid-row: 3;
  }

  /*
   * The rim is a second hexagon behind the first: `clip-path` clips a border
   * away with everything else, so the accent edge has to be a layer rather than
   * a stroke.
   */
  .event-info :deep(.hero-center__hero) {
    grid-column: 2;
    grid-row: 2;
    padding: var(--m-hex-rim);
    background: color-mix(
      in srgb,
      var(--m-action-primary-bg) 60%,
      var(--m-border-default)
    );
    clip-path: polygon(
      0 50%,
      var(--m-hex-point) 0,
      calc(100% - var(--m-hex-point)) 0,
      100% 50%,
      calc(100% - var(--m-hex-point)) 100%,
      var(--m-hex-point) 100%
    );
  }

  .event-info__hero {
    height: 100%;
    align-content: center;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    padding-inline: calc(var(--m-hex-point) + var(--m-space-4));
    text-align: center;
    clip-path: polygon(
      0 50%,
      var(--m-hex-point) 0,
      calc(100% - var(--m-hex-point)) 0,
      100% 50%,
      calc(100% - var(--m-hex-point)) 100%,
      var(--m-hex-point) 100%
    );
  }

  .event-info__hero dl {
    justify-items: center;
  }

  /* Right edge angled to the hero's left point. */
  .event-info__section[data-section="food"] {
    padding-inline-end: calc(var(--m-hex-point) + var(--m-space-2));
    clip-path: polygon(
      0 0,
      100% 0,
      calc(100% - var(--m-hex-point)) 50%,
      100% 100%,
      0 100%
    );
  }

  /* Left edge angled to the hero's right point. */
  .event-info__section[data-section="housing"] {
    padding-inline-start: calc(var(--m-hex-point) + var(--m-space-2));
    clip-path: polygon(
      0 0,
      100% 0,
      100% 100%,
      0 100%,
      var(--m-hex-point) 50%
    );
  }

  .event-info__section[data-section="food"],
  .event-info__section[data-section="housing"] {
    border-radius: 0;
  }
}

.event-info__hero dl {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.event-info__hero dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.event-info__hero dd {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-primary);
  overflow-wrap: anywhere;
}

.event-info__source {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.event-info__section {
  display: grid;
  gap: var(--m-space-3);
  min-width: 0;
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.event-info__section h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-base);
}

.event-info__document {
  display: grid;
  gap: var(--m-space-2);
  min-width: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.event-info__document h3 {
  margin: 0;
  font-size: var(--m-text-sm);
  font-weight: 900;
  text-transform: uppercase;
}

.event-info__document-body :deep(h1),
.event-info__document-body :deep(h2) {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-base);
}

.event-info__document-body :deep(p) {
  margin: 0 0 var(--m-space-2);
  max-width: var(--m-measure);
  color: var(--m-text-secondary);
}

.event-info__document-body :deep(p:last-child) {
  margin-bottom: 0;
}

.event-info__document-meta {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
}

.event-info__empty {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.event-info__error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-3);
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-status-danger, #cc792f);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-status-danger, #cc792f);
}

.event-info__error button {
  min-height: 2.25rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 900;
  cursor: pointer;
}
</style>
