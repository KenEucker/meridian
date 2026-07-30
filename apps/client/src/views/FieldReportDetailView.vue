<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watchEffect } from "vue";
import { RouterLink, useRoute } from "vue-router";

import { appendFieldReport } from "@/field-reports/appendFieldReport";
import { AuthorFieldReportCatalogError } from "@/field-reports/authorFieldReportCatalog";
import {
  authorFieldReportCatalog,
  fieldReportCatalogRevision,
  fieldReportPhotoRevision,
  bumpFieldReportPhotoRevision,
} from "@/field-reports/fieldReportRuntime";
import { resolveFieldSession } from "@/field-reports/fieldSession";
import {
  fieldReportSubmissionView,
  OfflineFieldReportError,
} from "@/field-reports/offlineFieldReport";
import { listPendingFieldReportPhotoRecords } from "@/field-reports/pendingFieldReportPhotos";
import { syncFieldReportOutbox } from "@/field-reports/syncFieldReportOutbox";

// View submitted Field Report — UI contract 12.3 `staff.field-reports.show`
// (M9.4 / M9.7A / M9.8). Authors may view their own reports (FR-004). Original
// title and body are view-only; no Edit/Save/autosave (FR-007; IMS surface §11;
// UI contract 14.2). Authors may append corrections (FR-007-FR-009). Incident
// attachment state is not shown to the submitter (technical spec 17.6). Local
// photo previews and pending upload state come from the durable encrypted photo
// queue until sync clears it.
const route = useRoute();
const session = computed(() => resolveFieldSession());

const fieldReportId = computed(() => String(route.params.fieldReportId ?? ""));

const report = computed(() => {
  void fieldReportCatalogRevision.value;

  const current = session.value;
  if (!current || !fieldReportId.value) {
    return undefined;
  }

  // Staff id as well as user id, so a staff member can open the Field Report an
  // operator typed for them (FR-004).
  return authorFieldReportCatalog.getForAuthor(
    fieldReportId.value,
    current.submittedByUserId,
    current.staffId,
  );
});

const submission = computed(() =>
  report.value ? fieldReportSubmissionView(report.value) : null,
);

const appendBody = ref("");
const appendError = ref<string | null>(null);
const appending = ref(false);

/**
 * Only the original submitter may append (data/API 15.3).
 *
 * A dictated Field Report is readable by the staff member it is about, but the
 * submitter is the operator who took it down, so appending stays with them.
 * Without this the wider read scope would quietly widen the append rule too.
 */
const isSubmitter = computed(
  () =>
    report.value !== undefined &&
    session.value !== null &&
    report.value.submittedByUserId === session.value.submittedByUserId,
);

const canAppend = computed(
  () =>
    Boolean(session.value) &&
    Boolean(report.value) &&
    isSubmitter.value &&
    appendBody.value.trim().length > 0 &&
    !appending.value,
);

function onClearAppend(): void {
  appendBody.value = "";
  appendError.value = null;
}

function onAppend(): void {
  const current = session.value;
  const existing = report.value;
  if (!current || !existing) {
    return;
  }

  appendError.value = null;
  appending.value = true;

  try {
    appendFieldReport({
      fieldReportId: existing.id,
      authorUserId: current.submittedByUserId,
      body: appendBody.value,
    });
    appendBody.value = "";
  } catch (error) {
    if (
      error instanceof OfflineFieldReportError ||
      error instanceof AuthorFieldReportCatalogError
    ) {
      appendError.value = error.message;
    } else {
      appendError.value = "Unable to append to this Field Report.";
    }
  } finally {
    appending.value = false;
  }
}

interface LocalPhotoPreview {
  readonly id: string;
  readonly syncStatus: string;
  readonly previewUrl: string;
  readonly lastError: string | null;
}

const pendingPhotoCount = ref(0);
const failedPhotoCount = ref(0);
const localPhotoPreviews = ref<LocalPhotoPreview[]>([]);
const syncing = ref(false);
const syncMessage = ref<string | null>(null);
const hasSyncWork = computed(
  () =>
    Boolean(submission.value?.pendingSync) ||
    pendingPhotoCount.value > 0 ||
    failedPhotoCount.value > 0,
);
const syncTextStatus = computed(() =>
  submission.value?.pendingSync ? "Queued locally" : "Accepted by server",
);
const syncPhotoStatus = computed(() => {
  if (localPhotoPreviews.value.length === 0) {
    return "No local photos";
  }

  if (failedPhotoCount.value > 0) {
    return `${failedPhotoCount.value} failed, ${pendingPhotoCount.value} queued`;
  }

  if (pendingPhotoCount.value > 0) {
    return `${pendingPhotoCount.value} queued`;
  }

  return `${localPhotoPreviews.value.length} uploaded`;
});

function revokePreviews(previews: readonly LocalPhotoPreview[]): void {
  if (typeof URL?.revokeObjectURL !== "function") {
    return;
  }
  for (const preview of previews) {
    URL.revokeObjectURL(preview.previewUrl);
  }
}

watchEffect(() => {
  void fieldReportPhotoRevision.value;
  const id = fieldReportId.value;
  if (!id) {
    revokePreviews(localPhotoPreviews.value);
    localPhotoPreviews.value = [];
    pendingPhotoCount.value = 0;
    failedPhotoCount.value = 0;
    return;
  }

  void listPendingFieldReportPhotoRecords(id).then((records) => {
    revokePreviews(localPhotoPreviews.value);

    pendingPhotoCount.value = records.filter(
      (record) => record.syncStatus === "pending_upload",
    ).length;
    failedPhotoCount.value = records.filter(
      (record) => record.syncStatus === "failed",
    ).length;

    localPhotoPreviews.value = records.map((record) => {
      const photoBytes = record.photo.bytes;
      const blob = new Blob(
        [
          photoBytes.buffer.slice(
            photoBytes.byteOffset,
            photoBytes.byteOffset + photoBytes.byteLength,
          ) as ArrayBuffer,
        ],
        {
          type: record.photo.mimeType,
        },
      );
      return {
        id: record.photo.id,
        syncStatus: record.syncStatus,
        lastError: record.lastError,
        previewUrl: URL.createObjectURL(blob),
      };
    });
  });
});

onBeforeUnmount(() => {
  revokePreviews(localPhotoPreviews.value);
});

async function onRetrySync(): Promise<void> {
  syncing.value = true;
  syncMessage.value = null;
  try {
    const result = await syncFieldReportOutbox();
    bumpFieldReportPhotoRevision();
    if (result.blockedReason) {
      syncMessage.value = result.blockedReason;
    } else if (result.lastError) {
      syncMessage.value = result.lastError;
    } else if (result.textPending > 0) {
      /*
       * Work is still owed to the node and this pass did not clear it — most
       * often because a drain was already running when the button was pressed.
       * Saying "nothing pending" here would tell an author their report had
       * gone somewhere when the device is still the only copy of it.
       */
      syncMessage.value = "Still queued; this device will send it when the node is reachable.";
    } else if (result.photosUploaded === 0 && result.textAccepted === 0) {
      syncMessage.value = "Nothing pending to sync.";
    } else {
      syncMessage.value = "Sync completed.";
    }
  } catch {
    syncMessage.value = "Unable to sync Field Report uploads.";
  } finally {
    syncing.value = false;
  }
}
</script>

<template>
  <section class="fr-detail" aria-labelledby="fr-detail-heading">
    <p class="fr-detail__nav">
      <RouterLink :to="{ name: 'staff.field-reports.index' }"
        >Back to My Field Reports</RouterLink
      >
    </p>

    <template v-if="!session">
      <h1 id="fr-detail-heading" class="fr-detail__heading">Field Report</h1>
      <p class="fr-detail__unavailable" role="status">
        Field session is unavailable. Sign in to view your submitted Field
        Reports.
      </p>
    </template>

    <template v-else-if="!report || !submission">
      <h1 id="fr-detail-heading" class="fr-detail__heading">Field Report</h1>
      <p class="fr-detail__unavailable" role="status">
        This Field Report is not available. You can only view Field Reports you
        authored.
      </p>
    </template>

    <template v-else>
      <h1 id="fr-detail-heading" class="fr-detail__heading">
        {{ report.title }}
      </h1>
      <p class="fr-detail__number" aria-label="Field Report number">
        {{ submission.displayNumber }}
      </p>

      <h2 class="fr-detail__body-heading">Report text</h2>
      <pre class="fr-detail__body" tabindex="0">{{ report.body }}</pre>

      <div
        v-if="report.appends.length > 0"
        class="fr-detail__appends"
        aria-label="Field Report appends"
      >
        <h2 class="fr-detail__body-heading">Appended updates</h2>
        <ol class="fr-detail__append-list">
          <li
            v-for="append in report.appends"
            :key="append.id"
            class="fr-detail__append-item"
          >
            <p class="fr-detail__append-meta">
              {{ append.deviceSubmittedAt }}
            </p>
            <pre class="fr-detail__body" tabindex="0">{{ append.body }}</pre>
          </li>
        </ol>
      </div>

      <div
        v-if="localPhotoPreviews.length > 0"
        class="fr-detail__photos"
        aria-label="Local Field Report photos"
      >
        <h2 class="fr-detail__body-heading">Photos</h2>
        <ul class="fr-detail__photo-list">
          <li
            v-for="photo in localPhotoPreviews"
            :key="photo.id"
            class="fr-detail__photo-item"
          >
            <img
              class="fr-detail__photo-preview"
              :src="photo.previewUrl"
              alt="Field Report photo"
            />
            <p class="fr-detail__photo-status">
              {{
                photo.syncStatus === "uploaded"
                  ? "Uploaded"
                  : photo.syncStatus === "failed"
                    ? "Upload failed"
                    : "Pending upload"
              }}
            </p>
            <p v-if="photo.lastError" class="fr-detail__photo-error">
              {{ photo.lastError }}
            </p>
          </li>
        </ul>
      </div>

      <p class="fr-detail__immutable">
        This original title and body are finalized and cannot be edited. You can
        append an update below.
      </p>

      <p v-if="!isSubmitter" class="fr-detail__append-help" role="status">
        This Field Report was filled out for you by another staff member. Only
        the person who submitted it can append to it.
      </p>

      <form
        v-else
        class="fr-detail__append-form"
        aria-labelledby="fr-append-heading"
        @submit.prevent="onAppend"
      >
        <h2 id="fr-append-heading" class="fr-detail__body-heading">
          Append update
        </h2>
        <p class="fr-detail__append-help" id="fr-append-help">
          Appends add a new timestamped entry. They do not change the original
          title or report text.
        </p>
        <label class="fr-detail__append-label" for="fr-append-body"
          >Append text</label
        >
        <textarea
          id="fr-append-body"
          v-model="appendBody"
          class="fr-detail__append-input"
          rows="5"
          required
          aria-describedby="fr-append-help"
          :disabled="appending"
        />
        <p
          v-if="appendError"
          class="fr-detail__append-error"
          role="alert"
        >
          {{ appendError }}
        </p>
        <div class="fr-detail__append-actions">
          <button
            type="submit"
            class="fr-detail__append-submit"
            :disabled="!canAppend"
          >
            {{ appending ? "Appending…" : "Append" }}
          </button>
          <button
            type="button"
            class="fr-detail__append-clear"
            :disabled="appending || appendBody.length === 0"
            @click="onClearAppend"
          >
            Clear
          </button>
        </div>
      </form>

      <h2 class="fr-detail__debug-heading">Field Report details</h2>
      <dl class="fr-detail__meta">
        <div>
          <dt>Title</dt>
          <dd>{{ report.title }}</dd>
        </div>
        <div>
          <dt>Status</dt>
          <dd>Submitted</dd>
        </div>
        <div>
          <dt>Sync</dt>
          <dd>{{ submission.pendingSync ? "Pending sync" : "Synced" }}</dd>
        </div>
        <div v-if="localPhotoPreviews.length > 0">
          <dt>Photos</dt>
          <dd>
            <span v-if="pendingPhotoCount > 0">
              {{ pendingPhotoCount }} pending upload
            </span>
            <span v-if="failedPhotoCount > 0">
              <template v-if="pendingPhotoCount > 0">; </template>
              {{ failedPhotoCount }} failed upload
            </span>
            <span
              v-if="
                pendingPhotoCount === 0 &&
                failedPhotoCount === 0 &&
                localPhotoPreviews.length > 0
              "
            >
              {{ localPhotoPreviews.length }} uploaded
            </span>
          </dd>
        </div>
        <div>
          <dt>Submitted at</dt>
          <dd>{{ report.deviceSubmittedAt }}</dd>
        </div>
        <div>
          <dt>Submitted by</dt>
          <dd>You (signed-in author)</dd>
        </div>
        <div v-if="session.eventLabel">
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
      </dl>

      <div
        v-if="hasSyncWork || syncMessage"
        class="fr-detail__sync-panel"
        aria-label="Field Report sync status"
      >
        <p class="fr-detail__sync-row">
          <span>Report text</span>
          <strong>{{ syncTextStatus }}</strong>
        </p>
        <p
          v-if="submission.displayNumberIsTemporary"
          class="fr-detail__sync-row"
          role="status"
        >
          <span>FR number</span>
          <strong>Temporary until sync assigns the FRA number</strong>
        </p>
        <p
          v-if="localPhotoPreviews.length > 0"
          class="fr-detail__sync-row"
        >
          <span>Photos</span>
          <strong>{{ syncPhotoStatus }}</strong>
        </p>
        <button
          v-if="hasSyncWork"
          type="button"
          class="fr-detail__retry"
          :disabled="syncing"
          @click="onRetrySync"
        >
          {{ syncing ? "Syncing…" : "Retry sync" }}
        </button>
        <p v-if="syncMessage" class="fr-detail__sync-message" role="status">
          {{ syncMessage }}
        </p>
      </div>
    </template>
  </section>
</template>

<style scoped>
.fr-detail {
  width: var(--m-content-narrow);
}

.fr-detail__nav {
  margin: 0 0 var(--m-space-4);
}

.fr-detail__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.fr-detail__number {
  margin: 0 0 var(--m-space-2);
  color: var(--m-text-secondary);
  font-weight: 600;
}

.fr-detail__unavailable,
.fr-detail__immutable,
.fr-detail__photo-status,
.fr-detail__photo-error,
.fr-detail__sync-message,
.fr-detail__append-help,
.fr-detail__append-meta {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.fr-detail__append-error {
  margin: 0 0 var(--m-space-3);
  color: var(--m-text-danger, #b42318);
}

.fr-detail__photo-error {
  color: var(--m-text-danger, #b42318);
}

.fr-detail__meta {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-6);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.fr-detail__meta div {
  display: grid;
  gap: var(--m-space-1);
}

.fr-detail__meta dt {
  margin: 0;
  color: var(--m-text-secondary);
  font-weight: 600;
}

.fr-detail__meta dd {
  margin: 0;
}

.fr-detail__body-heading {
  margin: 0 0 var(--m-space-2);
  font-size: var(--m-text-lg);
  font-family: var(--m-font-heading);
}

.fr-detail__debug-heading {
  margin: var(--m-space-8) 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
}

.fr-detail__body {
  margin: 0 0 var(--m-space-3);
  padding: var(--m-space-3);
  white-space: pre-wrap;
  word-break: break-word;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  font: inherit;
}

.fr-detail__appends {
  margin: 0 0 var(--m-space-4);
}

.fr-detail__append-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: var(--m-space-4);
}

.fr-detail__append-item {
  margin: 0;
}

.fr-detail__append-meta {
  margin: 0 0 var(--m-space-2);
  font-size: var(--m-text-sm);
}

.fr-detail__append-form {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-6);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.fr-detail__append-label {
  font-weight: 600;
}

.fr-detail__append-input {
  width: 100%;
  min-height: 7rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  resize: vertical;
}

.fr-detail__append-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.fr-detail__append-submit,
.fr-detail__append-clear,
.fr-detail__retry {
  min-height: 2.75rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  cursor: pointer;
}

.fr-detail__append-submit {
  background: var(--m-surface-base);
  font-weight: 600;
}

.fr-detail__append-submit:disabled,
.fr-detail__append-clear:disabled,
.fr-detail__retry:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.fr-detail__sync-panel {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-6);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.fr-detail__sync-row {
  display: flex;
  justify-content: space-between;
  gap: var(--m-space-3);
  margin: 0;
  color: var(--m-text-secondary);
}

.fr-detail__sync-row strong {
  color: var(--m-text-primary);
  text-align: right;
}

.fr-detail__photos {
  margin: 0 0 var(--m-space-6);
}

.fr-detail__photo-list {
  display: grid;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-3);
  padding: 0;
  list-style: none;
}

.fr-detail__photo-preview {
  display: block;
  width: 100%;
  max-height: 16rem;
  object-fit: contain;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.fr-detail__retry {
  width: 100%;
}

@media (min-width: 44rem) {
  .fr-detail__meta div {
    grid-template-columns: 8rem minmax(0, 1fr);
    gap: var(--m-space-2);
  }

  .fr-detail__retry {
    width: auto;
  }
}
</style>
