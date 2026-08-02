<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import ControlBar from "@/components/ControlBar.vue";
import ControlField from "@/components/ControlField.vue";
import StaffCardList from "@/components/StaffCardList.vue";
import StaffListCard from "@/components/StaffListCard.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  getOrganizationDocuments,
  type DocumentLibrary,
  type ProductDocument,
} from "@/documents/documentAuthoringModel";
import {
  sessionOrganizationId,
  sessionOrganizationLabel,
} from "@/session/sessionContext";

/**
 * `staff.documents` — the Policies & Procedures library a staff member reads
 * from (M18.7; POL-006, POL-008 through POL-013, POL-055; UI contract 12.3).
 *
 * The documents were already published and already visible. What was missing was
 * a way to reach them that was not a department administration screen: a member
 * of two departments read one department's library at a time, from inside a
 * surface built for maintaining it, and an organization-scoped policy published
 * to everybody was reached through a page about somebody's department. This is
 * the reader's own page, and it is neither department-scoped nor event-scoped —
 * which documents reach somebody is the node's answer from their own
 * memberships (POL-008 through POL-012).
 *
 * Three properties are load-bearing:
 *
 *  1. **Published only.** The read asks for `state=published`, so a maintainer
 *     opening this page sees what everybody else sees rather than their own
 *     drafts sitting among the policies. A draft is not policy yet, and the
 *     authoring surface is where it belongs (POL-004, POL-006). For everybody
 *     else the node had already refused it.
 *  2. **Search goes to the node** (POL-055). Searching a list the browser holds
 *     would search whichever part of it arrived, and the node applies the search
 *     after deciding visibility, so a title nobody published to this reader stays
 *     unfindable rather than becoming a way to learn it exists.
 *  3. **The URL is the request.** The search term lives in the query string and
 *     the read follows it, so a result someone links to or reloads is the same
 *     result.
 */
const route = useRoute();
const router = useRouter();

const organizationId = computed(() => sessionOrganizationId.value ?? "");

/** The search term the URL asks for, and the box's own unsubmitted text. */
const searchQuery = computed(() =>
  typeof route.query.q === "string" ? route.query.q : "",
);
const searchDraft = ref(searchQuery.value);

const library = ref<DocumentLibrary | null>(null);
const loadError = ref<string | null>(null);
const loading = ref(false);

const documents = computed<readonly ProductDocument[]>(
  () => library.value?.documents ?? [],
);

const lede = computed(() => {
  const organization = sessionOrganizationLabel.value;

  return organization === null
    ? "Policies and procedures published to you."
    : `Policies and procedures ${organization} has published to you.`;
});

/**
 * Read the published documents this person may see.
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

  loading.value = true;
  loadError.value = null;

  try {
    library.value = await getOrganizationDocuments(
      organizationId.value,
      "published",
      searchQuery.value,
    );
  } catch (error) {
    library.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load documents. Check the connection to this node and try again.",
    );
  } finally {
    loading.value = false;
  }
}

/** Move the typed term into the URL; the watch below turns it into a read. */
async function onSearchSubmit(): Promise<void> {
  const search = searchDraft.value.trim();

  await router.push({
    name: "staff.documents.index",
    query: search === "" ? {} : { q: search },
  });
}

watch(searchQuery, (value) => {
  searchDraft.value = value;
});

watch([organizationId, searchQuery], () => {
  void loadLibrary();
});

void loadLibrary();

function documentTypeLabel(document: ProductDocument): string {
  return document.documentType === "policy" ? "Policy" : "Procedure";
}
</script>

<template>
  <StaffPageShell
    heading-id="staff-documents-heading"
    title="Policies &amp; Procedures"
    eyebrow="Your library"
    :lede="lede"
  >
    <template #actions>
      <RouterLink :to="{ name: 'staff.documents.acknowledgments' }">
        Acknowledgments
      </RouterLink>
    </template>

    <!--
      Organization-scoped, so with no organization there is nothing to read.
      Stated rather than rendered empty: an empty list would say nothing has been
      published, and what is true is that this device is not working in an
      organization yet.
    -->
    <p
      v-if="organizationId === ''"
      class="staff-documents__notice"
      role="status"
    >
      Your document library opens once this device is working in an
      organization.
    </p>

    <template v-else>
      <ControlBar label="Document search">
        <form data-control-group="grow" @submit.prevent="onSearchSubmit">
          <ControlField label="Search titles" control-id="staff-documents-search" width="grow">
            <input
              id="staff-documents-search"
              v-model="searchDraft"
              type="search"
              autocomplete="off"
            />
          </ControlField>
          <button type="submit">Search</button>
        </form>

        <template #end>
          <RouterLink
            v-if="searchQuery !== ''"
            :to="{ name: 'staff.documents.index' }"
          >
            Clear search
          </RouterLink>
        </template>
      </ControlBar>

      <!--
        A refusal is the node's own sentence, and an unreachable node is stated
        rather than shown as an organization with no documents (data/API 7.2).
      -->
      <p v-if="loadError" class="staff-documents__error" role="alert">
        {{ loadError }}
        <button type="button" @click="loadLibrary">Try again</button>
      </p>

      <p
        v-else-if="library === null && loading"
        class="staff-documents__notice"
        role="status"
      >
        Loading documents.
      </p>

      <StaffCardList
        v-else
        min="wide"
        label="Policies and procedures"
        :empty="documents.length === 0"
        :empty-message="
          searchQuery === ''
            ? 'No policies or procedures are published to you yet.'
            : `No published document you can see has ${searchQuery} in its title.`
        "
      >
        <!--
          The card links to the document rather than expanding it here. A policy
          is something somebody reads, refers back to, and links a colleague to,
          and a list of twenty accordions is none of those (UI contract 12.3).
        -->
        <StaffListCard
          v-for="document in documents"
          :key="document.id"
          :title="document.title"
          :eyebrow="documentTypeLabel(document)"
          :to="{
            name: 'staff.documents.show',
            params: {
              documentType: document.documentType,
              documentId: document.id,
            },
          }"
          :meta="[
            { label: 'Scope', value: document.scopeLabel },
            { label: 'Version', value: document.version },
          ]"
        />
      </StaffCardList>
    </template>
  </StaffPageShell>
</template>

<style scoped>
.staff-documents__notice,
.staff-documents__error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-3);
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.staff-documents__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.staff-documents__error button {
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
</style>
