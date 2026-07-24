<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import {
  archiveDocument,
  canAccessDocumentSurface,
  canMaintainDocuments,
  documentVersion,
  publishDocument,
  referencingDocuments,
  resolveDocumentAuthoringSession,
  scopeLabel,
  visibleDocuments,
  visibleFragments,
  visibilitySummary,
  type ProductDocument,
  type ProductDocumentState,
} from "@/documents/documentAuthoringModel";

/**
 * Policy, procedure, and fragment library featureset (M11.15, M11.17).
 *
 * Rendered as its own page at `department.documents` / `organizer.documents`
 * and embedded as a featureset in the Admin workflow hub.
 */
const props = withDefaults(
  defineProps<{
    variant?: "page" | "section";
    surface?: "department" | "organizer" | null;
  }>(),
  {
    variant: "page",
    surface: null,
  },
);

const route = useRoute();
const router = useRouter();
const surface = computed(
  () =>
    props.surface ??
    (String(route.name ?? "").startsWith("organizer.")
      ? ("organizer" as const)
      : ("department" as const)),
);
const session = computed(() =>
  resolveDocumentAuthoringSession(
    surface.value,
    typeof route.params.departmentId === "string"
      ? route.params.departmentId
      : null,
  ),
);
const stateFilter = computed<ProductDocumentState | "all">(() => {
  const value = route.query.state;
  return value === "draft" || value === "published" || value === "archived"
    ? value
    : "all";
});
const documents = computed(() =>
  visibleDocuments(session.value, stateFilter.value),
);
const fragments = computed(() => visibleFragments(session.value));
const canAccess = computed(() => canAccessDocumentSurface(session.value));
const canMaintain = computed(() => canMaintainDocuments(session.value));
const actionError = ref<string | null>(null);
const actionNotice = ref<string | null>(null);

const description = computed(() =>
  surface.value === "organizer"
    ? `${session.value.organizationLabel} policy, procedure, and fragment authoring.`
    : `${session.value.department.roleLabel} document library and maintainer workspace.`,
);

const indexRouteName = computed(() =>
  surface.value === "organizer"
    ? "organizer.documents.index"
    : "events.departments.documents.index",
);
const createRouteName = computed(() =>
  surface.value === "organizer"
    ? "organizer.documents.create"
    : "events.departments.documents.create",
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
        eventId: session.value.eventId,
        departmentId: session.value.department.departmentId,
      },
);

defineExpose({ canAccess, canMaintain, description });

function onStateChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;
  void router.replace({
    name: indexRouteName.value,
    params: baseParams.value,
    query: value === "all" ? {} : { state: value },
  });
}

function editParams(kind: string, artifactId: string): Record<string, string> {
  return {
    ...baseParams.value,
    artifactKind: kind,
    artifactId,
  };
}

function createParams(kind: string): Record<string, string> {
  return {
    ...baseParams.value,
    artifactKind: kind,
  };
}

function stateLabel(state: ProductDocumentState): string {
  return state[0]!.toUpperCase() + state.slice(1);
}

function transition(
  document: ProductDocument,
  state: ProductDocumentState,
): void {
  actionError.value = null;
  actionNotice.value = null;

  try {
    const updated =
      state === "published"
        ? publishDocument(session.value, document.id)
        : archiveDocument(session.value, document.id);
    actionNotice.value = `${updated.title} is now ${stateLabel(updated.state).toLowerCase()}.`;
  } catch (error) {
    actionError.value =
      error instanceof Error ? error.message : "Unable to update document.";
  }
}
</script>

<template>
  <WorkflowSection
    :variant="props.variant"
    title="Documents"
    heading-id="documents-section-heading"
    :description="description"
  >
    <template v-if="canMaintain" #actions>
      <RouterLink
        class="documents__button documents__button--primary"
        :to="{ name: createRouteName, params: createParams('policy') }"
      >
        New policy
      </RouterLink>
      <RouterLink
        class="documents__button"
        :to="{ name: createRouteName, params: createParams('procedure') }"
      >
        New procedure
      </RouterLink>
      <RouterLink
        class="documents__button"
        :to="{ name: createRouteName, params: createParams('fragment') }"
      >
        New fragment
      </RouterLink>
    </template>

    <p v-if="!canAccess" class="documents__restricted" role="status">
      Documents are available only to staff with organization, department, or
      team document visibility.
    </p>

    <template v-else>
      <div class="documents__toolbar">
        <label>
          State
          <select :value="stateFilter" @change="onStateChange">
            <option value="all">All</option>
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="archived">Archived</option>
          </select>
        </label>
      </div>

      <p v-if="actionError" class="documents__error" role="alert">
        {{ actionError }}
      </p>
      <p v-if="actionNotice" class="documents__notice" role="status">
        {{ actionNotice }}
      </p>

      <section class="documents__section" aria-labelledby="documents-list-heading">
        <h3 id="documents-list-heading">Policies and procedures</h3>
        <div class="documents__table-wrap" role="region" aria-label="Documents">
          <table class="documents__table">
            <thead>
              <tr>
                <th scope="col">Title</th>
                <th scope="col">Type</th>
                <th scope="col">State</th>
                <th scope="col">Scope</th>
                <th scope="col">Version</th>
                <th scope="col">Visibility</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="documents.length === 0">
                <td colspan="7">No documents match this filter.</td>
              </tr>
              <tr v-for="document in documents" :key="document.id">
                <td>
                  <RouterLink
                    :to="{
                      name: editRouteName,
                      params: editParams(document.kind, document.id),
                    }"
                  >
                    {{ document.title }}
                  </RouterLink>
                </td>
                <td>{{ document.kind === "policy" ? "Policy" : "Procedure" }}</td>
                <td>
                  <span class="documents__status" :data-state="document.state">
                    {{ stateLabel(document.state) }}
                  </span>
                </td>
                <td>{{ scopeLabel(document.scopeType, document.scopeId) }}</td>
                <td>{{ documentVersion(document) }}</td>
                <td>{{ visibilitySummary(document) }}</td>
                <td class="documents__actions">
                  <RouterLink
                    :to="{
                      name: editRouteName,
                      params: editParams(document.kind, document.id),
                    }"
                  >
                    Edit
                  </RouterLink>
                  <button
                    v-if="canMaintain && document.state !== 'published'"
                    type="button"
                    @click="transition(document, 'published')"
                  >
                    Publish
                  </button>
                  <button
                    v-if="canMaintain && document.state !== 'archived'"
                    type="button"
                    @click="transition(document, 'archived')"
                  >
                    Archive
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="documents__section" aria-labelledby="fragments-heading">
        <h3 id="fragments-heading">Fragments</h3>
        <p v-if="!canMaintain" class="documents__muted">
          Fragment maintenance is available only to document maintainers.
        </p>
        <div v-else class="documents__fragment-grid">
          <article
            v-for="fragment in fragments"
            :key="fragment.id"
            class="documents__fragment"
          >
            <div>
              <h4>{{ fragment.name }}</h4>
              <p>{{ scopeLabel(fragment.scopeType, fragment.scopeId) }}</p>
              <p>Version {{ fragment.version }}</p>
              <p>
                Published reference impact:
                {{
                  referencingDocuments(fragment).filter(
                    (document) => document.state === "published",
                  ).length
                }}
              </p>
            </div>
            <RouterLink
              :to="{
                name: editRouteName,
                params: editParams('fragment', fragment.id),
              }"
            >
              Edit
            </RouterLink>
          </article>
          <p v-if="fragments.length === 0" class="documents__muted">
            No maintainable fragments yet.
          </p>
        </div>
      </section>
    </template>
  </WorkflowSection>
</template>

<style scoped>
.documents__toolbar,
.documents__section {
  display: grid;
  gap: var(--m-space-3);
}

.documents__toolbar {
  justify-items: start;
}

.documents__toolbar label {
  display: grid;
  gap: var(--m-space-1);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.documents__toolbar select {
  min-height: 2.5rem;
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
}

.documents__button,
.documents__actions a,
.documents__actions button,
.documents__fragment a {
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

.documents__button--primary {
  border-color: var(--m-platform-accent);
}

.documents__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.documents__table {
  width: 100%;
  border-collapse: collapse;
  min-width: 58rem;
}

.documents__table th,
.documents__table td {
  padding: var(--m-space-3);
  border-bottom: 1px solid var(--m-border-subtle);
  color: var(--m-text-primary);
  text-align: left;
  vertical-align: top;
}

.documents__table th {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.documents__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.documents__status {
  font-weight: 900;
}

.documents__fragment-grid {
  display: grid;
  gap: var(--m-space-3);
}

.documents__fragment {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.documents__fragment h4,
.documents__fragment p,
.documents__section h3,
.documents__restricted,
.documents__muted,
.documents__error,
.documents__notice {
  margin: 0;
}

.documents__section h3 {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
}

.documents__fragment h4 {
  font-size: var(--m-text-md);
}

.documents__fragment p,
.documents__muted {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.documents__error,
.documents__restricted {
  color: var(--m-status-danger);
  font-weight: 800;
}

.documents__notice {
  color: var(--m-status-success);
  font-weight: 800;
}
</style>
