<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";

import { resolveFieldSession } from "@/field-reports/fieldSession";
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
// section 14.1–14.3 (M9.4 / M9.7 / M9.7A). Submit/Cancel only; finalize on
// submit; no drafts or autosave. Required title + body; title and original body
// are immutable after submit. Photos: max 2, images only, no GIFs, processed to
// Alpha 1 limits before submit. Name Reference autocomplete is intentionally
// absent (M9.6A); titles are not parsed for Name References. Photos attach to
// the durable encrypted pending upload queue after submit (M9.8).
const router = useRouter();
const session = computed(() => resolveFieldSession());
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

function onCancel(): void {
  photoSelection.clear();
  bumpPhotoSelection();
  void router.push({ name: "staff.field-reports.index" });
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
    const report = submitFieldReport({
      eventId: current.eventId,
      submittedByUserId: current.submittedByUserId,
      staffId: current.staffId,
      originDeviceId: current.originDeviceId,
      originNodeId: current.originNodeId,
      departmentId: current.departmentId,
      teamId: current.teamId,
      title: title.value,
      body: body.value,
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
      original title and body cannot be edited later.
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
