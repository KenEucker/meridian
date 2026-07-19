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
  checkOutLogisticsStaff,
  markLogisticsStaffOffSite,
  markLogisticsStaffOnSite,
  returnLogisticsEquipment,
  searchLogisticsDesk,
  selectLogisticsStaff,
  selectedLogisticsWorkspace,
} from "@/department-ops/logistics";
import type {
  EquipmentReturnCondition,
  LogisticsSearchHit,
} from "@/department-ops/types";

const desk = ref(LOCAL_LOGISTICS_DESK);
const query = ref("");
const hits = ref<readonly LogisticsSearchHit[]>([]);
const status = ref<string | null>(null);
const dialog = ref<"check-in" | "check-out" | null>(null);
const dialogShiftId = ref("");
const dialogTimestamp = ref(new Date().toISOString().slice(0, 16));
const selectedEquipmentIds = ref<string[]>([]);

const workspace = computed(() => selectedLogisticsWorkspace(desk.value));

function onSearch(value: string): void {
  query.value = value;
  hits.value = searchLogisticsDesk(desk.value, value);
}

function onSelect(hit: LogisticsSearchHit): void {
  status.value = null;
  dialog.value = null;

  if (hit.kind === "staff") {
    desk.value = selectLogisticsStaff(desk.value, hit.id);
    query.value = "";
    hits.value = [];
    return;
  }

  if (hit.kind === "equipment") {
    const holder = desk.value.searchableEquipment.find(
      (item) => item.equipmentItemId === hit.id,
    )?.holderName;
    const staff = desk.value.searchableStaff.find(
      (member) => member.displayName === holder,
    );
    if (staff) {
      desk.value = selectLogisticsStaff(desk.value, staff.staffId);
      status.value = `Opened workspace for holder of ${hit.label}.`;
      query.value = "";
      hits.value = [];
      return;
    }
  }

  status.value = `${hit.label} selected. Choose a staff member to continue the handoff.`;
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

function openDialog(kind: "check-in" | "check-out", shiftId: string): void {
  dialog.value = kind;
  dialogShiftId.value = shiftId;
  dialogTimestamp.value = new Date().toISOString().slice(0, 16);
  selectedEquipmentIds.value = [];
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
    } else {
      desk.value = checkOutLogisticsStaff(
        desk.value,
        workspace.value.staffId,
        dialogShiftId.value,
      );
      status.value = `${workspace.value.displayName} checked out.`;
    }
    dialog.value = null;
  } catch (error) {
    status.value =
      error instanceof Error ? error.message : "Unable to complete attendance.";
  }
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
        <h3 id="shift-cards-heading">Current / upcoming / outgoing shifts</h3>
        <ul class="logistics__cards">
          <li v-for="card in workspace.shiftCards" :key="card.shiftId">
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

      <section
        v-if="dialog"
        class="logistics__dialog"
        role="dialog"
        :aria-label="dialog === 'check-in' ? 'Check in staff' : 'Check out staff'"
      >
        <h3>{{ dialog === "check-in" ? "Check in" : "Check out" }}</h3>
        <label>
          <span>Timestamp</span>
          <input v-model="dialogTimestamp" type="datetime-local" />
        </label>
        <fieldset v-if="dialog === 'check-in' && workspace.availableEquipment.length > 0">
          <legend>Hand off equipment</legend>
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
        <div class="logistics__actions">
          <button type="button" @click="confirmDialog">Confirm</button>
          <button type="button" @click="dialog = null">Cancel</button>
        </div>
      </section>

      <section aria-labelledby="open-equipment-heading">
        <h3 id="open-equipment-heading">Equipment checked out</h3>
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

.logistics__staff-header {
  display: grid;
  gap: var(--m-space-2);
}

.logistics__staff-header h2,
.logistics__workspace h3 {
  margin: 0 0 var(--m-space-2);
}

.logistics__staff-header p {
  margin: 0;
  color: var(--m-text-muted);
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

.logistics__dialog {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 2px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
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
</style>
