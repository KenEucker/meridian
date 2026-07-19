<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import EntitySearch from "@/components/department-ops/EntitySearch.vue";
import { LOCAL_LOGISTICS_DESK } from "@/department-ops/fixtures";
import {
  attendanceStateLabel,
  equipmentStateLabel,
  formatTimestamp,
  lifecycleLabel,
  presenceStateLabel,
} from "@/department-ops/labels";
import {
  addLogisticsStaffToShift,
  checkInLogisticsStaff,
  checkOutLogisticsEquipment,
  checkOutLogisticsStaff,
  logisticsShiftSections,
  markLogisticsStaffOffSite,
  markLogisticsStaffOnSite,
  returnLogisticsEquipment,
  searchLogisticsDesk,
  selectLogisticsHit,
  selectLogisticsStaff,
  selectedLogisticsWorkspace,
} from "@/department-ops/logistics";
import type {
  EquipmentReturnCondition,
  LogisticsSearchHit,
  LogisticsStaffWorkspace,
} from "@/department-ops/types";

const desk = ref(LOCAL_LOGISTICS_DESK);
const query = ref("");
const hits = ref<readonly LogisticsSearchHit[]>([]);
const status = ref<string | null>(null);
const dialog = ref<"check-in" | "check-out" | "equipment-checkout" | null>(
  null,
);
const dialogShiftId = ref("");
const dialogTimestamp = ref(new Date().toISOString().slice(0, 16));
const selectedEquipmentIds = ref<string[]>([]);
const equipmentReturnConditions = ref<Record<string, EquipmentReturnCondition>>(
  {},
);

const workspace = computed(() => selectedLogisticsWorkspace(desk.value));
const searchContext = computed(() => desk.value.selectedSearchContext);
const searchContextStaff = computed(() => {
  const context = searchContext.value;
  if (!context) {
    return [];
  }

  return context.relatedStaffIds
    .map((staffId) => desk.value.staffWorkspaces[staffId])
    .filter((staff): staff is LogisticsStaffWorkspace => staff !== undefined);
});
const shiftSections = computed(() =>
  workspace.value ? logisticsShiftSections(workspace.value) : null,
);
const shiftSectionGroups = computed(() => [
  {
    id: "active",
    headingId: "active-shifts-heading",
    title: "Active shift",
    emptyMessage: "No active shift in the local workspace cache.",
    cards: shiftSections.value?.active ?? [],
  },
  {
    id: "upcoming",
    headingId: "upcoming-shifts-heading",
    title: "Upcoming shifts",
    emptyMessage: "No upcoming shifts in the local workspace cache.",
    cards: shiftSections.value?.upcoming ?? [],
  },
  {
    id: "outgoing",
    headingId: "outgoing-shifts-heading",
    title: "Outgoing shifts",
    emptyMessage: "No outgoing shift in the local workspace cache.",
    cards: shiftSections.value?.outgoing ?? [],
  },
]);

function onSearch(value: string): void {
  query.value = value;
  hits.value = searchLogisticsDesk(desk.value, value);
}

function onSelect(hit: LogisticsSearchHit): void {
  status.value = null;
  dialog.value = null;
  desk.value = selectLogisticsHit(desk.value, hit);
  if (hit.kind === "equipment" && workspace.value) {
    status.value = `Opened workspace for holder of ${hit.label}.`;
  } else if (hit.kind === "shift") {
    status.value = `${hit.label} selected from the department shift cache.`;
  }
  query.value = "";
  hits.value = [];
}

function openStaff(staffId: string): void {
  desk.value = selectLogisticsStaff(desk.value, staffId);
  status.value = null;
}

function markOnSite(): void {
  if (!workspace.value) return;
  try {
    desk.value = markLogisticsStaffOnSite(desk.value, workspace.value.staffId);
    status.value = `${workspace.value.displayName} marked on-site.`;
  } catch (error) {
    status.value = error instanceof Error ? error.message : "Unable to mark on-site.";
  }
}

function markOffSite(): void {
  if (!workspace.value) return;
  try {
    desk.value = markLogisticsStaffOffSite(desk.value, workspace.value.staffId);
    status.value = `${workspace.value.displayName} marked off-site.`;
  } catch (error) {
    status.value =
      error instanceof Error ? error.message : "Unable to mark off-site.";
  }
}

function openDialog(
  kind: "check-in" | "check-out" | "equipment-checkout",
  shiftId = "",
): void {
  dialog.value = kind;
  dialogShiftId.value = shiftId;
  dialogTimestamp.value = new Date().toISOString().slice(0, 16);
  selectedEquipmentIds.value = [];
  equipmentReturnConditions.value =
    kind === "check-out" && workspace.value
      ? Object.fromEntries(
          workspace.value.openEquipment
            .filter((item) => item.checkoutId !== null)
            .map((item) => [item.checkoutId, "returned"]),
        )
      : {};
}

function confirmDialog(): void {
  if (!workspace.value || !dialog.value) return;

  try {
    if (dialog.value === "check-in") {
      desk.value = checkInLogisticsStaff(
        desk.value,
        workspace.value.staffId,
        dialogShiftId.value,
        new Date(dialogTimestamp.value).toISOString(),
        selectedEquipmentIds.value,
      );
      status.value = `${workspace.value.displayName} checked in.`;
    } else if (dialog.value === "check-out") {
      let updatedDesk = checkOutLogisticsStaff(
        desk.value,
        workspace.value.staffId,
        dialogShiftId.value,
      );
      for (const [checkoutId, condition] of Object.entries(
        equipmentReturnConditions.value,
      )) {
        updatedDesk = returnLogisticsEquipment(
          updatedDesk,
          workspace.value.staffId,
          checkoutId,
          condition,
        );
      }
      desk.value = updatedDesk;
      status.value = `${workspace.value.displayName} checked out.`;
    } else {
      desk.value = checkOutLogisticsEquipment(
        desk.value,
        workspace.value.staffId,
        new Date(dialogTimestamp.value).toISOString(),
        selectedEquipmentIds.value,
      );
      status.value = `${workspace.value.displayName} equipment checked out.`;
    }
    dialog.value = null;
  } catch (error) {
    status.value =
      error instanceof Error ? error.message : "Unable to complete attendance.";
  }
}

function closeDialog(): void {
  dialog.value = null;
}

function returnItem(
  checkoutId: string,
  condition: EquipmentReturnCondition,
): void {
  if (!workspace.value) return;
  try {
    desk.value = returnLogisticsEquipment(
      desk.value,
      workspace.value.staffId,
      checkoutId,
      condition,
    );
    status.value = `Equipment marked ${equipmentStateLabel(condition)}.`;
  } catch (error) {
    status.value =
      error instanceof Error ? error.message : "Unable to update equipment.";
  }
}

function addToShift(shiftId: string): void {
  if (!workspace.value) return;
  try {
    desk.value = addLogisticsStaffToShift(
      desk.value,
      workspace.value.staffId,
      shiftId,
      `local-assignment-${workspace.value.staffId}-${shiftId}`,
    );
    status.value = `${workspace.value.displayName} added to the shift.`;
  } catch (error) {
    status.value =
      error instanceof Error ? error.message : "Unable to add staff to shift.";
  }
}
</script>

<template>
  <DeptOpsShell
    title="Logistics Desk"
    :eyebrow="desk.context.departmentLabel"
    lede="Staff-first service station for presence, attendance, and equipment handoff."
    :freshness="desk.context.dataFreshnessLabel"
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back to Home</RouterLink>
    </template>

    <EntitySearch
      :hits="hits"
      @search="onSearch"
      @select="onSelect"
    />

    <section class="logistics__cache" aria-labelledby="search-cache-heading">
      <h2 id="search-cache-heading">Offline search cache</h2>
      <p>
        {{ desk.searchCache.scopeLabel }} ·
        {{
          desk.searchCache.state === "offline_usable"
            ? "Offline usable"
            : desk.searchCache.state
        }}
      </p>
      <p>{{ desk.searchCache.note }}</p>
    </section>

    <section
      v-if="searchContext"
      class="logistics__search-context"
      aria-labelledby="search-context-heading"
    >
      <h2 id="search-context-heading">{{ searchContext.label }}</h2>
      <p>{{ searchContext.detail }}</p>
      <p v-if="searchContext.emptyReason" role="status">
        {{ searchContext.emptyReason }}
      </p>
      <div v-if="searchContextStaff.length > 0" class="logistics__actions">
        <button
          v-for="staff in searchContextStaff"
          :key="staff.staffId"
          type="button"
          @click="openStaff(staff.staffId)"
        >
          Open {{ staff.displayName }}
        </button>
      </div>
    </section>

    <p v-if="status" class="logistics__status" role="status">{{ status }}</p>

    <p v-if="!workspace" class="logistics__empty" role="status">
      Search for a staff member to open their operational workspace.
    </p>

    <section
      v-else
      class="logistics__workspace"
      aria-labelledby="staff-workspace-heading"
    >
      <header class="logistics__staff-header">
        <div>
          <h2 id="staff-workspace-heading">{{ workspace.displayName }}</h2>
          <p>
            {{ workspace.teamLabel }}
            <template v-if="workspace.handle"> · @{{ workspace.handle }}</template>
          </p>
        </div>
        <p>
          Presence:
          <strong>{{ presenceStateLabel(workspace.presenceState) }}</strong>
        </p>
      </header>

      <div class="logistics__actions">
        <button
          type="button"
          :disabled="workspace.presenceState === 'on_site'"
          @click="markOnSite"
        >
          Mark on-site
        </button>
        <button
          type="button"
          :disabled="!workspace.canGoOffSite"
          @click="markOffSite"
        >
          Mark off-site
        </button>
      </div>
      <p
        v-if="workspace.offSiteBlockedReason"
        class="logistics__note"
        role="status"
      >
        {{ workspace.offSiteBlockedReason }}
      </p>

      <section aria-labelledby="shift-cards-heading">
        <h3 id="shift-cards-heading">Shift context</h3>
        <section
          v-for="section in shiftSectionGroups"
          :key="section.id"
          class="logistics__shift-section"
          :aria-labelledby="section.headingId"
        >
          <h4 :id="section.headingId">{{ section.title }}</h4>
          <p v-if="section.cards.length === 0" role="status">
            {{ section.emptyMessage }}
          </p>
          <ul v-else class="logistics__cards">
            <li
              v-for="card in section.cards"
              :key="`${section.id}-${card.shiftId}`"
            >
              <div>
                <strong>{{ card.title }}</strong>
                <span>
                  {{ lifecycleLabel(card.lifecycle) }} ·
                  {{
                    card.attendanceState
                      ? attendanceStateLabel(card.attendanceState)
                      : "Not assigned"
                  }}
                </span>
                <span>
                  {{ formatTimestamp(card.startsAt, desk.context.timeZone) }} -
                  {{ formatTimestamp(card.endsAt, desk.context.timeZone) }}
                </span>
              </div>
              <div class="logistics__actions">
                <button
                  v-if="card.canCheckIn"
                  type="button"
                  @click="openDialog('check-in', card.shiftId)"
                >
                  Check in
                </button>
                <button
                  v-if="card.canCheckOut"
                  type="button"
                  @click="openDialog('check-out', card.shiftId)"
                >
                  Check out
                </button>
                <button
                  v-if="card.canAddToShift"
                  type="button"
                  @click="addToShift(card.shiftId)"
                >
                  Add to shift
                </button>
              </div>
            </li>
          </ul>
        </section>
      </section>

      <section aria-labelledby="open-equipment-heading">
        <h3 id="open-equipment-heading">Equipment checked out</h3>
        <div
          v-if="workspace.availableEquipment.length > 0"
          class="logistics__actions"
        >
          <button type="button" @click="openDialog('equipment-checkout')">
            Check out equipment
          </button>
        </div>
        <p v-if="workspace.openEquipment.length === 0" role="status">
          No open equipment for this staff member.
        </p>
        <ul v-else class="logistics__cards">
          <li
            v-for="item in workspace.openEquipment"
            :key="item.checkoutId ?? item.equipmentItemId"
          >
            <div>
              <strong>{{ item.name }}</strong>
              <span>{{ equipmentStateLabel(item.status) }}</span>
            </div>
            <div class="logistics__actions">
              <button
                type="button"
                @click="returnItem(item.checkoutId!, 'returned')"
              >
                Returned
              </button>
              <button
                type="button"
                @click="returnItem(item.checkoutId!, 'missing')"
              >
                Missing
              </button>
              <button
                type="button"
                @click="returnItem(item.checkoutId!, 'damaged')"
              >
                Damaged
              </button>
            </div>
          </li>
        </ul>
      </section>

      <section aria-labelledby="provisions-heading">
        <h3 id="provisions-heading">Provisions</h3>
        <p class="logistics__note" role="status">
          {{ workspace.provisionsExtensionNote }}
        </p>
      </section>

      <section aria-labelledby="future-signups-heading">
        <h3 id="future-signups-heading">Future shift signups</h3>
        <p v-if="workspace.futureSignups.length === 0" role="status">
          No future signups for this staff member.
        </p>
        <ul v-else class="logistics__list">
          <li
            v-for="signup in workspace.futureSignups"
            :key="signup.signupId"
          >
            {{ signup.shiftTitle }} ·
            {{ formatTimestamp(signup.startsAt, desk.context.timeZone) }}
          </li>
        </ul>
      </section>
    </section>

    <div
      v-if="dialog && workspace"
      class="logistics__modal-backdrop"
      @click.self="closeDialog"
      @keydown.esc="closeDialog"
    >
      <section
        class="logistics__dialog"
        role="dialog"
        aria-modal="true"
        tabindex="-1"
        :aria-labelledby="'attendance-dialog-heading'"
      >
        <h2 id="attendance-dialog-heading">
          {{
            dialog === "check-in"
              ? "Check in"
              : dialog === "check-out"
                ? "Check out"
                : "Check out equipment"
          }}
        </h2>
        <label>
          <span>Timestamp</span>
          <input v-model="dialogTimestamp" type="datetime-local" />
        </label>
        <fieldset
          v-if="
            (dialog === 'check-in' || dialog === 'equipment-checkout') &&
            workspace.availableEquipment.length > 0
          "
        >
          <legend>
            {{ dialog === "check-in" ? "Hand off equipment" : "Available equipment" }}
          </legend>
          <label
            v-for="item in workspace.availableEquipment"
            :key="item.equipmentItemId"
            class="logistics__check"
          >
            <input
              v-model="selectedEquipmentIds"
              type="checkbox"
              :value="item.equipmentItemId"
            />
            <span>
              {{ item.name }}
              <template v-if="item.assetTag">({{ item.assetTag }})</template>
            </span>
          </label>
        </fieldset>
        <fieldset
          v-if="dialog === 'check-out' && workspace.openEquipment.length > 0"
        >
          <legend>Return equipment</legend>
          <div
            v-for="item in workspace.openEquipment"
            :key="item.checkoutId ?? item.equipmentItemId"
            class="logistics__return-item"
          >
            <p>
              <strong>{{ item.name }}</strong>
              <template v-if="item.assetTag">({{ item.assetTag }})</template>
            </p>
            <label
              v-for="condition in (['returned', 'missing', 'damaged'] as const)"
              :key="`${item.checkoutId}-${condition}`"
              class="logistics__check"
            >
              <input
                v-model="equipmentReturnConditions[item.checkoutId!]"
                type="radio"
                :name="`return-${item.checkoutId}`"
                :value="condition"
              />
              <span>{{ equipmentStateLabel(condition) }}</span>
            </label>
          </div>
        </fieldset>
        <div class="logistics__actions">
          <button type="button" @click="confirmDialog">Confirm</button>
          <button type="button" @click="closeDialog">Cancel</button>
        </div>
      </section>
    </div>
  </DeptOpsShell>
</template>

<style scoped>
.logistics__status,
.logistics__empty,
.logistics__note {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.logistics__workspace {
  display: grid;
  gap: var(--m-space-5);
}

.logistics__cache,
.logistics__search-context {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-5);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.logistics__cache h2,
.logistics__search-context h2 {
  margin: 0;
  font-size: var(--m-text-base);
}

.logistics__cache p,
.logistics__search-context p {
  margin: 0;
  color: var(--m-text-muted);
}

.logistics__staff-header {
  display: grid;
  gap: var(--m-space-2);
}

.logistics__staff-header h2,
.logistics__workspace h3,
.logistics__workspace h4 {
  margin: 0 0 var(--m-space-2);
}

.logistics__workspace h4 {
  font-size: var(--m-text-base);
}

.logistics__staff-header p {
  margin: 0;
  color: var(--m-text-muted);
}

.logistics__shift-section + .logistics__shift-section {
  margin-top: var(--m-space-4);
}

.logistics__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.logistics__actions button,
.logistics__dialog button {
  min-height: 2.5rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

.logistics__actions button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.logistics__actions button:focus-visible,
.logistics__dialog button:focus-visible,
.logistics__dialog input:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.logistics__cards,
.logistics__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: var(--m-space-2);
}

.logistics__cards li,
.logistics__list li {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.logistics__cards li span {
  display: block;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.logistics__modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 20;
  display: grid;
  place-items: center;
  padding: var(--m-space-4);
  background: rgb(17 24 39 / 0.48);
}

.logistics__dialog {
  width: min(100%, 34rem);
  max-height: min(90vh, 38rem);
  overflow: auto;
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 2px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  box-shadow: 0 1.5rem 4rem rgb(17 24 39 / 0.24);
}

.logistics__dialog h2 {
  margin: 0;
  font-size: var(--m-text-lg);
}

.logistics__dialog label,
.logistics__check {
  display: grid;
  gap: var(--m-space-2);
}

.logistics__dialog input[type="datetime-local"] {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font: inherit;
}

.logistics__check {
  grid-template-columns: auto 1fr;
  align-items: center;
}

.logistics__return-item {
  display: grid;
  gap: var(--m-space-2);
}

.logistics__return-item + .logistics__return-item {
  margin-top: var(--m-space-3);
}

.logistics__return-item p {
  margin: 0;
}
</style>
