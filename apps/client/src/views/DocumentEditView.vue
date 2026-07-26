<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import {
  archiveDocument,
  canMaintainDocuments,
  documentExportMarkdown,
  documentVersion,
  getDocument,
  getFragment,
  maintainableScopes,
  publishDocument,
  referencingDocuments,
  renderDocumentMarkdown,
  resolveDocumentAuthoringSession,
  saveDocumentDraft,
  scopeLabel,
  visibilitySummary,
  type DocumentArtifactKind,
  type DocumentDraft,
  type DocumentScopeType,
  type ProductDocument,
} from "@/documents/documentAuthoringModel";
import {
  EVENT_INFO_SECTIONS,
  eventInfoSectionLabel,
  isEventInfoSection,
} from "@/documents/eventInfoSections";

const route = useRoute();
const router = useRouter();
const surface = computed(() =>
  String(route.name ?? "").startsWith("organizer.")
    ? ("organizer" as const)
    : ("department" as const),
);
const session = computed(() =>
  resolveDocumentAuthoringSession(
    surface.value,
    typeof route.params.departmentId === "string"
      ? route.params.departmentId
      : null,
  ),
);
const artifactKind = computed<DocumentArtifactKind>(() => {
  const value = route.params.artifactKind;
  return value === "procedure" || value === "fragment" ? value : "policy";
});
const artifactId = computed(() =>
  typeof route.params.artifactId === "string" ? route.params.artifactId : null,
);
const scopes = computed(() => maintainableScopes(session.value));
const existingDocument = computed(() =>
  artifactId.value === null || artifactKind.value === "fragment"
    ? null
    : getDocument(session.value, artifactId.value),
);
const existingFragment = computed(() =>
  artifactId.value === null || artifactKind.value !== "fragment"
    ? null
    : getFragment(session.value, artifactId.value),
);
const canMaintain = computed(() => canMaintainDocuments(session.value));
const form = reactive<DocumentDraft>({
  kind: "policy",
  scopeType: "organization",
  scopeId: "",
  title: "",
  slug: "",
  eventInfoSection: null,
  markdownSource: "",
});
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const savedDocument = ref<ProductDocument | null>(null);

const isCreate = computed(() => artifactId.value === null);
const indexRouteName = computed(() =>
  surface.value === "organizer"
    ? "organizer.documents.index"
    : "events.departments.documents.index",
);
const editRouteName = computed(() =>
  surface.value === "organizer"
    ? "organizer.documents.edit"
    : "events.departments.documents.edit",
);
const baseParams = computed(() =>
  surface.value === "organizer"
    ? {}
    : {
        eventId: session.value.eventId,
        departmentId: session.value.department.departmentId,
      },
);
const backRoute = computed(() => ({
  name: indexRouteName.value,
  params: baseParams.value,
}));
const pageTitle = computed(() => {
  if (artifactKind.value === "fragment") {
    return isCreate.value ? "New Fragment" : "Edit Fragment";
  }

  const label = artifactKind.value === "policy" ? "Policy" : "Procedure";
  return isCreate.value ? `New ${label}` : `Edit ${label}`;
});
const previewHtml = computed(() => renderDocumentMarkdown(form.markdownSource));
const exportHref = computed(() =>
  savedDocument.value === null
    ? "#"
    : `data:text/markdown;charset=utf-8,${encodeURIComponent(
        documentExportMarkdown(savedDocument.value),
      )}`,
);
const exportFilename = computed(() =>
  savedDocument.value === null
    ? "document.md"
    : `${savedDocument.value.kind}-${savedDocument.value.slug}-${documentVersion(
        savedDocument.value,
      ).replace(".", "-")}.md`,
);
const fragmentImpact = computed(() =>
  existingFragment.value === null ? [] : referencingDocuments(existingFragment.value),
);

watch(
  () => [artifactKind.value, artifactId.value, scopes.value] as const,
  () => {
    const defaultScope = scopes.value[0] ?? null;
    form.kind = artifactKind.value;

    if (existingDocument.value !== null) {
      form.scopeType = existingDocument.value.scopeType;
      form.scopeId = existingDocument.value.scopeId;
      form.title = existingDocument.value.title;
      form.slug = existingDocument.value.slug;
      form.eventInfoSection = existingDocument.value.eventInfoSection;
      form.markdownSource = existingDocument.value.markdownSource;
      savedDocument.value = existingDocument.value;
      return;
    }

    if (existingFragment.value !== null) {
      form.scopeType = existingFragment.value.scopeType;
      form.scopeId = existingFragment.value.scopeId;
      form.title = existingFragment.value.name;
      form.slug = existingFragment.value.slug;
      form.eventInfoSection = null;
      form.markdownSource = existingFragment.value.markdownSource;
      savedDocument.value = null;
      return;
    }

    form.scopeType = defaultScope?.type ?? "organization";
    form.scopeId = defaultScope?.id ?? "";
    form.title = "";
    form.slug = "";
    form.eventInfoSection = null;
    form.markdownSource = "";
    savedDocument.value = null;
  },
  { immediate: true },
);

function onScopeChange(event: Event): void {
  const [scopeType, scopeId] = (event.target as HTMLSelectElement).value.split(":");
  form.scopeType = scopeType as DocumentScopeType;
  form.scopeId = scopeId ?? "";
}

function onEventInfoSectionChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;
  form.eventInfoSection = isEventInfoSection(value) ? value : null;
}

function save(): void {
  error.value = null;
  notice.value = null;

  try {
    const saved = saveDocumentDraft(session.value, artifactId.value, form);
    if ("kind" in saved) {
      savedDocument.value = saved;
      notice.value = `${saved.title} saved.`;
      if (isCreate.value) {
        void router.replace({
          name: editRouteName.value,
          params: {
            ...baseParams.value,
            artifactKind: saved.kind,
            artifactId: saved.id,
          },
        });
      }
      return;
    }

    notice.value = `${saved.name} saved.`;
    if (isCreate.value) {
      void router.replace({
        name: editRouteName.value,
        params: {
          ...baseParams.value,
          artifactKind: "fragment",
          artifactId: saved.id,
        },
      });
    }
  } catch (caught) {
    error.value =
      caught instanceof Error ? caught.message : "Unable to save document.";
  }
}

function transition(state: "published" | "archived"): void {
  if (savedDocument.value === null) {
    error.value = "Save the document before changing its state.";
    return;
  }

  try {
    savedDocument.value =
      state === "published"
        ? publishDocument(session.value, savedDocument.value.id)
        : archiveDocument(session.value, savedDocument.value.id);
    notice.value = `${savedDocument.value.title} is now ${state}.`;
  } catch (caught) {
    error.value =
      caught instanceof Error ? caught.message : "Unable to update document.";
  }
}
</script>

<template>
  <WorkflowPageShell
    heading-id="document-edit-heading"
    :title="pageTitle"
    :eyebrow="
      surface === 'organizer'
        ? 'Organizer administration'
        : session.department.departmentLabel
    "
    :lede="session.organizationLabel"
  >
    <template #nav>
      <RouterLink :to="backRoute">Back To Documents</RouterLink>
    </template>

    <template #actions>
      <button
        v-if="artifactKind !== 'fragment' && savedDocument?.state !== 'published'"
        type="button"
        class="document-edit__button"
        @click="transition('published')"
      >
        Publish
      </button>
      <button
        v-if="artifactKind !== 'fragment' && savedDocument?.state !== 'archived'"
        type="button"
        class="document-edit__button"
        @click="transition('archived')"
      >
        Archive
      </button>
      <a
        v-if="savedDocument"
        class="document-edit__button"
        :href="exportHref"
        :download="exportFilename"
      >
        Export Markdown
      </a>
      <button
        v-if="savedDocument"
        type="button"
        class="document-edit__button"
        @click="notice = 'PDF export will use the server-rendered document export endpoint.'"
      >
        Export PDF
      </button>
      <button
        v-if="savedDocument"
        type="button"
        class="document-edit__button"
        @click="notice = 'External sharing is limited to permitted exported copies for Alpha 1.'"
      >
        Share
      </button>
    </template>

    <p v-if="!canMaintain" class="document-edit__restricted" role="status">
      Document authoring requires organizer, department lead, or team lead
      authority for this scope.
    </p>

    <template v-else>
      <p v-if="error" class="document-edit__error" role="alert">{{ error }}</p>
      <p v-if="notice" class="document-edit__notice" role="status">
        {{ notice }}
      </p>

      <div class="document-edit__layout">
        <form class="document-edit__form" @submit.prevent="save">
          <label>
            Scope
            <select
              :value="`${form.scopeType}:${form.scopeId}`"
              @change="onScopeChange"
            >
              <option
                v-for="scope in scopes"
                :key="`${scope.type}:${scope.id}`"
                :value="`${scope.type}:${scope.id}`"
              >
                {{ scope.label }}
              </option>
            </select>
          </label>

          <label>
            {{ artifactKind === "fragment" ? "Name" : "Title" }}
            <input v-model="form.title" required />
          </label>

          <label>
            Slug
            <input v-model="form.slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" />
          </label>

          <label v-if="artifactKind !== 'fragment'">
            Event Info section
            <select
              :value="form.eventInfoSection ?? ''"
              @change="onEventInfoSectionChange"
            >
              <option value="">Not shown on Event Info</option>
              <option
                v-for="section in EVENT_INFO_SECTIONS"
                :key="section"
                :value="section"
              >
                {{ eventInfoSectionLabel(section) }}
              </option>
            </select>
          </label>
          <p
            v-if="artifactKind !== 'fragment'"
            class="document-edit__hint"
          >
            Assigned documents appear on Event Info once published, to the staff
            who can already see them.
          </p>

          <label>
            Markdown
            <textarea v-model="form.markdownSource" required rows="16" />
          </label>

          <button type="submit" class="document-edit__save">Save</button>
        </form>

        <aside class="document-edit__review" aria-label="Preview and review">
          <section>
            <h2>Preview</h2>
            <div class="document-edit__preview" v-html="previewHtml" />
          </section>

          <section v-if="savedDocument">
            <h2>Visibility</h2>
            <dl>
              <div>
                <dt>Type</dt>
                <dd>
                  {{ savedDocument.kind === "policy" ? "Policy" : "Procedure" }}
                </dd>
              </div>
              <div>
                <dt>State</dt>
                <dd>{{ savedDocument.state }}</dd>
              </div>
              <div>
                <dt>Version</dt>
                <dd>{{ documentVersion(savedDocument) }}</dd>
              </div>
              <div>
                <dt>Scope</dt>
                <dd>{{ scopeLabel(savedDocument.scopeType, savedDocument.scopeId) }}</dd>
              </div>
              <div>
                <dt>Event Info</dt>
                <dd>
                  {{
                    savedDocument.eventInfoSection
                      ? eventInfoSectionLabel(savedDocument.eventInfoSection)
                      : "Not shown on Event Info"
                  }}
                </dd>
              </div>
            </dl>
            <p>{{ visibilitySummary(savedDocument) }}</p>
          </section>

          <section v-if="existingFragment">
            <h2>Published Reference Impact</h2>
            <p>
              {{ fragmentImpact.filter((document) => document.state === "published").length }}
              published document version bumps if Markdown changes.
            </p>
            <ul>
              <li v-for="document in fragmentImpact" :key="document.id">
                {{ document.title }} / {{ document.state }} /
                {{ documentVersion(document) }}
              </li>
            </ul>
          </section>
        </aside>
      </div>
    </template>
  </WorkflowPageShell>
</template>

<style scoped>
.document-edit__layout {
  display: grid;
  gap: var(--m-space-4);
}

.document-edit__form,
.document-edit__review {
  display: grid;
  gap: var(--m-space-3);
  min-width: 0;
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.document-edit__form label {
  display: grid;
  gap: var(--m-space-1);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 900;
}

.document-edit__form input,
.document-edit__form select,
.document-edit__form textarea {
  width: 100%;
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
}

.document-edit__form textarea {
  resize: vertical;
  min-height: 18rem;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
}

.document-edit__hint {
  margin: calc(var(--m-space-2) * -1) 0 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
}

.document-edit__button,
.document-edit__save {
  min-height: 2.25rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 900;
  text-decoration: none;
}

.document-edit__save {
  justify-self: start;
  border-color: var(--m-platform-accent);
}

.document-edit__review h2,
.document-edit__review p,
.document-edit__review dl,
.document-edit__error,
.document-edit__notice,
.document-edit__restricted {
  margin: 0;
}

.document-edit__review section {
  display: grid;
  gap: var(--m-space-2);
}

.document-edit__preview {
  overflow-wrap: anywhere;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: 8px;
  background: var(--m-surface-base);
}

.document-edit__review dl {
  display: grid;
  gap: var(--m-space-2);
}

.document-edit__review dl div {
  display: flex;
  justify-content: space-between;
  gap: var(--m-space-3);
  border-bottom: 1px solid var(--m-border-subtle);
}

.document-edit__review dt {
  color: var(--m-text-muted);
  font-weight: 800;
}

.document-edit__review dd {
  margin: 0;
  text-align: right;
}

.document-edit__error,
.document-edit__restricted {
  color: var(--m-status-danger);
  font-weight: 800;
}

.document-edit__notice {
  color: var(--m-status-success);
  font-weight: 800;
}

@media (min-width: 62rem) {
  .document-edit__layout {
    grid-template-columns: minmax(0, 1.1fr) minmax(20rem, 0.9fr);
    align-items: start;
  }
}
</style>
