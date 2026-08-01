<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRoute } from "vue-router";

import StaffPageShell from "@/components/StaffPageShell.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import DocumentLibrarySection from "@/components/sections/DocumentLibrarySection.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  getOrganizationDocuments,
  type DocumentLibrary,
  type DocumentStateFilter,
} from "@/documents/documentAuthoringModel";
import {
  sessionOrganizationId,
  sessionOrganizationLabel,
} from "@/session/sessionContext";
import { selectedSessionDepartment } from "@/session/sessionAccess";

/**
 * `organizer.documents` and `department.documents` (M11.15; bound to the node
 * in M16.19; UI contract 12.6).
 *
 * The page owns the one read the surface renders from. Which shell it wears,
 * what its lede says, and what the featureset below lists are three views of one
 * response rather than three answers that could disagree, and the featureset
 * asks for a re-read after every write it makes.
 */
const route = useRoute();
const surface = computed(() =>
  String(route.name ?? "").startsWith("organizer.")
    ? ("organizer" as const)
    : ("department" as const),
);
const organizationId = computed(() => sessionOrganizationId.value ?? "");

/** The state filter the featureset writes into the URL, read back for the request. */
const stateFilter = computed<DocumentStateFilter>(() => {
  const value = route.query.state;

  return value === "draft" || value === "published" || value === "archived"
    ? value
    : "all";
});

const library = ref<DocumentLibrary | null>(null);
const loadError = ref<string | null>(null);

/*
 * Maintainers get the wide workflow shell because authoring needs the table and
 * its lifecycle columns. Readers get the narrow, touch-first staff shell: they
 * are here to read a policy, usually on a phone. Which of the two applies is the
 * node's answer on the response rather than a role the client interpreted for
 * itself (CLIENT-006), and until the read lands the page shows the reader shell:
 * an unanswered question about authority is not a maintainer.
 */
const canMaintain = computed(() => library.value?.access.canMaintain ?? false);

const eyebrow = computed(() =>
  surface.value === "organizer"
    ? "Organizer administration"
    : (selectedSessionDepartment.value?.departmentLabel ?? "Department"),
);
const lede = computed(() => {
  if (!canMaintain.value) {
    return "Policies and procedures published to you.";
  }

  const organization = sessionOrganizationLabel.value ?? "This organization";

  return surface.value === "organizer"
    ? `${organization} policy, procedure, and fragment authoring.`
    : "Department and team document library and maintainer workspace.";
});

/**
 * Read the organization's documents.
 *
 * A failed read clears the library rather than leaving the last one on screen:
 * an organization whose documents could not be read must not look like an
 * organization with nothing published.
 */
async function loadLibrary(): Promise<void> {
  if (organizationId.value === "") {
    library.value = null;

    return;
  }

  loadError.value = null;

  try {
    library.value = await getOrganizationDocuments(
      organizationId.value,
      stateFilter.value,
    );
  } catch (error) {
    library.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load documents. Check the connection to this node and try again.",
    );
  }
}

watch([organizationId, stateFilter], () => {
  void loadLibrary();
});

void loadLibrary();
</script>

<template>
  <StaffPageShell
    v-if="!canMaintain"
    heading-id="documents-heading"
    title="Documents"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <!--
      A refusal is the node's own sentence, and an unreachable node is stated
      rather than shown as an organization with no documents (data/API 7.2).
    -->
    <p v-if="loadError" class="documents-page__error" role="alert">
      {{ loadError }}
      <button type="button" @click="loadLibrary">Try again</button>
    </p>

    <DocumentLibrarySection
      v-else
      variant="page"
      :surface="surface"
      :organization-id="organizationId"
      :library="library"
      @reload="loadLibrary"
    />
  </StaffPageShell>

  <WorkflowPageShell
    v-else
    heading-id="documents-heading"
    title="Documents"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <DocumentLibrarySection
      variant="page"
      :surface="surface"
      :organization-id="organizationId"
      :library="library"
      @reload="loadLibrary"
    />
  </WorkflowPageShell>
</template>

<style scoped>
.documents-page__error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-3);
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-status-danger, #cc792f);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-status-danger, #cc792f);
}

.documents-page__error button {
  min-height: 2.25rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 900;
  cursor: pointer;
}
</style>
