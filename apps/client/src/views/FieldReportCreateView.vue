<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";

import {
  findDictationStaff,
  loadDictationStaffDirectory,
  searchDictationStaff,
  type DictationStaffOption,
} from "@/field-reports/dictationStaffDirectory";
import { selectedSessionDepartmentRouteParams } from "@/session/sessionAccess";
import { resolveFieldSession } from "@/field-reports/fieldSession";
import { incidentAccess } from "@/ims/incidentReadModel";
import {
  FIELD_REPORT_TITLE_MAX_LENGTH,
  OfflineFieldReportError,
} from "@/field-reports/offlineFieldReport";
import {
  FieldReportPhotoLimitError,
  FieldReportPhotoSelection,
} from "@/field-reports/fieldReportPhotoSelection";
import {
  FieldReportPhotoProcessingError,
  processFieldReportPhotoFile,
} from "@/field-reports/fieldReportPhotoProcessor";
import { FIELD_REPORT_PHOTO_MAX_COUNT } from "@/field-reports/fieldReportPhotoLimits";
import { bumpFieldReportPhotoRevision } from "@/field-reports/fieldReportRuntime";
import { attachPendingFieldReportPhotos } from "@/field-reports/pendingFieldReportPhotos";
import { submitFieldReport } from "@/field-reports/submitFieldReport";
import { syncFieldReportOutbox } from "@/field-reports/syncFieldReportOutbox";

// Submit Field Report — UI contract 12.3 `staff.field-reports.create` and
// section 14.1-14.3 (M9.4 / M9.7 / M9.7A). Submit/Cancel only; finalize on
// submit; no drafts or autosave. Required title + body; title and original body
// are immutable after submit. Photos: max 2, images only, no GIFs, processed to
// Alpha 1 limits before submit. Name Reference autocomplete is intentionally
// absent (M9.6A); titles are not parsed for Name References. Photos attach to
// the durable encrypted pending upload queue after submit (M9.8).
//
// M-aside: the same form also takes dictated reports. An IC operator or lead
// opens it from Incidents to write down a report someone gave them verbally,
// names that staff member, and submits. The report is the named staff member's
// — it lands in their My Field Reports — while the operator stays recorded as
// the submitter and is named in a line prepended to the immutable body.
//
// Dictation is gated on the same permission as creating an incident, because
// recording an account on someone else's behalf is Incident Command work, not
// something any staff member may do to another staff member's record.
const router = useRouter();
const route = useRoute();
const session = computed(() => resolveFieldSession());

/** True on the `/ims/field-reports/create` route, false on the staff route. */
const dictationRoute = computed(() => route.name === "ims.field-reports.create");
const canDictate = computed(() => incidentAccess.value.canCreate);
const dictationMode = computed(() => dictationRoute.value && canDictate.value);

const staffQuery = ref("");
const selectedStaffId = ref<string | null>(null);

/**
 * The staff an operator may name, read from the node (M18.24A; FR-017).
 *
 * Loaded only in dictation mode. A staff member filing their own report names
 * nobody, so asking the node for a roster to satisfy a picker they will never
 * open would be a read taken for nothing — and one the node refuses for
 * everybody without taking authority, which is most of the people who file
 * reports.
 */
const staffDirectory = ref<readonly DictationStaffOption[]>([]);
const staffDirectoryError = ref<string | null>(null);
const staffMatches = computed(() =>
  searchDictationStaff(staffQuery.value, staffDirectory.value),
);

async function loadStaffDirectory(): Promise<void> {
  const params = selectedSessionDepartmentRouteParams.value;

  if (!dictationMode.value || params === null) {
    staffDirectory.value = [];

    return;
  }

  staffDirectoryError.value = null;

  try {
    staffDirectory.value = await loadDictationStaffDirectory(params.eventId);
  } catch (error) {
    staffDirectory.value = [];
    staffDirectoryError.value = meridianErrorMessage(
      error,
      "Unable to read the staff you may take a report for. Check the connection to this node and try again.",
    );
  }
}

watch(dictationMode, () => void loadStaffDirectory(), { immediate: true });

/**
 * Who the report is about. Defaults to the signed-in user, so an operator who
 * opens the form and types nothing into the picker files their own report.
 */
const reportingStaff = computed<DictationStaffOption | null>(() => {
  const current = session.value;
  if (!current) {
    return null;
  }

  return findDictationStaff(
    selectedStaffId.value ?? current.staffId,
    staffDirectory.value,
  );
});

const ownStaff = computed<DictationStaffOption | null>(() =>
  session.value
    ? findDictationStaff(session.value.staffId, staffDirectory.value)
    : null,
);

/** True only when the operator picked somebody other than themselves. */
const onBehalfOfSomeoneElse = computed(
  () =>
    dictationMode.value &&
    session.value !== null &&
    reportingStaff.value !== null &&
    reportingStaff.value.staffId !== session.value.staffId,
);

const attributionPreview = computed(() =>
  onBehalfOfSomeoneElse.value && ownStaff.value && reportingStaff.value
    ? `Field Report filled out by ${ownStaff.value.displayName} on behalf of ${reportingStaff.value.displayName}`
    : null,
);

const title = ref("");
const body = ref("");
const errorMessage = ref<string | null>(null);
const submitting = ref(false);
const processingPhotos = ref(false);
const photoSelection = new FieldReportPhotoSelection();
const photoSelectionRevision = ref(0);
const photoInput = ref<HTMLInputElement | null>(null);

const selectedPhotos = computed(() => {
  photoSelectionRevision.value;
  return photoSelection.list();
});
const remainingPhotoSlots = computed(() => {
  photoSelectionRevision.value;
  return photoSelection.remainingSlots();
});

function bumpPhotoSelection(): void {
  photoSelectionRevision.value += 1;
}

const canSubmit = computed(
  () =>
    Boolean(session.value) &&
    title.value.trim().length > 0 &&
    title.value.trim().length <= FIELD_REPORT_TITLE_MAX_LENGTH &&
    body.value.trim().length > 0 &&
    !submitting.value &&
    !processingPhotos.value,
);

onBeforeUnmount(() => {
  photoSelection.clear();
});

/** Where Cancel and the header link go back to, per entry point. */
const returnRoute = computed(() =>
  dictationRoute.value
    ? { name: "ims.field-reports.index" }
    : { name: "staff.field-reports.index" },
);

function selectStaff(option: DictationStaffOption): void {
  selectedStaffId.value = option.staffId;
  staffQuery.value = "";
}

function clearStaffSelection(): void {
  selectedStaffId.value = null;
  staffQuery.value = "";
}

function onCancel(): void {
  photoSelection.clear();
  bumpPhotoSelection();
  void router.push(returnRoute.value);
}

function openPhotoPicker(): void {
  photoInput.value?.click();
}

function removePhoto(id: string): void {
  photoSelection.remove(id);
  bumpPhotoSelection();
}

async function onPhotosSelected(event: Event): Promise<void> {
  errorMessage.value = null;
  const input = event.target as HTMLInputElement;
  const files = Array.from(input.files ?? []);
  input.value = "";

  if (files.length === 0) {
    return;
  }

  processingPhotos.value = true;

  try {
    for (const file of files) {
      if (photoSelection.remainingSlots() <= 0) {
        throw new FieldReportPhotoLimitError(
          `Field Reports allow at most ${FIELD_REPORT_PHOTO_MAX_COUNT} photos total.`,
        );
      }

      const processed = await processFieldReportPhotoFile(file);
      const previewUrl =
        typeof URL?.createObjectURL === "function"
          ? URL.createObjectURL(
              new Blob([processed.bytes.buffer.slice(
                processed.bytes.byteOffset,
                processed.bytes.byteOffset + processed.bytes.byteLength,
              ) as ArrayBuffer], { type: processed.mimeType }),
            )
          : "";
      photoSelection.add(processed, previewUrl);
      bumpPhotoSelection();
    }
  } catch (error) {
    if (
      error instanceof FieldReportPhotoProcessingError ||
      error instanceof FieldReportPhotoLimitError
    ) {
      errorMessage.value = error.message;
    } else {
      errorMessage.value = "Unable to process the selected photo.";
    }
  } finally {
    processingPhotos.value = false;
  }
}

async function onSubmit(): Promise<void> {
  errorMessage.value = null;

  const current = session.value;
  if (!current) {
    errorMessage.value =
      "Field session is unavailable. Sign in and select an event before submitting.";
    return;
  }

  if (title.value.trim().length === 0) {
    errorMessage.value = "Field Report title is required.";
    return;
  }

  if (body.value.trim().length === 0) {
    errorMessage.value = "Field Report body text is required.";
    return;
  }

  submitting.value = true;

  try {
    const photos = photoSelection.snapshotForSubmit();
    const subject = reportingStaff.value;
    const operator = ownStaff.value;

    if (onBehalfOfSomeoneElse.value && (!subject || !operator)) {
      errorMessage.value =
        "Select the staff member this Field Report is for before submitting.";
      submitting.value = false;
      return;
    }

    const report = submitFieldReport({
      eventId: current.eventId,
      // The operator stays the submitter; only the staff the report is about
      // changes. Both facts are recorded rather than one standing in for the
      // other (data/API 10.15).
      submittedByUserId: current.submittedByUserId,
      staffId:
        onBehalfOfSomeoneElse.value && subject ? subject.staffId : current.staffId,
      originDeviceId: current.originDeviceId,
      originNodeId: current.originNodeId,
      departmentId: current.departmentId,
      teamId: current.teamId,
      title: title.value,
      body: body.value,
      dictation:
        onBehalfOfSomeoneElse.value && subject && operator
          ? {
              recordedByStaffId: current.staffId,
              recordedByDisplayName: operator.displayName,
              reportedByDisplayName: subject.displayName,
            }
          : null,
    });

    // Text finalize and navigate immediately; photo persistence/upload is a
    // separate offline write (technical spec 18.2) and must not block submit.
    void attachPendingFieldReportPhotos(report.id, photos)
      .then(() => {
        bumpFieldReportPhotoRevision();
        return syncFieldReportOutbox();
      })
      .catch(() => {
        bumpFieldReportPhotoRevision();
      });
    photoSelection.clear();
    bumpPhotoSelection();

    await router.push({
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
        {{ dictationMode ? "Take Field Report" : "Submit Field Report" }}
      </h1>
      <RouterLink class="fr-create__cancel-link" :to="returnRoute">
        Cancel
      </RouterLink>
    </header>
    <p class="fr-create__lede">
      {{
        dictationMode
          ? "Write down a Field Report a staff member gave you. It is finalized on submit and files to their My Field Reports."
          : "Field Reports are finalized on submit. There are no drafts, and the original title and body cannot be edited later."
      }}
    </p>

    <p
      v-if="dictationRoute && !canDictate"
      class="fr-create__unavailable"
      role="status"
    >
      Taking a Field Report on behalf of another staff member requires Incident
      Command permission to create incidents.
    </p>

    <p v-else-if="!session" class="fr-create__unavailable" role="status">
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

      <section
        v-if="dictationMode"
        class="fr-create__on-behalf"
        aria-labelledby="fr-on-behalf-heading"
      >
        <h2 id="fr-on-behalf-heading" class="fr-create__label">
          Field Report for
        </h2>
        <p id="fr-on-behalf-help" class="fr-create__on-behalf-help">
          Defaults to you. Search for another staff member to write down the
          report they gave you; it files to their My Field Reports.
        </p>

        <!--
          A directory that could not be read is stated. An empty picker with no
          explanation reads as a department with nobody in it, and an operator
          would go looking for the person rather than for the network.
        -->
        <p
          v-if="staffDirectoryError"
          class="fr-create__on-behalf-error"
          role="alert"
        >
          {{ staffDirectoryError }}
          <button type="button" @click="loadStaffDirectory()">Try again</button>
        </p>

        <p class="fr-create__on-behalf-selected">
          <strong>{{ reportingStaff?.displayName ?? "You" }}</strong>
          <template v-if="reportingStaff?.detail">
            <span> / {{ reportingStaff.detail }}</span>
          </template>
          <button
            v-if="onBehalfOfSomeoneElse"
            type="button"
            class="fr-create__on-behalf-clear"
            @click="clearStaffSelection"
          >
            Use my own name
          </button>
        </p>

        <label class="fr-create__label" for="fr-on-behalf-search">
          Search staff
        </label>
        <input
          id="fr-on-behalf-search"
          v-model="staffQuery"
          class="fr-create__title"
          type="search"
          autocomplete="off"
          aria-describedby="fr-on-behalf-help"
        />

        <ul
          v-if="staffQuery.trim().length > 0"
          class="fr-create__on-behalf-results"
          aria-label="Staff matches"
        >
          <li v-if="staffMatches.length === 0" role="status">
            No staff match that search.
          </li>
          <li v-for="option in staffMatches" :key="option.staffId">
            <button type="button" @click="selectStaff(option)">
              <span class="fr-create__on-behalf-name">
                {{ option.displayName }}
              </span>
              <span class="fr-create__on-behalf-detail">{{ option.detail }}</span>
            </button>
          </li>
        </ul>

        <p
          v-if="attributionPreview"
          class="fr-create__on-behalf-preview"
          role="status"
        >
          This line is added to the top of the report: “{{ attributionPreview }}”
        </p>
      </section>

      <form class="fr-create__form" @submit.prevent="onSubmit">
        <label class="fr-create__label" for="fr-title">Title</label>
        <input
          id="fr-title"
          v-model="title"
          class="fr-create__title"
          name="title"
          type="text"
          required
          maxlength="200"
          autocomplete="off"
          spellcheck="true"
        />

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

        <div class="fr-create__photos">
          <div class="fr-create__photos-heading">
            <label class="fr-create__label" for="fr-photos">
              Photos (optional)
            </label>
            <p id="fr-photos-help" class="fr-create__photos-help">
              Up to {{ FIELD_REPORT_PHOTO_MAX_COUNT }} images. GIFs are not
              allowed. Photos are resized and compressed before submit;
              {{ remainingPhotoSlots }} slot{{ remainingPhotoSlots === 1 ? "" : "s" }}
              remaining.
            </p>
          </div>

          <input
            id="fr-photos"
            ref="photoInput"
            class="fr-create__photo-input"
            type="file"
            accept="image/*"
            capture="environment"
            multiple
            :disabled="remainingPhotoSlots === 0 || processingPhotos"
            aria-describedby="fr-photos-help"
            @change="onPhotosSelected"
          />

          <button
            type="button"
            class="fr-create__add-photo"
            :disabled="remainingPhotoSlots === 0 || processingPhotos"
            @click="openPhotoPicker"
          >
            {{ processingPhotos ? "Processing…" : "Add photo" }}
          </button>

          <ul
            v-if="selectedPhotos.length > 0"
            class="fr-create__photo-list"
            aria-label="Selected photos"
          >
            <li
              v-for="photo in selectedPhotos"
              :key="photo.id"
              class="fr-create__photo-item"
            >
              <img
                v-if="photo.previewUrl"
                class="fr-create__photo-preview"
                :src="photo.previewUrl"
                :alt="`Selected photo ${photo.width} by ${photo.height}`"
              />
              <div class="fr-create__photo-meta">
                <span>{{ photo.width }}×{{ photo.height }}</span>
                <span>{{ Math.ceil(photo.byteSize / 1024) }} KB</span>
              </div>
              <button
                type="button"
                class="fr-create__photo-remove"
                @click="removePhoto(photo.id)"
              >
                Remove
              </button>
            </li>
          </ul>
        </div>

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
  width: var(--m-content-narrow);
}

.fr-create__header {
  display: grid;
  gap: var(--m-space-3);
  margin-bottom: var(--m-space-2);
}

.fr-create__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.fr-create__cancel-link {
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
  gap: var(--m-space-1);
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
  font-size: inherit;
}

.fr-create__on-behalf {
  margin: 0 0 var(--m-space-6);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.fr-create__on-behalf-help,
.fr-create__on-behalf-preview {
  margin: 0 0 var(--m-space-3);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.fr-create__on-behalf-selected {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-3);
}

.fr-create__on-behalf-error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-3);
  color: var(--m-status-danger, #cc792f);
  font-size: var(--m-text-sm);
}

.fr-create__on-behalf-selected span {
  color: var(--m-text-muted);
}

.fr-create__on-behalf-clear {
  min-height: 2.25rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.fr-create__on-behalf-results {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-3);
  padding: 0;
  list-style: none;
}

.fr-create__on-behalf-results button {
  display: grid;
  gap: 0.15rem;
  width: 100%;
  min-height: 2.75rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.fr-create__on-behalf-name {
  font-weight: 700;
}

.fr-create__on-behalf-detail {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.fr-create__on-behalf-clear:focus-visible,
.fr-create__on-behalf-results button:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.fr-create__title,
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
}

.fr-create__body {
  resize: vertical;
}

.fr-create__title:focus-visible,
.fr-create__body:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.fr-create__photos {
  margin-bottom: var(--m-space-4);
}

.fr-create__photos-help {
  margin: 0 0 var(--m-space-3);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.fr-create__photo-input {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.fr-create__add-photo,
.fr-create__photo-remove {
  min-height: 2.75rem;
  min-width: 7rem;
  width: 100%;
  padding: var(--m-space-2) var(--m-space-4);
  border-radius: var(--m-radius-sm);
  border: 1px solid var(--m-border-default);
  background: var(--m-action-secondary-bg);
  color: var(--m-action-secondary-text);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.fr-create__photo-remove {
  border-color: var(--m-action-destructive-bg);
  background: var(--m-action-destructive-bg);
  color: var(--m-action-destructive-text);
}

.fr-create__add-photo:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.fr-create__add-photo:focus-visible,
.fr-create__photo-remove:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.fr-create__photo-list {
  display: grid;
  gap: var(--m-space-3);
  margin: var(--m-space-3) 0 0;
  padding: 0;
  list-style: none;
}

.fr-create__photo-item {
  display: grid;
  grid-template-columns: 4.5rem minmax(0, 1fr);
  gap: var(--m-space-3);
  align-items: center;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.fr-create__photo-preview {
  width: 4.5rem;
  height: 4.5rem;
  object-fit: cover;
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.fr-create__photo-meta {
  display: grid;
  gap: var(--m-space-1);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.fr-create__photo-remove {
  grid-column: 1 / -1;
}

.fr-create__error {
  margin: 0 0 var(--m-space-4);
  color: var(--m-status-danger);
}

.fr-create__actions {
  display: grid;
  gap: var(--m-space-3);
}

.fr-create__submit,
.fr-create__cancel {
  min-height: 2.75rem;
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

@media (min-width: 44rem) {
  .fr-create__header {
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: baseline;
  }

  .fr-create__context div {
    grid-template-columns: 8rem minmax(0, 1fr);
    gap: var(--m-space-2);
  }

  .fr-create__photo-item {
    grid-template-columns: 5rem minmax(0, 1fr) auto;
  }

  .fr-create__photo-preview {
    width: 5rem;
    height: 5rem;
  }

  .fr-create__photo-remove {
    grid-column: auto;
    width: auto;
  }

  .fr-create__add-photo {
    width: auto;
  }

  .fr-create__actions {
    grid-template-columns: repeat(2, auto);
    justify-content: start;
  }
}
</style>
