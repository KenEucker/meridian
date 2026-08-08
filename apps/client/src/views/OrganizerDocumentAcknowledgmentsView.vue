<script setup lang="ts">
import { computed, ref, watch } from "vue";

import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import StatusPill, { type StatusPillTone } from "@/components/StatusPill.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  acknowledgmentReviewAuthority,
  createAcknowledgmentRequirement,
  formatAcknowledgedAt,
  getAcknowledgmentReview,
  setAcknowledgmentRequirementActive,
  type AcknowledgmentReview,
  type ReviewedRequirement,
} from "@/documents/documentAcknowledgmentModel";
import { useLocalNodeReachable } from "@/offline/useConnectivity";
import { sessionOrganizationLabel } from "@/session/sessionContext";

/**
 * `organizer.document-acknowledgments` — deciding what must be acknowledged and
 * reading who has (M18.6; POL-023, POL-046, POL-047; UI contract 12.6).
 *
 * The requirement administration and the review are one page because they are
 * one loop: you ask for something, then you find out whether it was answered,
 * and the second is the only reason to do the first. Both answer to
 * `documents.acknowledgments.review`, and a client holding neither is told so
 * rather than shown an empty table (CLIENT-005).
 *
 * Three things it takes care over.
 *
 *  1. **The form offers only what the command accepts.** Documents, scopes, and
 *     contexts come from the node with the review (CLIENT-006). Draft documents
 *     are not among them, because a requirement pointing at one is refused for
 *     everybody it is addressed to — a trap rather than a requirement.
 *  2. **Retired requirements stay on screen.** An organizer checking whether
 *     something was ever asked is looking for exactly the row a list of active
 *     requirements would have dropped, and the acknowledgments recorded against
 *     it are still true.
 *  3. **It says what it is not.** POL-026 and POL-027 keep acknowledgment out
 *     of shift signup and credential eligibility. An organizer looking at a
 *     column of people who have not answered will otherwise assume there is a
 *     consequence attached, and act on that assumption.
 */
const authority = acknowledgmentReviewAuthority;
const nodeReachable = useLocalNodeReachable();

const review = ref<AcknowledgmentReview | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const notice = ref<string | null>(null);
const busyRequirementId = ref<string | null>(null);
const creating = ref(false);
const expandedRequirementId = ref<string | null>(null);

const form = ref({ document: "", scope: "", context: "" });

const isOffline = computed(() => !nodeReachable.value);

const lede = computed(() => {
  const held = authority.value;

  if (held === null) {
    return sessionOrganizationLabel.value ?? "";
  }

  return `${review.value?.organizationName ?? sessionOrganizationLabel.value ?? "This organization"} — reviewed as ${held.roleLabel}.`;
});

const requirements = computed<readonly ReviewedRequirement[]>(
  () => review.value?.requirements ?? [],
);

/** Whether the create form has everything the command needs. */
const canCreate = computed(
  () =>
    form.value.document !== "" &&
    form.value.scope !== "" &&
    form.value.context !== "" &&
    !isOffline.value &&
    !creating.value,
);

watch(
  authority,
  () => {
    void load();
  },
  { immediate: true },
);

async function load(): Promise<void> {
  const held = authority.value;

  if (held === null) {
    review.value = null;

    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    const answer = await getAcknowledgmentReview(held.organizationId);

    review.value = answer;
    form.value = {
      document: "",
      scope: answer.scopes[0]
        ? optionKey(answer.scopes[0].scopeType, answer.scopes[0].scopeId)
        : "",
      context: answer.contexts[0]?.value ?? "",
    };
  } catch (error) {
    review.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to read acknowledgment requirements. Check the connection to this node and try again.",
    );
  } finally {
    loading.value = false;
  }
}

/**
 * Ask for a document to be acknowledged.
 *
 * Re-reads rather than splicing the answer in. A new requirement arrives with
 * its whole subject list — everybody in the scope who has not answered it yet —
 * and that list is what the page is for; there is no partial update that leaves
 * the surface saying something true.
 */
async function submitRequirement(): Promise<void> {
  const held = authority.value;
  const document = selectedDocument.value;
  const scope = selectedScope.value;

  if (held === null || document === null || scope === null) {
    return;
  }

  actionError.value = null;
  notice.value = null;
  creating.value = true;

  try {
    await createAcknowledgmentRequirement({
      organizationId: held.organizationId,
      documentType: document.documentType,
      documentId: document.documentId,
      scopeType: scope.scopeType,
      scopeId: scope.scopeId,
      context: form.value.context,
    });

    notice.value = `${document.title} is now required for ${scope.label}.`;

    await load();
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to create this acknowledgment requirement.",
    );
  } finally {
    creating.value = false;
  }
}

/**
 * Retire a requirement, or put it back.
 *
 * The answer replaces the row in place: the acknowledgments already recorded
 * against it are unchanged, and seeing the count survive the retirement is the
 * point.
 */
async function toggleActive(requirement: ReviewedRequirement): Promise<void> {
  if (busyRequirementId.value !== null) {
    return;
  }

  actionError.value = null;
  notice.value = null;
  busyRequirementId.value = requirement.id;

  try {
    const updated = await setAcknowledgmentRequirementActive(
      requirement.id,
      !requirement.active,
    );

    if (updated !== null && review.value !== null) {
      review.value = {
        ...review.value,
        requirements: review.value.requirements.map((candidate) =>
          candidate.id === updated.id ? updated : candidate,
        ),
      };
    } else {
      await load();
    }

    notice.value = requirement.active
      ? `${requirement.documentTitle} is no longer required for ${requirement.scopeLabel}.`
      : `${requirement.documentTitle} is required again for ${requirement.scopeLabel}.`;
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to change this acknowledgment requirement.",
    );
  } finally {
    busyRequirementId.value = null;
  }
}

const selectedDocument = computed(
  () =>
    review.value?.documents.find(
      (option) =>
        optionKey(option.documentType, option.documentId) ===
        form.value.document,
    ) ?? null,
);

const selectedScope = computed(
  () =>
    review.value?.scopes.find(
      (option) => optionKey(option.scopeType, option.scopeId) === form.value.scope,
    ) ?? null,
);

function optionKey(type: string, id: string): string {
  return `${type}:${id}`;
}

function toggleExpanded(requirement: ReviewedRequirement): void {
  expandedRequirementId.value =
    expandedRequirementId.value === requirement.id ? null : requirement.id;
}

function requirementTone(requirement: ReviewedRequirement): StatusPillTone {
  return requirement.active ? "positive" : "neutral";
}
</script>

<template>
  <WorkflowPageShell
    heading-id="organizer-acknowledgments-heading"
    title="Acknowledgments"
    eyebrow="Organizer administration"
    :lede="lede"
  >
    <!--
      Absent, not disabled: a client whose session carries no review capability
      has nothing this page can offer (CLIENT-005), and the node refuses the
      request either way (CLIENT-006).
    -->
    <p v-if="authority === null" class="acks__restricted" role="status">
      Reviewing and maintaining document acknowledgment requirements requires
      organizer authority for an organization this device is working in.
    </p>

    <template v-else>
      <p v-if="loadError" class="acks__error" role="alert">
        {{ loadError }}
        <button type="button" @click="load">Try again</button>
      </p>

      <p v-if="isOffline" class="acks__notice" role="status">
        Changing what must be acknowledged needs a connection to the node. It is
        not held on this device for later.
      </p>

      <!-- POL-026, POL-027: not a gate, said before anybody reads a column of
           people who have not answered and assumes there is a consequence. -->
      <p class="acks__gating" data-testid="acknowledgment-gating">
        An outstanding acknowledgment does not block shift signup or event
        credential eligibility. It is a record that somebody read a document.
      </p>

      <p v-if="actionError" class="acks__error" role="alert">
        {{ actionError }}
      </p>

      <p v-if="notice" class="acks__notice" role="status">{{ notice }}</p>

      <form class="acks__form" @submit.prevent="submitRequirement">
        <h2 class="acks__form-title">Require a document</h2>

        <p v-if="review && review.documents.length === 0" class="acks__notice">
          This organization has no published policy or procedure to require.
          Publish one first.
        </p>

        <template v-else>
          <label class="acks__field">
            <span>Document</span>
            <select v-model="form.document" data-testid="requirement-document">
              <option value="">Choose a published document</option>
              <option
                v-for="option in review?.documents ?? []"
                :key="optionKey(option.documentType, option.documentId)"
                :value="optionKey(option.documentType, option.documentId)"
              >
                {{ option.title }} (version {{ option.version }})
              </option>
            </select>
          </label>

          <label class="acks__field">
            <span>Asked of</span>
            <select v-model="form.scope" data-testid="requirement-scope">
              <option
                v-for="option in review?.scopes ?? []"
                :key="optionKey(option.scopeType, option.scopeId)"
                :value="optionKey(option.scopeType, option.scopeId)"
              >
                {{ option.label }}
              </option>
            </select>
          </label>

          <label class="acks__field">
            <span>Required at</span>
            <select v-model="form.context" data-testid="requirement-context">
              <option
                v-for="option in review?.contexts ?? []"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </option>
            </select>
          </label>

          <button
            type="submit"
            class="acks__submit"
            :disabled="!canCreate"
            data-testid="requirement-submit"
          >
            {{ creating ? "Requiring…" : "Require this document" }}
          </button>
        </template>
      </form>

      <p v-if="loading" class="acks__notice" role="status">
        Reading acknowledgment requirements…
      </p>

      <p
        v-else-if="requirements.length === 0 && !loadError"
        class="acks__notice"
        role="status"
      >
        No document is currently required in this organization.
      </p>

      <article
        v-for="requirement in requirements"
        :key="requirement.id"
        class="acks__requirement"
        :data-active="requirement.active ? 'true' : 'false'"
      >
        <header class="acks__requirement-header">
          <h2 class="acks__requirement-title">
            {{ requirement.documentTitle }}
          </h2>
          <StatusPill
            :label="requirement.active ? 'Required' : 'Retired'"
            :tone="requirementTone(requirement)"
            sr-prefix="Requirement"
          />
        </header>

        <dl class="acks__facts">
          <div class="acks__fact">
            <dt>Asked of</dt>
            <dd>{{ requirement.scopeLabel }}</dd>
          </div>
          <div class="acks__fact">
            <dt>Required at</dt>
            <dd>{{ requirement.contextLabel }}</dd>
          </div>
          <div class="acks__fact">
            <dt>Version</dt>
            <dd>{{ requirement.documentVersion ?? "Unknown" }}</dd>
          </div>
          <div class="acks__fact">
            <dt>Answered</dt>
            <dd data-testid="requirement-answered">
              {{ requirement.acknowledgedCount }} of
              {{ requirement.subjectCount }}
            </dd>
          </div>
        </dl>

        <div class="acks__requirement-actions">
          <button
            type="button"
            class="acks__toggle"
            :aria-expanded="
              expandedRequirementId === requirement.id ? 'true' : 'false'
            "
            @click="toggleExpanded(requirement)"
          >
            {{
              expandedRequirementId === requirement.id
                ? "Hide who was asked"
                : "Who was asked"
            }}
          </button>

          <button
            type="button"
            class="acks__toggle"
            :disabled="isOffline || busyRequirementId === requirement.id"
            @click="toggleActive(requirement)"
          >
            {{ requirement.active ? "Stop requiring" : "Require again" }}
          </button>
        </div>

        <ul
          v-if="expandedRequirementId === requirement.id"
          class="acks__subjects"
          data-testid="requirement-subjects"
        >
          <li v-if="requirement.staff.length === 0" class="acks__subject">
            Nobody is in this scope yet.
          </li>
          <li
            v-for="subject in requirement.staff"
            :key="subject.staffId"
            class="acks__subject"
            :data-acknowledged="subject.acknowledged ? 'true' : 'false'"
          >
            <span class="acks__subject-name">
              {{ subject.displayName
              }}<template v-if="subject.handle"> ({{ subject.handle }})</template>
            </span>
            <span class="acks__subject-state">
              <template v-if="subject.acknowledged">
                Version {{ subject.acknowledgedVersion }} on
                {{ formatAcknowledgedAt(subject.acknowledgedAt) }}
                <template v-if="subject.documentChangedSince">
                  — the document has changed since, and is not re-required
                </template>
              </template>
              <template v-else>Not yet acknowledged</template>
            </span>
          </li>
        </ul>
      </article>
    </template>
  </WorkflowPageShell>
</template>

<style scoped>
.acks__restricted,
.acks__notice,
.acks__error,
.acks__gating {
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

.acks__form,
.acks__requirement {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.acks__requirement[data-active="false"] {
  opacity: 0.85;
}

.acks__form-title,
.acks__requirement-title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.acks__requirement-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.acks__field {
  display: grid;
  gap: var(--m-space-1);
}

.acks__field > span {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.acks__field select {
  min-height: 2.75rem;
  padding: 0 var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
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

.acks__requirement-actions {
  display: grid;
  gap: var(--m-space-2);
}

.acks__submit,
.acks__toggle {
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

.acks__submit {
  border-color: var(--m-action-primary-bg);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.acks__submit:disabled,
.acks__toggle:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.acks__submit:focus-visible,
.acks__toggle:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.acks__subjects {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.acks__subject {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: var(--m-space-2);
  padding: var(--m-space-2) 0;
  border-top: 1px solid var(--m-border-default);
}

.acks__subject-state {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

@media (min-width: 44rem) {
  .acks__requirement-actions {
    display: flex;
    flex-wrap: wrap;
  }

  .acks__submit,
  .acks__toggle {
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
