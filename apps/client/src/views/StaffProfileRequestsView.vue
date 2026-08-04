<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import {
  dismissProfileChangeRequest,
  getMyProfile,
  profileDisplayName,
  withdrawProfileChangeRequest,
  type MyStaffProfile,
  type ProfileChangeRequest,
} from "@/staff-profile/myProfileModel";

/**
 * `staff.profile-requests` — where a submitter's own requests stand (M18.20D;
 * VOL-024, VOL-025, VOL-029; UI contract 12.3).
 *
 * The counterpart of the reviewer's queue, and deliberately not a second copy of
 * the edit form. Edit profile is where a change is *made*; this page is where a
 * change already made is *tracked* — its state, the decision if one has been
 * taken, the reviewer's reason if it was a rejection, and how many direct handle
 * changes are left before one needs review. Submitting is one link away, which
 * is the correct distance: somebody who came here to check on an answer should
 * not be shown a form.
 *
 * Two decisions worth naming.
 *
 *  1. **Read from the same endpoint the edit page reads.** `GET /api/me/profile`
 *     already carries the most recent undismissed request of each kind and the
 *     remaining allowance. A second endpoint would be a second answer to
 *     disagree with the first, and Alpha 1 keeps no history beyond the most
 *     recent submission of each kind (VOL-029), so there is nothing further to
 *     ask for.
 *  2. **Withdraw and Clear are different verbs and stay apart.** Withdraw ends
 *     a decision nobody has taken yet; Clear removes a decision already taken
 *     from this page. Clear destroys nothing — the row is audit history and one
 *     of the facts the handle allowance is counted from, so "I have read this"
 *     must not be able to give somebody a change back (VOL-018, VOL-029).
 */
const profiles = ref<readonly MyStaffProfile[]>([]);
const selectedId = ref<string | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const notice = ref<string | null>(null);
const busy = ref(false);

const profile = computed(
  () => profiles.value.find((entry) => entry.id === selectedId.value) ?? null,
);

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = null;

  try {
    profiles.value = (await getMyProfile()).profiles;
    selectedId.value =
      profiles.value.find((entry) => entry.id === selectedId.value)?.id ??
      profiles.value[0]?.id ??
      null;
  } catch (error) {
    profiles.value = [];
    selectedId.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to read your requests. Check the connection to this node and try again.",
    );
  } finally {
    loading.value = false;
  }
}

void load();

/** The two rows this page is made of, present whether or not anything is on them. */
const rows = computed(() => {
  const current = profile.value;

  if (current === null) {
    return [];
  }

  return [
    {
      key: "handle",
      title: "Handle",
      request: current.latestHandleRequest,
      /*
       * Stated even with nothing outstanding, because the number is the thing
       * somebody came to check before spending one (VOL-017, VOL-028). Zero
       * under any policy that reviews every change, so this never promises a
       * direct change the next save would refuse.
       */
      note:
        current.remainingSelfServiceHandleChanges > 0
          ? `${current.remainingSelfServiceHandleChanges} handle ${
              current.remainingSelfServiceHandleChanges === 1
                ? "change takes"
                : "changes take"
            } effect immediately. After that, changes are reviewed.`
          : "Handle changes are reviewed before they take effect.",
    },
    {
      key: "profile_picture",
      title: "Profile picture",
      request: current.latestPictureRequest,
      note:
        current.profilePictureChangePolicy === "auto_approved"
          ? "A picture you submit takes effect as soon as it uploads."
          : "A picture you submit is reviewed before it replaces the one on your record. Removing yours needs no review.",
    },
  ];
});

/** Whether anything is on this page beyond the allowance notes. */
const hasAnyRequest = computed(() =>
  rows.value.some((row) => row.request !== null),
);

function stateLabel(request: ProfileChangeRequest): string {
  switch (request.status) {
    case "pending":
      return "Waiting for review";
    case "approved":
      return request.selfService ? "Applied" : "Approved";
    case "rejected":
      return "Not approved";
    default:
      return "Withdrawn";
  }
}

/**
 * What this request asked for, in the submitter's own terms.
 *
 * A handle request names both values, because "what did I ask for and what do I
 * hold now" is the question; a picture request has no values to name and says so
 * plainly rather than printing a filename nobody chose to be shown.
 */
function askedFor(request: ProfileChangeRequest): string {
  if (request.kind !== "handle") {
    return "A picture you submitted.";
  }

  return request.previousHandle === null
    ? `Your first handle: ${request.requestedHandle ?? "unknown"}.`
    : `${request.previousHandle} → ${request.requestedHandle ?? "unknown"}.`;
}

/** The reviewer's reason, which is the whole point of showing a rejection (VOL-025). */
function decisionReason(request: ProfileChangeRequest): string | null {
  if (request.status !== "rejected") {
    return null;
  }

  return request.decisionReason === null || request.decisionReason === ""
    ? "No reason was recorded."
    : request.decisionReason;
}

function decidedLabel(request: ProfileChangeRequest): string | null {
  if (request.decidedAt === null) {
    return null;
  }

  const parsed = new Date(request.decidedAt);

  return Number.isNaN(parsed.getTime()) ? null : parsed.toLocaleString();
}

async function onWithdraw(request: ProfileChangeRequest): Promise<void> {
  await act(async () => {
    await withdrawProfileChangeRequest(request.id);

    return request.kind === "handle"
      ? "Withdrawn. Your handle is unchanged."
      : "Withdrawn. The submitted picture was discarded.";
  });
}

async function onClear(request: ProfileChangeRequest): Promise<void> {
  await act(async () => {
    await dismissProfileChangeRequest(request.id);

    return "Cleared. The record of the decision is kept.";
  });
}

async function act(run: () => Promise<string>): Promise<void> {
  if (busy.value) {
    return;
  }

  actionError.value = null;
  notice.value = null;
  busy.value = true;

  try {
    const message = await run();

    await load();

    notice.value = message;
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to do that. Check the connection to this node and try again.",
    );
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <section class="requests" aria-labelledby="profile-requests-heading">
    <p class="requests__nav">
      <RouterLink :to="{ name: 'staff.me' }">Back To Me</RouterLink>
    </p>

    <p class="requests__eyebrow">Staff profile</p>
    <h1 id="profile-requests-heading" class="requests__heading">My requests</h1>

    <p v-if="loadError" class="requests__notice" role="alert">
      {{ loadError }}
      <button type="button" @click="load()">Try again</button>
    </p>

    <p v-else-if="loading" class="requests__notice" role="status">
      Reading your requests…
    </p>

    <p v-else-if="profile === null" class="requests__notice" role="status">
      This login is not linked to a staff profile, so there is nothing to track
      here.
    </p>

    <template v-else>
      <label v-if="profiles.length > 1" class="requests__field">
        Which staff record
        <select v-model="selectedId">
          <option v-for="entry in profiles" :key="entry.id" :value="entry.id">
            {{ profileDisplayName(entry) }} — {{ entry.email }}
          </option>
        </select>
      </label>

      <p v-if="actionError" class="requests__error" role="alert">
        {{ actionError }}
      </p>
      <p v-else-if="notice" class="requests__saved" role="status">
        {{ notice }}
      </p>

      <p v-if="!hasAnyRequest" class="requests__notice" role="status">
        You have not submitted a handle change or a profile picture. Anything you
        submit shows up here with what became of it.
      </p>

      <article
        v-for="row in rows"
        :key="row.key"
        class="requests__row"
        :data-kind="row.key"
      >
        <header class="requests__row-header">
          <h2 class="requests__row-title">{{ row.title }}</h2>
          <span
            v-if="row.request"
            class="requests__state"
            :data-status="row.request.status"
          >
            {{ stateLabel(row.request) }}
          </span>
        </header>

        <template v-if="row.request">
          <p class="requests__asked">{{ askedFor(row.request) }}</p>

          <div
            v-if="row.request.kind === 'profile_picture' && row.request.submittedPictureUrl"
            class="requests__pictures"
          >
            <figure class="requests__picture">
              <img
                :src="row.request.submittedPictureUrl"
                alt="The profile picture you submitted"
              />
              <figcaption>What you submitted</figcaption>
            </figure>
          </div>

          <!-- The reviewer's reason, which is what a rejection is for (VOL-025). -->
          <p
            v-if="decisionReason(row.request)"
            class="requests__reason"
            data-testid="decision-reason"
          >
            {{ decisionReason(row.request) }}
          </p>

          <p v-if="decidedLabel(row.request)" class="requests__meta">
            Decided {{ decidedLabel(row.request) }}.
          </p>
          <p v-else class="requests__meta">
            An organizer or Staff Coordinator decides this. Your record is
            unchanged until they do.
          </p>

          <div class="requests__actions">
            <button
              v-if="row.request.status === 'pending'"
              type="button"
              :disabled="busy"
              @click="onWithdraw(row.request)"
            >
              Withdraw
            </button>
            <button v-else type="button" :disabled="busy" @click="onClear(row.request)">
              Clear
            </button>
          </div>
        </template>

        <p v-else class="requests__meta">Nothing outstanding.</p>

        <p class="requests__hint">{{ row.note }}</p>
      </article>

      <p class="requests__nav">
        <RouterLink :to="{ name: 'staff.profile.edit' }">
          Submit a change on Edit profile
        </RouterLink>
      </p>
    </template>
  </section>
</template>

<style scoped>
.requests {
  width: min(100%, 36rem);
  display: grid;
  gap: var(--m-space-4);
}

.requests__nav {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.requests__nav a {
  color: var(--m-text-secondary);
  text-decoration: none;
}

.requests__eyebrow {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.requests__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.requests__notice,
.requests__error,
.requests__saved {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.requests__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.requests__saved {
  border-color: color-mix(
    in srgb,
    var(--m-status-success, #4a7c59) 40%,
    var(--m-border-default)
  );
}

.requests__row {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.requests__row-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.requests__row-title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
  font-weight: 800;
}

/*
 * The state, said in words. A rejection is the one somebody has to act on, so
 * it is the one that carries a colour; the rest state themselves.
 */
.requests__state {
  padding: 0.2rem 0.55rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.requests__state[data-status="rejected"] {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 50%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.requests__state[data-status="approved"] {
  border-color: color-mix(
    in srgb,
    var(--m-status-success, #4a7c59) 50%,
    var(--m-border-default)
  );
}

.requests__asked {
  margin: 0;
  font-weight: 700;
}

.requests__reason {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid
    color-mix(in srgb, var(--m-status-danger, #cc792f) 40%, var(--m-border-default));
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.requests__meta,
.requests__hint {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.requests__pictures {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-4);
}

.requests__picture {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  justify-items: center;
}

.requests__picture figcaption {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 700;
  text-transform: uppercase;
}

.requests__picture img {
  width: 7rem;
  aspect-ratio: 1;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  object-fit: cover;
  background: var(--m-surface-base);
}

.requests__field {
  display: grid;
  gap: var(--m-space-1);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.requests__field select {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font: inherit;
  font-weight: 400;
}

.requests__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.requests__actions button,
.requests__notice button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.requests__notice button {
  min-height: 2rem;
  margin-left: var(--m-space-2);
}

.requests__actions button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.requests__actions button:focus-visible,
.requests__notice button:focus-visible,
.requests__nav a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
