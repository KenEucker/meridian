<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import {
  decideApplication,
  getApplicationReviewQueue,
  type ReviewableApplication,
} from "@/applications/applicationReviewModel";
import { participationLink } from "@/applications/participationModel";
import { sessionOrganizationSlug } from "@/session/sessionContext";

/**
 * `organizer.applications` — application review as a product surface
 * (M18.21A; APP-005, APP-011, APP-017, APP-019).
 *
 * Review has lived only in the God Mode console until now, which made a routine
 * organizer job reachable only through the repair interface. This is the same
 * decisions against the same domain service, gated by the catalog.
 *
 * Three things this page does deliberately:
 *
 *  1. **Scope is the node's answer.** One read, never one per organization.
 *     The list is whatever `organization.applications.review` and the APP-011
 *     department-lead visibility resolved to, and a caller holding neither
 *     meets a 403 rather than an empty list — "nothing is waiting" and "you do
 *     not review applications" are different facts.
 *  2. **Review controls follow the row, not the page.** A department lead's
 *     read-only visibility (APP-011) renders the application and no decision
 *     controls, and somebody who reviews for one organization and only leads a
 *     department in another sees both kinds of row in one list.
 *  3. **Reject asks for a reason before it is available.** The node accepts a
 *     rejection without one, but the applicant is told about the decision
 *     (NOTIFY-001) and a rejection nobody explained is a message with nothing
 *     to act on.
 *
 * The shareable participation link (APP-017) is here because this is where
 * somebody thinking about intake already is. It carries no token: the page it
 * opens is public, and a token would make it look like a gate.
 */
const applications = ref<readonly ReviewableApplication[]>([]);
const canReview = ref(false);
const hasLeadVisibility = ref(false);
const loading = ref(false);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const notice = ref<string | null>(null);
const busyId = ref<string | null>(null);
const rejectReasons = ref<Record<string, string>>({});
const statusFilter = ref<string>("submitted");
const interestFilter = ref<string>("");
const copied = ref(false);

const shareLink = computed(() => {
  const slug = sessionOrganizationSlug.value;

  return slug == null || slug === "" ? null : participationLink(slug);
});

/** APP-011: the organizer list filters by department interest in Alpha 1. */
const interestOptions = computed(() => {
  const names = new Set<string>();

  for (const application of applications.value) {
    for (const interest of application.departmentInterests) {
      names.add(interest.name);
    }
  }

  return [...names].sort((left, right) => left.localeCompare(right));
});

const visibleApplications = computed(() =>
  applications.value.filter((application) => {
    if (
      statusFilter.value !== "" &&
      application.status !== statusFilter.value
    ) {
      return false;
    }

    if (interestFilter.value === "") {
      return true;
    }

    return application.departmentInterests.some(
      (interest) => interest.name === interestFilter.value,
    );
  }),
);

function scopeLabel(application: ReviewableApplication): string {
  return application.scope === "organization"
    ? `${application.organizationName ?? "the organization"} — no event`
    : (application.eventName ?? "Unnamed event");
}

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = null;

  try {
    const queue = await getApplicationReviewQueue();

    applications.value = queue.applications;
    canReview.value = queue.canReview;
    hasLeadVisibility.value = queue.hasDepartmentLeadVisibility;
  } catch (error) {
    loadError.value = meridianErrorMessage(
      error,
      "Applications could not be read.",
    );
  } finally {
    loading.value = false;
  }
}

async function decide(
  decision: "approve" | "reject" | "defer",
  application: ReviewableApplication,
): Promise<void> {
  busyId.value = application.id;
  actionError.value = null;
  notice.value = null;

  try {
    const reason =
      decision === "reject" ? (rejectReasons.value[application.id] ?? "") : "";

    await decideApplication(
      decision,
      application.id,
      reason === "" ? null : reason,
    );

    notice.value = `${application.applicantLegalName}'s application was ${decision}${
      decision === "defer" ? "red" : decision === "approve" ? "d" : "ed"
    }.`;
    rejectReasons.value = { ...rejectReasons.value, [application.id]: "" };

    await load();
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "That decision could not be recorded.",
    );
  } finally {
    busyId.value = null;
  }
}

async function copyShareLink(): Promise<void> {
  const link = shareLink.value;

  if (link == null) {
    return;
  }

  try {
    await navigator.clipboard.writeText(link);
    copied.value = true;
    window.setTimeout(() => {
      copied.value = false;
    }, 2000);
  } catch {
    // A browser that refuses clipboard access still shows the address in the
    // field beside the button, which is the thing somebody actually needs.
    copied.value = false;
  }
}

onMounted(() => {
  void load();
});
</script>

<template>
  <section class="applications" aria-labelledby="applications-heading">
    <p class="applications__eyebrow">Organizer</p>
    <h1 id="applications-heading" class="applications__heading">
      Applications
    </h1>

    <!-- APP-017: the address anybody may hand out. -->
    <section
      v-if="shareLink"
      class="applications__share"
      aria-label="Participation link"
    >
      <p class="applications__share-label">
        Share this link with anybody who might want to join. It is public, and
        opening it grants nothing.
      </p>
      <div class="applications__share-row">
        <input
          class="applications__share-input"
          type="text"
          readonly
          :value="shareLink"
          aria-label="Participation link"
        />
        <button type="button" @click="copyShareLink()">
          {{ copied ? "Copied" : "Copy link" }}
        </button>
      </div>
    </section>

    <p v-if="loadError" class="applications__notice" role="alert">
      {{ loadError }}
      <button type="button" @click="load()">Try again</button>
    </p>

    <p v-else-if="loading" class="applications__notice" role="status">
      Reading applications…
    </p>

    <template v-else>
      <p
        v-if="!canReview && hasLeadVisibility"
        class="applications__notice"
        role="status"
      >
        You are seeing applications that named a department you lead. Deciding
        them is an organizer or Staff Coordinator job.
      </p>

      <div class="applications__filters">
        <label class="applications__filter">
          Status
          <select v-model="statusFilter">
            <option value="">All</option>
            <option value="submitted">Submitted</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="deferred">Deferred</option>
            <option value="withdrawn">Withdrawn</option>
          </select>
        </label>

        <label v-if="interestOptions.length > 0" class="applications__filter">
          Department interest
          <select v-model="interestFilter">
            <option value="">Any</option>
            <option v-for="name in interestOptions" :key="name" :value="name">
              {{ name }}
            </option>
          </select>
        </label>
      </div>

      <p v-if="actionError" class="applications__error" role="alert">
        {{ actionError }}
      </p>
      <p v-else-if="notice" class="applications__saved" role="status">
        {{ notice }}
      </p>

      <p
        v-if="visibleApplications.length === 0"
        class="applications__notice"
        role="status"
      >
        Nothing matches those filters.
      </p>

      <article
        v-for="application in visibleApplications"
        :key="application.id"
        class="applications__row"
        :data-scope="application.scope"
      >
        <header class="applications__row-header">
          <!--
            The applicant's name opens the detail surface (M18.29; UI contract
            12.6 `organizer.application-detail`). A link rather than a control,
            because it is an address: the same page is reachable by pasting it,
            which is what makes an application shareable between two reviewers.
            Department leads follow it to the same read-only view APP-011 gives
            them here.
          -->
          <h2 class="applications__applicant">
            <RouterLink
              :to="{
                name: 'organizer.applications.show',
                params: { applicationId: application.id },
              }"
            >
              {{ application.applicantLegalName }}
            </RouterLink>
          </h2>
          <span class="applications__status" :data-status="application.status">
            {{ application.statusLabel }}
          </span>
        </header>

        <dl class="applications__facts">
          <div>
            <dt>Applied to</dt>
            <dd>{{ scopeLabel(application) }}</dd>
          </div>
          <div>
            <dt>Email</dt>
            <dd>{{ application.applicantEmail }}</dd>
          </div>
          <div v-if="application.submittedAt">
            <dt>Submitted</dt>
            <dd>{{ new Date(application.submittedAt).toLocaleString() }}</dd>
          </div>
          <div>
            <dt>Department interest</dt>
            <dd v-if="application.departmentInterests.length === 0">
              No department preference
            </dd>
            <dd v-else>
              <span
                v-for="interest in application.departmentInterests"
                :key="interest.id"
                class="applications__interest"
                :data-archived="interest.archived"
              >
                {{ interest.name }}{{ interest.archived ? " (archived)" : "" }}
              </span>
            </dd>
          </div>
          <div v-if="application.decisionReason">
            <dt>Reason</dt>
            <dd>{{ application.decisionReason }}</dd>
          </div>
        </dl>

        <div
          v-if="application.canReview && application.status === 'submitted'"
          class="applications__actions"
        >
          <button
            type="button"
            :disabled="busyId === application.id"
            @click="decide('approve', application)"
          >
            Approve
          </button>
          <button
            type="button"
            :disabled="busyId === application.id"
            @click="decide('defer', application)"
          >
            Defer
          </button>
          <label class="applications__reason">
            Reason for rejection
            <input
              type="text"
              maxlength="2000"
              :value="rejectReasons[application.id] ?? ''"
              @input="
                rejectReasons = {
                  ...rejectReasons,
                  [application.id]: ($event.target as HTMLInputElement).value,
                }
              "
            />
          </label>
          <button
            type="button"
            :disabled="
              busyId === application.id ||
              (rejectReasons[application.id] ?? '').trim() === ''
            "
            @click="decide('reject', application)"
          >
            Reject
          </button>
        </div>

        <p
          v-else-if="!application.canReview"
          class="applications__readonly"
          role="note"
        >
          Read-only: this application named a department you lead.
        </p>
      </article>
    </template>
  </section>
</template>

<style scoped>
.applications {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-4, 1rem);
  padding: var(--m-space-4, 1rem);
}

.applications__eyebrow {
  margin: 0;
  color: var(--m-color-muted-foreground, #5b6b66);
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.06em;
}

.applications__heading {
  margin: 0;
  font-size: 1.5rem;
}

.applications__share {
  border: 1px solid var(--m-color-border, #d5ddda);
  border-radius: var(--m-radius-2, 0.375rem);
  padding: var(--m-space-3, 0.75rem);
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
}

.applications__share-label {
  margin: 0;
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.applications__share-row {
  display: flex;
  gap: var(--m-space-2, 0.5rem);
  flex-wrap: wrap;
}

.applications__share-input {
  flex: 1 1 20rem;
  padding: 0.4rem;
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}

.applications__filters {
  display: flex;
  gap: var(--m-space-3, 0.75rem);
  flex-wrap: wrap;
}

.applications__filter {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.9rem;
}

.applications__row {
  border: 1px solid var(--m-color-border, #d5ddda);
  border-radius: var(--m-radius-2, 0.375rem);
  padding: var(--m-space-3, 0.75rem);
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
}

.applications__row-header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: var(--m-space-2, 0.5rem);
}

.applications__applicant {
  margin: 0;
  font-size: 1.05rem;
}

.applications__facts {
  margin: 0;
  display: grid;
  grid-template-columns: minmax(8rem, auto) 1fr;
  gap: 0.25rem var(--m-space-3, 0.75rem);
}

.applications__facts > div {
  display: contents;
}

.applications__facts dt {
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.applications__facts dd {
  margin: 0;
}

.applications__interest + .applications__interest::before {
  content: ", ";
}

.applications__actions {
  display: flex;
  gap: var(--m-space-2, 0.5rem);
  align-items: flex-end;
  flex-wrap: wrap;
}

.applications__reason {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.9rem;
  flex: 1 1 16rem;
}

.applications__reason input {
  padding: 0.4rem;
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}

.applications__readonly {
  margin: 0;
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.applications__notice,
.applications__error,
.applications__saved {
  margin: 0;
  padding: var(--m-space-3, 0.75rem);
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}
</style>
