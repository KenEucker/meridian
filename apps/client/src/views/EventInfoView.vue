<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRoute } from "vue-router";

import HeroCenterLayout from "@/components/HeroCenterLayout.vue";
import SegmentedOctagon, {
  type OctagonSegment,
} from "@/components/SegmentedOctagon.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import { participationLink } from "@/applications/participationModel";
import { formatTimestamp } from "@/department-ops/labels";
import { getEventInfo, type EventInfoView } from "@/event-info/eventInfoModel";
import { clientSessionState } from "@/session/clientSession";
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
  () =>
    eventInfo.value?.event.name ||
    sessionEventContext.value?.eventLabel ||
    "Event",
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

/**
 * The public participation address for this event (APP-016, APP-017).
 *
 * The organization slug is looked up from the event this page is *about*
 * rather than from the session's selected organization, because the two can
 * differ for somebody who belongs to more than one and has switched context;
 * an invitation naming the wrong organization would send a friend to the wrong
 * front door.
 *
 * Null when either slug is missing, and the block is hidden rather than
 * rendering an invitation with a broken link in it.
 */
const inviteLink = computed<string | null>(() => {
  const event = eventInfo.value?.event;
  const organizationSlug = clientSessionState.document?.organizations.find(
    (organization) => organization.id === event?.organizationId,
  )?.slug;

  if (!event?.slug || !organizationSlug) {
    return null;
  }

  return participationLink(organizationSlug, event.slug);
});

/**
 * Whether a document's title says the same thing as its section's label.
 *
 * Case and spacing only — this is not trying to detect similar titles, just
 * the exact repetition that reads as two headings for one subject.
 */
function titleRepeatsSection(title: string, sectionLabel: string): boolean {
  const normalize = (value: string): string =>
    value.trim().toLowerCase().replace(/\s+/g, " ");

  return normalize(title) === normalize(sectionLabel);
}

/**
 * Whether there is room for the ring.
 *
 * A media query cannot choose between two different DOM trees, and the ring and
 * the card stack are different trees rather than one tree styled twice — so the
 * breakpoint is observed here. It matches the width the card grid already used
 * for its three-column layout, so the page changes shape once rather than
 * twice.
 */
const OCTAGON_BREAKPOINT = "(min-width: 94rem)";
const showOctagon = ref(false);

if (typeof window !== "undefined" && typeof window.matchMedia === "function") {
  const query = window.matchMedia(OCTAGON_BREAKPOINT);

  showOctagon.value = query.matches;
  query.addEventListener("change", (event) => {
    showOctagon.value = event.matches;
  });
}

/**
 * What a segment says about its section.
 *
 * A description of the contents, not the contents. The shapes are wide but
 * they are still trapezoids, and a policy set in one is a policy nobody can
 * read — so a segment names what is published there and the dialog carries
 * the text itself. An empty section says so in those words rather than
 * borrowing the node's longer sentence, which does not fit and does not need
 * to: the block already names the subject.
 */
function describeSection(documents: readonly { title: string }[]): string {
  if (documents.length === 0) {
    return "No information has been published yet.";
  }

  if (documents.length === 1) {
    return documents[0]!.title;
  }

  return `${documents.length} published documents: ${documents
    .map((document) => document.title)
    .join(", ")}`;
}

/**
 * The eight segments of the ring.
 *
 * Six document sections, the invitation, and the page's own provenance note —
 * which is exactly eight, and is why the note moved out of the middle. The
 * centre holds the one fact somebody opening this page always wants: which
 * event, and when.
 *
 * An unavailable invitation still takes its segment rather than collapsing the
 * ring to seven. Seven segments would leave a gap in the octagon, which reads
 * as a rendering fault rather than as a design.
 */
const selectedKey = ref<string | null>(null);
const detailDialog = ref<HTMLDialogElement | null>(null);

/**
 * Open the reading pane on the chosen segment.
 *
 * `showModal` rather than `show`: a reading pane over a diagram wants the
 * diagram inert underneath it, and modal brings focus trapping, Escape, and
 * the backdrop without any of it being ours to maintain. Choosing the segment
 * already open closes it.
 */
function openSegment(key: string): void {
  if (selectedKey.value === key) {
    closeSegment();

    return;
  }

  selectedKey.value = key;
  const dialog = detailDialog.value;

  if (dialog !== null && !dialog.open) {
    dialog.showModal();
  }
}

function closeSegment(): void {
  detailDialog.value?.close();
  selectedKey.value = null;
}

const selectedSection = computed(
  () =>
    sections.value.find((section) => section.section === selectedKey.value) ??
    null,
);

const octagonSegments = computed<OctagonSegment[]>(() => {
  const documentSegments = sections.value.map((section, index) => ({
    key: section.section,
    title: section.label,
    summary: describeSection(section.documents),
    tone: index % 4,
  }));

  return [
    ...documentSegments,
    {
      key: "invite",
      title: "Invite your friends!",
      summary: inviteLink.value
        ? "The public application address for this event, to send to somebody who would be good here."
        : "Available once this event has a public application address.",
      tone: 2,
    },
    {
      key: "about",
      title: "About this page",
      summary: `Where this page's content comes from, and what you can see of it.`,
      tone: 3,
    },
  ];
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
  <!--
    `event-info` is the styling scope the honeycomb treatment and the invite
    hang off. It rides the shell's root through Vue's class fallthrough, so
    the wide-layout rules — which must reach into HeroCenterLayout's grid to
    place and stretch the cells — have an ancestor to anchor :deep() on.
  -->
  <StaffPageShell
    class="event-info"
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
      The ring is a navigator, not the reading surface (M18.21B).

      A segment is 164×126 and the things behind it are policy documents, so
      the octagon carries a heading and a line of summary, and choosing one
      hands the full text to the panel underneath — full width, left aligned,
      normal measure, which is the shape prose is actually read in. Trying to
      set a policy inside a trapezoid is how the previous attempt at this page
      became unreadable.

      Wide widths only. The ring needs room for eight segments around a centre;
      below that it is a stack of cards, which is the same content in the shape
      a phone can hold.
    -->
    <!--
      The ring, with the text inside the shapes (M18.21B).

      The segments are wide enough to hold a heading and a real paragraph, so
      there is no dialog and nothing to click through: what the page says is
      what is on the page. That is the whole reason the band is thick and the
      channel around the centre is thin — the proportions serve the words
      rather than the diagram.
    -->
    <div v-else-if="showOctagon" class="event-info__ring">
      <SegmentedOctagon
        :segments="octagonSegments"
        :center-title="eventLabel"
        :center-subtitle="operationsWindowLabel"
        :selected="selectedKey"
        label="Event information"
        @select="openSegment"
      />

      <dialog
        ref="detailDialog"
        class="event-info__dialog"
        @close="selectedKey = null"
      >
        <div class="event-info__detail">
          <button
            type="button"
            class="event-info__dialog-close"
            @click="closeSegment"
          >
            Close
          </button>

          <template v-if="selectedKey === 'invite'">
            <h2>Invite your friends!</h2>
            <p>
              Events run on the people who show up. If you know somebody who
              would be good here, send them this link. They do not need a
              Meridian account, and applying commits them to nothing until an
              organizer says yes.
            </p>
            <p v-if="inviteLink" class="event-info__invite-action">
              <a :href="inviteLink">Apply to staff {{ eventLabel }}</a>
            </p>
            <p v-else class="event-info__empty">
              This event has no public application address yet.
            </p>
          </template>

          <template v-else-if="selectedKey === 'about'">
            <h2>About this page</h2>
            <dl class="event-info__facts">
              <div>
                <dt>Department</dt>
                <dd>{{ departmentLabel }}</dd>
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
              Every section here is the published policy and procedure content
              you are permitted to see. Sections without a published document
              say so instead of standing in for one.
            </p>
          </template>

          <template v-else-if="selectedSection">
            <h2>{{ selectedSection.label }}</h2>

            <p
              v-if="selectedSection.emptyDescription"
              class="event-info__empty"
              role="status"
            >
              {{ selectedSection.emptyDescription }}
            </p>

            <section
              v-for="document in selectedSection.documents"
              :key="document.id"
              class="event-info__document"
            >
              <h3
                v-if="
                  !titleRepeatsSection(document.title, selectedSection.label)
                "
              >
                {{ document.title }}
              </h3>
              <div
                class="event-info__document-body"
                v-html="document.renderedHtml"
              />
              <p class="event-info__document-meta">
                {{
                  document.documentType === "policy" ? "Policy" : "Procedure"
                }}
                / {{ document.scopeLabel }} / version {{ document.version }}
              </p>
            </section>
          </template>
        </div>
      </dialog>
    </div>

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

        <!--
          The empty state wears the same tinted inner card as a published
          document so every block has one anatomy, and carries no subtitle of
          its own: the block already has a heading, and "Nothing published yet"
          under "Housing" would be a second heading saying less than the first.
        -->
        <section
          v-if="section.emptyDescription"
          class="event-info__document"
          data-empty-card="true"
        >
          <div class="event-info__document-body">
            <p class="event-info__empty" role="status">
              {{ section.emptyDescription }}
            </p>
          </div>
        </section>

        <section
          v-for="document in section.documents"
          :key="document.id"
          class="event-info__document"
        >
          <!--
            The title is the card's subtitle, and it is dropped when it only
            repeats the block's heading: a document called "What To Bring"
            inside a section called "What to bring" is the same words twice in
            two sizes, which is what makes the page look like it has more
            headings than it has subjects.
          -->
          <h3 v-if="!titleRepeatsSection(document.title, section.label)">
            {{ document.title }}
          </h3>
          <!--
            The node's render, with fragment text inline and raw HTML stripped
            (POL-022, POL-034), so staff read the published document rather than
            the browser's approximation of it.
          -->
          <div
            class="event-info__document-body"
            v-html="document.renderedHtml"
          />
          <p class="event-info__document-meta">
            {{ document.documentType === "policy" ? "Policy" : "Procedure" }} /
            {{ document.scopeLabel }} / version
            {{ document.version }}
          </p>
        </section>
      </article>
      <!--
        Deliberately not a seventh Event Info section, though it sits in the
        ring with them. The six are document-backed by design — a maintainer
        authors and publishes the guidance, and a section with nothing
        published says so rather than standing in for it. This is not guidance
        and has no author: it is one product affordance with fixed copy and a
        link, so putting it in the document vocabulary would have created a
        section that permanently reports "no published document covers this
        yet". At wide widths it takes the cell directly below the octagon;
        stacked, it lands last, which is the right place for the one block
        addressed to somebody who has already read the rest.
      -->
      <article
        v-if="inviteLink"
        class="event-info__invite"
        aria-labelledby="invite-heading"
      >
        <h2 id="invite-heading">Invite your friends!</h2>
        <!--
          The same inner-card anatomy as every section: an h3, the tinted
          card, body paragraphs. The action link is the one extra element,
          because the link is the block's whole reason to exist.
        -->
        <section class="event-info__document">
          <div class="event-info__document-body">
            <p>
              Events run on the people who show up. If you know somebody who
              would be good here, send them this link.
            </p>
            <p>
              They do not need a Meridian account, and applying commits them to
              nothing until an organizer says yes.
            </p>
          </div>
          <p class="event-info__invite-action">
            <a :href="inviteLink">Apply to staff {{ eventLabel }}</a>
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
  border: 2px solid
    color-mix(in srgb, var(--m-action-primary-bg) 45%, var(--m-border-default));
  border-inline-start-width: 6px;
  border-radius: var(--m-radius-sm);
  background: color-mix(
    in srgb,
    var(--m-action-primary-bg) 8%,
    var(--m-surface-raised)
  );
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
 * Octagon treatment, wide layout only.
 *
 * This is scoped to the breakpoint where the hero actually sits in the middle
 * of the grid. An octagon in a single stacked column is just a card with its
 * corners cut off, and the angled corners on its neighbours would point at
 * nothing, so below this width everything stays rectangular.
 *
 * The six sections are a fixed vocabulary, so they are placed by name rather
 * than left to auto-flow. Explicit placement is what lets each card cut the
 * corner that faces the hero: with auto-placement there is no way to say
 * "the card above and left of the hero".
 *
 * Every cut is `--m-oct-cut` in both axes, which is what makes the angles
 * shared by construction: a 45° corner is 45° whatever the card's height, so
 * unlike a pointed shape nothing here depends on the row being equal heights.
 * The surrounding cards cut only the corners on their connecting side —
 * `directions` (above left) cuts its bottom-right corner toward the octagon's
 * top-left facet, `arrival` (directly above) cuts both bottom corners, and so
 * on around the ring — so the eight facets of the hero are answered by eight
 * parallel edges around it, an octagonal socket with the summary in it.
 */
@media (min-width: 94rem) {
  .event-info {
    /*
     * Small enough that a cut corner reads as a chamfer on a card rather than
     * a card losing a bite: the blocks stay visibly the same size as each
     * other, and the corners sit close to where a square corner would be.
     */
    --m-oct-cut: 1.5rem;
    --m-oct-rim: 3px;
  }

  /*
   * Equal rows, stretched cells. `1fr` auto-rows make every row the height of
   * the tallest, so the seven blocks and the octagon come out the same size
   * instead of each row hugging its own content.
   */
  .event-info :deep(.hero-center) {
    align-items: stretch;
    grid-auto-rows: 1fr;
  }

  /*
   * Text leans inward, toward the octagon. The left column reads to its right
   * edge and the right column reads from its left edge, so the words stay
   * close to the centre while the clipped corners spread away from it. The
   * other direction pushes the text apart and leaves a hole where the figure
   * should be.
   */
  .event-info__section[data-section="directions"],
  .event-info__section[data-section="food"],
  .event-info__section[data-section="requirements"] {
    text-align: end;
  }

  .event-info__section[data-section="packing"],
  .event-info__section[data-section="housing"] {
    text-align: start;
  }

  .event-info__section[data-section="arrival"] {
    text-align: center;
  }

  /*
   * Reading measure is capped per paragraph, so alignment alone would leave a
   * capped block against the wrong edge. The free space is pushed outward, and
   * the inner card leans the same way, so the block's whole mass sits on its
   * inner side — which is what "close to the centre" actually requires.
   */
  .event-info__section[data-section="directions"]
    .event-info__document-body
    :deep(p),
  .event-info__section[data-section="food"] .event-info__document-body :deep(p),
  .event-info__section[data-section="requirements"]
    .event-info__document-body
    :deep(p) {
    margin-inline-start: auto;
  }

  .event-info__section[data-section="arrival"]
    .event-info__document-body
    :deep(p),
  .event-info__invite .event-info__document-body :deep(p) {
    margin-inline: auto;
  }

  .event-info__section[data-section="directions"] .event-info__document,
  .event-info__section[data-section="food"] .event-info__document,
  .event-info__section[data-section="requirements"] .event-info__document {
    margin-inline-start: var(--m-oct-cut);
  }

  .event-info__section[data-section="packing"] .event-info__document,
  .event-info__section[data-section="housing"] .event-info__document {
    margin-inline-end: var(--m-oct-cut);
  }

  .event-info__section[data-section="directions"] {
    grid-column: 1;
    grid-row: 1;
    clip-path: polygon(
      0 0,
      100% 0,
      100% calc(100% - var(--m-oct-cut)),
      calc(100% - var(--m-oct-cut)) 100%,
      0 100%
    );
  }

  .event-info__section[data-section="arrival"] {
    grid-column: 2;
    grid-row: 1;
    clip-path: polygon(
      0 0,
      100% 0,
      100% calc(100% - var(--m-oct-cut)),
      calc(100% - var(--m-oct-cut)) 100%,
      var(--m-oct-cut) 100%,
      0 calc(100% - var(--m-oct-cut))
    );
  }

  .event-info__section[data-section="packing"] {
    grid-column: 3;
    grid-row: 1;
    clip-path: polygon(
      0 0,
      100% 0,
      100% 100%,
      var(--m-oct-cut) 100%,
      0 calc(100% - var(--m-oct-cut))
    );
  }

  .event-info__section[data-section="food"] {
    grid-column: 1;
    grid-row: 2;
    clip-path: polygon(
      0 0,
      calc(100% - var(--m-oct-cut)) 0,
      100% var(--m-oct-cut),
      100% calc(100% - var(--m-oct-cut)),
      calc(100% - var(--m-oct-cut)) 100%,
      0 100%
    );
  }

  .event-info__section[data-section="housing"] {
    grid-column: 3;
    grid-row: 2;
    clip-path: polygon(
      var(--m-oct-cut) 0,
      100% 0,
      100% 100%,
      var(--m-oct-cut) 100%,
      0 calc(100% - var(--m-oct-cut)),
      0 var(--m-oct-cut)
    );
  }

  /*
   * With the invite taking the cell under the octagon, requirements closes the
   * bottom-left of the ring; its connecting corner is the one that faces the
   * octagon diagonally.
   */
  .event-info__section[data-section="requirements"] {
    grid-column: 1;
    grid-row: 3;
    clip-path: polygon(
      0 0,
      calc(100% - var(--m-oct-cut)) 0,
      100% var(--m-oct-cut),
      100% 100%,
      0 100%
    );
  }

  /*
   * The invitation sits directly below the octagon (the cell "adjacent" means
   * once the ring exists), cut to answer the octagon's bottom facets the same
   * way arrival answers its top ones.
   */
  .event-info__invite {
    grid-column: 2;
    grid-row: 3;
    margin-top: 0;
    border-radius: 0;
    text-align: center;
    align-items: center;
    justify-content: center;
    clip-path: polygon(
      var(--m-oct-cut) 0,
      calc(100% - var(--m-oct-cut)) 0,
      100% var(--m-oct-cut),
      100% 100%,
      0 100%,
      0 var(--m-oct-cut)
    );
  }

  /*
   * The rim is a second octagon behind the first: `clip-path` clips a border
   * away with everything else, so the accent edge has to be a layer rather than
   * a stroke.
   */
  .event-info :deep(.hero-center__hero) {
    grid-column: 2;
    grid-row: 2;
    padding: var(--m-oct-rim);
    background: color-mix(
      in srgb,
      var(--m-action-primary-bg) 60%,
      var(--m-border-default)
    );
    clip-path: polygon(
      var(--m-oct-cut) 0,
      calc(100% - var(--m-oct-cut)) 0,
      100% var(--m-oct-cut),
      100% calc(100% - var(--m-oct-cut)),
      calc(100% - var(--m-oct-cut)) 100%,
      var(--m-oct-cut) 100%,
      0 calc(100% - var(--m-oct-cut)),
      0 var(--m-oct-cut)
    );
  }

  .event-info__hero {
    height: 100%;
    align-content: center;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    padding-inline: calc(var(--m-oct-cut) + var(--m-space-3));
    text-align: center;
    clip-path: polygon(
      var(--m-oct-cut) 0,
      calc(100% - var(--m-oct-cut)) 0,
      100% var(--m-oct-cut),
      100% calc(100% - var(--m-oct-cut)),
      calc(100% - var(--m-oct-cut)) 100%,
      var(--m-oct-cut) 100%,
      0 calc(100% - var(--m-oct-cut)),
      0 var(--m-oct-cut)
    );
  }

  .event-info__hero dl {
    justify-items: center;
  }

  /* A cut corner and a rounded corner disagree about where the card ends. */
  .event-info__section {
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

/*
 * One heading per block, and it is this one.
 *
 * A document's own title used to be a second heading directly under the
 * section label, which on a page of seven blocks read as fourteen headings
 * saying much the same thing twice. The title stays — it is how somebody
 * knows which published document they are reading — but as the card's
 * subtitle rather than as a rival heading.
 */
.event-info__section h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
  line-height: 1.25;
  color: var(--m-text-primary);
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
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.event-info__document-body :deep(h1),
.event-info__document-body :deep(h2) {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
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

/*
 * A section with nothing published still owns the block, and its card is
 * drawn quieter — dashed rim, no fill of its own — so a page with four gaps
 * reads as four waiting spaces rather than four more things to read.
 */
.event-info__document[data-empty-card="true"] {
  border-style: dashed;
  background: none;
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

/*
 * The ring and its detail panel. The octagon is capped so it stays a diagram
 * rather than growing to fill a wall display, and the panel below it keeps a
 * normal reading measure — the whole point of separating the two.
 */
/*
 * The ring is now the page rather than the top half of it: with the reading
 * pane in a dialog there is nothing underneath competing for height, so the
 * octagon takes the room it needs to be legible at a glance. Capped against
 * the viewport height as well as the width, because a diagram taller than the
 * window is one somebody has to scroll to see whole.
 */
.event-info__ring {
  display: grid;
  justify-items: center;
}

/*
 * Width leads, not viewport height.
 *
 * The shape is square — a regular octagon inside an 800×800 box — so any cap
 * on height is also a cap on width, and a `vh` limit was leaving most of a
 * wide page empty while the diagram sat small in the middle. Letting width
 * lead costs some vertical scrolling on a short window, which is the cheaper
 * of the two: a diagram that is too small to read is useless at any scroll
 * position, and one that is legible is worth a scroll.
 */
.event-info__ring :deep(.octagon) {
  width: min(100%, 1100px);
}

.event-info__dialog {
  width: min(46rem, 92vw);
  max-height: 84vh;
  padding: 0;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  overflow: auto;
}

.event-info__dialog::backdrop {
  background: rgb(0 0 0 / 55%);
}

.event-info__detail {
  display: grid;
  gap: var(--m-space-3);
  align-content: start;
  padding: var(--m-space-4);
  text-align: start;
}

.event-info__dialog-close {
  justify-self: end;
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

.event-info__detail h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
  color: var(--m-text-primary);
}

.event-info__detail p {
  margin: 0;
  max-width: var(--m-measure);
  color: var(--m-text-secondary);
}

.event-info__detail-prompt {
  color: var(--m-text-muted);
}

.event-info__facts {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
}

.event-info__facts dt {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.event-info__facts dd {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-primary);
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

/*
 * The invitation is a section like the other six, not a special card. It
 * inherits the section shell and the inner-document card wholesale, so the
 * only rules here are the ones for the element the sections do not have: the
 * action link.
 *
 * Sharing the shell rather than restating it is what keeps the seven blocks
 * identical as the section styling changes — a second copy of the padding,
 * border, and background would drift the first time one of them moved.
 */
.event-info__invite {
  display: grid;
  gap: var(--m-space-3);
  min-width: 0;
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  align-content: start;
}

.event-info__invite h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
}

.event-info__invite-action {
  margin: var(--m-space-1) 0 0;
}

.event-info__invite-action a {
  display: inline-block;
  min-height: 2.25rem;
  padding: var(--m-space-2) var(--m-space-4, 1.25rem);
  border-radius: 8px;
  background: var(--m-accent-primary, var(--m-text-primary));
  color: var(--m-surface-base);
  font-weight: 700;
  text-decoration: none;
}
</style>
