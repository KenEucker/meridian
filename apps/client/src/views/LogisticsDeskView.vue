<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import EntitySearch from "@/components/department-ops/EntitySearch.vue";
import StatusPill from "@/components/StatusPill.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  attendanceStateLabel,
  attendanceTone,
  equipmentStateLabel,
  equipmentTone,
  formatTimestamp,
  lifecycleLabel,
  lifecycleTone,
  presenceStateLabel,
  presenceTone,
} from "@/department-ops/labels";
import {
  CURRENT_SHIFT_WINDOW_MINUTES,
  addStaffToShift,
  checkoutEquipment,
  correctHours,
  currentLogisticsShifts,
  logisticsShiftSections,
  logisticsStaffOnShift,
  logisticsStaffStates,
  logisticsStatePills,
  queueCheckIn,
  queueCheckOut,
  queueMarkNoShow,
  readLogisticsDesk,
  returnEquipment,
  searchLogisticsDesk,
  setDepartmentPresence,
  type DepartmentOpsShift,
  type LogisticsDeskRead,
  type LogisticsSearchHit,
  type LogisticsShiftCard,
} from "@/department-ops/departmentOpsReadModel";
import type { EquipmentReturnCondition } from "@/department-ops/types";

/**
 * The Logistics Window (SLB-003 through SLB-008, SLB-011, SLB-012, SLB-016
 * through SLB-018, SLB-021; bound to the node in M16.21).
 *
 * The desk reads its whole department index in one request and writes through
 * the node from then on. Everything this page used to decide for itself — who
 * may go off-site, what a check-in does to a shift card, which equipment moves
 * to whose hands, which shift somebody may be added to — arrives decided, and
 * every write is followed by a re-read, because a command answers with the
 * record it changed and not with what that change did to the rest of the screen.
 *
 * Attendance is queued and the rest is not. Check-in, check-out, and no-show are
 * Alpha 1 offline writes (data/API 7.2) and go into the command outbox, so a
 * desk with no node keeps working; presence, shift additions, and equipment
 * handoff are refused where they stand rather than held (CLIENT-018).
 *
 * The index behind the search is durable from M18.8, which is the other half of
 * "a desk with no node keeps working": the read is stored on the device and the
 * desk opens on it when the node cannot be reached, so somebody can still be
 * looked up and checked in (SLB-021). It says which it is showing. A stale index
 * presented as current would have an operator reading yesterday's presence off a
 * screen that gave them no reason to doubt it.
 */
/**
 * A `datetime-local` value for a moment, in the reader's own clock.
 *
 * Built from local components rather than from `toISOString().slice(0, 16)`,
 * which is the UTC wall time wearing a local label. The inputs below are read
 * back with `new Date(value)`, which parses a bare `datetime-local` as local, so
 * a UTC-shaped default round-trips to a moment offset by however far the desk is
 * from Greenwich — an hours correction typed into a pre-filled field would have
 * moved the very number it was correcting.
 */
function toDateTimeLocal(at: Date): string {
  const pad = (value: number): string => String(value).padStart(2, "0");

  return [
    `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`,
    `${pad(at.getHours())}:${pad(at.getMinutes())}`,
  ].join("T");
}

function toDateTimeLocalFrom(timestamp: string | null): string {
  if (timestamp === null || timestamp === "") {
    return "";
  }

  const at = new Date(timestamp);

  return Number.isNaN(at.getTime()) ? "" : toDateTimeLocal(at);
}

const route = useRoute();
const eventId = computed(() => String(route.params.eventId ?? ""));
const departmentId = computed(() => String(route.params.departmentId ?? ""));

const desk = ref<LogisticsDeskRead | null>(null);
/** Null while the desk is live; the storage timestamp while it is the stored index. */
const deskCachedAt = ref<string | null>(null);
const loadError = ref<string | null>(null);
const selectedStaffId = ref<string | null>(null);
const selectedShiftContextId = ref<string | null>(null);
const query = ref("");
const hits = ref<readonly LogisticsSearchHit[]>([]);
const status = ref<string | null>(null);
type DeskDialog =
  | "check-in"
  | "check-out"
  | "equipment-checkout"
  | "correct-hours";

const dialog = ref<DeskDialog | null>(null);
const dialogShiftId = ref("");
const dialogHoursWorkedId = ref("");
const dialogTimestamp = ref(toDateTimeLocal(new Date()));
const dialogStartTimestamp = ref("");
const selectedEquipmentIds = ref<string[]>([]);
const equipmentReturnConditions = ref<Record<string, EquipmentReturnCondition>>(
  {},
);

const context = computed(() => desk.value?.context ?? null);
const access = computed(() => desk.value?.access ?? null);
const timeZone = computed(() => desk.value?.context.timeZone ?? "UTC");
const departmentLabel = computed(
  () => desk.value?.context.departmentLabel ?? "Department",
);
const workspace = computed(() =>
  selectedStaffId.value === null
    ? null
    : (desk.value?.staffWorkspaces[selectedStaffId.value] ?? null),
);
const currentShifts = computed(() =>
  desk.value === null ? [] : currentLogisticsShifts(desk.value),
);
/**
 * The open workspace's states, minus presence.
 *
 * Presence has its own pill in the header and is stated whichever way it went,
 * so it is dropped from this list rather than rendered twice.
 */
const workspacePills = computed(() => {
  if (desk.value === null || selectedStaffId.value === null) {
    return [];
  }

  return logisticsStatePills(
    logisticsStaffStates(desk.value, selectedStaffId.value),
  ).filter((pill) => pill.key !== "onSite");
});
const staffOnShift = computed(() =>
  desk.value === null ? [] : logisticsStaffOnShift(desk.value),
);
const logisticsSummary = computed(() => ({
  onSite: (desk.value?.searchableStaff ?? []).filter(
    (staff) => staff.presenceState === "on_site",
  ).length,
  offSite: (desk.value?.searchableStaff ?? []).filter(
    (staff) => staff.presenceState === "off_site",
  ).length,
  equipmentOut: (desk.value?.searchableEquipment ?? []).filter(
    (item) => item.status === "checked_out",
  ).length,
  currentShifts: currentShifts.value.length,
}));
const selectedShift = computed(
  () =>
    desk.value?.searchableShifts.find(
      (shift) => shift.shiftId === selectedShiftContextId.value,
    ) ?? null,
);
/**
 * Who the selected shift is worth showing beside it.
 *
 * Every workspace holding a card for that shift with an assignment on it —
 * derived from the read rather than fetched again, so this list and the
 * workspace an operator opens from it are the same answer.
 */
const selectedShiftStaff = computed(() => {
  const shift = selectedShift.value;

  if (shift === null || desk.value === null) {
    return [];
  }

  return Object.values(desk.value.staffWorkspaces)
    .flatMap((member) => {
      const card = member.shiftCards.find(
        (candidate) =>
          candidate.shiftId === shift.shiftId &&
          candidate.attendanceState !== null,
      );

      return card === undefined
        ? []
        : [
            {
              staffId: member.staffId,
              displayName: member.displayName,
              teamLabel: member.teamLabel,
              attendanceLabel: attendanceStateLabel(card.attendanceState!),
              shiftTitle: card.title,
              startsAt: card.startsAt,
              endsAt: card.endsAt,
            },
          ];
    })
    .sort((left, right) => left.displayName.localeCompare(right.displayName));
});
const shiftSections = computed(() =>
  workspace.value ? logisticsShiftSections(workspace.value) : null,
);
const shiftSectionGroups = computed(() => [
  {
    id: "active",
    headingId: "active-shifts-heading",
    title: "Active shift",
    emptyMessage: "No active shift for this staff member.",
    cards: shiftSections.value?.active ?? [],
  },
  {
    id: "upcoming",
    headingId: "upcoming-shifts-heading",
    title: "Upcoming shifts",
    emptyMessage: "No upcoming shifts for this staff member.",
    cards: shiftSections.value?.upcoming ?? [],
  },
  {
    id: "outgoing",
    headingId: "outgoing-shifts-heading",
    title: "Outgoing shifts",
    emptyMessage: "No outgoing shift for this staff member.",
    cards: shiftSections.value?.outgoing ?? [],
  },
]);

/**
 * Read the desk, from the node or from the index this device is holding.
 *
 * A read that produces neither clears the desk rather than leaving the last
 * index on screen: a department whose roster could not be read must not look
 * like an empty one. The open workspace survives a re-read when that staff
 * member is still in the response, so a write does not close the person the
 * operator is serving.
 */
async function loadDesk(): Promise<void> {
  if (eventId.value === "" || departmentId.value === "") {
    desk.value = null;
    deskCachedAt.value = null;

    return;
  }

  loadError.value = null;

  try {
    const snapshot = await readLogisticsDesk(eventId.value, departmentId.value);

    desk.value = snapshot.desk;
    deskCachedAt.value = snapshot.cachedAt;

    if (
      selectedStaffId.value !== null &&
      snapshot.desk.staffWorkspaces[selectedStaffId.value] === undefined
    ) {
      selectedStaffId.value = null;
    }
  } catch (error) {
    desk.value = null;
    deskCachedAt.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load this department's logistics desk. Check the connection to this node and try again.",
    );
  }
}

function onSearch(value: string): void {
  query.value = value;
  hits.value = desk.value === null ? [] : searchLogisticsDesk(desk.value, value);
}

function onSelect(hit: LogisticsSearchHit): void {
  status.value = null;
  dialog.value = null;

  if (hit.kind === "staff") {
    selectedStaffId.value = hit.id;
    selectedShiftContextId.value = null;
  } else if (hit.kind === "equipment") {
    const holder = desk.value?.searchableEquipment.find(
      (item) => item.equipmentItemId === hit.id,
    )?.holderStaffId;

    selectedStaffId.value = holder ?? null;
    selectedShiftContextId.value = null;
    status.value =
      holder === null || holder === undefined
        ? `${hit.label} is not checked out to anyone.`
        : `Opened workspace for holder of ${hit.label}.`;
  } else {
    selectedShiftContextId.value = hit.id;
    status.value = `${hit.label} selected.`;
  }

  query.value = "";
  hits.value = [];
}

function onSelectCurrentShift(shift: DepartmentOpsShift): void {
  onSelect({
    id: shift.shiftId,
    kind: "shift",
    label: shift.title,
    detail: `${shift.teamLabel} / ${lifecycleLabel(shift.lifecycle)}`,
  });
}

function openStaff(staffId: string): void {
  selectedStaffId.value = staffId;
  status.value = null;
}

/**
 * What is happening right now, in the operator's words.
 *
 * A command here is a command plus a re-read of the whole desk, which is two
 * round trips on a field network — long enough that "Mark on-site" looked like
 * it had done nothing, and long enough for somebody to press it again. The
 * workspace says it is working instead: the panel stays legible and on screen,
 * its controls stop accepting presses, and the line above it names the command
 * in flight rather than showing a spinner over the data somebody is reading.
 */
const pendingWork = ref<string | null>(null);
const busy = computed(() => pendingWork.value !== null);

/**
 * Run a write and read the desk again.
 *
 * Every command on this page changes more than the record it names — a check-in
 * closes an off-site option and moves someone onto the on-shift roster, an
 * equipment return may reopen it — and none of that comes back in the response.
 * The refusal shown is the node's own sentence, including the one the outbox
 * produces for a connected-only command issued with no node in reach.
 *
 * One at a time, deliberately. The re-read is what every one of these commands
 * ends with, and two of them in flight would land in whichever order the network
 * settled on, leaving the screen showing the answer to the earlier one.
 */
async function run(
  work: () => Promise<void>,
  success: string,
  pending = "Working",
): Promise<void> {
  if (busy.value) {
    return;
  }

  pendingWork.value = pending;
  status.value = null;

  try {
    await work();
    await loadDesk();
    status.value = success;
  } catch (error) {
    status.value = meridianErrorMessage(
      error,
      error instanceof Error ? error.message : "Unable to complete that.",
    );
  } finally {
    pendingWork.value = null;
  }
}

function markOnSite(): void {
  const member = workspace.value;

  if (member === null || context.value === null) return;

  void run(
    () => setDepartmentPresence(context.value!, member.staffId, "on_site"),
    `${member.displayName} marked on-site.`,
    `Marking ${member.displayName} on-site`,
  );
}

function markOffSite(): void {
  const member = workspace.value;

  if (member === null || context.value === null) return;

  void run(
    () => setDepartmentPresence(context.value!, member.staffId, "off_site"),
    `${member.displayName} marked off-site.`,
    `Marking ${member.displayName} off-site`,
  );
}

function markNoShow(shiftId: string): void {
  const member = workspace.value;

  if (member === null || context.value === null) return;

  void run(
    async () =>
      queueMarkNoShow({
        context: context.value!,
        shiftId,
        staffId: member.staffId,
      }),
    `${member.displayName} marked as a no-show.`,
    `Marking ${member.displayName} as a no-show`,
  );
}

/**
 * Act on someone from the on-shift roster without searching for them first.
 *
 * Selecting the staff member before opening the dialog keeps one code path for
 * attendance: the dialog always acts on the open workspace, so the roster
 * shortcut and the searched-for workspace cannot drift apart.
 */
function openDialogForStaff(
  kind: "check-out" | "equipment-checkout",
  staffId: string,
  shiftId = "",
): void {
  openStaff(staffId);
  openDialog(kind, shiftId);
}

function openDialog(kind: DeskDialog, shiftId = ""): void {
  dialog.value = kind;
  dialogShiftId.value = shiftId;
  dialogHoursWorkedId.value = "";
  dialogTimestamp.value = toDateTimeLocal(new Date());
  dialogStartTimestamp.value = "";
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

/**
 * Open the correction dialog on what is on file (SLB-031).
 *
 * Both fields start filled with the recorded actual times rather than empty or
 * with now. A correction is an edit to two times that already exist, and the
 * operator making it has the staff member in front of them saying which one is
 * wrong — the other has to survive being confirmed untouched.
 */
function openCorrection(card: LogisticsShiftCard): void {
  openDialog("correct-hours", card.shiftId);
  dialogHoursWorkedId.value = card.hoursWorkedId ?? "";
  dialogStartTimestamp.value = toDateTimeLocalFrom(card.actualStartedAt);
  dialogTimestamp.value = toDateTimeLocalFrom(card.actualEndedAt);
}

/**
 * Confirm the open dialog.
 *
 * Check-in and check-out are queued attendance operations; the equipment beside
 * them is a separate connected-only command per item, issued after the
 * attendance so a refused handoff does not cost the check-in. The dialog's
 * timestamp is the recorded time: `device_created_at` for a check-in, the actual
 * end time for a check-out (SLB-006).
 */
function confirmDialog(): void {
  const member = workspace.value;
  const kind = dialog.value;

  if (member === null || kind === null || context.value === null) return;

  /*
   * A correction names both ends of the window and the record it edits. The
   * node requires all three and would refuse without them; saying so here means
   * the operator reads it beside the fields rather than after a round trip.
   */
  if (
    kind === "correct-hours" &&
    (dialogHoursWorkedId.value === "" ||
      dialogStartTimestamp.value === "" ||
      dialogTimestamp.value === "")
  ) {
    status.value = "Enter both the actual start and the actual end to correct these hours.";

    return;
  }

  const occurredAt = new Date(dialogTimestamp.value).toISOString();
  const shiftId = dialogShiftId.value;

  void run(async () => {
    if (kind === "check-in") {
      queueCheckIn({
        context: context.value!,
        shiftId,
        staffId: member.staffId,
        occurredAt,
      });

      for (const equipmentItemId of selectedEquipmentIds.value) {
        await checkoutEquipment(
          context.value!,
          member.staffId,
          equipmentItemId,
          shiftId === "" ? null : shiftId,
        );
      }
    } else if (kind === "correct-hours") {
      await correctHours(
        context.value!,
        dialogHoursWorkedId.value,
        new Date(dialogStartTimestamp.value).toISOString(),
        occurredAt,
      );
    } else if (kind === "check-out") {
      queueCheckOut({
        context: context.value!,
        shiftId,
        staffId: member.staffId,
        occurredAt,
        startedAt:
          dialogStartTimestamp.value === ""
            ? null
            : new Date(dialogStartTimestamp.value).toISOString(),
      });

      for (const [checkoutId, condition] of Object.entries(
        equipmentReturnConditions.value,
      )) {
        await returnEquipment(context.value!, checkoutId, condition);
      }
    } else {
      for (const equipmentItemId of selectedEquipmentIds.value) {
        await checkoutEquipment(
          context.value!,
          member.staffId,
          equipmentItemId,
          null,
        );
      }
    }

    dialog.value = null;
  },
  dialogSuccessMessage(kind, member.displayName),
  dialogPendingMessage(kind, member.displayName));
}

function dialogSuccessMessage(kind: DeskDialog, displayName: string): string {
  switch (kind) {
    case "check-in":
      return `${displayName} checked in.`;
    case "check-out":
      return `${displayName} checked out.`;
    case "equipment-checkout":
      return `${displayName} equipment checked out.`;
    case "correct-hours":
      return `Hours corrected for ${displayName}.`;
  }
}

function dialogPendingMessage(kind: DeskDialog, displayName: string): string {
  switch (kind) {
    case "check-in":
      return `Checking ${displayName} in`;
    case "check-out":
      return `Checking ${displayName} out`;
    case "equipment-checkout":
      return `Checking out equipment to ${displayName}`;
    case "correct-hours":
      return `Correcting hours for ${displayName}`;
  }
}

function dialogHeading(kind: DeskDialog): string {
  switch (kind) {
    case "check-in":
      return "Check in";
    case "check-out":
      return "Check out";
    case "equipment-checkout":
      return "Check out equipment";
    case "correct-hours":
      return "Correct hours";
  }
}

function closeDialog(): void {
  dialog.value = null;
}

function returnItem(
  checkoutId: string,
  condition: EquipmentReturnCondition,
): void {
  if (context.value === null) return;

  void run(
    () => returnEquipment(context.value!, checkoutId, condition),
    `Equipment marked ${equipmentStateLabel(condition)}.`,
    `Marking equipment ${equipmentStateLabel(condition).toLowerCase()}`,
  );
}

function addToShift(shiftId: string): void {
  const member = workspace.value;

  if (member === null || context.value === null) return;

  void run(async () => {
    const warnings = await addStaffToShift(
      context.value!,
      member.staffId,
      shiftId,
    );

    // Overlapping assignments are warned about rather than refused (technical
    // spec 20.5), so the node's warning is what the desk shows.
    if (warnings.length > 0) {
      overlapWarnings.value = warnings;
    } else {
      overlapWarnings.value = [];
    }
  },
  `${member.displayName} added to the shift.`,
  `Adding ${member.displayName} to the shift`);
}

const overlapWarnings = ref<readonly string[]>([]);

/**
 * Equipment inventory setup lives on its own `department.equipment` page
 * (M11.18); the Logistics Window stays the service station that checks that
 * inventory out and back in.
 */
const equipmentInventoryRoute = computed(() => ({
  name: "events.departments.equipment.index",
  params: {
    eventId: eventId.value,
    departmentId: departmentId.value,
  },
}));

watch([eventId, departmentId], () => {
  selectedStaffId.value = null;
  selectedShiftContextId.value = null;
  void loadDesk();
});

void loadDesk();
</script>

<template>
  <DeptOpsShell
    title="Logistics Window"
    :eyebrow="departmentLabel"
    lede="Staff-first service station for presence, attendance, and equipment handoff."
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <template #actions>
      <div v-if="access?.canManageEquipment" class="logistics__heading-actions">
        <WorkflowActionButton :to="equipmentInventoryRoute">
          Manage Equipment
        </WorkflowActionButton>
      </div>
    </template>

    <!--
      A refusal is the node's own sentence, and an unreachable node is stated
      rather than shown as a department with nobody in it.
    -->
    <p v-if="loadError" class="logistics__error" role="alert">
      {{ loadError }}
      <button type="button" @click="loadDesk">Try again</button>
    </p>

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
              {{ formatTimestamp(shift.startsAt, timeZone) }} -
              {{ formatTimestamp(shift.endsAt, timeZone) }}
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

    <section class="logistics__on-shift" aria-labelledby="on-shift-heading">
      <h2 id="on-shift-heading">On shift now</h2>
      <p class="logistics__on-shift-lede">
        Staff checked in and not yet checked out. Act on them here without
        searching first.
      </p>
      <p v-if="staffOnShift.length === 0" class="logistics__note" role="status">
        No staff are checked in for this department right now.
      </p>
      <ul v-else class="logistics__on-shift-list">
        <li
          v-for="member in staffOnShift"
          :key="`${member.staffId}-${member.shiftId}`"
        >
          <div class="logistics__on-shift-who">
            <strong>{{ member.displayName }}</strong>
            <span>{{ member.teamLabel }} - {{ member.shiftTitle }}</span>
            <span>
              {{ formatTimestamp(member.startsAt, timeZone) }} -
              {{ formatTimestamp(member.endsAt, timeZone) }}
              <template v-if="member.openEquipmentCount > 0">
                / {{ member.openEquipmentCount }} equipment out
              </template>
            </span>
          </div>
          <div class="logistics__actions">
            <button
              type="button"
              :disabled="!member.canCheckOut"
              @click="
                openDialogForStaff('check-out', member.staffId, member.shiftId)
              "
            >
              Check out
            </button>
            <button
              type="button"
              :disabled="!member.canCheckOutEquipment"
              @click="
                openDialogForStaff('equipment-checkout', member.staffId)
              "
            >
              Check out equipment
            </button>
          </div>
        </li>
      </ul>
    </section>

    <!--
      The scope notice sits beside the search rather than under it. It qualifies
      what the search can find, so it should be readable while someone is typing
      into the box, not after they have scrolled past it.
    -->
    <div class="logistics__find">
      <EntitySearch
        class="logistics__search"
        :hits="hits"
        @search="onSearch"
        @select="onSelect"
      />

      <section
        v-if="desk"
        class="logistics__cache"
        :data-source="deskCachedAt === null ? 'node' : 'cache'"
        aria-labelledby="search-scope-heading"
      >
        <h2 id="search-scope-heading">Search scope</h2>
        <p>{{ desk.context.eventLabel }} / {{ desk.context.departmentLabel }}</p>
        <p>
          Department staff, equipment, and shifts, read
          {{ formatTimestamp(desk.context.asOf, timeZone) }}.
        </p>
        <!--
          Stated, not implied. The line above already carries the moment the
          index was read, and on a stored copy that moment is the only thing
          separating it from a live one — a reader who has not been watching the
          clock cannot tell those apart, so the desk says which it is showing
          rather than leaving them to work it out (contract 16.2).
        -->
        <p v-if="deskCachedAt !== null" class="logistics__cache-stale" role="status">
          This node could not be reached. Searching the copy this device stored
          {{ formatTimestamp(deskCachedAt, timeZone) }}; presence, equipment, and
          shift state may have moved on since.
        </p>
      </section>
    </div>

    <section
      v-if="selectedShift"
      class="logistics__search-context"
      aria-labelledby="search-context-heading"
    >
      <h2 id="search-context-heading">{{ selectedShift.title }}</h2>
      <p>
        {{ selectedShift.teamLabel }} /
        {{ lifecycleLabel(selectedShift.lifecycle) }}
      </p>
      <section
        class="logistics__shift-drilldown"
        aria-labelledby="selected-shift-staff-heading"
      >
        <h3 id="selected-shift-staff-heading">Scheduled staff</h3>
        <p>Staff holding an assignment on this shift.</p>
        <p
          v-if="selectedShiftStaff.length === 0"
          class="logistics__note"
          role="status"
        >
          Nobody is assigned to this shift.
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
                {{ formatTimestamp(member.startsAt, timeZone) }}
                to
                {{ formatTimestamp(member.endsAt, timeZone) }}
              </span>
            </div>
            <button type="button" @click="openStaff(member.staffId)">
              Open {{ member.displayName }}
            </button>
          </li>
        </ul>
      </section>
    </section>

    <p v-if="status" class="logistics__status" role="status">{{ status }}</p>

    <!--
      Overlapping assignments are allowed and warned about rather than refused
      (technical spec 20.5), so the node's warning is shown beside the addition
      that produced it.
    -->
    <ul v-if="overlapWarnings.length > 0" class="logistics__warnings" role="status">
      <li v-for="warning in overlapWarnings" :key="warning">{{ warning }}</li>
    </ul>

    <p v-if="!workspace" class="logistics__empty" role="status">
      Search for a staff member to open their operational workspace.
    </p>

    <!--
      The one panel on this page an operator is actually working in, and it used
      to look like every other block on it: the same border, the same surface,
      no way to find it after a glance back at the roster. It now carries its own
      outline and accent edge, so "who am I looking at" is answered by the shape
      of the page rather than by re-reading it.

      `aria-busy` while a command is in flight, and the panel stays on screen
      throughout. Nothing is hidden behind an overlay — an operator mid-check-in
      still needs to read the shift times they are checking somebody in for.
    -->
    <section
      v-else
      class="logistics__workspace"
      :class="{ 'logistics__workspace--busy': busy }"
      :aria-busy="busy"
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
        <!--
          Presence is stated outright rather than by omission, because the header
          is the one place "off-site" is the answer somebody came for. The rest
          are shown only when true: four grey pills saying nothing is how a
          reader learns to stop looking at them.
        -->
        <div class="logistics__staff-states">
          <StatusPill
            :label="presenceStateLabel(workspace.presenceState)"
            :tone="presenceTone(workspace.presenceState)"
            sr-prefix="Presence"
          />
          <StatusPill
            v-for="pill in workspacePills"
            :key="pill.key"
            :label="pill.label"
            :tone="pill.tone"
          />
        </div>
      </header>

      <!--
        Named rather than a bare spinner. "Working" tells somebody the screen is
        alive; "Marking Dana Ranger on-site" tells them which of the four buttons
        they just pressed is the one still running.
      -->
      <p
        v-if="pendingWork"
        class="logistics__pending"
        role="status"
        aria-live="polite"
      >
        <span class="logistics__pending-spinner" aria-hidden="true" />
        {{ pendingWork }}…
      </p>

      <div class="logistics__actions">
        <button
          type="button"
          :disabled="busy || workspace.presenceState === 'on_site'"
          @click="markOnSite"
        >
          Mark on-site
        </button>
        <button
          type="button"
          :disabled="busy || !workspace.canGoOffSite"
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
                <span class="logistics__card-pills">
                  <StatusPill
                    :label="lifecycleLabel(card.lifecycle)"
                    :tone="lifecycleTone(card.lifecycle)"
                    sr-prefix="Shift"
                  />
                  <StatusPill
                    :label="
                      card.attendanceState
                        ? attendanceStateLabel(card.attendanceState)
                        : 'Not assigned'
                    "
                    :tone="
                      card.attendanceState
                        ? attendanceTone(card.attendanceState)
                        : 'neutral'
                    "
                    sr-prefix="Attendance"
                  />
                </span>
                <span>
                  {{ formatTimestamp(card.startsAt, timeZone) }} -
                  {{ formatTimestamp(card.endsAt, timeZone) }}
                </span>
                <!--
                  The hours on file, once a check-out has created them. Shown
                  beside the shift they belong to because that is what a
                  correction is measured against: an actual start two hours
                  after the shift began is the thing an operator is being asked
                  to fix (SLB-031).
                -->
                <span
                  v-if="card.actualStartedAt && card.actualEndedAt"
                  class="logistics__card-hours"
                >
                  Recorded hours:
                  {{ formatTimestamp(card.actualStartedAt, timeZone) }} -
                  {{ formatTimestamp(card.actualEndedAt, timeZone) }}
                  <template v-if="card.minutesWorked !== null">
                    ({{ card.minutesWorked }} min)
                  </template>
                </span>
                <!--
                  Why there is no "Add to shift" button on this card. The card
                  used to be absent entirely in these cases, so an operator who
                  had just created a shift and marked somebody on-site went
                  looking for a button that was not there and had nothing to read.
                -->
                <span
                  v-if="card.addToShiftBlockedReason"
                  class="logistics__card-blocked"
                  role="status"
                >
                  {{ card.addToShiftBlockedReason }}
                </span>
                <!--
                  Why there is no "Correct hours" button. The frozen sentence is
                  the node's own and names the date the grace period closed, so
                  an operator can tell "an hour too late" from "a month too
                  late" and knows whether to raise it with an organizer at all
                  (HOURS-008, SLB-031).
                -->
                <span
                  v-if="card.correctHoursBlockedReason"
                  class="logistics__card-blocked"
                  role="status"
                >
                  {{ card.correctHoursBlockedReason }}
                </span>
              </div>
              <div class="logistics__actions">
                <button
                  v-if="card.canCheckIn"
                  type="button"
                  :disabled="busy"
                  @click="openDialog('check-in', card.shiftId)"
                >
                  Check in
                </button>
                <button
                  v-if="card.canCheckOut"
                  type="button"
                  :disabled="busy"
                  @click="openDialog('check-out', card.shiftId)"
                >
                  Check out
                </button>
                <button
                  v-if="card.canMarkNoShow"
                  type="button"
                  :disabled="busy"
                  @click="markNoShow(card.shiftId)"
                >
                  Mark no-show
                </button>
                <button
                  v-if="card.canAddToShift"
                  type="button"
                  :disabled="busy"
                  @click="addToShift(card.shiftId)"
                >
                  Add to shift
                </button>
                <button
                  v-if="card.canCorrectHours"
                  type="button"
                  :disabled="busy"
                  @click="openCorrection(card)"
                >
                  Correct hours
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
          <button
            type="button"
            :disabled="busy"
            @click="openDialog('equipment-checkout')"
          >
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
              <span class="logistics__card-pills">
                <StatusPill
                  :label="equipmentStateLabel(item.status)"
                  :tone="equipmentTone(item.status)"
                  sr-prefix="Equipment"
                />
              </span>
            </div>
            <div class="logistics__actions">
              <button
                type="button"
                :disabled="busy"
                @click="returnItem(item.checkoutId!, 'returned')"
              >
                Returned
              </button>
              <button
                type="button"
                :disabled="busy"
                @click="returnItem(item.checkoutId!, 'missing')"
              >
                Missing
              </button>
              <button
                type="button"
                :disabled="busy"
                @click="returnItem(item.checkoutId!, 'damaged')"
              >
                Damaged
              </button>
            </div>
          </li>
        </ul>
      </section>

      <!--
        Provisions is an extension point the workspace keeps a place for. The
        note is the client's own words rather than a field on the read, because
        the node has nothing to say about a domain that is not specified yet.
      -->
      <section aria-labelledby="provisions-heading">
        <h3 id="provisions-heading">Provisions</h3>
        <p class="logistics__note" role="status">
          Provisions will appear here once that domain is specified.
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
            {{ formatTimestamp(signup.startsAt, timeZone) }}
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
        <h2 id="attendance-dialog-heading">{{ dialogHeading(dialog) }}</h2>
        <!--
          A correction is an edit to a record that already exists, so the dialog
          says which one and what it currently holds before offering to change it
          (SLB-031, SLB-032). The prior values are not discarded by the change —
          they stay in attendance history and in the audit entry — and saying so
          is what makes an operator willing to touch a recorded total.
        -->
        <p v-if="dialog === 'correct-hours'" class="logistics__dialog-note">
          Editing the recorded hours for this shift. The previous values stay in
          attendance history.
        </p>
        <label v-if="dialog === 'correct-hours'">
          <span>Actual start</span>
          <input v-model="dialogStartTimestamp" type="datetime-local" />
        </label>
        <label>
          <span>
            {{
              dialog === "check-out" || dialog === "correct-hours"
                ? "Actual end"
                : "Timestamp"
            }}
          </span>
          <input v-model="dialogTimestamp" type="datetime-local" />
        </label>
        <!--
          Both actual times are editable on check-out (SLB-006). The end defaults
          to now, which is the ordinary case; the start is left empty unless
          somebody is correcting it, because an empty start means "leave the
          recorded check-in alone" to `AttendanceCheckOutService` and a
          pre-filled one would silently overwrite it. The correction dialog above
          is the opposite case and fills both: there is nothing to leave alone,
          only two recorded times, one of which is wrong.
        -->
        <label v-if="dialog === 'check-out'">
          <span>Actual start (leave empty to keep the recorded check-in)</span>
          <input v-model="dialogStartTimestamp" type="datetime-local" />
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

.logistics__warnings {
  margin: 0 0 var(--m-space-4);
  padding: var(--m-space-3) var(--m-space-3) var(--m-space-3) var(--m-space-5);
  border: 1px solid
    color-mix(in srgb, var(--m-status-warning) 55%, var(--m-border-default));
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-secondary);
}

.logistics__error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
  padding: var(--m-space-3);
  border: 1px solid var(--m-status-danger, #cc792f);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-status-danger, #cc792f);
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

/*
 * The staff workspace is the page's subject, and it is drawn like it.
 *
 * An outlined container with an accent edge, rather than another block of the
 * same surface as everything above it. The desk's other sections are reference —
 * counts, current shifts, who is on shift — and this is the one an operator acts
 * in, so it is the one that has to be findable in a glance from across a table
 * in daylight.
 */
.logistics__workspace {
  display: grid;
  gap: var(--m-space-5);
  padding: var(--m-space-4);
  border: 2px solid
    color-mix(in srgb, var(--m-action-primary-bg) 45%, var(--m-border-default));
  border-left-width: 6px;
  border-left-color: var(--m-action-primary-bg);
  border-radius: 12px;
  background: var(--m-surface-base);
  box-shadow: var(--m-shadow-sm);
}

/*
 * Busy dims the controls and leaves the data alone.
 *
 * An operator waiting on a check-in is usually still reading the shift they are
 * checking somebody in for, so nothing goes behind an overlay and nothing is
 * removed. The panel loses a little contrast and stops taking presses; the named
 * line above says which command it is waiting on.
 */
.logistics__workspace--busy .logistics__actions button {
  opacity: 0.45;
}

.logistics__workspace--busy {
  border-left-color: var(--m-status-warning);
}

.logistics__pending {
  display: flex;
  align-items: center;
  gap: var(--m-space-2);
  margin: 0;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid
    color-mix(in srgb, var(--m-status-warning) 55%, var(--m-border-default));
  border-radius: 8px;
  background: color-mix(
    in srgb,
    var(--m-status-warning) 14%,
    var(--m-surface-raised)
  );
  color: var(--m-text-secondary);
  font-weight: 800;
}

.logistics__pending-spinner {
  flex: none;
  width: 0.85rem;
  height: 0.85rem;
  border: 2px solid
    color-mix(in srgb, var(--m-status-warning) 35%, transparent);
  border-top-color: var(--m-status-warning);
  border-radius: var(--m-radius-pill);
  animation: logistics-spin 900ms linear infinite;
}

/* A spinner that never stops is a distraction nobody asked for. */
@media (prefers-reduced-motion: reduce) {
  .logistics__pending-spinner {
    animation: none;
  }
}

@keyframes logistics-spin {
  to {
    transform: rotate(360deg);
  }
}

.logistics__card-pills,
.logistics__staff-states {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.logistics__card-blocked {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

/* The number a correction is about, so it reads as data rather than as a note. */
.logistics__card-hours {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.logistics__dialog-note {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
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

.logistics__on-shift {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-5);
}

.logistics__on-shift h2 {
  margin: 0;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  letter-spacing: 0;
  text-transform: uppercase;
}

.logistics__on-shift-lede,
.logistics__on-shift .logistics__note {
  margin: 0;
  color: var(--m-text-muted);
}

.logistics__on-shift-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: var(--m-space-2);
}

.logistics__on-shift-list li {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.logistics__on-shift-who {
  display: grid;
  gap: var(--m-space-1);
}

.logistics__on-shift-who span {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

/*
 * Narrow: cache notice above the search, and the search takes the full column
 * because 720px does not exist on a phone. Wide: the two sit side by side with
 * the search held at a 720px floor.
 */
.logistics__find {
  display: grid;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
}

/* Nested so this outranks EntitySearch's own scoped bottom margin. */
.logistics__find .logistics__search {
  margin: 0;
  min-width: 0;
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

.logistics__find .logistics__cache {
  order: -1;
  margin: 0;
  align-self: start;
}

.logistics__cache h2,
.logistics__search-context h2 {
  margin: 0;
  font-size: var(--m-text-md);
}

.logistics__cache p,
.logistics__search-context p {
  margin: 0;
  color: var(--m-text-muted);
}

/*
 * The stored-index notice carries the warning colour and a rule beside it,
 * because it qualifies every result underneath it rather than describing the
 * panel it sits in. Colour is not carrying the message on its own — the sentence
 * says what happened and when the copy was taken.
 */
.logistics__cache[data-source="cache"] {
  border-color: color-mix(
    in srgb,
    var(--m-status-warning) 55%,
    var(--m-border-default)
  );
}

.logistics__cache .logistics__cache-stale {
  padding-inline-start: var(--m-space-3);
  border-inline-start: 3px solid var(--m-status-warning);
  color: var(--m-text-secondary);
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
  font-size: var(--m-text-md);
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
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-3);
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
  font-size: var(--m-text-md);
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

  .logistics__scheduled-staff li,
  .logistics__on-shift-list li {
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
  }

  .logistics__find .logistics__cache {
    order: 0;
  }
}

/*
 * The side-by-side split waits until the search can hold its 720px floor and
 * still leave the cache notice a readable column. Below that the two stack, so
 * the floor never forces a horizontal scrollbar.
 */
@media (min-width: 68rem) {
  .logistics__find {
    grid-template-columns: minmax(720px, 1.4fr) minmax(0, 1fr);
    align-items: start;
  }
}
</style>
