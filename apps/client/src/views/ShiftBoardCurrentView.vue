<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink } from "vue-router";

import {
  LOCAL_CURRENT_SHIFT_BOARD,
  addEquipmentAndCheckoutToStaff,
  addUnscheduledRosterMember,
  assignCurrentDeployment,
  attendanceStateLabel,
  checkedInRoster,
  checkedOutEquipment,
  checkoutEquipmentToStaff,
  checkoutReadyEquipment,
  deploymentLabel,
  equipmentItemLabel,
  equipmentStateLabel,
  equipmentSummary,
  eligibleUnscheduledCandidates,
  markStaffOffSite,
  markStaffOnSite,
  onSiteStaff,
  presenceStateLabel,
  presenceSummary,
  returnEquipmentFromStaff,
  rosterSummary,
  shiftSignupStateLabel,
  type CheckedOutEquipment,
  type DepartmentPresenceMember,
  type EquipmentReturnCondition,
  type ShiftBoardRosterMember,
} from "@/shift-board/currentShiftBoard";

type DepartmentBoardSurface = "current" | "logistics" | "operations" | "planning";

const props = withDefaults(
  defineProps<{
    surface?: DepartmentBoardSurface;
  }>(),
  {
    surface: "current",
  },
);

// Department board prototype - UI contract 12.5 (M10 course correction).
// Server-backed role enforcement is owned by Laravel; this local surface keeps
// the role workflows visually separated while API parity continues.
const board = ref(LOCAL_CURRENT_SHIFT_BOARD);
const checkedInMembers = computed(() => checkedInRoster(board.value));
const summary = computed(() => rosterSummary(board.value));
const presenceCounts = computed(() => presenceSummary(board.value));
const onsiteMembers = computed(() => onSiteStaff(board.value));
const equipmentCounts = computed(() => equipmentSummary(board.value));
const candidates = computed(() => eligibleUnscheduledCandidates(board.value));
const selectedCandidateId = ref(candidates.value[0]?.staffId ?? "");
const addStatus = ref<string | null>(null);
const selectedPresenceStaffId = ref(
  board.value.departmentPresence[0]?.staffId ?? "",
);
const presenceStatus = ref<string | null>(null);
const selectedAssignmentId = ref(board.value.roster[0]?.assignmentId ?? "");
const selectedDeploymentId = ref(
  board.value.deploymentOptions[0]?.deploymentId ?? "",
);
const deploymentStatus = ref<string | null>(null);
const availableEquipment = computed(() => checkoutReadyEquipment(board.value));
const equipmentCheckedOut = computed(() => checkedOutEquipment(board.value));
const selectedEquipmentItemId = ref(
  availableEquipment.value[0]?.equipmentItemId ?? "",
);
const selectedEquipmentAssignmentId = ref(
  board.value.roster[0]?.assignmentId ?? "",
);
const selectedNewEquipmentAssignmentId = ref(
  board.value.roster[0]?.assignmentId ?? "",
);
const newEquipmentName = ref("");
const newEquipmentAssetTag = ref("");
const selectedReturnStaffId = ref(equipmentCheckedOut.value[0]?.staffId ?? "");
const selectedReturnCheckoutIds = ref<string[]>(
  equipmentCheckedOut.value[0] ? [equipmentCheckedOut.value[0].checkoutId] : [],
);
const selectedReturnCondition = ref<EquipmentReturnCondition>("returned");
const equipmentStatus = ref<string | null>(null);
const equipmentByStaff = computed(() => {
  const grouped = new Map<string, CheckedOutEquipment[]>();
  for (const checkout of equipmentCheckedOut.value) {
    grouped.set(checkout.staffId, [
      ...(grouped.get(checkout.staffId) ?? []),
      checkout,
    ]);
  }

  return grouped;
});
const staffWithCheckedOutEquipment = computed(() =>
  board.value.roster.filter((member) => equipmentByStaff.value.has(member.staffId)),
);
const selectedStaffEquipment = computed(
  () => equipmentByStaff.value.get(selectedReturnStaffId.value) ?? [],
);

const isCurrentSurface = computed(() => props.surface === "current");
const isLogisticsSurface = computed(() => props.surface === "logistics");
const isOperationsSurface = computed(() => props.surface === "operations");
const isPlanningSurface = computed(() => props.surface === "planning");
const showsAssignments = computed(
  () =>
    isCurrentSurface.value ||
    isLogisticsSurface.value ||
    isOperationsSurface.value,
);
const showsCheckedIn = computed(
  () => isCurrentSurface.value || isLogisticsSurface.value,
);
const showsEquipment = computed(
  () => isCurrentSurface.value || isLogisticsSurface.value,
);
const surfaceTitle = computed(() => {
  switch (props.surface) {
    case "logistics":
      return "Logistics Desk";
    case "operations":
      return "Operations Board";
    case "planning":
      return "Planning Board";
    case "current":
      return "Department Board";
  }
});
const surfaceLinks = computed(() => [
  {
    surface: "current",
    label: "Board",
    routeName: "events.departments.shift-board.current",
  },
  {
    surface: "logistics",
    label: "Logistics",
    routeName: "events.departments.shift-board.logistics",
  },
  {
    surface: "operations",
    label: "Operations",
    routeName: "events.departments.shift-board.operations",
  },
  {
    surface: "planning",
    label: "Planning",
    routeName: "events.departments.shift-board.planning",
  },
]);

const dateTimeFormatter = new Intl.DateTimeFormat("en-US", {
  month: "short",
  day: "numeric",
  hour: "numeric",
  minute: "2-digit",
  timeZone: board.value.timeZone,
  timeZoneName: "short",
});

function formatTimestamp(timestamp: string): string {
  return dateTimeFormatter.format(new Date(timestamp));
}

function checkedInText(member: ShiftBoardRosterMember): string {
  if (member.attendanceState !== "checked_in" || !member.checkedInAt) {
    return "Not checked in";
  }

  return `Checked in ${formatTimestamp(member.checkedInAt)}`;
}

function deploymentText(member: ShiftBoardRosterMember): string {
  return deploymentLabel(board.value, member.currentDeploymentId);
}

function shiftTitle(shiftId: string): string {
  return (
    board.value.shiftSchedule.find((shift) => shift.shiftId === shiftId)
      ?.title ?? "Unknown shift"
  );
}

function checkedOutEquipmentText(item: CheckedOutEquipment): string {
  return `${equipmentStateLabel(item.status)} to ${item.staffName} since ${formatTimestamp(
    item.checkedOutAt,
  )}`;
}

function presenceOptionText(member: DepartmentPresenceMember): string {
  return `${member.displayName} - ${member.teamLabel} - ${presenceStateLabel(
    member.presenceState,
  )}`;
}

function markSelectedStaffOnSite(): void {
  const member = board.value.departmentPresence.find(
    (item) => item.staffId === selectedPresenceStaffId.value,
  );

  if (member === undefined) {
    return;
  }

  board.value = markStaffOnSite(board.value, member.staffId);
  selectedCandidateId.value = candidates.value[0]?.staffId ?? "";
  presenceStatus.value = `${member.displayName} marked on-site.`;
}

function markSelectedStaffOffSite(): void {
  const member = board.value.departmentPresence.find(
    (item) => item.staffId === selectedPresenceStaffId.value,
  );

  if (member === undefined) {
    return;
  }

  try {
    board.value = markStaffOffSite(board.value, member.staffId);
    selectedCandidateId.value = candidates.value[0]?.staffId ?? "";
    presenceStatus.value = `${member.displayName} marked off-site.`;
  } catch (error) {
    presenceStatus.value =
      error instanceof Error ? error.message : "Unable to update presence.";
  }
}

function addSelectedCandidate(): void {
  const candidate = candidates.value.find(
    (item) => item.staffId === selectedCandidateId.value,
  );

  if (candidate === undefined) {
    return;
  }

  board.value = addUnscheduledRosterMember(
    board.value,
    candidate.staffId,
    `local-unscheduled-${candidate.staffId}`,
  );
  selectedCandidateId.value = candidates.value[0]?.staffId ?? "";
  addStatus.value = `${candidate.displayName} added to shift.`;
}

function moveSelectedDeployment(): void {
  const member = board.value.roster.find(
    (item) => item.assignmentId === selectedAssignmentId.value,
  );

  if (member === undefined || selectedDeploymentId.value === "") {
    return;
  }

  board.value = assignCurrentDeployment(
    board.value,
    member.assignmentId,
    selectedDeploymentId.value,
  );

  deploymentStatus.value = `${member.displayName} moved to ${deploymentLabel(
    board.value,
    selectedDeploymentId.value,
  )}.`;
}

function checkoutSelectedEquipment(): void {
  const equipment = availableEquipment.value.find(
    (item) => item.equipmentItemId === selectedEquipmentItemId.value,
  );
  const member = board.value.roster.find(
    (item) => item.assignmentId === selectedEquipmentAssignmentId.value,
  );

  if (equipment === undefined || member === undefined) {
    return;
  }

  const checkedOutAt = new Date().toISOString();

  board.value = checkoutEquipmentToStaff(
    board.value,
    equipment.equipmentItemId,
    member.assignmentId,
    `local-equipment-checkout-${equipment.equipmentItemId}-${member.staffId}`,
    checkedOutAt,
  );

  selectedEquipmentItemId.value =
    availableEquipment.value[0]?.equipmentItemId ?? "";
  equipmentStatus.value = `${equipment.name} checked out to ${member.displayName}.`;
}

function makeLocalEquipmentId(name: string): string {
  return `local-equipment-${name
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")}-${Date.now()}`;
}

function addAndCheckoutEquipment(): void {
  const member = board.value.roster.find(
    (item) => item.assignmentId === selectedNewEquipmentAssignmentId.value,
  );
  const name = newEquipmentName.value.trim();

  if (member === undefined || name === "") {
    return;
  }

  const equipmentItemId = makeLocalEquipmentId(name);
  const checkedOutAt = new Date().toISOString();

  board.value = addEquipmentAndCheckoutToStaff(
    board.value,
    {
      equipmentItemId,
      name,
      assetTag: newEquipmentAssetTag.value.trim() || null,
    },
    member.assignmentId,
    `local-equipment-checkout-${equipmentItemId}-${member.staffId}`,
    checkedOutAt,
  );

  newEquipmentName.value = "";
  newEquipmentAssetTag.value = "";
  selectedReturnStaffId.value = member.staffId;
  selectedReturnCheckoutIds.value = [
    `local-equipment-checkout-${equipmentItemId}-${member.staffId}`,
  ];
  equipmentStatus.value = `${name} added and checked out to ${member.displayName}.`;
}

function returnSelectedEquipment(): void {
  const selected = selectedReturnCheckoutIds.value
    .map((checkoutId) =>
      equipmentCheckedOut.value.find((item) => item.checkoutId === checkoutId),
    )
    .filter((item): item is CheckedOutEquipment => item !== undefined);

  if (selected.length === 0) {
    return;
  }

  for (const checkout of selected) {
    board.value = returnEquipmentFromStaff(
      board.value,
      checkout.checkoutId,
      selectedReturnCondition.value,
      new Date().toISOString(),
    );
  }

  selectedEquipmentItemId.value =
    availableEquipment.value[0]?.equipmentItemId ?? "";
  selectedReturnCheckoutIds.value = [];
  equipmentStatus.value = `${selected.length} item${
    selected.length === 1 ? "" : "s"
  } checked in as ${equipmentStateLabel(
    selectedReturnCondition.value,
  )}.`;
}

watch(
  candidates,
  (items) => {
    if (
      selectedCandidateId.value &&
      items.some((candidate) => candidate.staffId === selectedCandidateId.value)
    ) {
      return;
    }

    selectedCandidateId.value = items[0]?.staffId ?? "";
  },
  { immediate: true },
);

watch(
  () => board.value.departmentPresence,
  (items) => {
    if (
      selectedPresenceStaffId.value &&
      items.some((member) => member.staffId === selectedPresenceStaffId.value)
    ) {
      return;
    }

    selectedPresenceStaffId.value = items[0]?.staffId ?? "";
  },
  { immediate: true },
);

watch(
  staffWithCheckedOutEquipment,
  (staff) => {
    if (
      selectedReturnStaffId.value &&
      staff.some((member) => member.staffId === selectedReturnStaffId.value)
    ) {
      return;
    }

    selectedReturnStaffId.value = staff[0]?.staffId ?? "";
  },
  { immediate: true },
);

watch(
  selectedStaffEquipment,
  (items) => {
    selectedReturnCheckoutIds.value = selectedReturnCheckoutIds.value.filter(
      (checkoutId) => items.some((item) => item.checkoutId === checkoutId),
    );

    if (selectedReturnCheckoutIds.value.length === 0 && items[0]) {
      selectedReturnCheckoutIds.value = [items[0].checkoutId];
    }
  },
  { immediate: true },
);
</script>

<template>
  <section class="shift-board" aria-labelledby="shift-board-heading">
    <p class="shift-board__nav">
      <RouterLink :to="{ name: 'home' }">Back to Home</RouterLink>
    </p>

    <header class="shift-board__header">
      <div>
        <p class="shift-board__eyebrow">{{ board.departmentLabel }}</p>
        <h1 id="shift-board-heading" class="shift-board__heading">
          {{ surfaceTitle }}
        </h1>
      </div>
      <p class="shift-board__window" aria-label="Current shift time">
        {{ formatTimestamp(board.startsAt) }} - {{ formatTimestamp(board.endsAt) }}
      </p>
    </header>

    <dl class="shift-board__context" aria-label="Shift context">
      <div>
        <dt>Event</dt>
        <dd>{{ board.eventLabel }}</dd>
      </div>
      <div>
        <dt>Department</dt>
        <dd>{{ board.departmentLabel }}</dd>
      </div>
      <div v-if="board.selectedTeamLabel">
        <dt>Team filter</dt>
        <dd>{{ board.selectedTeamLabel }}</dd>
      </div>
      <div>
        <dt>Active shift</dt>
        <dd>{{ board.shiftTitle }}</dd>
      </div>
    </dl>

    <nav class="shift-board__surface-nav" aria-label="Department board views">
      <RouterLink
        v-for="link in surfaceLinks"
        :key="link.surface"
        :to="{
          name: link.routeName,
          params: {
            eventId: board.eventId,
            departmentId: board.departmentId,
          },
        }"
        :aria-current="props.surface === link.surface ? 'page' : undefined"
      >
        {{ link.label }}
      </RouterLink>
    </nav>

    <dl class="shift-board__summary" aria-label="Department operations summary">
      <div>
        <dt>Shift assignments</dt>
        <dd>{{ summary.rosterCount }}</dd>
      </div>
      <div>
        <dt>Checked in</dt>
        <dd>{{ summary.checkedInCount }}</dd>
      </div>
      <div>
        <dt>On-site</dt>
        <dd>{{ presenceCounts.onSiteCount }}</dd>
      </div>
      <div>
        <dt>Equipment out</dt>
        <dd>{{ equipmentCounts.checkedOutCount }}</dd>
      </div>
    </dl>

    <section
      v-if="isLogisticsSurface"
      class="shift-board__presence"
      aria-labelledby="presence-heading"
    >
      <h2 id="presence-heading" class="shift-board__subheading">
        On-site status
      </h2>
      <form
        class="shift-board__add-form shift-board__presence-form"
        aria-label="Update department on-site status"
      >
        <label class="shift-board__field">
          <span>Department staff</span>
          <select
            v-model="selectedPresenceStaffId"
            :disabled="board.departmentPresence.length === 0"
          >
            <option
              v-for="member in board.departmentPresence"
              :key="member.staffId"
              :value="member.staffId"
            >
              {{ presenceOptionText(member) }}
            </option>
          </select>
        </label>
        <button
          class="shift-board__button"
          type="button"
          :disabled="selectedPresenceStaffId === ''"
          @click="markSelectedStaffOnSite"
        >
          Mark on-site
        </button>
        <button
          class="shift-board__button"
          type="button"
          :disabled="selectedPresenceStaffId === ''"
          @click="markSelectedStaffOffSite"
        >
          Mark off-site
        </button>
      </form>
      <p
        class="shift-board__status"
        role="status"
        aria-label="Presence workflow status"
      >
        {{ presenceStatus ?? "No on-site status changes." }}
      </p>
      <h3 class="shift-board__minor-heading">Currently on-site</h3>
      <p
        v-if="onsiteMembers.length === 0"
        class="shift-board__empty"
        role="status"
      >
        No department staff are marked on-site.
      </p>
      <ul v-else class="shift-board__checked-list">
        <li
          v-for="member in onsiteMembers"
          :key="member.staffId"
          class="shift-board__checked-item"
        >
          <span>{{ member.displayName }}</span>
          <span>{{ member.teamLabel }}</span>
        </li>
      </ul>
    </section>

    <section
      v-if="isLogisticsSurface"
      class="shift-board__unscheduled"
      aria-labelledby="unscheduled-heading"
    >
      <h2 id="unscheduled-heading" class="shift-board__subheading">
        Add on-site staff to shift
      </h2>
      <form
        class="shift-board__add-form"
        aria-label="Add eligible unscheduled staff"
        @submit.prevent="addSelectedCandidate"
      >
        <label class="shift-board__field">
          <span>Staff</span>
          <select
            v-model="selectedCandidateId"
            :disabled="candidates.length === 0"
          >
            <option
              v-for="candidate in candidates"
              :key="candidate.staffId"
              :value="candidate.staffId"
            >
              {{ candidate.displayName }} - {{ candidate.teamLabel }}
            </option>
          </select>
        </label>
        <button
          class="shift-board__button"
          type="submit"
          :disabled="selectedCandidateId === ''"
        >
          Add to shift
        </button>
      </form>
      <p class="shift-board__status" role="status">
        {{ addStatus ?? "No staff added to this shift." }}
      </p>
    </section>

    <section
      v-if="isOperationsSurface"
      class="shift-board__deployments"
      aria-labelledby="deployments-heading"
    >
      <h2 id="deployments-heading" class="shift-board__subheading">
        Deployment assignments
      </h2>
      <form
        class="shift-board__add-form"
        aria-label="Move staff to deployment"
        @submit.prevent="moveSelectedDeployment"
      >
        <label class="shift-board__field">
          <span>Staff</span>
          <select v-model="selectedAssignmentId" :disabled="board.roster.length === 0">
            <option
              v-for="member in board.roster"
              :key="member.assignmentId"
              :value="member.assignmentId"
            >
              {{ member.displayName }} - {{ deploymentText(member) }}
            </option>
          </select>
        </label>
        <label class="shift-board__field">
          <span>Deployment</span>
          <select
            v-model="selectedDeploymentId"
            :disabled="board.deploymentOptions.length === 0"
          >
            <option
              v-for="deployment in board.deploymentOptions"
              :key="deployment.deploymentId"
              :value="deployment.deploymentId"
            >
              {{ deployment.name }}
            </option>
          </select>
        </label>
        <button
          class="shift-board__button"
          type="submit"
          :disabled="selectedAssignmentId === '' || selectedDeploymentId === ''"
        >
          Move to deployment
        </button>
      </form>
      <p
        class="shift-board__status"
        role="status"
        aria-label="Deployment assignment status"
      >
        {{ deploymentStatus ?? "No deployment changes." }}
      </p>
    </section>

    <section
      v-if="showsEquipment"
      class="shift-board__equipment"
      aria-labelledby="equipment-heading"
    >
      <h2 id="equipment-heading" class="shift-board__subheading">
        Equipment
      </h2>

      <div v-if="isLogisticsSurface" class="shift-board__equipment-actions">
        <form
          class="shift-board__add-form"
          aria-label="Check out equipment"
          @submit.prevent="checkoutSelectedEquipment"
        >
          <label class="shift-board__field">
            <span>Equipment</span>
            <select
              v-model="selectedEquipmentItemId"
              :disabled="availableEquipment.length === 0"
            >
              <option
                v-for="equipment in availableEquipment"
                :key="equipment.equipmentItemId"
                :value="equipment.equipmentItemId"
              >
                {{ equipmentItemLabel(equipment) }} -
                {{ equipmentStateLabel(equipment.status) }}
              </option>
            </select>
          </label>
          <label class="shift-board__field">
            <span>Staff</span>
            <select
              v-model="selectedEquipmentAssignmentId"
              :disabled="board.roster.length === 0"
            >
              <option
                v-for="member in board.roster"
                :key="member.assignmentId"
                :value="member.assignmentId"
              >
                {{ member.displayName }}
              </option>
            </select>
          </label>
          <button
            class="shift-board__button"
            type="submit"
            :disabled="
              selectedEquipmentItemId === '' ||
              selectedEquipmentAssignmentId === ''
            "
          >
            Check out equipment
          </button>
        </form>

        <form
          class="shift-board__add-form"
          aria-label="Add and check out equipment"
          @submit.prevent="addAndCheckoutEquipment"
        >
          <label class="shift-board__field">
            <span>New equipment</span>
            <input
              v-model="newEquipmentName"
              type="text"
              autocomplete="off"
              placeholder="Radio 14"
            />
          </label>
          <label class="shift-board__field">
            <span>Asset tag</span>
            <input
              v-model="newEquipmentAssetTag"
              type="text"
              autocomplete="off"
              placeholder="RDO-14"
            />
          </label>
          <label class="shift-board__field">
            <span>Staff</span>
            <select
              v-model="selectedNewEquipmentAssignmentId"
              :disabled="board.roster.length === 0"
            >
              <option
                v-for="member in board.roster"
                :key="member.assignmentId"
                :value="member.assignmentId"
              >
                {{ member.displayName }}
              </option>
            </select>
          </label>
          <button
            class="shift-board__button"
            type="submit"
            :disabled="
              newEquipmentName.trim() === '' ||
              selectedNewEquipmentAssignmentId === ''
            "
          >
            Add and check out
          </button>
        </form>

        <form
          class="shift-board__add-form shift-board__return-form"
          aria-label="Check in equipment by staff member"
          @submit.prevent="returnSelectedEquipment"
        >
          <label class="shift-board__field">
            <span>Staff</span>
            <select
              v-model="selectedReturnStaffId"
              :disabled="staffWithCheckedOutEquipment.length === 0"
            >
              <option
                v-for="member in staffWithCheckedOutEquipment"
                :key="member.staffId"
                :value="member.staffId"
              >
                {{ member.displayName }}
              </option>
            </select>
          </label>
          <fieldset class="shift-board__checks">
            <legend>Items to check in</legend>
            <label
              v-for="checkout in selectedStaffEquipment"
              :key="checkout.checkoutId"
              class="shift-board__check"
            >
              <input
                v-model="selectedReturnCheckoutIds"
                type="checkbox"
                :value="checkout.checkoutId"
              />
              <span>
                {{ checkout.itemName }}
                <template v-if="checkout.assetTag">({{ checkout.assetTag }})</template>
              </span>
            </label>
            <span
              v-if="selectedStaffEquipment.length === 0"
              class="shift-board__empty"
            >
              This staff member has no checked-out equipment.
            </span>
          </fieldset>
          <label class="shift-board__field">
            <span>Return state</span>
            <select v-model="selectedReturnCondition">
              <option value="returned">Returned</option>
              <option value="missing">Missing</option>
              <option value="damaged">Damaged</option>
            </select>
          </label>
          <button
            class="shift-board__button"
            type="submit"
            :disabled="selectedReturnCheckoutIds.length === 0"
          >
            Check in equipment
          </button>
        </form>
      </div>

      <p
        v-if="isLogisticsSurface"
        class="shift-board__status"
        role="status"
        aria-label="Equipment workflow status"
      >
        {{ equipmentStatus ?? "No equipment changes." }}
      </p>

      <h3 class="shift-board__minor-heading">Checked-out equipment</h3>
      <p
        v-if="equipmentCheckedOut.length === 0"
        class="shift-board__empty"
        role="status"
      >
        No equipment is checked out.
      </p>
      <ul v-else class="shift-board__checked-list">
        <li
          v-for="checkout in equipmentCheckedOut"
          :key="checkout.checkoutId"
          class="shift-board__checked-item"
        >
          <span>{{ checkout.itemName }}</span>
          <span>{{ checkedOutEquipmentText(checkout) }}</span>
        </li>
      </ul>
    </section>

    <section
      v-if="isPlanningSurface"
      class="shift-board__planning"
      aria-labelledby="planning-schedule-heading"
    >
      <h2 id="planning-schedule-heading" class="shift-board__subheading">
        Shift schedule
      </h2>
      <div class="shift-board__table-frame">
        <table class="shift-board__table">
          <thead>
            <tr>
              <th scope="col">Shift</th>
              <th scope="col">Team</th>
              <th scope="col">Window</th>
              <th scope="col">Signups</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="shift in board.shiftSchedule" :key="shift.shiftId">
              <th scope="row" data-label="Shift">{{ shift.title }}</th>
              <td data-label="Team">{{ shift.teamLabel }}</td>
              <td data-label="Window">
                {{ formatTimestamp(shift.startsAt) }} -
                {{ formatTimestamp(shift.endsAt) }}
              </td>
              <td data-label="Signups">{{ shift.signupCount }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section
      v-if="isPlanningSurface"
      class="shift-board__planning"
      aria-labelledby="planning-signups-heading"
    >
      <h2 id="planning-signups-heading" class="shift-board__subheading">
        Shift signups
      </h2>
      <div class="shift-board__table-frame">
        <table class="shift-board__table">
          <thead>
            <tr>
              <th scope="col">Staff</th>
              <th scope="col">Team</th>
              <th scope="col">Shift</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="signup in board.shiftSignups" :key="signup.signupId">
              <th scope="row" data-label="Staff">{{ signup.displayName }}</th>
              <td data-label="Team">{{ signup.teamLabel }}</td>
              <td data-label="Shift">{{ shiftTitle(signup.shiftId) }}</td>
              <td data-label="Status">{{ shiftSignupStateLabel(signup.state) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section
      v-if="isPlanningSurface"
      class="shift-board__planning"
      aria-labelledby="planning-members-heading"
    >
      <h2 id="planning-members-heading" class="shift-board__subheading">
        Team members
      </h2>
      <div class="shift-board__table-frame">
        <table class="shift-board__table">
          <thead>
            <tr>
              <th scope="col">Staff</th>
              <th scope="col">Team</th>
              <th scope="col">Presence</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in board.teamMembers" :key="member.staffId">
              <th scope="row" data-label="Staff">
                <span class="shift-board__name">{{ member.displayName }}</span>
                <span v-if="member.handle" class="shift-board__handle"
                  >@{{ member.handle }}</span
                >
              </th>
              <td data-label="Team">{{ member.teamLabel }}</td>
              <td data-label="Presence">
                {{ presenceStateLabel(member.presenceState) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section
      v-if="showsCheckedIn"
      class="shift-board__checked-in"
      aria-labelledby="checked-in-heading"
    >
      <h2 id="checked-in-heading" class="shift-board__subheading">
        Checked-in staff
      </h2>
      <p
        v-if="checkedInMembers.length === 0"
        class="shift-board__empty"
        role="status"
      >
        No staff are checked in.
      </p>
      <ul v-else class="shift-board__checked-list">
        <li
          v-for="member in checkedInMembers"
          :key="member.assignmentId"
          class="shift-board__checked-item"
        >
          <span>{{ member.displayName }}</span>
          <span>{{ checkedInText(member) }}</span>
        </li>
      </ul>
    </section>

    <section
      v-if="showsAssignments"
      class="shift-board__roster"
      aria-labelledby="roster-heading"
    >
      <h2 id="roster-heading" class="shift-board__subheading">
        Shift assignments
      </h2>

      <div class="shift-board__table-frame">
        <table class="shift-board__table">
          <thead>
            <tr>
              <th scope="col">Staff</th>
              <th scope="col">Team</th>
              <th scope="col">Attendance</th>
              <th scope="col">Deployment</th>
              <th scope="col">Checked-in time</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in board.roster" :key="member.assignmentId">
              <th scope="row" data-label="Staff">
                <span class="shift-board__name">{{ member.displayName }}</span>
                <span v-if="member.handle" class="shift-board__handle"
                  >@{{ member.handle }}</span
                >
              </th>
              <td data-label="Team">{{ member.teamLabel }}</td>
              <td data-label="Attendance">
                <span
                  class="shift-board__state"
                  :class="`shift-board__state--${member.attendanceState}`"
                >
                  {{ attendanceStateLabel(member.attendanceState) }}
                </span>
              </td>
              <td data-label="Deployment">{{ deploymentText(member) }}</td>
              <td data-label="Checked-in time">{{ checkedInText(member) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</template>

<style scoped>
.shift-board {
  width: var(--m-content-wide);
}

.shift-board__nav {
  margin: 0 0 var(--m-space-4);
}

.shift-board__nav a {
  color: var(--m-text-primary);
}

.shift-board__nav a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.shift-board__header {
  display: grid;
  gap: var(--m-space-3);
  margin-bottom: var(--m-space-4);
}

.shift-board__eyebrow,
.shift-board__window,
.shift-board__empty {
  margin: 0;
  color: var(--m-text-muted);
}

.shift-board__eyebrow {
  font-size: var(--m-text-sm);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0;
}

.shift-board__heading {
  margin: var(--m-space-1) 0 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.shift-board__window {
  font-size: var(--m-text-sm);
}

.shift-board__context,
.shift-board__summary {
  display: grid;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
  grid-template-columns: minmax(0, 1fr);
}

.shift-board__surface-nav {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-5);
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.shift-board__surface-nav a {
  min-height: 2.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
  text-decoration: none;
}

.shift-board__surface-nav a[aria-current="page"] {
  border-color: var(--m-text-primary);
  background: var(--m-text-primary);
  color: var(--m-surface-app);
}

.shift-board__surface-nav a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.shift-board__context div,
.shift-board__summary div {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  padding: var(--m-space-3);
}

.shift-board__context dt,
.shift-board__summary dt {
  margin: 0 0 var(--m-space-1);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.shift-board__context dd,
.shift-board__summary dd {
  margin: 0;
  font-weight: 700;
}

.shift-board__summary dd {
  font-size: var(--m-text-xl);
}

.shift-board__checked-in,
.shift-board__deployments,
.shift-board__equipment,
.shift-board__planning,
.shift-board__presence,
.shift-board__unscheduled,
.shift-board__roster {
  margin-top: var(--m-space-8);
}

.shift-board__subheading {
  margin: 0 0 var(--m-space-3);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.shift-board__minor-heading {
  margin: var(--m-space-4) 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
}

.shift-board__equipment-actions {
  display: grid;
  gap: var(--m-space-4);
}

.shift-board__equipment-actions .shift-board__add-form {
  max-width: none;
}

.shift-board__checked-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: var(--m-space-3);
}

.shift-board__checked-item {
  display: grid;
  gap: var(--m-space-1);
  border-left: 4px solid var(--m-status-success);
  padding: var(--m-space-3);
  background: var(--m-surface-raised);
}

.shift-board__checked-item span:last-child {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.shift-board__add-form {
  display: grid;
  align-items: stretch;
  gap: var(--m-space-3);
  width: 100%;
}

.shift-board__field {
  display: grid;
  flex: 1;
  gap: var(--m-space-1);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.shift-board__field input,
.shift-board__field select {
  min-height: 2.75rem;
  width: 100%;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  padding: 0 var(--m-space-3);
}

.shift-board__button {
  min-height: 2.75rem;
  width: 100%;
  border: 1px solid var(--m-text-primary);
  border-radius: var(--m-radius-sm);
  background: var(--m-text-primary);
  color: var(--m-surface-app);
  font: inherit;
  font-weight: 700;
  padding: 0 var(--m-space-4);
}

.shift-board__button:disabled,
.shift-board__field input:disabled,
.shift-board__field select:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.shift-board__button:focus-visible,
.shift-board__field input:focus-visible,
.shift-board__field select:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.shift-board__checks {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.shift-board__checks legend {
  padding: 0 var(--m-space-1);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.shift-board__check {
  display: grid;
  grid-template-columns: 1.25rem minmax(0, 1fr);
  gap: var(--m-space-2);
  align-items: start;
  min-height: 2.5rem;
  color: var(--m-text-primary);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.shift-board__check input {
  width: 1.1rem;
  height: 1.1rem;
  margin-top: 0.15rem;
  accent-color: var(--m-action-primary-bg);
}

.shift-board__check input:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.shift-board__status {
  margin: var(--m-space-3) 0 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.shift-board__table-frame {
  overflow: hidden;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.shift-board__table {
  width: 100%;
  border-collapse: collapse;
}

.shift-board__table thead {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.shift-board__table tbody {
  display: grid;
}

.shift-board__table tr {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-3);
  border-bottom: 1px solid var(--m-border-subtle);
}

.shift-board__table tbody tr:last-child {
  border-bottom: 0;
}

.shift-board__table th,
.shift-board__table td {
  display: grid;
  grid-template-columns: minmax(0, 7rem) minmax(0, 1fr);
  gap: var(--m-space-2);
  padding: 0;
  text-align: left;
  vertical-align: top;
  border-bottom: 0;
}

.shift-board__table th::before,
.shift-board__table td::before {
  content: attr(data-label);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.shift-board__name,
.shift-board__handle {
  display: block;
  grid-column: 2;
}

.shift-board__handle {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 400;
}

.shift-board__state {
  display: inline-flex;
  grid-column: 2;
  align-items: center;
  width: fit-content;
  min-height: 1.75rem;
  padding: 0 var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-app);
  color: var(--m-text-primary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.shift-board__state--checked_in {
  border-color: var(--m-status-success);
  color: var(--m-status-success);
}

.shift-board__state--no_show {
  border-color: var(--m-status-danger);
  color: var(--m-status-danger);
}

@media (min-width: 44rem) {
  .shift-board__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--m-space-4);
  }

  .shift-board__window {
    text-align: right;
  }

  .shift-board__context,
  .shift-board__summary {
    grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  }

  .shift-board__surface-nav {
    display: flex;
    flex-wrap: wrap;
  }

  .shift-board__add-form {
    max-width: min(100%, 44rem);
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    align-items: end;
  }

  .shift-board__button {
    width: auto;
  }

  .shift-board__checked-item {
    display: flex;
    justify-content: space-between;
    gap: var(--m-space-3);
  }
}

@media (min-width: 64rem) {
  .shift-board__equipment-actions {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .shift-board__return-form {
    grid-column: 1 / -1;
  }

  .shift-board__table-frame {
    overflow-x: auto;
  }

  .shift-board__table {
    min-width: 52rem;
  }

  .shift-board__table thead {
    position: static;
    width: auto;
    height: auto;
    padding: 0;
    margin: 0;
    overflow: visible;
    clip: auto;
    white-space: normal;
    border: 0;
  }

  .shift-board__table tbody {
    display: table-row-group;
  }

  .shift-board__table tr {
    display: table-row;
    padding: 0;
    border-bottom: 0;
  }

  .shift-board__table th,
  .shift-board__table td {
    display: table-cell;
    padding: var(--m-space-3);
    border-bottom: 1px solid var(--m-border-subtle);
  }

  .shift-board__table th::before,
  .shift-board__table td::before {
    content: none;
  }

  .shift-board__table tbody tr:last-child th,
  .shift-board__table tbody tr:last-child td {
    border-bottom: 0;
  }

  .shift-board__table thead th {
    color: var(--m-text-muted);
    font-size: var(--m-text-sm);
    font-weight: 700;
  }

  .shift-board__name,
  .shift-board__handle,
  .shift-board__state {
    grid-column: auto;
  }
}
</style>
