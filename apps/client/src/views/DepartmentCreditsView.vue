<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRoute } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import ReportingExportSection from "@/components/sections/ReportingExportSection.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import {
  getDepartmentCreditReview,
  type DepartmentCreditReview,
} from "@/credits/departmentCreditReviewModel";
import {
  CREDITS_EARNED_EXPORT,
  reportingExportAuthorityFor,
} from "@/reporting/reportingExports";
import { selectedSessionDepartment } from "@/session/sessionAccess";

/**
 * `department.credits` — credit review and export for one department
 * (M18.30; UI contract 12.4; CREDIT-004, CREDIT-005; REPORT-005).
 *
 * The contract calls this screen "credit review/export" and it is both, in that
 * order. The review is what somebody reads before they download anything: what
 * each member of this department earned at this event, and — beside every line
 * — the hours, the rate, and the policy those credits were arrived at from, so
 * CREDIT-005 is satisfied on the screen rather than by pointing a reader at a
 * file (or at a ledger they cannot open).
 *
 * **Nothing here changes a number.** Credits freeze at calculation and a frozen
 * entry cannot be repriced (CREDIT-004), and starting a calculation run belongs
 * to organizers (ORG-010). A lead who thinks a number is wrong is looking at an
 * hours record, and hours corrections are the attendance path's.
 *
 * **What is not yet credited is on the page.** Hours this department worked
 * that carry no entry are counted at the top, because a total that quietly
 * omits them would read as the department's final answer when it is not.
 *
 * The export is the same one `department.exports` offers, offered here too
 * because this is the page somebody is standing on when they want the file. It
 * runs through the shared section, so its scope statement and its excluded
 * fields are worded once.
 */
const route = useRoute();
const eventId = computed(() => String(route.params.eventId ?? ""));
const departmentId = computed(() => String(route.params.departmentId ?? ""));

const review = ref<DepartmentCreditReview | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);

/**
 * The credits export alone, at whichever reach this reader holds it.
 *
 * `"any"` rather than `"department"`: an organizer reading a department's
 * credits from here is entitled to the file too, and their standing reaches the
 * event. The `department_id` narrowing below is what keeps the file to the
 * department on screen either way, and the node refuses one outside the
 * caller's scope rather than widening to it.
 */
const exportAuthority = reportingExportAuthorityFor([CREDITS_EARNED_EXPORT]);

const eyebrow = computed(
  () =>
    review.value?.departmentLabel ||
    selectedSessionDepartment.value?.departmentLabel ||
    "Department",
);

const lede = computed(() =>
  review.value === null
    ? ""
    : `Credits earned by this department at ${review.value.eventLabel}.`,
);

const exportScopeStatement = computed(() =>
  review.value === null
    ? ""
    : `${review.value.departmentLabel} only, within ${review.value.eventLabel}. The file carries the frozen basis behind every number, and no phone number, emergency contact, or date of birth is a column in it.`,
);

const hasOutstanding = computed(() => {
  const outstanding = review.value?.outstanding;

  return (
    outstanding !== undefined &&
    (outstanding.openHoursCount > 0 || outstanding.uncreditedHoursCount > 0)
  );
});

function formatMoment(value: string | null): string {
  if (value === null) {
    return "Not recorded";
  }

  const parsed = new Date(value);

  return Number.isNaN(parsed.getTime()) ? "Not recorded" : parsed.toLocaleString();
}

async function load(): Promise<void> {
  if (eventId.value === "" || departmentId.value === "") {
    review.value = null;

    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    review.value = await getDepartmentCreditReview(eventId.value, departmentId.value);
  } catch (error) {
    // A ledger that could not be read must not render as a department that
    // earned nothing.
    review.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "The department's credits could not be read.",
    );
  } finally {
    loading.value = false;
  }
}

watch([eventId, departmentId], () => void load(), { immediate: true });
</script>

<template>
  <WorkflowPageShell
    heading-id="department-credits-heading"
    title="Credits"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <p v-if="loadError" class="credits__notice" role="alert">
      {{ loadError }}
      <button type="button" @click="load()">Try again</button>
    </p>

    <p v-else-if="loading && review === null" class="credits__notice" role="status">
      Reading the department's credits…
    </p>

    <template v-else-if="review">
      <dl class="credits__totals">
        <div>
          <dt>Credits</dt>
          <dd>{{ review.totals.credits }}</dd>
        </div>
        <div>
          <dt>Hours</dt>
          <dd>{{ review.totals.hours }}</dd>
        </div>
        <div>
          <dt>Staff credited</dt>
          <dd>{{ review.totals.staffCount }}</dd>
        </div>
      </dl>

      <p v-if="hasOutstanding" class="credits__notice" role="status">
        <template v-if="review.outstanding.openHoursCount > 0">
          {{ review.outstanding.openHoursCount }} hours records are still inside
          the correction grace period and cannot be credited yet.
        </template>
        <template v-if="review.outstanding.uncreditedHoursCount > 0">
          {{ review.outstanding.uncreditedHoursCount }} frozen hours records
          carry no credit entry: either a calculation run has not covered them,
          or they resolved to no credit policy.
        </template>
        <template v-if="review.outstanding.graceClosesAt">
          The grace period
          {{ review.outstanding.graceClosed ? "closed" : "closes" }}
          {{ formatMoment(review.outstanding.graceClosesAt) }}.
        </template>
      </p>

      <p v-if="review.entries.length === 0" class="credits__notice" role="status">
        Nothing has been credited to this department for this event yet.
      </p>

      <template v-else>
        <section aria-labelledby="credits-staff-heading">
          <h2 id="credits-staff-heading" class="credits__subheading">
            By staff member
          </h2>

          <div class="credits__scroll">
            <table class="credits__table">
              <thead>
                <tr>
                  <th scope="col">Staff member</th>
                  <th scope="col">Shifts credited</th>
                  <th scope="col">Hours</th>
                  <th scope="col">Credits</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in review.staff" :key="row.staffId">
                  <th scope="row">
                    {{ row.staffName }}
                    <span v-if="row.staffHandle" class="credits__aside">
                      {{ row.staffHandle }}
                    </span>
                  </th>
                  <td>{{ row.entryCount }}</td>
                  <td>{{ row.hours }}</td>
                  <td>{{ row.credits }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section aria-labelledby="credits-entries-heading">
          <h2 id="credits-entries-heading" class="credits__subheading">
            Every entry, and what it was calculated from
          </h2>

          <div class="credits__scroll">
            <table class="credits__table">
              <thead>
                <tr>
                  <th scope="col">Staff member</th>
                  <th scope="col">Shift</th>
                  <th scope="col">Hours</th>
                  <th scope="col">Rate</th>
                  <th scope="col">Credits</th>
                  <th scope="col">Policy</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="entry in review.entries" :key="entry.id">
                  <th scope="row">{{ entry.staffName }}</th>
                  <td>
                    {{ entry.shiftTitle ?? "—" }}
                    <span v-if="entry.team" class="credits__aside">
                      {{ entry.team }}
                    </span>
                  </td>
                  <td>{{ entry.hours }}</td>
                  <!--
                    The rate the work was credited at, read from the entry's
                    frozen basis rather than from the policy record it names: a
                    policy re-rated afterwards must not restate a finished event
                    (CREDIT-004).
                  -->
                  <td>{{ entry.creditMultiplier ?? "Not recorded" }}</td>
                  <td>{{ entry.credits }}</td>
                  <td>
                    {{ entry.creditPolicyName ?? "Not recorded" }}
                    <span v-if="entry.policySource" class="credits__aside">
                      {{ entry.policySource }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>

      <!--
        Absent, not disabled, for a reader who holds no credits export
        (CLIENT-005). Reading the ledger and exporting it answer to the same
        capability, so in practice this is here whenever the page above is —
        but the section decides that from the session rather than assuming it.
      -->
      <ReportingExportSection
        v-if="exportAuthority"
        :authority="exportAuthority"
        :scope-statement="exportScopeStatement"
        :department-id="review.departmentId"
      />
    </template>
  </WorkflowPageShell>
</template>

<style scoped>
.credits__notice {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.credits__subheading {
  margin: 0 0 var(--m-space-2);
  font-size: 1.05rem;
}

.credits__totals {
  margin: 0;
  display: flex;
  gap: var(--m-space-4);
  flex-wrap: wrap;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.credits__totals dt {
  font-size: 0.85rem;
}

.credits__totals dd {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 600;
}

.credits__scroll {
  overflow-x: auto;
}

.credits__table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}

.credits__table th,
.credits__table td {
  padding: var(--m-space-2);
  border-bottom: 1px solid var(--m-border-default);
  vertical-align: top;
}

.credits__aside {
  display: block;
  font-size: 0.85rem;
  font-weight: 400;
}
</style>
