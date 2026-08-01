<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  createDocument,
  createDocumentFragment,
  documentExportFormatLabel,
  exportDocument,
  getDocument,
  getDocumentFragment,
  getOrganizationDocuments,
  publishedReferenceImpact,
  transitionDocument,
  updateDocument,
  updateDocumentFragment,
  type DocumentArtifactKind,
  type DocumentDraft,
  type DocumentLibrary,
  type DocumentScopeType,
  type DocumentType,
  type ProductDocument,
  type ProductDocumentFragment,
} from "@/documents/documentAuthoringModel";
import {
  sessionOrganizationId,
  sessionOrganizationLabel,
} from "@/session/sessionContext";
import { selectedSessionDepartment } from "@/session/sessionAccess";

/**
 * `organizer.documents.create` / `.edit` and their department equivalents
 * (M11.15; bound to the node in M16.19; UI contract 12.6).
 *
 * Two reads fill this page. The organization read carries the scopes this
 * caller may author in and the Event Info placements a document may take, which
 * a create form needs before there is any document to read them off. The
 * artifact read carries the record being edited, including the node's own
 * rendered HTML and its answer on whether this caller may maintain it.
 *
 * Every rule the form used to enforce for itself — slug shape, scope authority,
 * the version split, the refusal to nest fragments — now belongs to the node,
 * and its refusals are shown as it worded them (CLIENT-006).
 */
const route = useRoute();
const router = useRouter();

const surface = computed(() =>
  String(route.name ?? "").startsWith("organizer.")
    ? ("organizer" as const)
    : ("department" as const),
);
const organizationId = computed(() => sessionOrganizationId.value ?? "");

const artifactKind = computed<DocumentArtifactKind>(() => {
  const value = route.params.artifactKind;

  return value === "procedure" || value === "fragment" ? value : "policy";
});
const documentType = computed<DocumentType>(() =>
  artifactKind.value === "procedure" ? "procedure" : "policy",
);
const artifactId = computed(() =>
  typeof route.params.artifactId === "string" ? route.params.artifactId : null,
);
const isCreate = computed(() => artifactId.value === null);

const library = ref<DocumentLibrary | null>(null);
const savedDocument = ref<ProductDocument | null>(null);
const savedFragment = ref<ProductDocumentFragment | null>(null);
const loadError = ref<string | null>(null);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const busy = ref(false);

/** The publish or archive waiting on the reason both commands require. */
const pendingState = ref<"published" | "archived" | null>(null);
const pendingReason = ref("");

const scopes = computed(() => library.value?.access.scopes ?? []);
const eventInfoSections = computed(() => library.value?.eventInfoSections ?? []);

/*
 * Authority is the node's answer. Creating needs any maintainable scope at all;
 * editing needs it for this document, which is what `can_maintain` on the read
 * says and what the update command enforces.
 */
const canMaintain = computed(() => {
  if (artifactKind.value === "fragment") {
    // The fragment read is maintain-scoped: reaching one means the node let it
    // through, and a create needs some scope to place it in.
    return isCreate.value
      ? (library.value?.access.canMaintain ?? false)
      : savedFragment.value !== null;
  }

  return isCreate.value
    ? (library.value?.access.canMaintain ?? false)
    : (savedDocument.value?.canMaintain ?? false);
});

const loaded = computed(
  () =>
    library.value !== null &&
    (isCreate.value ||
      savedDocument.value !== null ||
      savedFragment.value !== null),
);

const form = reactive<DocumentDraft>(emptyDraft());

const pageTitle = computed(() => {
  if (artifactKind.value === "fragment") {
    return isCreate.value ? "New Fragment" : "Edit Fragment";
  }

  const label = artifactKind.value === "policy" ? "Policy" : "Procedure";

  return isCreate.value ? `New ${label}` : `Edit ${label}`;
});

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
    ? ({} as Record<string, string>)
    : {
        eventId: String(route.params.eventId ?? ""),
        departmentId: String(route.params.departmentId ?? ""),
      },
);
const backRoute = computed(() => ({
  name: indexRouteName.value,
  params: baseParams.value,
}));

const fragmentImpact = computed(
  () => savedFragment.value?.referencingDocuments ?? [],
);

function emptyDraft(): DocumentDraft {
  return {
    scopeType: "organization",
    scopeId: "",
    title: "",
    slug: "",
    eventInfoSection: null,
    markdownSource: "",
  };
}

/**
 * The scope the form is set to, as the node described it.
 *
 * Its label is where the slug prefix comes from, and the label is the node's own
 * wording — "Department: Rangers" — so the prefix is taken from the part after
 * the colon rather than the whole of it.
 */
const selectedScope = computed(
  () =>
    scopes.value.find(
      (scope) =>
        scope.scopeType === form.scopeType && scope.scopeId === form.scopeId,
    ) ?? null,
);

/**
 * A slug the node will accept: `^[a-z0-9]+(?:-[a-z0-9]+)*$`.
 *
 * Written against that expression rather than against a general idea of
 * slugging, because a suggestion the node refuses is worse than no suggestion —
 * it looks like the form filled itself in correctly and then blames the person
 * who accepted it.
 */
function slugify(value: string): string {
  return value
    .normalize("NFKD")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

/** The scope's own name, without the "Department: " kind the label leads with. */
function scopeSlugPrefix(): string {
  const label = selectedScope.value?.label ?? "";
  const separator = label.indexOf(":");

  return slugify(separator === -1 ? label : label.slice(separator + 1));
}

function suggestedSlug(): string {
  return [scopeSlugPrefix(), slugify(form.title)].filter(Boolean).join("-");
}

/**
 * Whether somebody has taken the slug over.
 *
 * The suggestion follows the title and the scope right up until the moment it is
 * edited by hand, and then stops for good. A field that keeps overwriting what
 * was typed into it is worse than one that never filled itself in.
 */
const slugEdited = ref(false);

function onSlugInput(): void {
  slugEdited.value = true;
}

/*
 * Only on a create form. An existing document's slug is part of how it is
 * addressed, and rewriting it because somebody fixed a typo in the title would
 * change what the document is on the strength of an edit that was not about
 * that.
 */
watch(
  () => [isCreate.value, form.title, selectedScope.value] as const,
  () => {
    if (!isCreate.value || slugEdited.value) {
      return;
    }

    form.slug = suggestedSlug();
  },
);

function applyDocument(document: ProductDocument): void {
  form.scopeType = document.scopeType;
  form.scopeId = document.scopeId;
  form.title = document.title;
  form.slug = document.slug;
  form.eventInfoSection = document.eventInfoSection;
  form.markdownSource = document.markdownSource;
}

function applyFragment(fragment: ProductDocumentFragment): void {
  form.scopeType = fragment.scopeType;
  form.scopeId = fragment.scopeId;
  form.title = fragment.name;
  form.slug = fragment.slug;
  form.eventInfoSection = null;
  form.markdownSource = fragment.markdownSource;
}

/**
 * Fill the scope field from the scopes the node offered.
 *
 * A create form lands on the broadest scope the caller holds, which is the
 * order the read returned them in.
 */
function applyDefaultScope(): void {
  const first = scopes.value[0] ?? null;

  form.scopeType = (first?.scopeType ?? "organization") as DocumentScopeType;
  form.scopeId = first?.scopeId ?? "";
}

async function load(): Promise<void> {
  loadError.value = null;
  savedDocument.value = null;
  savedFragment.value = null;
  Object.assign(form, emptyDraft());
  // A new form is a new slug. Carrying the flag across would leave the next
  // document silently not suggesting one, for a reason nothing on screen shows.
  slugEdited.value = false;

  if (organizationId.value === "") {
    library.value = null;

    return;
  }

  try {
    library.value = await getOrganizationDocuments(organizationId.value);
  } catch (caught) {
    library.value = null;
    loadError.value = meridianErrorMessage(
      caught,
      "Unable to load the document workspace. Check the connection to this node and try again.",
    );

    return;
  }

  const id = artifactId.value;

  if (id === null) {
    applyDefaultScope();

    return;
  }

  try {
    if (artifactKind.value === "fragment") {
      const fragment = await getDocumentFragment(id);
      savedFragment.value = fragment;
      applyFragment(fragment);

      return;
    }

    const document = await getDocument(documentType.value, id);
    savedDocument.value = document;
    applyDocument(document);
  } catch (caught) {
    loadError.value = meridianErrorMessage(
      caught,
      "Unable to load this document.",
    );
  }
}

watch(
  [organizationId, artifactKind, artifactId],
  () => {
    void load();
  },
  { immediate: true },
);

function onScopeChange(event: Event): void {
  const [scopeType, scopeId] = (event.target as HTMLSelectElement).value.split(
    ":",
  );

  form.scopeType = scopeType as DocumentScopeType;
  form.scopeId = scopeId ?? "";
}

function onEventInfoSectionChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;

  form.eventInfoSection = value === "" ? null : value;
}

/**
 * Save the artifact and take its state from the node's answer.
 *
 * Nothing is patched in place: the version, the state, the rendered HTML, and
 * the fragment references all come back on the response, so what the review
 * panel shows is what was stored rather than what was typed.
 */
async function save(): Promise<void> {
  error.value = null;
  notice.value = null;
  busy.value = true;

  try {
    if (artifactKind.value === "fragment") {
      const fragment =
        artifactId.value === null
          ? await createDocumentFragment(organizationId.value, form)
          : await updateDocumentFragment(
              artifactId.value,
              organizationId.value,
              form,
            );

      savedFragment.value = fragment;
      applyFragment(fragment);
      notice.value = `${fragment.name} saved.`;

      if (artifactId.value === null) {
        await router.replace({
          name: editRouteName.value,
          params: {
            ...baseParams.value,
            artifactKind: "fragment",
            artifactId: fragment.id,
          },
        });
      }

      return;
    }

    const document =
      artifactId.value === null
        ? await createDocument(documentType.value, organizationId.value, form)
        : await updateDocument(
            documentType.value,
            artifactId.value,
            organizationId.value,
            form,
          );

    savedDocument.value = document;
    applyDocument(document);
    notice.value = `${document.title} saved.`;

    if (artifactId.value === null) {
      await router.replace({
        name: editRouteName.value,
        params: {
          ...baseParams.value,
          artifactKind: document.documentType,
          artifactId: document.id,
        },
      });
    }
  } catch (caught) {
    error.value = meridianErrorMessage(caught, "Unable to save this document.");
  } finally {
    busy.value = false;
  }
}

function askForReason(state: "published" | "archived"): void {
  error.value = null;
  notice.value = null;
  pendingReason.value = "";
  pendingState.value = state;
}

async function confirmTransition(): Promise<void> {
  const state = pendingState.value;
  const document = savedDocument.value;

  if (state === null || document === null) {
    return;
  }

  error.value = null;
  notice.value = null;
  busy.value = true;

  try {
    const updated = await transitionDocument(
      document.documentType,
      document.id,
      state,
      pendingReason.value,
    );

    savedDocument.value = updated;
    applyDocument(updated);
    pendingState.value = null;
    pendingReason.value = "";
    notice.value = `${updated.title} is now ${updated.stateLabel.toLowerCase()}.`;
  } catch (caught) {
    error.value = meridianErrorMessage(
      caught,
      "Unable to update this document.",
    );
  } finally {
    busy.value = false;
  }
}

/**
 * Download this document (M16.12; CLIENT-019, CLIENT-020).
 *
 * The node decides authorization when it issues the URL, so a refusal is shown
 * here rather than opening a tab onto an error page.
 */
async function download(format: string): Promise<void> {
  const document = savedDocument.value;

  if (document === null) {
    return;
  }

  error.value = null;
  notice.value = null;

  try {
    await exportDocument(document.documentType, document.id, format);
  } catch (caught) {
    error.value = meridianErrorMessage(
      caught,
      "Unable to export this document.",
    );
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
        : (selectedSessionDepartment?.departmentLabel ?? 'Department')
    "
    :lede="sessionOrganizationLabel ?? ''"
  >
    <template #nav>
      <RouterLink :to="backRoute">Back To Documents</RouterLink>
    </template>

    <!--
      Every action here is one the node said this caller holds. Publishing,
      archiving, and exporting are all maintain-scoped, so a document reached
      read-only offers none of them rather than offering three the commands
      would refuse (CLIENT-006).
    -->
    <template v-if="savedDocument && canMaintain" #actions>
      <button
        v-if="savedDocument.state !== 'published'"
        type="button"
        class="document-edit__button"
        @click="askForReason('published')"
      >
        Publish
      </button>
      <button
        v-if="savedDocument.state !== 'archived'"
        type="button"
        class="document-edit__button"
        @click="askForReason('archived')"
      >
        Archive
      </button>
      <button
        v-for="format in savedDocument.exportFormats"
        :key="format"
        type="button"
        class="document-edit__button"
        @click="download(format)"
      >
        {{ documentExportFormatLabel(format) }}
      </button>
      <button
        type="button"
        class="document-edit__button"
        @click="notice = 'External sharing is limited to permitted exported copies for Alpha 1.'"
      >
        Share
      </button>
    </template>

    <!--
      A refusal is the node's own sentence: a document in a scope this caller
      does not maintain is refused there rather than hidden here (CLIENT-006).
    -->
    <p v-if="loadError" class="document-edit__error" role="alert">
      {{ loadError }}
      <button type="button" @click="load">Try again</button>
    </p>

    <p v-else-if="!loaded" class="document-edit__hint" role="status">
      Loading the document workspace.
    </p>

    <p v-else-if="!canMaintain" class="document-edit__restricted" role="status">
      Document authoring requires organizer, department lead, or team lead
      authority for this scope.
    </p>

    <template v-else>
      <p v-if="error" class="document-edit__error" role="alert">{{ error }}</p>
      <p v-if="notice" class="document-edit__notice" role="status">
        {{ notice }}
      </p>

      <form
        v-if="pendingState"
        class="document-edit__reason"
        @submit.prevent="confirmTransition"
      >
        <label>
          Reason for
          {{ pendingState === "published" ? "publishing" : "archiving" }}
          <input v-model="pendingReason" required />
        </label>
        <button type="submit" :disabled="busy">
          {{ pendingState === "published" ? "Publish" : "Archive" }}
        </button>
        <button type="button" @click="pendingState = null">Cancel</button>
      </form>

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
                :key="`${scope.scopeType}:${scope.scopeId}`"
                :value="`${scope.scopeType}:${scope.scopeId}`"
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
            <input v-model="form.slug" required @input="onSlugInput" />
          </label>
          <p v-if="isCreate && !slugEdited" class="document-edit__hint">
            Suggested from the scope and the title. Edit it and it stays as you
            leave it.
          </p>

          <label v-if="artifactKind !== 'fragment'">
            Event Info section
            <select
              :value="form.eventInfoSection ?? ''"
              @change="onEventInfoSectionChange"
            >
              <option value="">Not shown on Event Info</option>
              <option
                v-for="section in eventInfoSections"
                :key="section.value"
                :value="section.value"
              >
                {{ section.label }}
              </option>
            </select>
          </label>
          <p v-if="artifactKind !== 'fragment'" class="document-edit__hint">
            Assigned documents appear on Event Info once published, to the staff
            who can already see them.
          </p>

          <label>
            Markdown
            <textarea v-model="form.markdownSource" required rows="16" />
          </label>

          <button type="submit" class="document-edit__save" :disabled="busy">
            Save
          </button>
        </form>

        <aside class="document-edit__review" aria-label="Preview and review">
          <!--
            The node's render of the saved document, with fragments resolved and
            raw HTML stripped, so the preview is the published document rather
            than a second renderer's approximation of it (POL-022, POL-034).
          -->
          <section v-if="savedDocument">
            <h2>Preview</h2>
            <div
              class="document-edit__preview"
              v-html="savedDocument.renderedHtml"
            />
            <p class="document-edit__hint">
              Rendered by the node from the saved Markdown, with fragment text
              inline. Save to refresh it.
            </p>
          </section>

          <section v-if="savedDocument">
            <h2>Visibility</h2>
            <dl>
              <div>
                <dt>Type</dt>
                <dd>
                  {{
                    savedDocument.documentType === "policy"
                      ? "Policy"
                      : "Procedure"
                  }}
                </dd>
              </div>
              <div>
                <dt>State</dt>
                <dd>{{ savedDocument.stateLabel }}</dd>
              </div>
              <div>
                <dt>Version</dt>
                <dd>{{ savedDocument.version }}</dd>
              </div>
              <div>
                <dt>Scope</dt>
                <dd>{{ savedDocument.scopeLabel }}</dd>
              </div>
              <div>
                <dt>Event Info</dt>
                <dd>
                  {{
                    savedDocument.eventInfoSectionLabel ??
                    "Not shown on Event Info"
                  }}
                </dd>
              </div>
            </dl>
            <p>{{ savedDocument.visibilitySummary }}</p>
          </section>

          <section v-if="savedDocument && savedDocument.fragmentReferences.length">
            <h2>Fragments Referenced</h2>
            <ul>
              <li
                v-for="reference in savedDocument.fragmentReferences"
                :key="reference.fragmentId"
              >
                {{ reference.fragmentName ?? reference.token }} / version
                {{ reference.fragmentVersion }}
              </li>
            </ul>
          </section>

          <section v-if="savedFragment">
            <h2>Published Reference Impact</h2>
            <p>
              {{ publishedReferenceImpact(savedFragment) }}
              published document version bumps if Markdown changes.
            </p>
            <ul>
              <li v-for="document in fragmentImpact" :key="document.id">
                {{ document.title }} / {{ document.state }} /
                {{ document.version }}
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

.document-edit__form label,
.document-edit__reason label {
  display: grid;
  gap: var(--m-space-1);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 900;
}

.document-edit__form input,
.document-edit__form select,
.document-edit__form textarea,
.document-edit__reason input {
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

.document-edit__reason {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: var(--m-space-3);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.document-edit__reason label {
  flex: 1 1 20rem;
}

.document-edit__hint {
  margin: calc(var(--m-space-2) * -1) 0 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
}

.document-edit__button,
.document-edit__save,
.document-edit__reason button,
.document-edit__error button {
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
.document-edit__notice,
.document-edit__restricted {
  margin: 0;
}

.document-edit__error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-3);
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
