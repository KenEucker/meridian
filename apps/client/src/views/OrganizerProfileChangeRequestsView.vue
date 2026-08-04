<script setup lang="ts">
import { computed, ref } from "vue";

import { MeridianApiError, meridianErrorMessage } from "@/api/meridianApi";
import StatusPill from "@/components/StatusPill.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import { useConnectivity } from "@/offline/useConnectivity";
import {
  approveProfileChangeRequest,
  getProfileChangeReviewQueue,
  rejectProfileChangeRequest,
  type ReviewableChangeRequest,
} from "@/staff-profile/profileChangeReviewModel";

/**
 * `organizer.profile-change-requests` — deciding handle and picture change
 * requests (M18.20D; VOL-019 through VOL-022, VOL-025; UI contract 12.6).
 *
 * The page is a queue of decisions, and each row is built around the comparison
 * the decision actually needs: previous handle beside requested handle, current
 * picture beside submitted picture. Nothing else is asked of the reviewer,
 * because nothing else is theirs to decide — a change request carries one field.
 *
 * Three things it takes care over.
 *
 *  1. **A collision is named, not enforced** (VOL-020). Another active staff
 *     member already using the requested handle appears on the row by name, and
 *     both decisions stay available. Two people may legitimately be told apart
 *     by their departments, and refusing here would decide for them. The names
 *     are the people rather than the handle, because "dispatch is taken by
 *     dispatch" answers nothing.
 *  2. **A rejection cannot be silent** (VOL-025). Reject is unavailable until a
 *     reason is typed, and the node requires one too — the submitter is told
 *     why, so there has to be a why.
 *  3. **Scope is the node's answer, not this page's.** The queue arrives already
 *     narrowed to the organizations this caller holds the review capability in,
 *     and a caller holding it nowhere is told so rather than shown an empty
 *     table (CLIENT-005, CLIENT-006).
 */
const connectivity = useConnectivity();

const queue = ref<readonly ReviewableChangeRequest[]>([]);
const loading = ref(false);
const loadError = ref<string | null>(null);
const restricted = ref(false);
const actionError = ref<string | null>(null);
const notice = ref<string | null>(null);
const busyRequestId = ref<string | null>(null);

/** One reason box per row, so two open rejections do not share a draft. */
const reasons = ref<Record<string, string>>({});

const isOffline = computed(() => connectivity.value !== "online");

const handleRequests = computed(() =>
  queue.value.filter((request) => request.kind === "handle"),
);
const pictureRequests = computed(() =>
  queue.value.filter((request) => request.kind === "profile_picture"),
);

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = null;
  restricted.value = false;

  try {
    queue.value = (await getProfileChangeReviewQueue()).requests;
  } catch (error) {
    queue.value = [];

    /*
     * The node answers 403 for a caller who reviews for no organization, which
     * is a different fact from a read that failed: one says "this is not yours
     * to do" and the other says "ask again". Showing the first as an error with
     * a Try again button would invite somebody to keep pressing it.
     */
    if (error instanceof MeridianApiError && error.status === 403) {
      restricted.value = true;
    } else {
      loadError.value = meridianErrorMessage(
        error,
        "Unable to read the profile change request queue. Check the connection to this node and try again.",
      );
    }
  } finally {
    loading.value = false;
  }
}

void load();

function reasonFor(request: ReviewableChangeRequest): string {
  return reasons.value[request.id] ?? "";
}

function setReason(request: ReviewableChangeRequest, value: string): void {
  reasons.value = { ...reasons.value, [request.id]: value };
}

/** What this request is asking for, in one line, for the notices. */
function subject(request: ReviewableChangeRequest): string {
  return request.kind === "handle"
    ? `${request.staffName}'s handle change to ${request.requestedHandle ?? "a new handle"}`
    : `${request.staffName}'s profile picture`;
}

async function onApprove(request: ReviewableChangeRequest): Promise<void> {
  await decide(request, async () => {
    await approveProfileChangeRequest(request.id, reasonFor(request) || null);

    return request.kind === "handle"
      ? `Approved. ${request.staffName} now holds ${request.requestedHandle}.`
      : `Approved. The submitted picture is now on ${request.staffName}'s record.`;
  });
}

async function onReject(request: ReviewableChangeRequest): Promise<void> {
  const reason = reasonFor(request).trim();

  if (reason === "") {
    return;
  }

  await decide(request, async () => {
    await rejectProfileChangeRequest(request.id, reason);

    return `Rejected. ${request.staffName} is told why.`;
  });
}

/**
 * Both decisions, minus the sentence each one prints.
 *
 * The row leaves the queue by re-reading rather than by being spliced out. A
 * decision can change what else is true — approving a handle creates the
 * collision the next row would be judged against — so the queue after a
 * decision is the node's answer rather than this page's arithmetic.
 */
async function decide(
  request: ReviewableChangeRequest,
  act: () => Promise<string>,
): Promise<void> {
  if (busyRequestId.value !== null) {
    return;
  }

  actionError.value = null;
  notice.value = null;
  busyRequestId.value = request.id;

  try {
    const message = await act();

    await load();

    notice.value = message;
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      `Unable to decide ${subject(request)}.`,
    );
  } finally {
    busyRequestId.value = null;
  }
}

function submittedAt(request: ReviewableChangeRequest): string {
  if (request.createdAt === null) {
    return "Submitted";
  }

  const parsed = new Date(request.createdAt);

  return Number.isNaN(parsed.getTime())
    ? "Submitted"
    : `Submitted ${parsed.toLocaleString()}`;
}
</script>

<template>
  <WorkflowPageShell
    heading-id="profile-change-requests-heading"
    title="Profile requests"
    eyebrow="Organizer administration"
    lede="Handle changes and profile pictures waiting on a decision."
  >
    <!--
      Absent, not disabled: a caller who reviews for no organization has nothing
      this page can offer (CLIENT-005), and the node refuses either way.
    -->
    <p v-if="restricted" class="review__restricted" role="status">
      Reviewing staff handle and profile picture changes requires organizer or
      Staff Coordinator authority for an organization this device is working in.
    </p>

    <template v-else>
      <p v-if="loadError" class="review__error" role="alert">
        {{ loadError }}
        <button type="button" @click="load">Try again</button>
      </p>

      <p v-if="isOffline" class="review__notice" role="status">
        Deciding a request needs a connection to the node. It is not held on this
        device for later.
      </p>

      <p v-if="actionError" class="review__error" role="alert">
        {{ actionError }}
      </p>

      <p v-if="notice" class="review__notice" role="status">{{ notice }}</p>

      <p v-if="loading" class="review__notice" role="status">
        Reading the review queue…
      </p>

      <p
        v-else-if="queue.length === 0 && !loadError"
        class="review__notice"
        role="status"
      >
        Nothing is waiting on a decision.
      </p>

      <!--
        Handles first, and separated from pictures rather than interleaved.
        They are different judgements — one is about whether a name is
        unambiguous across the organization, the other about whether a
        photograph shows a face — and reading them in one column makes a
        reviewer switch between the two on every row.
      -->
      <section
        v-if="handleRequests.length > 0"
        class="review__group"
        aria-labelledby="handle-requests-heading"
        data-testid="handle-requests"
      >
        <h2 id="handle-requests-heading" class="review__group-title">
          Handle changes
        </h2>

        <article
          v-for="request in handleRequests"
          :key="request.id"
          class="review__request"
        >
          <header class="review__request-header">
            <h3 class="review__request-title">{{ request.staffName }}</h3>
            <StatusPill
              v-if="request.handleCollisions.length > 0"
              label="Handle in use"
              tone="caution"
              sr-prefix="Requested handle"
            />
          </header>

          <p class="review__meta">
            {{ request.organizationName ?? "This organization" }} —
            {{ submittedAt(request) }}
          </p>

          <!-- Previous beside requested, which is the whole comparison. -->
          <dl class="review__pair" data-testid="handle-pair">
            <div>
              <dt>Handle now</dt>
              <dd>{{ request.previousHandle ?? "No handle on record" }}</dd>
            </div>
            <div>
              <dt>Requested</dt>
              <dd>{{ request.requestedHandle ?? "Unknown" }}</dd>
            </div>
          </dl>

          <!--
            VOL-020: named to the reviewer, and both decisions stay available.
          -->
          <p
            v-if="request.handleCollisions.length > 0"
            class="review__collision"
            data-testid="handle-collision"
          >
            Already used by
            {{ request.handleCollisions.join(", ") }}. Two people can share a
            handle if their departments tell them apart — this is yours to
            decide.
          </p>

          <label class="review__reason">
            <span>Reason</span>
            <textarea
              rows="2"
              :value="reasonFor(request)"
              :disabled="busyRequestId === request.id"
              placeholder="Required to reject. The staff member is told what you write."
              @input="
                setReason(request, ($event.target as HTMLTextAreaElement).value)
              "
            ></textarea>
          </label>

          <div class="review__actions">
            <button
              type="button"
              class="review__approve"
              :disabled="isOffline || busyRequestId === request.id"
              @click="onApprove(request)"
            >
              Approve
            </button>
            <button
              type="button"
              :disabled="
                isOffline ||
                busyRequestId === request.id ||
                reasonFor(request).trim() === ''
              "
              @click="onReject(request)"
            >
              Reject
            </button>
          </div>
        </article>
      </section>

      <section
        v-if="pictureRequests.length > 0"
        class="review__group"
        aria-labelledby="picture-requests-heading"
        data-testid="picture-requests"
      >
        <h2 id="picture-requests-heading" class="review__group-title">
          Profile pictures
        </h2>

        <article
          v-for="request in pictureRequests"
          :key="request.id"
          class="review__request"
        >
          <header class="review__request-header">
            <h3 class="review__request-title">{{ request.staffName }}</h3>
          </header>

          <p class="review__meta">
            {{ request.organizationName ?? "This organization" }} —
            {{ submittedAt(request) }}
          </p>

          <!--
            Current beside submitted (VOL-021). The current one is still the
            picture in force everywhere else in the product while this decision
            is outstanding, and showing them together is what makes that
            legible.
          -->
          <div class="review__pictures" data-testid="picture-pair">
            <figure class="review__picture">
              <img
                v-if="request.currentPictureUrl"
                :src="request.currentPictureUrl"
                :alt="`${request.staffName} current profile picture`"
              />
              <div
                v-else
                class="review__picture-none"
                role="img"
                aria-label="No profile picture on record"
              >
                <span aria-hidden="true">No picture</span>
              </div>
              <figcaption>On the record now</figcaption>
            </figure>

            <figure class="review__picture">
              <img
                v-if="request.submittedPictureUrl"
                :src="request.submittedPictureUrl"
                :alt="`${request.staffName} submitted profile picture`"
              />
              <div
                v-else
                class="review__picture-none"
                role="img"
                aria-label="Submitted picture is not available to display"
              >
                <span aria-hidden="true">Unavailable</span>
              </div>
              <figcaption>Submitted</figcaption>
            </figure>
          </div>

          <label class="review__reason">
            <span>Reason</span>
            <textarea
              rows="2"
              :value="reasonFor(request)"
              :disabled="busyRequestId === request.id"
              placeholder="Required to reject. The staff member is told what you write."
              @input="
                setReason(request, ($event.target as HTMLTextAreaElement).value)
              "
            ></textarea>
          </label>

          <div class="review__actions">
            <button
              type="button"
              class="review__approve"
              :disabled="isOffline || busyRequestId === request.id"
              @click="onApprove(request)"
            >
              Approve
            </button>
            <button
              type="button"
              :disabled="
                isOffline ||
                busyRequestId === request.id ||
                reasonFor(request).trim() === ''
              "
              @click="onReject(request)"
            >
              Reject
            </button>
          </div>
        </article>
      </section>
    </template>
  </WorkflowPageShell>
</template>

<style scoped>
.review__restricted,
.review__notice,
.review__error {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.review__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.review__group {
  display: grid;
  gap: var(--m-space-3);
}

.review__group-title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--m-text-secondary);
}

.review__request {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.review__request-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.review__request-title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.review__meta {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

/*
 * The comparison, side by side past a phone and stacked below it. Stacked is
 * still the comparison — the two values are adjacent and labelled — and a
 * two-column grid at 320px would truncate a handle to make the point.
 */
.review__pair {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.review__pair div {
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.review__pair dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.review__pair dd {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-primary);
  font-weight: 800;
}

.review__collision {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px dashed
    color-mix(in srgb, var(--m-status-warning, #b7791f) 50%, var(--m-border-default));
  border-radius: var(--m-radius-sm);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.review__pictures {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-4);
}

.review__picture {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  justify-items: center;
}

.review__picture figcaption {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 700;
  text-transform: uppercase;
}

.review__picture img,
.review__picture-none {
  width: 8rem;
  aspect-ratio: 1;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  object-fit: cover;
  background: var(--m-surface-base);
  flex: 0 0 auto;
}

.review__picture-none {
  display: grid;
  place-items: center;
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 700;
  text-transform: uppercase;
}

.review__reason {
  display: grid;
  gap: var(--m-space-1);
}

.review__reason > span {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.review__reason textarea {
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  resize: vertical;
}

.review__actions {
  display: grid;
  gap: var(--m-space-2);
}

.review__actions button,
.review__error button {
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

.review__approve {
  border-color: var(--m-action-primary-bg);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.review__actions button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.review__actions button:focus-visible,
.review__error button:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.review__error button {
  width: auto;
  min-height: 2rem;
  margin-left: var(--m-space-2);
}

@media (min-width: 30rem) {
  .review__pair {
    grid-template-columns: 1fr 1fr;
  }
}

@media (min-width: 44rem) {
  .review__actions {
    display: flex;
    flex-wrap: wrap;
  }

  .review__actions button {
    width: auto;
  }
}
</style>
