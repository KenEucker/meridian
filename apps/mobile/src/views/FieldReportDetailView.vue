<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watchEffect } from "vue";
import { RouterLink, useRoute } from "vue-router";

import {
  authorFieldReportCatalog,
  fieldReportCatalogRevision,
  fieldReportPhotoRevision,
  bumpFieldReportPhotoRevision,
} from "@/field-reports/fieldReportRuntime";
import { resolveFieldSession } from "@/field-reports/fieldSession";
import { fieldReportSubmissionView } from "@/field-reports/offlineFieldReport";
import { listPendingFieldReportPhotoRecords } from "@/field-reports/pendingFieldReportPhotos";
import { syncFieldReportOutbox } from "@/field-reports/syncFieldReportOutbox";

// View submitted Field Report — UI contract 12.3 `staff.field-reports.show`
// (M9.4 / M9.7A / M9.8). Authors may view their own reports (FR-004). Original
// title and body are view-only; no Edit/Save/autosave (FR-007; IMS surface §11;
// UI contract 14.2). Incident attachment state is not shown to the submitter
// (technical spec 17.6). Local photo previews and pending upload state come
// from the durable encrypted photo queue until sync clears it.
const route = useRoute();
const session = computed(() => resolveFieldSession());

const fieldReportId = computed(() => String(route.params.fieldReportId ?? ""));

const report = computed(() => {
  void fieldReportCatalogRevision.value;

  const current = session.value;
  if (!current || !fieldReportId.value) {
    return undefined;
  }

  return authorFieldReportCatalog.getForAuthor(
    fieldReportId.value,
    current.submittedByUserId,
  );
});

const submission = computed(() =>
  report.value ? fieldReportSubmissionView(report.value) : null,
);

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
      const blob = new Blob([record.photo.bytes], {
        type: record.photo.mimeType,
      });
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
    if (result.photosFailed > 0 || result.textFailed > 0) {
      syncMessage.value =
        "Some uploads failed. Confirm the server is running, the local Field fixture is seeded, and the API token matches.";
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
      <p
        v-if="submission.displayNumberIsTemporary"
        class="fr-detail__temporary"
        role="status"
      >
        Temporary local number until sync assigns the FRA number.
      </p>

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
        <p
          v-if="pendingPhotoCount > 0 || failedPhotoCount > 0"
          class="fr-detail__actions"
        >
          <button
            type="button"
            class="fr-detail__retry"
            :disabled="syncing"
            @click="onRetrySync"
          >
            {{ syncing ? "Syncing…" : "Retry upload" }}
          </button>
        </p>
        <p v-if="syncMessage" class="fr-detail__sync-message" role="status">
          {{ syncMessage }}
        </p>
      </div>

      <h2 class="fr-detail__body-heading">Report text</h2>
      <pre class="fr-detail__body" tabindex="0">{{ report.body }}</pre>
      <p class="fr-detail__immutable">
        This original title and body are finalized and cannot be edited.
      </p>
    </template>
  </section>
</template>

<style scoped>
.fr-detail {
  max-width: 40rem;
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

.fr-detail__temporary,
.fr-detail__unavailable,
.fr-detail__immutable,
.fr-detail__photo-status,
.fr-detail__photo-error,
.fr-detail__sync-message {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
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
  grid-template-columns: 8rem 1fr;
  gap: var(--m-space-2);
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
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  cursor: pointer;
}

.fr-detail__retry:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
