<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import { connectionRequiredMessage } from "@/offline/connectionRequired";
import { getAuditReview, type AuditReview } from "@/audit/auditReviewModel";
import { sessionOrganizationId } from "@/session/sessionContext";

/**
 * `organizer.audit` — audit review (M18.29; requirements 2.4; UI contract
 * 12.6).
 *
 * Requirements 2.4 asks that changes be attributed to the person who made them
 * and that important history be preserved. This is where an organizer reads the
 * first half: who acted, on what, when, from where, and why where a reason was
 * required.
 *
 * **It shows which fields moved and not what they moved to.** An audit payload
 * is a verbatim copy of whatever the writing path snapshotted, so serving the
 * values would make this page an unscoped read of every field every audited
 * path records — a staff member's phone number among them, which REPORT-002
 * keeps out of the roster an organizer exports. Repair work that needs the
 * values is God Mode's, and M18.34 builds that screen.
 *
 * Incident and Field Report history is absent, and absent from the node's
 * answer rather than filtered here: ORG-015 says organizing reaches neither,
 * and an audit row naming an incident discloses that it exists. An organizer
 * who also holds Incident Command standing reads that history through the
 * incident's own timeline, which is where it has always lived.
 *
 * The filter lists come from the node too, built from the rows this caller may
 * see, so a filter can never offer an action that exists only in a part of the
 * record they are not shown.
 */
const review = ref<AuditReview | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);

const filters = reactive({
  action: "",
  entityType: "",
  from: "",
  to: "",
  page: 1,
});

const organizationId = computed(() => sessionOrganizationId.value);
const entries = computed(() => review.value?.entries ?? []);
const pagination = computed(() => review.value?.pagination ?? null);

function formatMoment(value: string | null): string {
  if (value === null) {
    return "Not recorded";
  }

  const parsed = new Date(value);

  return Number.isNaN(parsed.getTime()) ? "Not recorded" : parsed.toLocaleString();
}

async function load(): Promise<void> {
  const id = organizationId.value;

  if (id === null || id === "") {
    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    review.value = await getAuditReview(id, {
      action: filters.action,
      entityType: filters.entityType,
      from: filters.from === "" ? undefined : new Date(filters.from).toISOString(),
      to: filters.to === "" ? undefined : new Date(filters.to).toISOString(),
      page: filters.page,
    });
  } catch (error) {
    loadError.value = meridianErrorMessage(
      error,
      connectionRequiredMessage(
        "The audit record",
        "an audit trail is the organization's history and is never sent to a device",
      ),
    );
  } finally {
    loading.value = false;
  }
}

/** A changed filter starts again at the first page, never mid-record. */
function refilter(): void {
  filters.page = 1;
  void load();
}

function turnTo(page: number): void {
  filters.page = page;
  void load();
}

onMounted(() => {
  void load();
});

watch(organizationId, () => {
  filters.page = 1;
  void load();
});
</script>

<template>
  <section class="audit" aria-labelledby="audit-heading">
    <p class="audit__eyebrow">Organizer</p>
    <h1 id="audit-heading" class="audit__heading">Audit</h1>
    <p class="audit__lede">
      Who changed what in this organization, when, and why. Incident and Field
      Report history is not here: it belongs to Incident Command, and is read
      from the incident itself.
    </p>

    <div class="audit__filters">
      <label class="audit__filter">
        Action
        <select v-model="filters.action" @change="refilter()">
          <option value="">Any</option>
          <option
            v-for="action in review?.actions ?? []"
            :key="action"
            :value="action"
          >
            {{ action }}
          </option>
        </select>
      </label>

      <label class="audit__filter">
        Record type
        <select v-model="filters.entityType" @change="refilter()">
          <option value="">Any</option>
          <option
            v-for="entityType in review?.entityTypes ?? []"
            :key="entityType"
            :value="entityType"
          >
            {{ entityType }}
          </option>
        </select>
      </label>

      <label class="audit__filter">
        From
        <input v-model="filters.from" type="datetime-local" @change="refilter()" />
      </label>

      <label class="audit__filter">
        To
        <input v-model="filters.to" type="datetime-local" @change="refilter()" />
      </label>
    </div>

    <p v-if="loadError" class="audit__notice" role="alert">
      {{ loadError }}
      <button type="button" @click="load()">Try again</button>
    </p>

    <p v-else-if="loading" class="audit__notice" role="status">
      Reading the audit record…
    </p>

    <template v-else>
      <p v-if="entries.length === 0" class="audit__notice" role="status">
        Nothing in this organization's record matches those filters.
      </p>

      <ol v-else class="audit__list">
        <li v-for="entry in entries" :key="entry.id" class="audit__entry">
          <p class="audit__action">
            <strong>{{ entry.action }}</strong>
            <span class="audit__entity">{{ entry.entityLabel }}</span>
          </p>
          <dl class="audit__facts">
            <div>
              <dt>By</dt>
              <!--
                Requirements 2.4: attribution. A row with no actor is a
                scheduled job — the lifecycle evaluator, the automatic no-show —
                or an exchange a device or node made on its own, and the node
                says which, rather than leaving a blank that reads like the
                record lost somebody's name. Worded by the node so this surface
                and the God Mode trail cannot disagree about what "nobody" is
                called.
              -->
              <dd>{{ entry.actorLabel }}</dd>
            </div>
            <div>
              <dt>When</dt>
              <dd>{{ formatMoment(entry.recordedAt) }}</dd>
            </div>
            <div v-if="entry.eventName">
              <dt>Event</dt>
              <dd>{{ entry.eventName }}</dd>
            </div>
            <div v-if="entry.departmentName">
              <dt>Department</dt>
              <dd>{{ entry.departmentName }}</dd>
            </div>
            <div v-if="entry.reason">
              <dt>Reason</dt>
              <dd>{{ entry.reason }}</dd>
            </div>
            <div v-if="entry.changedFields.length > 0">
              <dt>Fields changed</dt>
              <dd>{{ entry.changedFields.join(", ") }}</dd>
            </div>
            <div>
              <dt>Recorded from</dt>
              <dd>{{ entry.sourceContext }}</dd>
            </div>
          </dl>
        </li>
      </ol>

      <div v-if="pagination && pagination.lastPage > 1" class="audit__pager">
        <button
          type="button"
          :disabled="pagination.page <= 1"
          @click="turnTo(pagination.page - 1)"
        >
          Newer
        </button>
        <span>
          Page {{ pagination.page }} of {{ pagination.lastPage }} —
          {{ pagination.total }} entries
        </span>
        <button
          type="button"
          :disabled="pagination.page >= pagination.lastPage"
          @click="turnTo(pagination.page + 1)"
        >
          Older
        </button>
      </div>
    </template>
  </section>
</template>

<style scoped>
.audit {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-3, 0.75rem);
  padding: var(--m-space-4, 1rem);
}

.audit__eyebrow {
  margin: 0;
  color: var(--m-color-muted-foreground, #5b6b66);
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.06em;
}

.audit__heading {
  margin: 0;
  font-size: 1.5rem;
}

.audit__lede {
  margin: 0;
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.audit__filters {
  display: flex;
  gap: var(--m-space-3, 0.75rem);
  flex-wrap: wrap;
}

.audit__filter {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.9rem;
}

.audit__filter select,
.audit__filter input {
  padding: 0.4rem;
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}

.audit__list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
}

.audit__entry {
  border: 1px solid var(--m-color-border, #d5ddda);
  border-radius: var(--m-radius-2, 0.375rem);
  padding: var(--m-space-3, 0.75rem);
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
}

.audit__action {
  margin: 0;
  display: flex;
  gap: var(--m-space-2, 0.5rem);
  align-items: baseline;
  flex-wrap: wrap;
}

.audit__entity {
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.audit__facts {
  margin: 0;
  display: grid;
  grid-template-columns: minmax(8rem, auto) 1fr;
  gap: 0.25rem var(--m-space-3, 0.75rem);
}

.audit__facts > div {
  display: contents;
}

.audit__facts dt {
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.audit__facts dd {
  margin: 0;
}

.audit__pager {
  display: flex;
  gap: var(--m-space-2, 0.5rem);
  align-items: center;
  flex-wrap: wrap;
  font-size: 0.9rem;
}

.audit__notice {
  margin: 0;
  padding: var(--m-space-3, 0.75rem);
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}
</style>
