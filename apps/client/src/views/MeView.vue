<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import { formatTimestamp } from "@/department-ops/labels";
import { clientSessionState } from "@/session/clientSession";
import {
  selectedSessionDepartment,
  selectedSessionDepartmentRouteParams,
  sessionDepartmentRoleSummary,
  sessionEventContext,
  sessionEventTimeZone,
  sessionEventWindow,
  sessionLedTeams,
} from "@/session/sessionAccess";
import {
  getShiftBoard,
  shiftBoardWindowLabel,
  type ShiftBoardEntry,
} from "@/shift-board/staffShiftBoardModel";
import {
  getMyProfile,
  profileDisplayName,
  type MyStaffProfile,
} from "@/staff-profile/myProfileModel";

/**
 * The staff member's own page (UI contract 12.3; bound to the session in M18.9).
 *
 * Every fact on it used to come from `department-ops/fixtures.ts`: the name, the
 * event, the department, the schedule, and a years-of-service figure computed
 * from the fixture's shift dates. So a signed-in staff member opened their own
 * profile and was shown somebody else's — "Local Field Author", at "Local Field
 * Event" — with no indication that any of it was invented.
 *
 * Identity, department, team, and role now come from the session document, which
 * is the same answer the server enforces (CLIENT-001 through CLIENT-004). The
 * schedule is the staff shift board (M18.2), filtered to the shifts this person
 * actually holds.
 *
 * The profile rows — handle, phone, city/state, and the picture — come from the
 * profile read M18.20 added (`GET /api/me/profile`), the same read the edit
 * page writes back through. When that read has not answered, the rows are
 * simply absent rather than printed as "Not set": a claim about somebody's
 * record needs the record. Presence remains the one fixture row still gone,
 * because nothing reads it yet.
 */
const ROLE_SHIFT_LEAD = "shift_lead";

/**
 * The caller's own staff record (M18.20; VOL-009). The first when a login
 * speaks for several: this page is a summary, and the edit page is where the
 * choice of record is made explicit.
 */
const profile = ref<MyStaffProfile | null>(null);

async function loadProfile(): Promise<void> {
  try {
    profile.value = (await getMyProfile()).profiles[0] ?? null;
  } catch {
    // The page still stands on the session document; the profile rows are
    // absent rather than invented, and the edit page reports its own reads.
    profile.value = null;
  }
}

void loadProfile();

/**
 * The name that leads: the handle where there is one (VOL-010), because that
 * is what people at the event call each other.
 */
const displayName = computed(() => {
  if (profile.value !== null) {
    return profileDisplayName(profile.value);
  }

  return clientSessionState.document?.user.name ?? "Not signed in";
});

/** The name on the record, shown under the handle rather than replaced by it. */
const recordName = computed(
  () => profile.value?.preferredName ?? profile.value?.legalName ?? null,
);
const initials = computed(() =>
  displayName.value
    .split(/\s+/u)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join(""),
);
const department = computed(() => selectedSessionDepartment.value);
const eventId = computed(() => sessionEventContext.value?.eventId ?? null);
const eventLabel = computed(
  () => sessionEventContext.value?.eventLabel ?? "No event selected",
);
const timeZone = computed(() => sessionEventTimeZone.value);
const eventStatus = computed<"ongoing" | "upcoming" | "ended" | "unscheduled">(
  () => {
    const startsAt = sessionEventWindow.value?.startsAt ?? null;
    const endsAt = sessionEventWindow.value?.endsAt ?? null;
    const now = Date.now();

    if (startsAt !== null && now < Date.parse(startsAt)) {
      return "upcoming";
    }

    if (endsAt !== null && now > Date.parse(endsAt)) {
      return "ended";
    }

    return startsAt === null && endsAt === null ? "unscheduled" : "ongoing";
  },
);
const eventCards = computed(() => {
  const id = eventId.value;

  return id === null
    ? []
    : [
        {
          id,
          label: eventLabel.value,
          department: department.value?.departmentLabel ?? "No department",
          status: eventStatus.value,
          startsAt: sessionEventWindow.value?.startsAt ?? null,
          endsAt: sessionEventWindow.value?.endsAt ?? null,
        },
      ];
});
const memberTeamLabels = computed(() =>
  (department.value?.teams ?? []).map((team) => team.teamLabel),
);
const ledTeams = computed(() =>
  sessionLedTeams(department.value, ROLE_SHIFT_LEAD),
);
const leadTeamId = computed(() => ledTeams.value[0]?.teamId ?? null);

/**
 * The shifts this person holds on the context event, soonest first.
 *
 * Read from the board rather than from a page of its own, because "the shifts I
 * am on" is the same question the board answers and a second endpoint would be a
 * second answer to disagree with it. Cancelled shifts drop out; a shift somebody
 * is signed up for that was then cancelled is not a shift they are working.
 */
const board = ref<readonly ShiftBoardEntry[]>([]);
const scheduleError = ref<string | null>(null);
const scheduleLoading = ref(false);

const scheduleRows = computed(() =>
  board.value
    .filter((shift) => shift.signedUp && shift.cancelledAt === null)
    .slice()
    .sort((left, right) => (left.startsAt ?? "").localeCompare(right.startsAt ?? "")),
);

async function loadSchedule(): Promise<void> {
  const id = eventId.value;

  if (id === null) {
    board.value = [];

    return;
  }

  scheduleLoading.value = true;
  scheduleError.value = null;

  try {
    board.value = (await getShiftBoard(id)).shifts;
  } catch (error) {
    board.value = [];
    scheduleError.value = meridianErrorMessage(
      error,
      "Unable to read your schedule. Check the connection to this node and try again.",
    );
  } finally {
    scheduleLoading.value = false;
  }
}

watch(eventId, () => void loadSchedule(), { immediate: true });

const personalDetails = computed(() => {
  const details = [
    {
      label: "Department",
      value: department.value?.departmentLabel ?? "Not set",
    },
    {
      label: "Team",
      value:
        memberTeamLabels.value.length > 0
          ? memberTeamLabels.value.join(", ")
          : "Not set",
    },
    {
      label: "Role",
      value:
        department.value === null
          ? "Not set"
          : sessionDepartmentRoleSummary(department.value),
    },
    {
      label: "Events",
      value: `${clientSessionState.document?.events.length ?? 0}`,
    },
  ];

  // Profile rows appear once the profile read answered (M18.20; VOL-009,
  // VOL-010). "Not set" is only printed against the record itself.
  const current = profile.value;

  if (current !== null) {
    details.push(
      { label: "Handle", value: current.handle ?? "Not set" },
      { label: "Phone", value: current.phone ?? "Not set" },
      {
        label: "City/State",
        value:
          [current.city, current.state].filter(Boolean).join(", ") || "Not set",
      },
    );
  }

  return details;
});
/**
 * Role-aware event routing (M11.20, UI contract 12.3). Department leads land on
 * Department Overview, team leads on the team overview for a team they lead,
 * and everyone else on Event Info. The team-lead branch previously fell through
 * to the Admin page as an interim, which answered who is on the team but not
 * what the team is doing.
 */
const isDepartmentLead = computed(() =>
  (department.value?.roleCodes ?? []).includes("department_lead"),
);
const departmentRouteParams = computed(
  () => selectedSessionDepartmentRouteParams.value,
);
const currentEventTarget = computed(() => {
  const params = departmentRouteParams.value;

  if (params !== null && isDepartmentLead.value) {
    return { name: "events.departments.overview", params };
  }

  if (params !== null && leadTeamId.value !== null) {
    return {
      name: "events.departments.teams.show",
      params: { ...params, teamId: leadTeamId.value },
    };
  }

  return { name: "events.info", params: { eventId: eventId.value ?? "" } };
});
const currentEventTargetLabel = computed(() => {
  if (departmentRouteParams.value !== null && isDepartmentLead.value) {
    return "Opens Department Overview";
  }

  return departmentRouteParams.value !== null && leadTeamId.value !== null
    ? "Opens Team Overview"
    : "Opens Event Info";
});
</script>

<template>
  <section class="me" aria-labelledby="me-heading">
    <p class="me__nav">
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </p>

    <header class="me__hero">
      <img
        v-if="profile?.profilePictureUrl"
        class="me__photo me__photo--picture"
        :src="profile.profilePictureUrl"
        :alt="`${displayName} profile photo`"
      />
      <div
        v-else
        class="me__photo"
        role="img"
        :aria-label="`${displayName} profile photo`"
      >
        <span>{{ initials }}</span>
      </div>
      <div class="me__identity">
        <p class="me__eyebrow">Staff profile</p>
        <h1 id="me-heading">{{ displayName }}</h1>
        <!--
          The name on the record, under the handle that leads (VOL-010).
          Absent when they are the same, so nobody reads their own name twice.
        -->
        <p v-if="recordName && recordName !== displayName" class="me__record-name">
          {{ recordName }}
        </p>
        <p>{{ eventLabel }} - {{ department?.departmentLabel ?? "No department" }}</p>
      </div>
      <dl class="me__details" aria-label="Personal details">
        <div v-for="detail in personalDetails" :key="detail.label">
          <dt>{{ detail.label }}</dt>
          <dd>{{ detail.value }}</dd>
        </div>
      </dl>
    </header>

    <nav class="me__links" aria-label="Me links">
      <RouterLink :to="{ name: 'staff.profile.edit' }">Edit Profile</RouterLink>
      <RouterLink
        v-if="eventId"
        :to="{ name: 'events.info', params: { eventId } }"
      >
        Event Info
      </RouterLink>
      <RouterLink :to="{ name: 'staff.field-reports.index' }">
        My Field Reports
      </RouterLink>
      <RouterLink :to="{ name: 'readiness' }">Device Readiness</RouterLink>
      <RouterLink :to="{ name: 'settings.about' }">Account and Device</RouterLink>
    </nav>

    <section class="me__section" aria-labelledby="me-assignments-heading">
      <div class="me__section-heading">
        <p class="me__eyebrow">Assignments</p>
        <h2 id="me-assignments-heading">Upcoming assignments and schedule</h2>
      </div>

      <div class="me__event-list" aria-label="Events">
        <RouterLink
          v-for="event in eventCards"
          :key="event.id"
          class="me__event"
          :to="currentEventTarget"
        >
          <span class="me__event-status" :data-status="event.status">
            {{ event.status }}
          </span>
          <strong>{{ event.label }}</strong>
          <span>{{ event.department }}</span>
          <small v-if="event.startsAt && event.endsAt">
            {{ formatTimestamp(event.startsAt, timeZone) }}
            to
            {{ formatTimestamp(event.endsAt, timeZone) }}
          </small>
          <small>{{ currentEventTargetLabel }}</small>
        </RouterLink>
      </div>

      <section
        class="me__schedule"
        aria-labelledby="me-current-schedule-heading"
      >
        <h3 id="me-current-schedule-heading">
          {{
            eventStatus === "ongoing"
              ? "Schedule for ongoing event"
              : "Schedule for next event"
          }}
        </h3>
        <!--
          A read that failed is stated as a failed read. It used to be reported
          as "no upcoming assignments", which tells somebody they are on no
          shifts when what happened is that nobody asked.
        -->
        <p v-if="scheduleError" class="me__schedule-error" role="alert">
          {{ scheduleError }}
          <button type="button" @click="loadSchedule()">Try again</button>
        </p>
        <p v-else-if="scheduleLoading" role="status">Reading your schedule.</p>
        <p v-else-if="scheduleRows.length === 0" role="status">
          You are not signed up for any shifts on this event.
        </p>
        <ul v-else class="me__schedule-list">
          <li v-for="shift in scheduleRows" :key="shift.id">
            <div>
              <strong>{{ shift.title }}</strong>
              <span>
                {{ shift.departmentName ?? "Department" }} -
                {{ shift.eligibleTeamName ?? "Team" }}
              </span>
            </div>
            <dl>
              <div>
                <dt>When</dt>
                <dd>{{ shiftBoardWindowLabel(shift) }}</dd>
              </div>
              <div>
                <dt>Status</dt>
                <dd>{{ shift.assignmentStatus ?? "Signed up" }}</dd>
              </div>
            </dl>
          </li>
        </ul>
      </section>
    </section>
  </section>
</template>

<style scoped>
.me {
  display: grid;
  gap: var(--m-space-5);
  width: var(--m-content-workflow);
}

.me__schedule-error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-3);
  margin: 0;
  color: var(--m-status-danger, #cc792f);
}

.me__hero,
.me__section {
  display: grid;
  gap: var(--m-space-4);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.me__nav {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.me__nav a {
  color: var(--m-text-secondary);
  text-decoration: none;
}

.me__photo {
  display: grid;
  place-items: center;
  width: clamp(7rem, 42vw, 10rem);
  aspect-ratio: 1;
  border: 1px solid var(--m-border-default);
  border-radius: 50%;
  background:
    radial-gradient(
      circle at 34% 26%,
      color-mix(in srgb, var(--m-action-secondary-bg) 24%, transparent),
      transparent 32%
    ),
    linear-gradient(
      145deg,
      color-mix(in srgb, var(--m-surface-base) 88%, transparent),
      color-mix(in srgb, var(--m-action-primary-bg) 18%, var(--m-surface-raised))
    );
  color: var(--m-text-primary);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  font-weight: 900;
}

.me__photo--picture {
  object-fit: cover;
}

.me__photo span {
  display: grid;
  place-items: center;
  width: 58%;
  aspect-ratio: 1;
  border-radius: 50%;
  background: color-mix(in srgb, var(--m-surface-overlay) 72%, transparent);
}

.me__identity {
  display: grid;
  gap: var(--m-space-1);
}

.me__identity h1,
.me__section-heading h2,
.me__schedule h3 {
  margin: 0;
  font-family: var(--m-font-heading);
}

.me__identity h1 {
  font-size: var(--m-text-xl);
}

.me__identity p,
.me__section-heading,
.me__schedule p {
  margin: 0;
  color: var(--m-text-muted);
}

.me__record-name {
  color: var(--m-text-secondary);
  font-weight: 700;
}

.me__eyebrow {
  margin: 0;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 900;
  text-transform: uppercase;
}

.me__details {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
  gap: var(--m-space-2);
  margin: 0;
}

.me__details div {
  min-width: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: 8px;
  background: var(--m-surface-base);
}

.me__details dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.me__details dd {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-primary);
  font-weight: 800;
}

.me__links,
.me__event-list {
  display: grid;
  gap: var(--m-space-3);
}

.me__links a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font-weight: 800;
  text-decoration: none;
}

.me__event,
.me__schedule-list li {
  display: grid;
  gap: var(--m-space-4);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  text-decoration: none;
}

.me__event strong,
.me__schedule-list strong {
  font-size: var(--m-text-md);
}

.me__event span,
.me__event small,
.me__schedule-list span,
.me__schedule-list dd {
  color: var(--m-text-muted);
}

.me__event-status {
  justify-self: start;
  padding: 0.2rem 0.55rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.me__event-status[data-status="ongoing"] {
  border-color: var(--m-status-success);
  color: var(--m-text-primary);
}

.me__schedule {
  display: grid;
  gap: var(--m-space-3);
}

.me__schedule-list {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}

.me__schedule-list dl {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
}

.me__schedule-list li > div:first-child {
  display: grid;
  gap: var(--m-space-2);
}

.me__schedule-list dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.me__schedule-list dd {
  margin: var(--m-space-1) 0 0;
}

.me__links a:focus-visible,
.me__nav a:focus-visible,
.me__event:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 48rem) {
  .me__hero {
    grid-template-columns: auto minmax(12rem, 1fr);
    align-items: center;
  }

  .me__details {
    grid-column: 1 / -1;
  }

  .me__links {
    grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  }

  .me__schedule-list dl {
    grid-template-columns: 1fr minmax(8rem, auto);
  }
}

/*
 * Past a laptop, the event cards and the shift schedule tile instead of
 * stacking, so a staff member with a full rotation sees the whole rotation
 * rather than the first two entries and a scrollbar.
 */
@media (min-width: 64rem) {
  .me__event-list,
  .me__schedule-list {
    grid-template-columns: repeat(auto-fill, minmax(var(--m-tile-min), 1fr));
    align-items: start;
  }

  .me__hero {
    grid-template-columns: auto minmax(12rem, 1fr) minmax(0, 2fr);
  }

  .me__details {
    grid-column: 3;
    grid-row: 1;
    align-self: center;
  }
}
</style>
