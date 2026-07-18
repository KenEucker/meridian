<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";

import { resolveFieldSession } from "@/field-reports/fieldSession";
import { OfflineFieldReportError } from "@/field-reports/offlineFieldReport";
import { submitFieldReport } from "@/field-reports/submitFieldReport";

// Submit Field Report — UI contract 12.3 `staff.field-reports.create` and
// section 14.1–14.2 (M9.4). Submit/Cancel only; finalize on submit; no drafts
// or autosave. Name Reference autocomplete is intentionally absent (M9.6A).
const router = useRouter();
const session = computed(() => resolveFieldSession());
const body = ref("");
const errorMessage = ref<string | null>(null);
const submitting = ref(false);

const canSubmit = computed(
  () =>
    Boolean(session.value) &&
    body.value.trim().length > 0 &&
    !submitting.value,
);

function onCancel(): void {
  void router.push({ name: "staff.field-reports.index" });
}

function onSubmit(): void {
  errorMessage.value = null;

  const current = session.value;
  if (!current) {
    errorMessage.value =
      "Field session is unavailable. Sign in and select an event before submitting.";
    return;
  }

  if (body.value.trim().length === 0) {
    errorMessage.value = "Field Report body text is required.";
    return;
  }

  submitting.value = true;

  try {
    const report = submitFieldReport({
      eventId: current.eventId,
      submittedByUserId: current.submittedByUserId,
      staffId: current.staffId,
      originDeviceId: current.originDeviceId,
      originNodeId: current.originNodeId,
      departmentId: current.departmentId,
      teamId: current.teamId,
      body: body.value,
    });

    void router.push({
      name: "staff.field-reports.show",
      params: { fieldReportId: report.id },
    });
  } catch (error) {
    errorMessage.value =
      error instanceof OfflineFieldReportError
        ? error.message
        : "Unable to submit Field Report.";
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <section class="fr-create" aria-labelledby="fr-create-heading">
    <header class="fr-create__header">
      <h1 id="fr-create-heading" class="fr-create__heading">
        Submit Field Report
      </h1>
      <RouterLink
        class="fr-create__cancel-link"
        :to="{ name: 'staff.field-reports.index' }"
      >
        Cancel
      </RouterLink>
    </header>
    <p class="fr-create__lede">
      Field Reports are finalized on submit. There are no drafts, and the
      original body cannot be edited later.
    </p>

    <p v-if="!session" class="fr-create__unavailable" role="status">
      Field session is unavailable. Sign in and select an event before
      submitting a Field Report.
    </p>

    <template v-else>
      <dl class="fr-create__context">
        <div>
          <dt>Event</dt>
          <dd>{{ session.eventLabel }}</dd>
        </div>
        <div v-if="session.departmentLabel">
          <dt>Department</dt>
          <dd>{{ session.departmentLabel }}</dd>
        </div>
        <div v-if="session.teamLabel">
          <dt>Team</dt>
          <dd>{{ session.teamLabel }}</dd>
        </div>
        <div>
          <dt>Submitted by</dt>
          <dd>Set by your signed-in session</dd>
        </div>
      </dl>

      <form class="fr-create__form" @submit.prevent="onSubmit">
        <label class="fr-create__label" for="fr-body">Report text</label>
        <textarea
          id="fr-body"
          v-model="body"
          class="fr-create__body"
          name="body"
          rows="6"
          required
          autocomplete="off"
          spellcheck="true"
        />

        <p
          v-if="errorMessage"
          class="fr-create__error"
          role="alert"
        >
          {{ errorMessage }}
        </p>

        <div class="fr-create__actions">
          <button
            type="submit"
            class="fr-create__submit"
            :disabled="!canSubmit"
          >
            Submit
          </button>
          <button
            type="button"
            class="fr-create__cancel"
            @click="onCancel"
          >
            Cancel
          </button>
        </div>
      </form>
    </template>
  </section>
</template>

<style scoped>
.fr-create {
  max-width: 40rem;
}

.fr-create__header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--m-space-3);
  margin-bottom: var(--m-space-2);
}

.fr-create__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.fr-create__cancel-link {
  flex-shrink: 0;
  font-weight: 600;
  color: var(--m-text-secondary);
  text-decoration: underline;
}

.fr-create__cancel-link:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.fr-create__lede,
.fr-create__unavailable {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.fr-create__context {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-6);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.fr-create__context div {
  display: grid;
  grid-template-columns: 8rem 1fr;
  gap: var(--m-space-2);
}

.fr-create__context dt {
  margin: 0;
  color: var(--m-text-secondary);
  font-weight: 600;
}

.fr-create__context dd {
  margin: 0;
}

.fr-create__label {
  display: block;
  margin-bottom: var(--m-space-2);
  font-weight: 600;
}

.fr-create__body {
  width: 100%;
  box-sizing: border-box;
  margin-bottom: var(--m-space-4);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  resize: vertical;
}

.fr-create__body:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.fr-create__error {
  margin: 0 0 var(--m-space-4);
  color: var(--m-status-danger);
}

.fr-create__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3);
}

.fr-create__submit,
.fr-create__cancel {
  min-width: 7rem;
  padding: var(--m-space-2) var(--m-space-4);
  border-radius: var(--m-radius-sm);
  border: 1px solid transparent;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.fr-create__submit {
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.fr-create__submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.fr-create__cancel {
  background: var(--m-action-secondary-bg);
  color: var(--m-action-secondary-text);
  border-color: var(--m-action-secondary-bg);
}

.fr-create__submit:focus-visible,
.fr-create__cancel:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
