<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import StaffCardList from "@/components/StaffCardList.vue";
import StaffListCard from "@/components/StaffListCard.vue";
import ControlBar from "@/components/ControlBar.vue";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  getOrganizationDocuments,
  publishedReferenceImpact,
  transitionDocument,
  type DocumentLibrary,
  type DocumentStateFilter,
  type ProductDocument,
} from "@/documents/documentAuthoringModel";
import { sessionOrganizationId } from "@/session/sessionContext";

/**
 * Policy, procedure, and fragment library featureset (M11.15, M11.17; bound to
 * the node in M16.19).
 *
 * Rendered as its own page at `department.documents` / `organizer.documents`
 * and embedded as a featureset in the Admin workflow hub.
 *
 * The page above it already reads the organization's documents for its shell
 * and heading, so it hands that response down through `library` and re-reads on
 * `reload`; embedded on its own the section makes the read itself. Either way
 * one read is behind what is shown rather than two that could disagree about
 * which documents exist.
 *
 * Who sees what is the node's answer, carried on that response: it returns the
 * published documents in the caller's scopes plus anything they maintain, and
 * `access.can_maintain` says whether they are here to author or to read
 * (CLIENT-006).
 */
const props = withDefaults(
  defineProps<{
    variant?: "page" | "section";
    surface?: "department" | "organizer" | null;
    organizationId?: string | null;
    library?: DocumentLibrary | null;
  }>(),
  {
    variant: "page",
    surface: null,
    organizationId: null,
    library: undefined,
  },
);

const emit = defineEmits<{ reload: [] }>();

const route = useRoute();
const router = useRouter();

const surface = computed(
  () =>
    props.surface ??
    (String(route.name ?? "").startsWith("organizer.")
      ? ("organizer" as const)
      : ("department" as const)),
);

const organizationId = computed(
  () => props.organizationId ?? sessionOrganizationId.value ?? "",
);

/** Whether this section is responsible for its own read. */
const ownsRead = computed(() => props.library === undefined);

const ownLibrary = ref<DocumentLibrary | null>(null);
const library = computed<DocumentLibrary | null>(() =>
  ownsRead.value ? ownLibrary.value : (props.library ?? null),
);

const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const actionNotice = ref<string | null>(null);
const busyId = ref<string | null>(null);

/**
 * The publish or archive waiting on a reason.
 *
 * Both commands require one and it is what the audit snapshot records, so the
 * surface asks rather than sending a sentence the maintainer did not write.
 */
const pending = ref<{
  readonly document: ProductDocument;
  readonly state: "published" | "archived";
} | null>(null);
const pendingReason = ref("");

const stateFilter = computed<DocumentStateFilter>(() => {
  const value = route.query.state;

  return value === "draft" || value === "published" || value === "archived"
    ? value
    : "all";
});

const canAccess = computed(() => library.value !== null);
const canMaintain = computed(() => library.value?.access.canMaintain ?? false);
const documents = computed<readonly ProductDocument[]>(
  () => library.value?.documents ?? [],
);
const fragments = computed(() => library.value?.fragments ?? []);

const description = computed(() => {
  if (!canMaintain.value) {
    return "Policies and procedures published to you.";
  }

  return surface.value === "organizer"
    ? "Organization policy, procedure, and fragment authoring."
    : "Department and team document library and maintainer workspace.";
});

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
        eventId: String(route.params.eventId ?? ""),
        departmentId: String(route.params.departmentId ?? ""),
      },
);

async function loadOwnLibrary(): Promise<void> {
  if (!ownsRead.value) {
    return;
  }

  if (organizationId.value === "") {
    ownLibrary.value = null;

    return;
  }

  loadError.value = null;

  try {
    ownLibrary.value = await getOrganizationDocuments(
      organizationId.value,
      stateFilter.value,
    );
  } catch (error) {
    ownLibrary.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load documents. Check the connection to this node and try again.",
    );
  }
}

watch([organizationId, stateFilter], () => {
  actionError.value = null;
  actionNotice.value = null;
  pending.value = null;
  void loadOwnLibrary();
});

void loadOwnLibrary();

defineExpose({ canAccess, canMaintain, description });

function editParams(kind: string, artifactId: string): Record<string, string> {
  return { ...baseParams.value, artifactKind: kind, artifactId };
}

function createParams(kind: string): Record<string, string> {
  return { ...baseParams.value, artifactKind: kind };
}

/**
 * Move the state filter into the URL.
 *
 * The filter is the read's, not the rendered list's: the page or this section
 * watches the query and asks the node again, so the table is always the answer
 * to the filter that is showing.
 */
function onStateChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;

  void router.replace({
    name: indexRouteName.value,
    params: baseParams.value,
    query: value === "all" ? {} : { state: value },
  });
}

function askForReason(
  document: ProductDocument,
  state: "published" | "archived",
): void {
  actionError.value = null;
  actionNotice.value = null;
  pendingReason.value = "";
  pending.value = { document, state };
}

function cancelTransition(): void {
  pending.value = null;
  pendingReason.value = "";
}

/**
 * Run the transition and then take the surface's state from the node again.
 *
 * Nothing is patched in place: a publish that changes which actions a row
 * offers, and a refusal that changes nothing at all, are both read back rather
 * than guessed at.
 */
async function confirmTransition(): Promise<void> {
  const request = pending.value;

  if (request === null) {
    return;
  }

  actionError.value = null;
  actionNotice.value = null;
  busyId.value = request.document.id;

  try {
    const updated = await transitionDocument(
      request.document.documentType,
      request.document.id,
      request.state,
      pendingReason.value,
    );
    actionNotice.value = `${updated.title} is now ${updated.stateLabel.toLowerCase()}.`;
    pending.value = null;
    pendingReason.value = "";
    await reload();
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to update this document.",
    );
  } finally {
    busyId.value = null;
  }
}

async function reload(): Promise<void> {
  if (ownsRead.value) {
    await loadOwnLibrary();

    return;
  }

  emit("reload");
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

    <!--
      A refusal is the node's own sentence, and an unreachable node is stated
      rather than shown as an organization with no documents (data/API 7.2).
    -->
    <p v-if="loadError" class="documents__error" role="alert">
      {{ loadError }}
      <button type="button" @click="reload">Try again</button>
    </p>

    <p v-else-if="!canAccess" class="documents__muted" role="status">
      Loading documents.
    </p>

    <!--
      Readers get the mobile-first card list. A maintainer's table has eight
      columns of lifecycle metadata a reader has no use for, and on a phone it
      scrolls sideways until the labels are off screen.
    -->
    <template v-else-if="!canMaintain">
      <StaffCardList
        min="wide"
        label="Documents"
        :empty="documents.length === 0"
        empty-message="No policies or procedures are published to you yet."
      >
        <StaffListCard
          v-for="document in documents"
          :key="document.id"
          :title="document.title"
          :eyebrow="document.documentType === 'policy' ? 'Policy' : 'Procedure'"
          :meta="[
            { label: 'Scope', value: document.scopeLabel },
            { label: 'Version', value: document.version },
          ]"
        >
          <details class="documents__reader">
            <summary>Read document</summary>
            <div class="documents__reader-body" v-html="document.renderedHtml" />
          </details>
        </StaffListCard>
      </StaffCardList>
    </template>

    <template v-else>
      <ControlBar label="Document filters">
        <label>
          State
          <select :value="stateFilter" @change="onStateChange">
            <option value="all">All</option>
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="archived">Archived</option>
          </select>
        </label>
      </ControlBar>

      <p v-if="actionError" class="documents__error" role="alert">
        {{ actionError }}
      </p>
      <p v-if="actionNotice" class="documents__notice" role="status">
        {{ actionNotice }}
      </p>

      <form
        v-if="pending"
        class="documents__reason"
        @submit.prevent="confirmTransition"
      >
        <label>
          Reason for {{ pending.state === "published" ? "publishing" : "archiving" }}
          {{ pending.document.title }}
          <input v-model="pendingReason" required />
        </label>
        <button type="submit" :disabled="busyId === pending.document.id">
          {{ pending.state === "published" ? "Publish" : "Archive" }}
        </button>
        <button type="button" @click="cancelTransition">Cancel</button>
      </form>

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
                <th scope="col">Event Info</th>
                <th scope="col">Visibility</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="documents.length === 0">
                <td colspan="8">No documents match this filter.</td>
              </tr>
              <tr v-for="document in documents" :key="document.id">
                <td>
                  <RouterLink
                    v-if="document.canMaintain"
                    :to="{
                      name: editRouteName,
                      params: editParams(document.documentType, document.id),
                    }"
                  >
                    {{ document.title }}
                  </RouterLink>
                  <template v-else>{{ document.title }}</template>
                </td>
                <td>
                  {{ document.documentType === "policy" ? "Policy" : "Procedure" }}
                </td>
                <td>
                  <span class="documents__status" :data-state="document.state">
                    {{ document.stateLabel }}
                  </span>
                </td>
                <td>{{ document.scopeLabel }}</td>
                <td>{{ document.version }}</td>
                <td>{{ document.eventInfoSectionLabel ?? "Not shown" }}</td>
                <td>{{ document.visibilitySummary }}</td>
                <!--
                  Each row offers what the node said this caller may do with it.
                  A maintainer of one department reads another department's
                  published documents here, and the commands would refuse them
                  (CLIENT-006).
                -->
                <td class="documents__actions">
                  <RouterLink
                    v-if="document.canMaintain"
                    :to="{
                      name: editRouteName,
                      params: editParams(document.documentType, document.id),
                    }"
                  >
                    Edit
                  </RouterLink>
                  <button
                    v-if="document.canMaintain && document.state !== 'published'"
                    type="button"
                    :disabled="busyId === document.id"
                    @click="askForReason(document, 'published')"
                  >
                    Publish
                  </button>
                  <button
                    v-if="document.canMaintain && document.state !== 'archived'"
                    type="button"
                    :disabled="busyId === document.id"
                    @click="askForReason(document, 'archived')"
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
        <div class="documents__fragment-grid">
          <article
            v-for="fragment in fragments"
            :key="fragment.id"
            class="documents__fragment"
          >
            <div>
              <h4>{{ fragment.name }}</h4>
              <p>{{ fragment.scopeLabel }}</p>
              <p>Version {{ fragment.version }}</p>
              <p>
                Published reference impact:
                {{ publishedReferenceImpact(fragment) }}
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

.documents__reader summary {
  min-height: 2.75rem;
  display: flex;
  align-items: center;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 900;
  cursor: pointer;
}

.documents__reader summary:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.documents__reader-body {
  padding-top: var(--m-space-2);
  border-top: 1px solid var(--m-border-subtle);
}

.documents__reader-body :deep(h1),
.documents__reader-body :deep(h2) {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-base);
}

.documents__reader-body :deep(p) {
  margin: 0 0 var(--m-space-2);
  max-width: var(--m-measure);
  color: var(--m-text-secondary);
}

.documents__reader-body :deep(p:last-child) {
  margin-bottom: 0;
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

.documents__reason {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: var(--m-space-3);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.documents__reason label {
  display: grid;
  flex: 1 1 20rem;
  gap: var(--m-space-1);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.documents__reason input {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
}

.documents__button,
.documents__actions a,
.documents__actions button,
.documents__reason button,
.documents__error button,
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

.documents__error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-3);
  color: var(--m-status-danger);
  font-weight: 800;
}

.documents__notice {
  color: var(--m-status-success);
  font-weight: 800;
}
</style>
