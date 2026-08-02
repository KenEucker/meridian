<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRoute } from "vue-router";

import StaffPageShell from "@/components/StaffPageShell.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  getDocument,
  type DocumentType,
  type ProductDocument,
} from "@/documents/documentAuthoringModel";

/**
 * `staff.document-detail` — one policy or procedure, rendered (M18.7; POL-006,
 * POL-008 through POL-013; UI contract 12.3).
 *
 * The read is `GET /api/policy-documents/{id}` or its procedure counterpart, and
 * it is the authorization as well as the content: a document outside this
 * caller's visibility comes back as the node's 403 rather than as a row missing
 * from a list, so a link somebody was sent to a document they may not read says
 * so instead of rendering an empty page (POL-013). The library never offers such
 * a link; a URL typed, guessed, or forwarded is the case this handles.
 *
 * `renderedHtml` is `DocumentRenderer`'s output with fragment text resolved
 * inline, raw HTML stripped, and unsafe links refused (POL-022, POL-034,
 * POL-035). The browser renders nothing of its own, so what a reader sees here
 * is the published document rather than an approximation of it.
 *
 * Scope and version sit under the text rather than above it, which is where the
 * mobile Policies & Procedures area puts them (data/API 11.13): somebody opening
 * a policy came to read it, and the metadata is what they check afterwards.
 */
const route = useRoute();

const documentType = computed<DocumentType | null>(() =>
  route.params.documentType === "policy" || route.params.documentType === "procedure"
    ? route.params.documentType
    : null,
);
const documentId = computed(() => String(route.params.documentId ?? ""));

const document = ref<ProductDocument | null>(null);
const loadError = ref<string | null>(null);

/** Draft and archived documents are not policy in force, and say so. */
const stateNotice = computed(() => {
  const state = document.value?.state;

  if (state === undefined || state === "published") {
    return null;
  }

  return state === "draft"
    ? "This document is still a draft. It is not published, and it is shown to you because you maintain it."
    : "This document is archived. It is kept for history and is not current.";
});

const publishedLabel = computed(() => {
  const publishedAt = document.value?.publishedAt ?? null;

  if (publishedAt === null) {
    return "Not published";
  }

  const moment = new Date(publishedAt);

  return Number.isNaN(moment.getTime())
    ? publishedAt
    : moment.toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
});

async function loadDocument(): Promise<void> {
  const type = documentType.value;

  if (type === null || documentId.value === "") {
    document.value = null;
    loadError.value = "That is not a policy or procedure address.";

    return;
  }

  loadError.value = null;

  try {
    document.value = await getDocument(type, documentId.value);
  } catch (error) {
    document.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load this document. Check the connection to this node and try again.",
    );
  }
}

watch([documentType, documentId], () => {
  void loadDocument();
});

void loadDocument();
</script>

<template>
  <StaffPageShell
    heading-id="staff-document-heading"
    :title="document?.title ?? 'Document'"
    :eyebrow="
      document === null
        ? 'Your library'
        : document.documentType === 'policy'
          ? 'Policy'
          : 'Procedure'
    "
    :back-to="{ name: 'staff.documents.index' }"
    back-label="Back To Policies &amp; Procedures"
  >
    <!--
      The node's refusal, quoted. A document somebody may not read is a sentence
      rather than a blank page, and a page that could not be loaded is not a
      document with nothing in it (data/API 7.2).
    -->
    <p v-if="loadError" class="staff-document__error" role="alert">
      {{ loadError }}
      <button type="button" @click="loadDocument">Try again</button>
    </p>

    <p v-else-if="document === null" class="staff-document__notice" role="status">
      Loading this document.
    </p>

    <template v-else>
      <p v-if="stateNotice" class="staff-document__notice" role="status">
        {{ stateNotice }}
      </p>

      <article class="staff-document__body" v-html="document.renderedHtml" />

      <dl class="staff-document__facts">
        <div class="staff-document__fact">
          <dt>Scope</dt>
          <dd>{{ document.scopeLabel }}</dd>
        </div>
        <div class="staff-document__fact">
          <dt>Version</dt>
          <dd>{{ document.version }}</dd>
        </div>
        <div class="staff-document__fact">
          <dt>State</dt>
          <dd>{{ document.stateLabel }}</dd>
        </div>
        <div class="staff-document__fact">
          <dt>Published</dt>
          <dd>{{ publishedLabel }}</dd>
        </div>
        <div class="staff-document__fact">
          <dt>Visibility</dt>
          <dd>{{ document.visibilitySummary }}</dd>
        </div>
      </dl>
    </template>
  </StaffPageShell>
</template>

<style scoped>
.staff-document__notice,
.staff-document__error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-3);
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.staff-document__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.staff-document__error button {
  min-height: 2.25rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 900;
  cursor: pointer;
}

.staff-document__body {
  padding: var(--m-pad-block) var(--m-pad-inline);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.staff-document__body :deep(h1),
.staff-document__body :deep(h2),
.staff-document__body :deep(h3) {
  margin: var(--m-space-4) 0 var(--m-space-2);
  max-width: var(--m-measure);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
}

.staff-document__body :deep(h1:first-child),
.staff-document__body :deep(h2:first-child) {
  margin-top: 0;
}

.staff-document__body :deep(p),
.staff-document__body :deep(li) {
  max-width: var(--m-measure);
  color: var(--m-text-secondary);
}

.staff-document__body :deep(p) {
  margin: 0 0 var(--m-space-3);
}

.staff-document__body :deep(p:last-child) {
  margin-bottom: 0;
}

.staff-document__facts {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.staff-document__fact dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.staff-document__fact dd {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-primary);
  overflow-wrap: anywhere;
}

@media (min-width: 44rem) {
  .staff-document__facts {
    grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  }
}
</style>
