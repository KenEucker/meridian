<script setup lang="ts">
import { computed, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import ControlBar from "@/components/ControlBar.vue";
import ControlField from "@/components/ControlField.vue";
import StatusPill, { type StatusPillTone } from "@/components/StatusPill.vue";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import {
  formatRecordedHours,
  listEventCredentials,
  revokeEventCredential,
  type EventCredentialRow,
} from "@/credentials/eventCredentialAdminModel";
import { useLocalNodeReachable } from "@/offline/useConnectivity";

/**
 * Event credential administration (M18.5; CRED-009 through CRED-014; UI
 * contract 12.6).
 *
 * The domain behind this has been complete and unreachable since M12.4:
 * `CredentialRevocationService` removes future shifts, leaves completed shifts
 * and recorded hours alone, and refuses anybody who is not an organizer or an
 * Incident Command lead — and until this task the only way to call it was a
 * `tinker` session. This section is the path to it.
 *
 * Three things the surface does rather than the node, and each is presentation
 * of an answer the node already gave:
 *
 *  1. **It states the cost before the act.** Every row carries the future
 *     shifts revocation would remove and the completed shifts and recorded
 *     hours it would leave standing, counted by the node with the same rule it
 *     applies when it acts (CRED-012, CRED-013). The confirmation repeats those
 *     numbers for the person in front of it, because "revoke" on its own does
 *     not say that somebody's next four shifts are about to disappear from a
 *     schedule other people are working around.
 *  2. **It does not hide what already happened.** Revoked rows stay in the
 *     list. An organizer reviewing whether a decision was right is looking for
 *     exactly the row a tidier list would have removed.
 *  3. **It filters, it does not paginate.** An event's credential list is one
 *     row per person working the event, and the way somebody arrives here is
 *     with a name in mind. The filter is over what the node already sent, so it
 *     costs no request and works with the node unreachable.
 *
 * Revocation is refused on a device with no network rather than queued: it
 * removes shifts from a roster other people are being scheduled against, and a
 * copy of that decision sitting on a laptop is an event still planning around
 * somebody who was removed from it hours ago.
 */
const props = withDefaults(
  defineProps<{
    eventId: string | null;
    eventLabel?: string | null;
    variant?: "page" | "section";
  }>(),
  { eventLabel: null, variant: "section" },
);

const nodeReachable = useLocalNodeReachable();

const credentials = ref<readonly EventCredentialRow[]>([]);
const loading = ref(false);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const notice = ref<string | null>(null);
const filter = ref("");
const pendingStaffId = ref<string | null>(null);
const revokeReason = ref("");
const busyStaffId = ref<string | null>(null);

const isOffline = computed(() => !nodeReachable.value);

const visibleCredentials = computed(() => {
  const needle = filter.value.trim().toLowerCase();

  if (needle === "") {
    return credentials.value;
  }

  return credentials.value.filter((row) =>
    [row.displayName, row.legalName, row.handle]
      .filter((value): value is string => typeof value === "string")
      .some((value) => value.toLowerCase().includes(needle)),
  );
});

/** The row whose confirmation is open, if any. */
const pending = computed(
  () =>
    credentials.value.find((row) => row.staffId === pendingStaffId.value) ?? null,
);

watch(() => props.eventId, () => void loadCredentials(), { immediate: true });

async function loadCredentials(): Promise<void> {
  if (props.eventId === null) {
    credentials.value = [];

    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    const list = await listEventCredentials(props.eventId);

    credentials.value = list.credentials;
  } catch (error) {
    credentials.value = [];
    loadError.value = meridianErrorMessage(
      error,
      "Unable to read this event's credentials. Check the connection to this node and try again.",
    );
  } finally {
    loading.value = false;
  }
}

function startRevoke(row: EventCredentialRow): void {
  pendingStaffId.value = row.staffId;
  revokeReason.value = "";
  actionError.value = null;
  notice.value = null;
}

function cancelRevoke(): void {
  pendingStaffId.value = null;
  revokeReason.value = "";
}

/**
 * Revoke, then put what came back where the old row was.
 *
 * Replaced in place rather than re-read: the node answers with the whole row
 * rebuilt, so the surface already holds what is now true, and a second request
 * would be a chance for the screen to say something the command did not.
 */
async function confirmRevoke(): Promise<void> {
  const row = pending.value;
  const eventId = props.eventId;

  if (row === null || eventId === null) {
    return;
  }

  actionError.value = null;
  notice.value = null;
  busyStaffId.value = row.staffId;

  try {
    const revoked = await revokeEventCredential(
      eventId,
      row.staffId,
      revokeReason.value,
    );

    if (revoked !== null) {
      credentials.value = credentials.value.map((candidate) =>
        candidate.staffId === revoked.staffId ? revoked : candidate,
      );
    } else {
      await loadCredentials();
    }

    notice.value = `${row.displayName} no longer holds a credential for this event.`;
    cancelRevoke();
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to revoke this credential.",
    );
  } finally {
    busyStaffId.value = null;
  }
}

/** The credential state, in the words CRED-009 names it by. */
function statusLabel(row: EventCredentialRow): string {
  switch (row.status) {
    case "eligible":
      return "Eligible";
    case "blocked":
      return "Blocked";
    case "revoked":
      return "Revoked";
    default:
      // Not the same as Blocked, and not presented as though it were: the
      // domain has recorded nothing for this person yet.
      return "No credential";
  }
}

function statusTone(row: EventCredentialRow): StatusPillTone {
  switch (row.status) {
    case "eligible":
      return "positive";
    case "blocked":
      return "caution";
    case "revoked":
      return "critical";
    default:
      return "neutral";
  }
}

/** What revocation would take, said as shifts rather than as a count. */
function futureShiftText(row: EventCredentialRow): string {
  if (row.futureShiftCount === 0) {
    return "No upcoming shifts to remove";
  }

  return row.futureShiftCount === 1
    ? "1 upcoming shift would be removed"
    : `${row.futureShiftCount} upcoming shifts would be removed`;
}

/** What it would leave alone (CRED-013). */
function preservedText(row: EventCredentialRow): string {
  if (row.completedShiftCount === 0 && row.recordedMinutes === 0) {
    return "There is no completed work to preserve";
  }

  const shifts =
    row.completedShiftCount === 1
      ? "1 completed shift"
      : `${row.completedShiftCount} completed shifts`;

  const hours =
    row.recordedMinutes === 0
      ? "no recorded hours"
      : `${formatRecordedHours(row.recordedMinutes)} recorded`;

  return `${shifts} and ${hours} stay on the record`;
}

/**
 * The sentence the confirmation opens with.
 *
 * Written out per case rather than templated, because "removes Wren from 0
 * upcoming shifts" is the kind of sentence that makes an operator wonder
 * whether the screen knows what it is about to do.
 */
function confirmLede(row: EventCredentialRow): string {
  if (row.futureShiftCount === 0) {
    return `${row.displayName} has no upcoming shifts to remove. ${preservedText(row)}.`;
  }

  const shifts =
    row.futureShiftCount === 1
      ? "1 upcoming shift"
      : `${row.futureShiftCount} upcoming shifts`;

  return `Revoking removes ${row.displayName} from ${shifts}. ${preservedText(row)}.`;
}
</script>

<template>
  <WorkflowSection
    class="credential-admin"
    title="Event credentials"
    heading-id="event-credentials-heading"
    :description="
      props.eventLabel
        ? `Who holds a credential for ${props.eventLabel}, and who no longer does.`
        : 'Who holds a credential for this event, and who no longer does.'
    "
    :variant="props.variant"
  >
    <ControlBar label="Find a staff member">
      <ControlField
        label="Filter by name or handle"
        control-id="credential-filter"
        width="grow"
      >
        <input
          id="credential-filter"
          v-model="filter"
          type="search"
          autocomplete="off"
        />
      </ControlField>
    </ControlBar>

    <p v-if="isOffline" class="credential-admin__hint" role="status">
      Revoking a credential removes shifts other people are being scheduled
      around, so it needs a connection to the node. It is not held on this device
      for later.
    </p>

    <p v-if="loadError" class="credential-admin__error" role="alert">
      {{ loadError }}
    </p>
    <p v-if="actionError" class="credential-admin__error" role="alert">
      {{ actionError }}
    </p>
    <p v-if="notice" class="credential-admin__notice" role="status">
      {{ notice }}
    </p>

    <p
      v-if="loading && credentials.length === 0"
      class="credential-admin__empty"
      role="status"
    >
      Loading credentials.
    </p>

    <template v-else>
      <!--
        A failed read must not also claim the event has nobody credentialed.
        The two look identical on screen and are opposite problems.
      -->
      <p v-if="loadError" class="credential-admin__empty">
        This event's credentials could not be read.
      </p>

      <p v-else-if="credentials.length === 0" class="credential-admin__empty">
        Nobody holds a credential for this event yet. A credential follows a
        signed-up shift, so this fills in as the schedule does.
      </p>

      <p
        v-else-if="visibleCredentials.length === 0"
        class="credential-admin__empty"
      >
        No credential matches “{{ filter.trim() }}”.
      </p>

      <ul v-else class="credential-admin__list">
        <li v-for="row in visibleCredentials" :key="row.staffId">
          <div class="credential-admin__identity">
            <span class="credential-admin__name">{{ row.displayName }}</span>
            <span v-if="row.handle" class="credential-admin__handle">
              {{ row.handle }}
            </span>
            <span
              v-if="row.departments.length > 0"
              class="credential-admin__departments"
            >
              {{ row.departments.join(", ") }}
            </span>
          </div>

          <div class="credential-admin__state">
            <StatusPill
              :label="statusLabel(row)"
              :tone="statusTone(row)"
              sr-prefix="Credential"
            />
            <span v-if="row.statusReasonLabel" class="credential-admin__reason">
              {{ row.statusReasonLabel }}
            </span>
          </div>

          <p class="credential-admin__cost">
            <span>{{ futureShiftText(row) }}.</span>
            <span>{{ preservedText(row) }}.</span>
          </p>

          <!--
            Absent, not disabled, where there is nothing to revoke (CLIENT-005),
            and the reason stands in its place so a reader is not left to work
            out why the control they expected is missing.
          -->
          <button
            v-if="row.canRevoke"
            type="button"
            class="credential-admin__revoke"
            :disabled="isOffline || busyStaffId === row.staffId"
            :aria-label="`Revoke the credential for ${row.displayName}`"
            @click="startRevoke(row)"
          >
            Revoke credential
          </button>
          <span v-else class="credential-admin__blocked">
            {{ row.revokeBlockedReason ?? "This credential cannot be revoked." }}
          </span>

          <form
            v-if="pendingStaffId === row.staffId"
            class="credential-admin__confirm"
            :aria-label="`Revoke the credential for ${row.displayName}`"
            @submit.prevent="confirmRevoke"
          >
            <p class="credential-admin__confirm-lede">
              {{ confirmLede(row) }}
            </p>

            <ControlField
              label="Reason (optional)"
              :control-id="`revoke-reason-${row.staffId}`"
              width="grow"
            >
              <textarea
                :id="`revoke-reason-${row.staffId}`"
                v-model="revokeReason"
                rows="2"
                maxlength="500"
              />
            </ControlField>

            <p class="credential-admin__confirm-note">
              The reason is recorded in the audit log alongside who revoked the
              credential and when.
            </p>

            <div class="credential-admin__confirm-actions">
              <button
                type="submit"
                :disabled="isOffline || busyStaffId === row.staffId"
              >
                {{
                  busyStaffId === row.staffId ? "Revoking…" : "Confirm revocation"
                }}
              </button>
              <button type="button" @click="cancelRevoke">Cancel</button>
            </div>
          </form>
        </li>
      </ul>
    </template>
  </WorkflowSection>
</template>

<style scoped>
.credential-admin__list {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.credential-admin__list li {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) auto;
  gap: var(--m-space-2) var(--m-space-3);
  align-items: center;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.credential-admin__identity {
  display: grid;
  gap: 0.125rem;
  min-width: 0;
}

.credential-admin__name {
  font-weight: 700;
}

.credential-admin__handle,
.credential-admin__departments {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.credential-admin__state {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: center;
}

.credential-admin__reason {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.credential-admin__cost {
  grid-column: 1 / -1;
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-1) var(--m-space-3);
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.credential-admin__revoke {
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.credential-admin__revoke:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.credential-admin__blocked {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.credential-admin__confirm {
  grid-column: 1 / -1;
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.credential-admin__confirm-lede {
  margin: 0;
  font-weight: 600;
}

.credential-admin__confirm-note {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.credential-admin__confirm-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.credential-admin__empty,
.credential-admin__hint,
.credential-admin__notice {
  margin: 0;
  color: var(--m-text-muted);
}

.credential-admin__error {
  margin: 0;
  color: var(--m-attention-critical);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

@media (max-width: 48rem) {
  .credential-admin__list li {
    grid-template-columns: 1fr;
  }
}
</style>
