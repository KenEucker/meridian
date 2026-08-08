<script setup lang="ts">
import { computed, ref } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import StaleReadNotice from "@/components/StaleReadNotice.vue";
import StatusPill, { type StatusPillTone } from "@/components/StatusPill.vue";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import {
  acknowledgeDocument,
  formatAcknowledgedAt,
  getMyAcknowledgments,
  type AcknowledgmentGating,
  type AcknowledgmentRequirement,
} from "@/documents/documentAcknowledgmentModel";
import { LIVE_READ } from "@/offline/readFreshness";
import { useConnectivity } from "@/offline/useConnectivity";

/**
 * What one person has been asked to acknowledge, and the control that answers
 * it (M18.6; POL-023 through POL-027, POL-043 through POL-047).
 *
 * One component behind two surfaces, because they are one list read two ways.
 * `signup.policy-acknowledgment` asks for `context="signup"` and shows only
 * what is still outstanding — it is a step somebody is walking through, and a
 * step that lists work already done is a step that looks unfinished.
 * `staff.document-acknowledgments` asks for everything and shows answered rows
 * too, because knowing what you accepted and when is the reason to open a
 * ledger.
 *
 * Three properties are load-bearing.
 *
 *  1. **The document is on screen.** Not a link to it — the node's render, with
 *     referenced fragments inline as document text (POL-022). An acknowledgment
 *     of something the person was never shown is a record of nothing.
 *  2. **The version is stated on both sides.** The version standing now, and
 *     after acceptance the version that was accepted (POL-043). Where they
 *     differ the row says so and stays answered, because POL-045 is explicit
 *     that a document change does not re-require anything. Saying "acknowledged
 *     version 2.00, current version 3.00" is honest; moving the row back to
 *     outstanding would be the client reinstating a requirement the
 *     specification removed.
 *  3. **Nothing here is a gate.** POL-026 and POL-027 keep acknowledgment out
 *     of shift signup and credential eligibility, and the node says so in the
 *     read. It is printed rather than assumed, because a list of outstanding
 *     items is exactly the shape a reader takes for a blocker — somebody who
 *     believes an unread policy is holding up their shift will not sign up for
 *     one.
 *
 * Acceptance is connected-only and refused where it stands, not queued. The
 * record has to name the version that was read, and a device holding one for
 * later would name whatever version it last cached.
 */
const props = withDefaults(
  defineProps<{
    /** `signup` narrows to that context and hides answered rows. */
    context?: "signup" | "all";
    variant?: "page" | "section";
    headingId?: string;
    title?: string;
  }>(),
  {
    context: "all",
    variant: "page",
    headingId: "document-acknowledgments-heading",
    title: "Acknowledgments",
  },
);

const connectivity = useConnectivity();

const requirements = ref<readonly AcknowledgmentRequirement[]>([]);
const gating = ref<AcknowledgmentGating | null>(null);
/** Which copy of the list is on screen (M18.50; UI contract 16.2). */
const freshness = ref(LIVE_READ);
const loading = ref(false);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const notice = ref<string | null>(null);
const busyRequirementId = ref<string | null>(null);
const openRequirementId = ref<string | null>(null);

const isOffline = computed(() => connectivity.value !== "online");

/**
 * What this surface shows.
 *
 * The signup reading drops answered rows as well as the training context.
 * Somebody completing signup is being asked to do a thing; a list that also
 * recites what they already did makes the remaining work harder to find.
 */
const visible = computed<readonly AcknowledgmentRequirement[]>(() =>
  props.context === "signup"
    ? requirements.value.filter(
        (requirement) =>
          requirement.context === "signup" && !requirement.acknowledged,
      )
    : requirements.value,
);

const outstanding = computed(
  () => visible.value.filter((requirement) => !requirement.acknowledged).length,
);

/**
 * Whether the whole surface has nothing to say.
 *
 * Distinguished from a failed read, which clears the list too: "you have
 * nothing to acknowledge" and "this device could not ask" are different
 * sentences and only one of them is good news.
 */
const isEmpty = computed(() => !loading.value && visible.value.length === 0);

defineExpose({ reload });

async function reload(): Promise<void> {
  loading.value = true;
  loadError.value = null;

  try {
    const mine = await getMyAcknowledgments();

    requirements.value = mine.requirements;
    gating.value = mine.gating;
    freshness.value = mine.freshness;
  } catch (error) {
    requirements.value = [];
    gating.value = null;
    freshness.value = LIVE_READ;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to read your acknowledgments. Check the connection to this node and try again.",
    );
  } finally {
    loading.value = false;
  }
}

/**
 * Accept one, and put what came back where the old row was.
 *
 * Replaced in place rather than re-read: the node answers with the whole row
 * rebuilt, including the version it recorded, so the surface already holds what
 * is now true and a second request would be a chance for the screen to say
 * something the command did not.
 */
async function accept(requirement: AcknowledgmentRequirement): Promise<void> {
  if (busyRequirementId.value !== null) {
    return;
  }

  actionError.value = null;
  notice.value = null;
  busyRequirementId.value = requirement.requirementId;

  try {
    const accepted = await acknowledgeDocument(requirement.requirementId);

    if (accepted !== null) {
      requirements.value = requirements.value.map((candidate) =>
        candidate.requirementId === accepted.requirementId
          ? accepted
          : candidate,
      );
    } else {
      await reload();
    }

    notice.value = `${requirement.documentTitle} acknowledged at version ${
      accepted?.acknowledgedVersion ?? requirement.documentVersion
    }.`;
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to record this acknowledgment.",
    );
  } finally {
    busyRequirementId.value = null;
  }
}

/**
 * Open or close one document's text.
 *
 * Closed by default in the ledger and open by default at signup, which the
 * template decides: reading is the task in one and reference is the task in the
 * other.
 */
function toggle(requirement: AcknowledgmentRequirement): void {
  openRequirementId.value =
    openRequirementId.value === requirement.requirementId
      ? null
      : requirement.requirementId;
}

function isOpen(requirement: AcknowledgmentRequirement): boolean {
  return (
    props.context === "signup" ||
    openRequirementId.value === requirement.requirementId
  );
}

function statusLabel(requirement: AcknowledgmentRequirement): string {
  return requirement.acknowledged ? "Acknowledged" : "Not yet acknowledged";
}

function statusTone(requirement: AcknowledgmentRequirement): StatusPillTone {
  return requirement.acknowledged ? "positive" : "info";
}

void reload();
</script>

<template>
  <WorkflowSection
    :title="title"
    :heading-id="headingId"
    :variant="variant"
    description="Policies and procedures you have been asked to read."
  >
    <p v-if="loadError" class="acks__error" role="alert">
      {{ loadError }}
      <button type="button" @click="reload">Try again</button>
    </p>

    <StaleReadNotice :freshness="freshness" label="This list" />

    <p v-if="isOffline" class="acks__notice" role="status">
      Acknowledging needs a connection to the node. It is not held on this device
      for later.
    </p>

    <!--
      POL-026 and POL-027, in words. A list of outstanding items reads like a
      list of blockers unless it says otherwise, and somebody who thinks an
      unread policy is holding up their shift will not sign up for one.
    -->
    <p
      v-if="gating && !gating.blocksShiftSignup && !gating.blocksCredentialEligibility"
      class="acks__gating"
      data-testid="acknowledgment-gating"
    >
      {{ gating.explanation }}
    </p>

    <p v-if="actionError" class="acks__error" role="alert">{{ actionError }}</p>

    <p v-if="notice" class="acks__notice" role="status">{{ notice }}</p>

    <p v-if="loading" class="acks__notice" role="status">
      Reading your acknowledgments…
    </p>

    <p v-else-if="isEmpty && !loadError" class="acks__notice" role="status">
      {{
        context === "signup"
          ? "Nothing is waiting to be acknowledged. Signup can continue."
          : "Nobody has asked you to acknowledge a document."
      }}
    </p>

    <p
      v-else-if="context === 'all' && outstanding > 0"
      class="acks__summary"
      data-testid="acknowledgment-outstanding"
    >
      {{ outstanding }} of {{ visible.length }} still to acknowledge.
    </p>

    <article
      v-for="requirement in visible"
      :key="requirement.requirementId"
      class="acks__item"
      :data-acknowledged="requirement.acknowledged ? 'true' : 'false'"
    >
      <header class="acks__item-header">
        <h3 class="acks__item-title">{{ requirement.documentTitle }}</h3>
        <StatusPill
          :label="statusLabel(requirement)"
          :tone="statusTone(requirement)"
          sr-prefix="Acknowledgment"
        />
      </header>

      <dl class="acks__facts">
        <div class="acks__fact">
          <dt>Asked by</dt>
          <dd>{{ requirement.scopeLabel }}</dd>
        </div>
        <div class="acks__fact">
          <dt>Required at</dt>
          <dd>{{ requirement.contextLabel }}</dd>
        </div>
        <div class="acks__fact">
          <dt>Current version</dt>
          <dd>{{ requirement.documentVersion }}</dd>
        </div>
        <!-- POL-043: the version that was accepted, kept beside the one standing. -->
        <div v-if="requirement.acknowledged" class="acks__fact">
          <dt>You acknowledged</dt>
          <dd data-testid="acknowledged-version">
            Version {{ requirement.acknowledgedVersion }} on
            {{ formatAcknowledgedAt(requirement.acknowledgedAt) }}
          </dd>
        </div>
      </dl>

      <!--
        POL-045: the document moved and this is still answered. Said plainly so
        a reader is not left to wonder whether they are behind on something.
      -->
      <p
        v-if="requirement.acknowledged && requirement.documentChangedSince"
        class="acks__changed"
        data-testid="acknowledgment-changed"
      >
        This document has changed since you acknowledged it. You are not being
        asked to acknowledge it again.
      </p>

      <button
        v-if="context !== 'signup'"
        type="button"
        class="acks__toggle"
        :aria-expanded="isOpen(requirement) ? 'true' : 'false'"
        @click="toggle(requirement)"
      >
        {{ isOpen(requirement) ? "Hide document" : "Read document" }}
      </button>

      <!--
        The node's render (POL-022). Somebody cannot acknowledge what they have
        not been shown, so at signup this is never collapsed.
      -->
      <div
        v-if="isOpen(requirement) && requirement.renderedHtml !== ''"
        class="acks__document"
        data-testid="acknowledgment-document"
        v-html="requirement.renderedHtml"
      />

      <!--
        A stored copy carries which document is outstanding, not the document
        (M18.50). The set holds markdown and the render is the node's, with
        referenced fragments resolved, so the alternative to this sentence is an
        empty article standing where a policy should be.
      -->
      <p
        v-else-if="isOpen(requirement)"
        class="acks__document-unavailable"
        data-testid="acknowledgment-document-unavailable"
        role="status"
      >
        The text of this document needs a connection to this node. This device
        holds which documents you are outstanding on, not the documents
        themselves.
      </p>

      <!--
        Absent, not disabled, once it is answered (CLIENT-005). An acknowledged
        document has nothing left to offer, and a greyed-out button standing
        where the action was reads as something that failed.
      -->
      <div v-if="!requirement.acknowledged" class="acks__actions">
        <button
          type="button"
          class="acks__accept"
          data-variant="primary"
          :disabled="isOffline || busyRequirementId === requirement.requirementId"
          @click="accept(requirement)"
        >
          {{
            busyRequirementId === requirement.requirementId
              ? "Recording…"
              : "I have read this"
          }}
        </button>
      </div>
    </article>
  </WorkflowSection>
</template>

<style scoped>
.acks__notice,
.acks__error,
.acks__gating,
.acks__summary {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.acks__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.acks__gating {
  border-style: dashed;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.acks__item {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.acks__item[data-acknowledged="true"] {
  border-color: var(--m-status-success, var(--m-border-strong, currentColor));
}

.acks__item-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.acks__item-title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.acks__facts {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
}

.acks__fact {
  display: grid;
  grid-template-columns: minmax(5rem, 10rem) 1fr;
  gap: var(--m-space-3);
}

.acks__fact dt {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.acks__fact dd {
  margin: 0;
}

.acks__changed {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.acks__document-unavailable {
  margin: var(--m-space-3) 0 0;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.acks__document {
  max-height: 28rem;
  padding: var(--m-space-3);
  overflow-y: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.acks__toggle,
.acks__accept {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  width: 100%;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 800;
  cursor: pointer;
}

.acks__accept[data-variant="primary"] {
  border-color: var(--m-action-primary-bg);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.acks__accept:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.acks__toggle:focus-visible,
.acks__accept:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 44rem) {
  .acks__toggle,
  .acks__accept {
    width: auto;
  }
}

@media (max-width: 40rem) {
  .acks__fact {
    grid-template-columns: 1fr;
    gap: var(--m-space-1);
  }
}
</style>
