<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import EntitySearch from "@/components/department-ops/EntitySearch.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import {
  LOCAL_DEPARTMENT_OVERVIEW,
  LOCAL_LOGISTICS_DESK,
} from "@/department-ops/fixtures";
import {
  attendanceStateLabel,
  equipmentStateLabel,
  formatTimestamp,
  lifecycleLabel,
  presenceStateLabel,
} from "@/department-ops/labels";
import {
  CURRENT_SHIFT_WINDOW_MINUTES,
  addLogisticsStaffToShift,
  checkInLogisticsStaff,
  checkOutLogisticsEquipment,
  checkOutLogisticsStaff,
  currentLogisticsShifts,
  logisticsShiftSections,
  markLogisticsStaffOffSite,
  markLogisticsStaffOnSite,
  returnLogisticsEquipment,
  searchLogisticsDesk,
  selectLogisticsHit,
  selectLogisticsStaff,
  selectedLogisticsWorkspace,
} from "@/department-ops/logistics";
import { selectedFixtureDepartment } from "@/department-teams/fixtureDepartmentAccess";
import type {
  EquipmentReturnCondition,
  LogisticsSearchHit,
  LogisticsStaffWorkspace,
  ShiftOption,
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
const currentShifts = computed(() => currentLogisticsShifts(desk.value));
const logisticsSummary = computed(() => ({
  onSite: desk.value.searchableStaff.filter(
    (staff) => staff.presenceState === "on_site",
  ).length,
  offSite: desk.value.searchableStaff.filter(
    (staff) => staff.presenceState === "off_site",
  ).length,
  equipmentOut: desk.value.searchableEquipment.filter(
    (item) => item.status === "checked_out",
  ).length,
  currentShifts: currentShifts.value.length,
}));
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
const selectedShift = computed(() => {
  const context = searchContext.value;
  if (context?.kind !== "shift") {
    return null;
  }

  return (
    desk.value.searchableShifts.find((shift) => shift.shiftId === context.id) ??
    null
  );
});
const selectedShiftStaff = computed(() => {
  const shift = selectedShift.value;

  if (!shift) {
    return [];
  }

  const scheduled = new Map<
    string,
    {
      readonly staffId: string;
      readonly displayName: string;
      readonly teamLabel: string;
      readonly attendanceLabel: string;
      readonly shiftTitle: string;
      readonly startsAt: string;
      readonly endsAt: string;
      readonly canOpen: boolean;
    }
  >();

  for (const workspace of Object.values(desk.value.staffWorkspaces)) {
    for (const card of workspace.shiftCards) {
      if (
        card.attendanceState === null ||
        !shiftsOverlap(card, shift)
      ) {
        continue;
      }

      scheduled.set(workspace.staffId, {
        staffId: workspace.staffId,
        displayName: workspace.displayName,
        teamLabel: workspace.teamLabel,
        attendanceLabel: attendanceStateLabel(card.attendanceState),
        shiftTitle: card.title,
        startsAt: card.startsAt,
        endsAt: card.endsAt,
        canOpen: true,
      });
    }
  }

  if (shift.shiftId === LOCAL_DEPARTMENT_OVERVIEW.selectedShiftId) {
    for (const assignment of LOCAL_DEPARTMENT_OVERVIEW.assignments) {
      if (scheduled.has(assignment.staffId)) {
        continue;
      }

      scheduled.set(assignment.staffId, {
        staffId: assignment.staffId,
        displayName: assignment.displayName,
        teamLabel: assignment.teamLabel,
        attendanceLabel: attendanceStateLabel(assignment.attendanceState),
        shiftTitle: shift.title,
        startsAt: shift.startsAt,
        endsAt: shift.endsAt,
        canOpen: assignment.staffId in desk.value.staffWorkspaces,
      });
    }
  }

  return [...scheduled.values()].sort((left, right) => {
    const startsAt = left.startsAt.localeCompare(right.startsAt);
    return startsAt === 0
      ? left.displayName.localeCompare(right.displayName)
      : startsAt;
  });
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
const canManageLogisticsCatalog = computed(() => {
  const department = selectedFixtureDepartment.value;

  return (
    department.isDepartmentLead ||
    department.teams.some(
      (team) =>
        team.isTeamLead &&
        /logistics/i.test(`${team.teamLabel} ${team.teamCode}`),
    )
  );
});

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

function onSelectCurrentShift(shift: ShiftOption): void {
  onSelect({
    id: shift.shiftId,
    kind: "shift",
    label: shift.title,
    detail: `${shift.teamLabel} / ${lifecycleLabel(shift.lifecycle)}`,
  });
}

function shiftsOverlap(
  left: Pick<ShiftOption, "startsAt" | "endsAt">,
  right: Pick<ShiftOption, "startsAt" | "endsAt">,
): boolean {
  const leftStartsAt = Date.parse(left.startsAt);
  const leftEndsAt = Date.parse(left.endsAt);
  const rightStartsAt = Date.parse(right.startsAt);
  const rightEndsAt = Date.parse(right.endsAt);

  if (
    Number.isNaN(leftStartsAt) ||
    Number.isNaN(leftEndsAt) ||
    Number.isNaN(rightStartsAt) ||
    Number.isNaN(rightEndsAt)
  ) {
    return false;
  }

  return leftStartsAt < rightEndsAt && leftEndsAt > rightStartsAt;
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

function onAddStaff(): void {
  status.value =
    "Add Staff is available to Logistics leads; the create workflow is scaffolded.";
}

function onAddEquipment(): void {
  status.value =
    "Add Equipment is available to Logistics leads; the create workflow is scaffolded.";
}
</script>

<template>
  <DeptOpsShell
    title="Logistics Window"
    :eyebrow="desk.context.departmentLabel"
    lede="Staff-first service station for presence, attendance, and equipment handoff."
    :freshness="desk.context.dataFreshnessLabel"
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <template #actions>
      <div v-if="canManageLogisticsCatalog" class="logistics__heading-actions">
        <WorkflowActionButton @click="onAddStaff">Add Staff</WorkflowActionButton>
        <WorkflowActionButton @click="onAddEquipment">
          Add Equipment
        </WorkflowActionButton>
      </div>
    </template>

    <section
      class="logistics__current-shifts"
      aria-labelledby="current-shifts-heading"
    >
      <h2 id="current-shifts-heading">Current shifts</h2>
      <p class="logistics__current-shifts-lede">
        Shifts underway now, plus any that ended within the last
        {{ CURRENT_SHIFT_WINDOW_MINUTES }} minutes or start within the next
        {{ CURRENT_SHIFT_WINDOW_MINUTES }} minutes.
      </p>
      <p
        v-if="currentShifts.length === 0"
        class="logistics__note"
        role="status"
      >
        No shifts are currently going for this department.
      </p>
      <ul v-else class="logistics__current-shift-list">
        <li v-for="shift in currentShifts" :key="shift.shiftId">
          <button
            type="button"
            class="logistics__current-shift"
            @click="onSelectCurrentShift(shift)"
          >
            <span class="logistics__current-shift-title">{{ shift.title }}</span>
            <span class="logistics__current-shift-meta">
              {{ shift.teamLabel }} /
              {{ formatTimestamp(shift.startsAt, desk.context.timeZone) }} -
              {{ formatTimestamp(shift.endsAt, desk.context.timeZone) }}
            </span>
          </button>
        </li>
      </ul>
    </section>

    <section class="logistics__watch-grid" aria-label="Attendance watch">
      <div>
        <h2>Current</h2>
        <p>
          {{
            currentShifts.length > 0
              ? `${currentShifts.length} current shift window in view.`
              : "No shifts are currently going for this department."
          }}
        </p>
      </div>
      <div>
        <h2>Oustanding</h2>
        <p>
          {{
            logisticsSummary.equipmentOut > 0
              ? `${logisticsSummary.equipmentOut} equipment handoff still open.`
              : "No open equipment handoffs."
          }}
        </p>
      </div>
    </section>

    <dl class="logistics__stats" aria-label="Logistics summary">
      <div>
        <dt>On site</dt>
        <dd>{{ logisticsSummary.onSite }}</dd>
      </div>
      <div>
        <dt>Off site</dt>
        <dd>{{ logisticsSummary.offSite }}</dd>
      </div>
      <div>
        <dt>Equipment out</dt>
        <dd>{{ logisticsSummary.equipmentOut }}</dd>
      </div>
      <div>
        <dt>Current shifts</dt>
        <dd>{{ logisticsSummary.currentShifts }}</dd>
      </div>
    </dl>

    <EntitySearch
      :hits="hits"
      @search="onSearch"
      @select="onSelect"
    />

    <section class="logistics__cache" aria-labelledby="search-cache-heading">
      <h2 id="search-cache-heading">Offline search cache</h2>
      <p>
        {{ desk.searchCache.scopeLabel }} /
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
      <div
        v-if="searchContext.kind !== 'shift' && searchContextStaff.length > 0"
        class="logistics__actions"
      >
        <button
          v-for="staff in searchContextStaff"
          :key="staff.staffId"
          type="button"
          @click="openStaff(staff.staffId)"
        >
          Open {{ staff.displayName }}
        </button>
      </div>
      <section
        v-if="selectedShift"
        class="logistics__shift-drilldown"
        aria-labelledby="selected-shift-staff-heading"
      >
        <h3 id="selected-shift-staff-heading">Scheduled staff</h3>
        <p>
          Staff scheduled in or overlapping the selected shift window.
        </p>
        <p
          v-if="selectedShiftStaff.length === 0"
          class="logistics__note"
          role="status"
        >
          No scheduled staff overlap this shift in the local cache.
        </p>
        <ul v-else class="logistics__scheduled-staff">
          <li v-for="member in selectedShiftStaff" :key="member.staffId">
            <div>
              <strong>{{ member.displayName }}</strong>
              <span>
                {{ member.teamLabel }} -
                {{ member.attendanceLabel }}
              </span>
              <span>
                {{ member.shiftTitle }} -
                {{ formatTimestamp(member.startsAt, desk.context.timeZone) }}
                to
                {{ formatTimestamp(member.endsAt, desk.context.timeZone) }}
              </span>
            </div>
            <button
              v-if="member.canOpen"
              type="button"
              @click="openStaff(member.staffId)"
            >
              Open {{ member.displayName }}
            </button>
            <span v-else class="logistics__unavailable-workspace">
              Workspace pending
            </span>
          </li>
        </ul>
      </section>
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
            <template v-if="workspace.handle"> / @{{ workspace.handle }}</template>
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
                  {{ lifecycleLabel(card.lifecycle) }} /
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
            {{ signup.shiftTitle }} /
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

.logistics__toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
}

.logistics__heading-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.logistics__toolbar button {
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 900;
  cursor: pointer;
}

.logistics__toolbar button:first-child,
.logistics__toolbar button:nth-child(2) {
  border-color: var(--m-action-primary-bg);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.logistics__current-shifts {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-5);
}

.logistics__current-shifts h2 {
  margin: 0;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  letter-spacing: 0;
  text-transform: uppercase;
}

.logistics__current-shifts-lede,
.logistics__current-shifts .logistics__note {
  margin: 0;
  color: var(--m-text-muted);
}

.logistics__current-shift-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: var(--m-space-2);
}

.logistics__current-shift {
  display: grid;
  gap: var(--m-space-1);
  width: 100%;
  min-height: 2.75rem;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.logistics__current-shift-title {
  font-weight: 600;
}

.logistics__current-shift-meta {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.logistics__workspace {
  display: grid;
  gap: var(--m-space-5);
}

.logistics__watch-grid,
.logistics__stats {
  display: grid;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
}

.logistics__watch-grid div,
.logistics__stats div {
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.logistics__watch-grid h2,
.logistics__stats dt {
  margin: 0 0 var(--m-space-2);
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 900;
  letter-spacing: 0;
  text-transform: uppercase;
}

.logistics__watch-grid p,
.logistics__stats dd {
  margin: 0;
}

.logistics__stats dd {
  color: var(--m-text-primary);
  font-size: var(--m-text-xl);
  font-weight: 900;
}

.logistics__cache,
.logistics__search-context {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-5);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
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

.logistics__shift-drilldown {
  display: grid;
  gap: var(--m-space-3);
  margin-top: var(--m-space-3);
  padding-top: var(--m-space-3);
  border-top: 1px solid var(--m-border-subtle);
}

.logistics__shift-drilldown h3 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-base);
}

.logistics__scheduled-staff {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.logistics__scheduled-staff li {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
}

.logistics__scheduled-staff li > div {
  display: grid;
  gap: var(--m-space-1);
}

.logistics__scheduled-staff strong {
  color: var(--m-text-primary);
}

.logistics__scheduled-staff span {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.logistics__scheduled-staff button {
  min-height: 2.5rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 800;
  cursor: pointer;
}

.logistics__unavailable-workspace {
  align-self: center;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.logistics__staff-header {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
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
  border-radius: 6px;
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
.logistics__scheduled-staff button:focus-visible,
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
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
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
  background: color-mix(
    in srgb,
    var(--m-status-restricted) 48%,
    transparent
  );
}

.logistics__dialog {
  width: min(100%, 34rem);
  max-height: min(90vh, 38rem);
  overflow: auto;
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 2px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-overlay);
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
  border-radius: 6px;
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

@media (min-width: 48rem) {
  .logistics__watch-grid,
  .logistics__stats {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }

  .logistics__watch-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .logistics__scheduled-staff li {
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
  }
}
</style>
