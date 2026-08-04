<script setup lang="ts">
import { computed, ref, watch } from "vue";

import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import StatusPill, { type StatusPillTone } from "@/components/StatusPill.vue";
import { MeridianApiError, meridianErrorMessage } from "@/api/meridianApi";
import {
  createWaiver,
  formatWaiverMoment,
  getWaiverAdministration,
  getWaiverDetail,
  recordWaiverCompletion,
  setWaiverArchived,
  updateWaiver,
  type AdministeredWaiver,
  type WaiverAdministration,
  type WaiverDetail,
} from "@/waivers/waiverAdminModel";
import { useConnectivity } from "@/offline/useConnectivity";
import {
  sessionOrganizationId,
  sessionOrganizationLabel,
} from "@/session/sessionContext";

/**
 * `organizer.waivers` — waiver administration and completion recording
 * (M18.18; WAIVER-001 through WAIVER-006, WAIVER-010).
 *
 * One surface for every waiver maintainer, not an organizer-only page despite
 * the route prefix: waiver administration authority follows the scope of the
 * waiver (WAIVER-010) — organizers for organization scope, department leads
 * for department scope, team leads for team scope — and the node answers each
 * caller with exactly the waivers and scope options they hold. The client
 * renders that answer rather than deciding it (CLIENT-006); a caller holding
 * no scope is told so by the node's own refusal.
 *
 * Two things it takes care over:
 *
 *  1. **The document is shown where completion is recorded.** A
 *     document-backed waiver renders its referenced text, fragments inline,
 *     on the expanded card (WAIVER-007; POL-022) — the person recording a
 *     completion should be looking at what was agreed to, and the roster
 *     names the version each completion acknowledged (WAIVER-008).
 *  2. **Lapsed is not merely incomplete.** A staff member whose completion
 *     expired reads as lapsed, because that is the state WAIVER-006 turns
 *     into a credential block until renewed.
 */
const connectivity = useConnectivity();

const administration = ref<WaiverAdministration | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const refused = ref(false);
const actionError = ref<string | null>(null);
const notice = ref<string | null>(null);

const creating = ref(false);
const busyWaiverId = ref<string | null>(null);
const expandedWaiverId = ref<string | null>(null);
const detail = ref<WaiverDetail | null>(null);
const detailLoading = ref(false);
const detailError = ref<string | null>(null);
const completionStaffId = ref("");
const editing = ref(false);
const editForm = ref({ name: "", description: "", expiresAfterDays: "", document: "" });

const NO_DOCUMENT = "";

const createForm = ref({
  scope: "",
  name: "",
  description: "",
  expiresAfterDays: "",
  document: NO_DOCUMENT,
});

const isOffline = computed(() => connectivity.value !== "online");

const lede = computed(
  () =>
    administration.value?.organizationName ??
    sessionOrganizationLabel.value ??
    "",
);

const waivers = computed<readonly AdministeredWaiver[]>(
  () => administration.value?.waivers ?? [],
);

const canCreate = computed(
  () =>
    createForm.value.scope !== "" &&
    createForm.value.name.trim() !== "" &&
    !isOffline.value &&
    !creating.value,
);

watch(
  sessionOrganizationId,
  () => {
    void load();
  },
  { immediate: true },
);

async function load(): Promise<void> {
  const organizationId = sessionOrganizationId.value;

  if (organizationId === null) {
    administration.value = null;

    return;
  }

  loading.value = true;
  loadError.value = null;
  refused.value = false;

  try {
    const answer = await getWaiverAdministration(organizationId);

    administration.value = answer;

    if (createForm.value.scope === "" && answer.scopes[0]) {
      createForm.value.scope = scopeKey(
        answer.scopes[0].scopeType,
        answer.scopes[0].scopeId,
      );
    }
  } catch (error) {
    administration.value = null;

    // The node's refusal is the authority answer (WAIVER-010): this caller
    // holds no waiver-maintainable scope, which is a state, not a fault.
    if (error instanceof MeridianApiError && error.status === 403) {
      refused.value = true;
    } else {
      loadError.value = meridianErrorMessage(
        error,
        "Unable to read waivers. Check the connection to this node and try again.",
      );
    }
  } finally {
    loading.value = false;
  }
}

async function submitWaiver(): Promise<void> {
  const organizationId = sessionOrganizationId.value;
  const scope = selectedScope.value;

  if (organizationId === null || scope === null) {
    return;
  }

  actionError.value = null;
  notice.value = null;
  creating.value = true;

  const document = selectedCreateDocument.value;

  try {
    await createWaiver({
      organizationId,
      scopeType: scope.scopeType,
      scopeId: scope.scopeId,
      name: createForm.value.name.trim(),
      description: emptyToNull(createForm.value.description),
      expiresAfterDays: parsedDays(createForm.value.expiresAfterDays),
      documentType: document?.documentType ?? null,
      documentId: document?.documentId ?? null,
    });

    notice.value = `${createForm.value.name.trim()} now applies to ${scope.label}.`;
    createForm.value = {
      scope: scopeKey(scope.scopeType, scope.scopeId),
      name: "",
      description: "",
      expiresAfterDays: "",
      document: NO_DOCUMENT,
    };

    await load();
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to create this waiver.",
    );
  } finally {
    creating.value = false;
  }
}

async function toggleArchived(waiver: AdministeredWaiver): Promise<void> {
  if (busyWaiverId.value !== null) {
    return;
  }

  actionError.value = null;
  notice.value = null;
  busyWaiverId.value = waiver.id;

  try {
    await setWaiverArchived(waiver.id, !waiver.archived);

    notice.value = waiver.archived
      ? `${waiver.name} is asked again of ${waiver.scopeLabel}.`
      : `${waiver.name} is archived. Its recorded completions are kept.`;

    await load();
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to change this waiver.",
    );
  } finally {
    busyWaiverId.value = null;
  }
}

async function toggleExpanded(waiver: AdministeredWaiver): Promise<void> {
  if (expandedWaiverId.value === waiver.id) {
    expandedWaiverId.value = null;
    detail.value = null;
    editing.value = false;

    return;
  }

  expandedWaiverId.value = waiver.id;
  completionStaffId.value = "";
  editing.value = false;
  await loadDetail(waiver);
}

async function loadDetail(waiver: AdministeredWaiver): Promise<void> {
  const organizationId = sessionOrganizationId.value;

  if (organizationId === null) {
    return;
  }

  detailLoading.value = true;
  detailError.value = null;
  detail.value = null;

  try {
    detail.value = await getWaiverDetail(organizationId, waiver.id);
  } catch (error) {
    detailError.value = meridianErrorMessage(
      error,
      "Unable to read this waiver's roster.",
    );
  } finally {
    detailLoading.value = false;
  }
}

async function submitCompletion(waiver: AdministeredWaiver): Promise<void> {
  if (completionStaffId.value === "" || busyWaiverId.value !== null) {
    return;
  }

  actionError.value = null;
  notice.value = null;
  busyWaiverId.value = waiver.id;

  try {
    await recordWaiverCompletion(waiver.id, completionStaffId.value);

    notice.value = `Completion recorded for ${waiver.name}.`;
    completionStaffId.value = "";

    await Promise.all([load(), loadDetail(waiver)]);
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to record this completion.",
    );
  } finally {
    busyWaiverId.value = null;
  }
}

function beginEdit(waiver: AdministeredWaiver): void {
  editing.value = true;
  editForm.value = {
    name: waiver.name,
    description: waiver.description ?? "",
    expiresAfterDays:
      waiver.expiresAfterDays === null ? "" : String(waiver.expiresAfterDays),
    document:
      waiver.document === null
        ? NO_DOCUMENT
        : documentKey(waiver.document.documentType, waiver.document.documentId),
  };
}

async function submitEdit(waiver: AdministeredWaiver): Promise<void> {
  if (editForm.value.name.trim() === "" || busyWaiverId.value !== null) {
    return;
  }

  actionError.value = null;
  notice.value = null;
  busyWaiverId.value = waiver.id;

  const document =
    administration.value?.documents.find(
      (option) =>
        documentKey(option.documentType, option.documentId) ===
        editForm.value.document,
    ) ?? null;

  try {
    await updateWaiver({
      waiverId: waiver.id,
      name: editForm.value.name.trim(),
      description: emptyToNull(editForm.value.description),
      expiresAfterDays: parsedDays(editForm.value.expiresAfterDays),
      documentType: document?.documentType ?? null,
      documentId: document?.documentId ?? null,
    });

    notice.value = `${editForm.value.name.trim()} is updated.`;
    editing.value = false;

    await Promise.all([load(), loadDetail(waiver)]);
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to change this waiver.",
    );
  } finally {
    busyWaiverId.value = null;
  }
}

const selectedScope = computed(
  () =>
    administration.value?.scopes.find(
      (option) =>
        scopeKey(option.scopeType, option.scopeId) === createForm.value.scope,
    ) ?? null,
);

const selectedCreateDocument = computed(
  () =>
    administration.value?.documents.find(
      (option) =>
        documentKey(option.documentType, option.documentId) ===
        createForm.value.document,
    ) ?? null,
);

const incompleteRoster = computed(
  () => detail.value?.roster.filter((row) => !row.complete) ?? [],
);

function scopeKey(type: string, id: string): string {
  return `${type}:${id}`;
}

function documentKey(type: string, id: string): string {
  return `${type}:${id}`;
}

function emptyToNull(value: string): string | null {
  const trimmed = value.trim();

  return trimmed === "" ? null : trimmed;
}

function parsedDays(value: string | number): number | null {
  // A number input's v-model yields a number in some environments and a
  // string in others; accept both rather than caring which.
  const trimmed = String(value).trim();

  if (trimmed === "") {
    return null;
  }

  const parsed = Number.parseInt(trimmed, 10);

  return Number.isNaN(parsed) || parsed < 1 ? null : parsed;
}

function waiverTone(waiver: AdministeredWaiver): StatusPillTone {
  return waiver.archived ? "neutral" : "positive";
}

function rosterState(row: {
  complete: boolean;
  lapsed: boolean;
}): { label: string; tone: StatusPillTone } {
  if (row.complete) {
    return { label: "Complete", tone: "positive" };
  }

  // WAIVER-006: a lapsed required waiver is what blocks credential
  // eligibility until renewed, so it is named rather than folded into
  // "incomplete".
  return row.lapsed
    ? { label: "Lapsed", tone: "caution" }
    : { label: "Incomplete", tone: "neutral" };
}
</script>

<template>
  <WorkflowPageShell
    heading-id="waiver-administration-heading"
    title="Waivers"
    eyebrow="Administration"
    :lede="lede"
  >
    <p
      v-if="sessionOrganizationId === null"
      class="waivers__restricted"
      role="status"
    >
      Administering waivers requires a session with an organization this device
      is working in.
    </p>

    <!--
      The node's own refusal (WAIVER-010): this caller maintains no
      organization, department, or team scope, so the page has nothing to
      offer them (CLIENT-005, CLIENT-006).
    -->
    <p
      v-else-if="refused"
      class="waivers__restricted"
      role="status"
      data-testid="waivers-refused"
    >
      Administering waivers requires organizer, department lead, or team lead
      authority. Waiver authority follows the waiver's scope.
    </p>

    <template v-else>
      <p v-if="loadError" class="waivers__error" role="alert">
        {{ loadError }}
        <button type="button" @click="load">Try again</button>
      </p>

      <p v-if="isOffline" class="waivers__notice" role="status">
        Waiver administration needs a connection to the node. Nothing here is
        held on this device for later.
      </p>

      <p v-if="actionError" class="waivers__error" role="alert">
        {{ actionError }}
      </p>

      <p v-if="notice" class="waivers__notice" role="status">{{ notice }}</p>

      <form class="waivers__form" @submit.prevent="submitWaiver">
        <h2 class="waivers__form-title">Create a waiver</h2>

        <label class="waivers__field">
          <span>Applies to</span>
          <select v-model="createForm.scope" data-testid="waiver-scope">
            <option
              v-for="option in administration?.scopes ?? []"
              :key="scopeKey(option.scopeType, option.scopeId)"
              :value="scopeKey(option.scopeType, option.scopeId)"
            >
              {{ option.label }}
            </option>
          </select>
        </label>

        <label class="waivers__field">
          <span>Name</span>
          <input
            v-model="createForm.name"
            type="text"
            maxlength="255"
            data-testid="waiver-name"
          />
        </label>

        <label class="waivers__field">
          <span>Description (optional)</span>
          <textarea
            v-model="createForm.description"
            rows="2"
            maxlength="2000"
          ></textarea>
        </label>

        <label class="waivers__field">
          <span>Expires after (days, blank for never)</span>
          <input
            v-model="createForm.expiresAfterDays"
            type="number"
            min="1"
            max="3650"
            data-testid="waiver-expiry"
          />
        </label>

        <!-- WAIVER-007, WAIVER-009: a published document is an option, never
             a requirement. -->
        <label class="waivers__field">
          <span>Document being agreed to (optional)</span>
          <select v-model="createForm.document" data-testid="waiver-document">
            <option :value="NO_DOCUMENT">No document — name only</option>
            <option
              v-for="option in administration?.documents ?? []"
              :key="documentKey(option.documentType, option.documentId)"
              :value="documentKey(option.documentType, option.documentId)"
            >
              {{ option.title }} (version {{ option.version }})
            </option>
          </select>
        </label>

        <button
          type="submit"
          class="waivers__submit"
          :disabled="!canCreate"
          data-testid="waiver-submit"
        >
          {{ creating ? "Creating…" : "Create this waiver" }}
        </button>
      </form>

      <p v-if="loading" class="waivers__notice" role="status">
        Reading waivers…
      </p>

      <p
        v-else-if="waivers.length === 0 && !loadError"
        class="waivers__notice"
        role="status"
      >
        No waiver exists yet in the scopes you administer.
      </p>

      <article
        v-for="waiver in waivers"
        :key="waiver.id"
        class="waivers__waiver"
        :data-archived="waiver.archived ? 'true' : 'false'"
      >
        <header class="waivers__waiver-header">
          <h2 class="waivers__waiver-title">{{ waiver.name }}</h2>
          <StatusPill
            :label="waiver.archived ? 'Archived' : 'Active'"
            :tone="waiverTone(waiver)"
            sr-prefix="Waiver"
          />
        </header>

        <dl class="waivers__facts">
          <div class="waivers__fact">
            <dt>Applies to</dt>
            <dd>{{ waiver.scopeLabel }}</dd>
          </div>
          <div class="waivers__fact">
            <dt>Expires</dt>
            <dd>
              {{
                waiver.expiresAfterDays === null
                  ? "Never"
                  : `${waiver.expiresAfterDays} days after completion`
              }}
            </dd>
          </div>
          <div class="waivers__fact">
            <dt>Document</dt>
            <dd data-testid="waiver-document-title">
              {{
                waiver.document === null
                  ? "None — name only"
                  : `${waiver.document.title} (version ${waiver.document.version})`
              }}
            </dd>
          </div>
          <div class="waivers__fact">
            <dt>Current completions</dt>
            <dd data-testid="waiver-completions">
              {{ waiver.currentCompletionCount }}
            </dd>
          </div>
        </dl>

        <div class="waivers__waiver-actions">
          <button
            type="button"
            class="waivers__toggle"
            :aria-expanded="expandedWaiverId === waiver.id ? 'true' : 'false'"
            @click="toggleExpanded(waiver)"
          >
            {{
              expandedWaiverId === waiver.id
                ? "Hide completions"
                : "Completions and text"
            }}
          </button>

          <button
            type="button"
            class="waivers__toggle"
            :disabled="isOffline || busyWaiverId === waiver.id"
            @click="toggleArchived(waiver)"
          >
            {{ waiver.archived ? "Restore" : "Archive" }}
          </button>

          <button
            v-if="expandedWaiverId === waiver.id && !editing"
            type="button"
            class="waivers__toggle"
            :disabled="isOffline"
            data-testid="waiver-edit"
            @click="beginEdit(waiver)"
          >
            Edit
          </button>
        </div>

        <template v-if="expandedWaiverId === waiver.id">
          <form
            v-if="editing"
            class="waivers__edit"
            data-testid="waiver-edit-form"
            @submit.prevent="submitEdit(waiver)"
          >
            <label class="waivers__field">
              <span>Name</span>
              <input v-model="editForm.name" type="text" maxlength="255" />
            </label>
            <label class="waivers__field">
              <span>Description (optional)</span>
              <textarea
                v-model="editForm.description"
                rows="2"
                maxlength="2000"
              ></textarea>
            </label>
            <label class="waivers__field">
              <span>Expires after (days, blank for never)</span>
              <input
                v-model="editForm.expiresAfterDays"
                type="number"
                min="1"
                max="3650"
              />
            </label>
            <label class="waivers__field">
              <span>Document being agreed to (optional)</span>
              <select v-model="editForm.document">
                <option :value="NO_DOCUMENT">No document — name only</option>
                <option
                  v-for="option in administration?.documents ?? []"
                  :key="documentKey(option.documentType, option.documentId)"
                  :value="documentKey(option.documentType, option.documentId)"
                >
                  {{ option.title }} (version {{ option.version }})
                </option>
              </select>
            </label>
            <div class="waivers__waiver-actions">
              <button
                type="submit"
                class="waivers__submit"
                :disabled="isOffline || busyWaiverId === waiver.id"
              >
                Save changes
              </button>
              <button
                type="button"
                class="waivers__toggle"
                @click="editing = false"
              >
                Cancel
              </button>
            </div>
          </form>

          <p v-if="detailLoading" class="waivers__notice" role="status">
            Reading the roster…
          </p>

          <p v-else-if="detailError" class="waivers__error" role="alert">
            {{ detailError }}
          </p>

          <template v-else-if="detail !== null">
            <!-- WAIVER-007 / POL-022: the text being agreed to, fragments
                 inline, where completion is recorded. -->
            <section
              v-if="detail.renderedDocument !== null"
              class="waivers__document"
              data-testid="waiver-rendered-document"
            >
              <h3 class="waivers__document-title">
                {{ detail.renderedDocument.title }} (version
                {{ detail.renderedDocument.version }})
              </h3>
              <!-- eslint-disable-next-line vue/no-v-html -- the node
                   sanitizes its own render, the same trust the document
                   surfaces extend. -->
              <div
                class="waivers__document-body"
                v-html="detail.renderedDocument.renderedHtml"
              />
            </section>

            <p
              v-else-if="detail.documentUnavailableReason !== null"
              class="waivers__error"
              role="alert"
            >
              {{ detail.documentUnavailableReason }}
            </p>

            <form
              v-if="!waiver.archived"
              class="waivers__completion"
              @submit.prevent="submitCompletion(waiver)"
            >
              <label class="waivers__field">
                <span>Record a completion</span>
                <select
                  v-model="completionStaffId"
                  data-testid="completion-staff"
                >
                  <option value="">Choose a staff member</option>
                  <option
                    v-for="row in incompleteRoster"
                    :key="row.staffId"
                    :value="row.staffId"
                  >
                    {{ row.displayName
                    }}<template v-if="row.handle"> ({{ row.handle }})</template>
                  </option>
                </select>
              </label>
              <button
                type="submit"
                class="waivers__submit"
                :disabled="
                  completionStaffId === '' ||
                  isOffline ||
                  busyWaiverId === waiver.id
                "
                data-testid="completion-submit"
              >
                Record completion
              </button>
            </form>

            <ul class="waivers__roster" data-testid="waiver-roster">
              <li v-if="detail.roster.length === 0" class="waivers__row">
                Nobody is in this waiver's scope yet.
              </li>
              <li
                v-for="row in detail.roster"
                :key="row.staffId"
                class="waivers__row"
                :data-complete="row.complete ? 'true' : 'false'"
              >
                <span class="waivers__row-name">
                  {{ row.displayName
                  }}<template v-if="row.handle"> ({{ row.handle }})</template>
                </span>
                <span class="waivers__row-state">
                  <StatusPill
                    :label="rosterState(row).label"
                    :tone="rosterState(row).tone"
                    sr-prefix="Waiver completion"
                  />
                  <template v-if="row.completedAt">
                    {{ formatWaiverMoment(row.completedAt) }}
                    <template v-if="row.expiresAt">
                      — expires {{ formatWaiverMoment(row.expiresAt) }}
                    </template>
                    <template v-if="row.acknowledgedVersion">
                      — version {{ row.acknowledgedVersion }}
                    </template>
                  </template>
                </span>
              </li>
            </ul>
          </template>
        </template>
      </article>
    </template>
  </WorkflowPageShell>
</template>

<style scoped>
.waivers__restricted,
.waivers__notice,
.waivers__error {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.waivers__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.waivers__form,
.waivers__waiver {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.waivers__waiver[data-archived="true"] {
  opacity: 0.85;
}

.waivers__form-title,
.waivers__waiver-title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.waivers__waiver-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.waivers__field {
  display: grid;
  gap: var(--m-space-1);
}

.waivers__field > span {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.waivers__field input,
.waivers__field select,
.waivers__field textarea {
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
}

.waivers__facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  gap: var(--m-space-3);
  margin: 0;
}

.waivers__fact {
  display: grid;
  gap: var(--m-space-1);
}

.waivers__fact dt {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.waivers__fact dd {
  margin: 0;
}

.waivers__waiver-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.waivers__submit,
.waivers__toggle {
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  cursor: pointer;
}

.waivers__submit:disabled,
.waivers__toggle:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.waivers__edit,
.waivers__completion {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-3);
  border: 1px dashed var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.waivers__document {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.waivers__document-title {
  margin: 0;
  font-size: var(--m-text-md);
}

.waivers__roster {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.waivers__row {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--m-space-2);
  padding: var(--m-space-2);
  border-bottom: 1px solid var(--m-border-default);
}

.waivers__row-state {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-2);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}
</style>
