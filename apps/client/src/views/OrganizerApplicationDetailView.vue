<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import { connectionRequiredMessage } from "@/offline/connectionRequired";
import {
  decideApplication,
  getApplication,
  type ReviewableApplication,
} from "@/applications/applicationReviewModel";

/**
 * `organizer.application-detail` — one application, read and decided (M18.29;
 * APP-003, APP-005, APP-011, APP-019; UI contract 12.6, 12.10.2).
 *
 * The queue is for working through what is waiting; this is for the one
 * application somebody was sent a link to, or stopped at to read properly. It
 * carries what the queue's row cannot fit — the full status history of the
 * record, who decided it and when, and the reason they gave — and it decides
 * from the same commands the queue does.
 *
 * Three things it does deliberately:
 *
 *  1. **Its own read.** Not a row lifted out of the list, because the list is
 *     filtered and the application somebody followed a link to is often not in
 *     it. A refusal is the node's, and a caller who may not see this
 *     application is told so rather than shown an empty page.
 *  2. **Review controls follow the row.** `canReview` is the node's per-row
 *     answer, so a department lead's read-only visibility (APP-011) renders the
 *     application and no decision controls — on this surface exactly as on the
 *     queue.
 *  3. **Reject asks for a reason first.** The applicant is told about the
 *     decision (NOTIFY-001), and a rejection nobody explained is a message with
 *     nothing in it to act on.
 *
 * Department interest is shown and never edited: APP-011 forbids organizers
 * editing it during review in Alpha 1, and an archived department stays named
 * with its archived treatment because the interest record outlives the
 * department's eligibility.
 */
const route = useRoute();

const application = ref<ReviewableApplication | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const notice = ref<string | null>(null);
const busy = ref(false);
const rejectReason = ref("");

const applicationId = computed(() =>
  typeof route.params.applicationId === "string" ? route.params.applicationId : "",
);

const scopeLabel = computed(() => {
  const current = application.value;

  if (current === null) {
    return "";
  }

  // APP-001: an organization-scoped application names no event, and saying so
  // beats leaving a blank that reads like missing data.
  return current.scope === "organization"
    ? `${current.organizationName ?? "the organization"} — no event`
    : (current.eventName ?? "Unnamed event");
});

const canDecide = computed(
  () => application.value?.canReview === true && application.value.status === "submitted",
);

function formatMoment(value: string | null): string {
  if (value === null) {
    return "Not recorded";
  }

  const parsed = new Date(value);

  return Number.isNaN(parsed.getTime()) ? "Not recorded" : parsed.toLocaleString();
}

async function load(): Promise<void> {
  if (applicationId.value === "") {
    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    application.value = await getApplication(applicationId.value);
  } catch (error) {
    application.value = null;
    loadError.value = meridianErrorMessage(
      error,
      connectionRequiredMessage(
        "That application",
        "an application is filed by somebody who is not yet staff, so nothing about it is in a staff member's offline read set",
      ),
    );
  } finally {
    loading.value = false;
  }
}

async function decide(decision: "approve" | "reject" | "defer"): Promise<void> {
  const current = application.value;

  if (current === null) {
    return;
  }

  busy.value = true;
  actionError.value = null;
  notice.value = null;

  try {
    const reason = decision === "reject" ? rejectReason.value.trim() : "";

    await decideApplication(decision, current.id, reason === "" ? null : reason);

    notice.value = `${current.applicantLegalName}'s application was ${decision}${
      decision === "defer" ? "red" : decision === "approve" ? "d" : "ed"
    }.`;
    rejectReason.value = "";

    await load();
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "That decision could not be recorded.",
    );
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void load();
});

watch(applicationId, () => {
  void load();
});
</script>

<template>
  <section class="application" aria-labelledby="application-heading">
    <p class="application__eyebrow">Organizer</p>
    <h1 id="application-heading" class="application__heading">
      {{ application?.applicantLegalName ?? "Application" }}
    </h1>
    <p class="application__back">
      <RouterLink :to="{ name: 'organizer.applications.index' }">
        All applications
      </RouterLink>
    </p>

    <p v-if="loadError" class="application__notice" role="alert">
      {{ loadError }}
      <button type="button" @click="load()">Try again</button>
    </p>

    <p v-else-if="loading" class="application__notice" role="status">
      Reading this application…
    </p>

    <template v-else-if="application">
      <p class="application__status-line">
        <span class="application__status" :data-status="application.status">
          {{ application.statusLabel }}
        </span>
      </p>

      <dl class="application__facts">
        <div>
          <dt>Applied to</dt>
          <dd>{{ scopeLabel }}</dd>
        </div>
        <div>
          <dt>Email</dt>
          <dd>{{ application.applicantEmail }}</dd>
        </div>
        <div>
          <dt>Submitted</dt>
          <dd>{{ formatMoment(application.submittedAt) }}</dd>
        </div>
        <!-- APP-003: the decision, and who owns it (requirements 2.4). -->
        <div v-if="application.reviewedAt || application.reviewedBy">
          <dt>Decided</dt>
          <dd>
            {{ formatMoment(application.reviewedAt) }}
            <template v-if="application.reviewedBy">
              by {{ application.reviewedBy }}
            </template>
          </dd>
        </div>
        <div v-if="application.decisionReason">
          <dt>Reason</dt>
          <dd>{{ application.decisionReason }}</dd>
        </div>
        <div>
          <dt>Department interest</dt>
          <dd v-if="application.departmentInterests.length === 0">
            No department preference — open to any
          </dd>
          <dd v-else>
            <span
              v-for="interest in application.departmentInterests"
              :key="interest.id"
              class="application__interest"
              :data-archived="interest.archived"
            >
              {{ interest.name }}{{ interest.archived ? " (archived)" : "" }}
            </span>
          </dd>
        </div>
      </dl>
      <p class="application__interest-note">
        Department interest is what the applicant said they were drawn to. It is
        not an assignment, and it is not edited during review.
      </p>

      <p v-if="actionError" class="application__error" role="alert">
        {{ actionError }}
      </p>
      <p v-else-if="notice" class="application__saved" role="status">
        {{ notice }}
      </p>

      <div v-if="canDecide" class="application__actions">
        <button type="button" :disabled="busy" @click="decide('approve')">
          Approve
        </button>
        <button type="button" :disabled="busy" @click="decide('defer')">
          Defer
        </button>
        <label class="application__reason">
          Reason for rejection
          <input v-model="rejectReason" type="text" maxlength="2000" />
        </label>
        <button
          type="button"
          :disabled="busy || rejectReason.trim() === ''"
          @click="decide('reject')"
        >
          Reject
        </button>
      </div>

      <p
        v-else-if="!application.canReview"
        class="application__readonly"
        role="note"
      >
        Read-only: this application named a department you lead. Deciding it is
        an organizer or Staff Coordinator job.
      </p>

      <p v-else class="application__readonly" role="note">
        This application has already been decided.
      </p>
    </template>
  </section>
</template>

<style scoped>
.application {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-3, 0.75rem);
  padding: var(--m-space-4, 1rem);
}

.application__eyebrow {
  margin: 0;
  color: var(--m-color-muted-foreground, #5b6b66);
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.06em;
}

.application__heading {
  margin: 0;
  font-size: 1.5rem;
}

.application__back {
  margin: 0;
  font-size: 0.9rem;
}

.application__status-line {
  margin: 0;
}

.application__status {
  font-weight: 800;
}

.application__facts {
  margin: 0;
  display: grid;
  grid-template-columns: minmax(9rem, auto) 1fr;
  gap: 0.25rem var(--m-space-3, 0.75rem);
}

.application__facts > div {
  display: contents;
}

.application__facts dt {
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.application__facts dd {
  margin: 0;
}

.application__interest + .application__interest::before {
  content: ", ";
}

.application__interest-note {
  margin: 0;
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.85rem;
}

.application__actions {
  display: flex;
  gap: var(--m-space-2, 0.5rem);
  align-items: flex-end;
  flex-wrap: wrap;
}

.application__reason {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.9rem;
  flex: 1 1 16rem;
}

.application__reason input {
  padding: 0.4rem;
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}

.application__readonly {
  margin: 0;
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.application__notice,
.application__error,
.application__saved {
  margin: 0;
  padding: var(--m-space-3, 0.75rem);
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}
</style>
