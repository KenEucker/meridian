<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import {
  assignDepartmentToEvent,
  createEvent,
  getEventAdministration,
  removeDepartmentFromEvent,
  updateEvent,
  type AdministrableEvent,
  type EventAdministration,
  type EventAdministrationDraft,
  type ParticipatingDepartment,
} from "@/organizer-events/eventAdministrationModel";
import { sessionOrganizationId } from "@/session/sessionContext";

/**
 * `organizer.events` — event administration (M18.29; UI contract 12.6;
 * ORG-005, ORG-006; data/API 10.2).
 *
 * Declaring when an event runs has lived only in the God Mode console until
 * now, which made the fact everything else is measured from — the active event
 * window that freezes governance, closes the credential question, and starts
 * the hours grace period — reachable only through the repair interface. This is
 * the same record, answering to the catalog.
 *
 * The two windows are separate fields on purpose and the surface says why. The
 * published dates are what an event tells its staff; the active event window is
 * the operational phase, which starts before the gates open and ends after
 * teardown, and it is the one that moves authority to the on-site node and
 * freezes governance content.
 *
 * Which departments work the event is managed here too (M18.31). It is on the
 * event card rather than inside the edit form because adding a department is
 * its own act with its own audit entry, not a field of the event, and because
 * the Incident Command select in the form reads the very list being edited —
 * changing participation and then choosing from a stale copy of it is how an
 * organizer gets a refusal for a department they just added.
 *
 * Incident Command is offered per event and never per organization, because
 * ORG-006 admits only a department assigned to that event. An event with no
 * override says what it inherits (ORG-005) rather than showing an empty select
 * that reads like nobody is running incidents.
 *
 * A department running Incident Command or Placement for the event says so and
 * is offered no Remove, with the node's own sentence in its place. The node
 * refuses the removal regardless (ORG-006, PLACE-003); the difference is being
 * told which designation to clear first rather than finding out by being
 * refused.
 *
 * An event this node may not write says so and offers no save. It is not the
 * boundary — the node refuses the write regardless (CLIENT-006) — it is the
 * difference between being told where the edit belongs and finding out by
 * having one refused.
 */
const administration = ref<EventAdministration | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const saveError = ref<string | null>(null);
const notice = ref<string | null>(null);
const saving = ref(false);

/** The event being edited, or `"new"` while creating one. */
const editing = ref<string | null>(null);

interface EventForm {
  name: string;
  slug: string;
  timezone: string;
  minimumStaffAge: string;
  startsAt: string;
  endsAt: string;
  activeWindowStartsAt: string;
  activeWindowEndsAt: string;
  icDepartmentId: string;
}

const form = reactive<EventForm>(emptyForm());

const organizationId = computed(() => sessionOrganizationId.value);
const events = computed(() => administration.value?.events ?? []);
const editingEvent = computed(() =>
  editing.value === null || editing.value === "new"
    ? null
    : (events.value.find((event) => event.id === editing.value) ?? null),
);
// ORG-006 admits only a department assigned to this event, which is exactly the
// participating list — so the select reads that rather than a second list.
const icOptions = computed(
  () => editingEvent.value?.participatingDepartments ?? [],
);

/** The department chosen in each event's "add a department" select. */
const departmentToAdd = reactive<Record<string, string>>({});

/** The event whose participation change was refused, and what the node said. */
const participationError = ref<{ eventId: string; message: string } | null>(
  null,
);
const changingParticipation = ref(false);

function emptyForm(): EventForm {
  return {
    name: "",
    slug: "",
    // The device's own zone as the starting point for a new event, because it
    // is the only guess available and a wrong-but-visible default is easier to
    // correct than an empty required field.
    timezone: deviceTimeZone(),
    minimumStaffAge: "",
    startsAt: "",
    endsAt: "",
    activeWindowStartsAt: "",
    activeWindowEndsAt: "",
    icDepartmentId: "",
  };
}

function deviceTimeZone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
  } catch {
    return "UTC";
  }
}

/**
 * A `datetime-local` value for a moment, in the reader's own clock.
 *
 * Built from local components rather than sliced off an ISO string, which is
 * the UTC wall time wearing a local label: the input is read back as local, so
 * a UTC-shaped default would round-trip to a moment offset by however far the
 * reader is from Greenwich.
 */
function toDateTimeLocal(timestamp: string | null): string {
  if (timestamp === null || timestamp === "") {
    return "";
  }

  const at = new Date(timestamp);

  if (Number.isNaN(at.getTime())) {
    return "";
  }

  const pad = (value: number): string => String(value).padStart(2, "0");

  return [
    `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`,
    `${pad(at.getHours())}:${pad(at.getMinutes())}`,
  ].join("T");
}

function toInstant(value: string): string | null {
  if (value === "") {
    return null;
  }

  const at = new Date(value);

  return Number.isNaN(at.getTime()) ? null : at.toISOString();
}

function formatWindow(startsAt: string | null, endsAt: string | null): string {
  if (startsAt === null && endsAt === null) {
    return "Not set";
  }

  const format = (value: string | null): string => {
    if (value === null) {
      return "not set";
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? "not set" : parsed.toLocaleString();
  };

  return `${format(startsAt)} to ${format(endsAt)}`;
}

function phaseLabel(event: AdministrableEvent): string {
  return event.authority.phase === "active"
    ? "Running now"
    : event.authority.phase === "closed"
      ? "Window closed"
      : "Before the window";
}

function startCreate(): void {
  Object.assign(form, emptyForm());
  editing.value = "new";
  saveError.value = null;
  notice.value = null;
}

function startEdit(event: AdministrableEvent): void {
  Object.assign(form, {
    name: event.name,
    slug: event.slug,
    timezone: event.timezone,
    minimumStaffAge:
      event.minimumStaffAge === null ? "" : String(event.minimumStaffAge),
    startsAt: toDateTimeLocal(event.startsAt),
    endsAt: toDateTimeLocal(event.endsAt),
    activeWindowStartsAt: toDateTimeLocal(event.activeWindowStartsAt),
    activeWindowEndsAt: toDateTimeLocal(event.activeWindowEndsAt),
    icDepartmentId: event.icDepartmentId ?? "",
  });
  editing.value = event.id;
  saveError.value = null;
  notice.value = null;
}

function cancel(): void {
  editing.value = null;
  saveError.value = null;
}

function draft(includeIncidentCommand: boolean): EventAdministrationDraft {
  const base: EventAdministrationDraft = {
    name: form.name.trim(),
    slug: form.slug.trim(),
    timezone: form.timezone.trim(),
    minimum_staff_age:
      form.minimumStaffAge.trim() === ""
        ? null
        : Number(form.minimumStaffAge.trim()),
    starts_at: toInstant(form.startsAt),
    ends_at: toInstant(form.endsAt),
    active_event_window_starts_at: toInstant(form.activeWindowStartsAt),
    active_event_window_ends_at: toInstant(form.activeWindowEndsAt),
  };

  // Only an edit carries the designation, and only when this event has
  // departments to choose from. A create has no event department assignments
  // yet, so ORG-006 has nothing to admit and the node would refuse the choice.
  return includeIncidentCommand
    ? {
        ...base,
        ic_department_id:
          form.icDepartmentId === "" ? null : form.icDepartmentId,
      }
    : base;
}

async function load(): Promise<void> {
  const id = organizationId.value;

  if (id === null || id === "") {
    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    administration.value = await getEventAdministration(id);
  } catch (error) {
    loadError.value = meridianErrorMessage(
      error,
      "Events could not be read.",
    );
  } finally {
    loading.value = false;
  }
}

async function save(): Promise<void> {
  const id = organizationId.value;

  if (id === null || id === "" || editing.value === null) {
    return;
  }

  saving.value = true;
  saveError.value = null;
  notice.value = null;

  try {
    administration.value =
      editing.value === "new"
        ? await createEvent(id, draft(false))
        : await updateEvent(id, editing.value, draft(true));

    notice.value = `${form.name.trim()} was saved.`;
    editing.value = null;
  } catch (error) {
    saveError.value = meridianErrorMessage(
      error,
      "That event could not be saved.",
    );
  } finally {
    saving.value = false;
  }
}

/**
 * Add a department to an event, or take one out (M18.31).
 *
 * Both answer with the whole administration payload, so the participating list,
 * the Incident Command choices, and the designation labels all move together on
 * one read rather than being patched locally into three views of the same fact.
 */
async function changeParticipation(
  event: AdministrableEvent,
  departmentId: string,
  change: (
    organizationId: string,
    eventId: string,
    departmentId: string,
  ) => Promise<EventAdministration>,
): Promise<void> {
  const id = organizationId.value;

  if (id === null || id === "" || departmentId === "") {
    return;
  }

  changingParticipation.value = true;
  participationError.value = null;
  notice.value = null;

  try {
    administration.value = await change(id, event.id, departmentId);
    departmentToAdd[event.id] = "";
  } catch (error) {
    participationError.value = {
      eventId: event.id,
      message: meridianErrorMessage(
        error,
        "That department could not be changed.",
      ),
    };
  } finally {
    changingParticipation.value = false;
  }
}

function addDepartment(event: AdministrableEvent): void {
  void changeParticipation(
    event,
    departmentToAdd[event.id] ?? "",
    assignDepartmentToEvent,
  );
}

function removeDepartment(
  event: AdministrableEvent,
  department: ParticipatingDepartment,
): void {
  void changeParticipation(event, department.id, removeDepartmentFromEvent);
}

onMounted(() => {
  void load();
});

watch(organizationId, () => {
  void load();
});
</script>

<template>
  <section class="events" aria-labelledby="events-heading">
    <p class="events__eyebrow">Organizer</p>
    <h1 id="events-heading" class="events__heading">Events</h1>
    <p class="events__lede">
      The events this organization produces. The published dates are what staff
      are told; the active event window is the operational phase, and it is what
      moves authority to the on-site node and freezes governance content while
      it is open.
    </p>

    <p v-if="loadError" class="events__notice" role="alert">
      {{ loadError }}
      <button type="button" @click="load()">Try again</button>
    </p>

    <p v-else-if="loading" class="events__notice" role="status">
      Reading events…
    </p>

    <template v-else>
      <p v-if="notice" class="events__saved" role="status">{{ notice }}</p>

      <p
        v-if="administration?.defaultIcDepartment"
        class="events__default-ic"
        role="note"
      >
        An event that names no Incident Command Department of its own runs on
        the organization default,
        {{ administration.defaultIcDepartment.name }}.
      </p>

      <div v-if="editing === null" class="events__toolbar">
        <button type="button" @click="startCreate()">Create event</button>
      </div>

      <form v-else class="events__form" @submit.prevent="save()">
        <h2 class="events__form-heading">
          {{ editing === "new" ? "New event" : `Edit ${editingEvent?.name}` }}
        </h2>

        <p v-if="saveError" class="events__error" role="alert">
          {{ saveError }}
        </p>

        <div class="events__row">
          <label class="events__field">
            Name
            <input v-model="form.name" type="text" required maxlength="255" />
          </label>
          <label class="events__field">
            Address
            <input v-model="form.slug" type="text" required maxlength="255" />
            <span class="events__hint">
              The readable part of the event's web address. Unique within this
              organization.
            </span>
          </label>
        </div>

        <div class="events__row">
          <label class="events__field">
            Time zone
            <input v-model="form.timezone" type="text" required />
            <span class="events__hint">
              The clock the event keeps on site, whatever clock a reader is on.
            </span>
          </label>
          <label class="events__field">
            Minimum staff age
            <input v-model="form.minimumStaffAge" type="number" min="0" max="130" />
            <span class="events__hint">Leave blank for no minimum.</span>
          </label>
        </div>

        <div class="events__row">
          <label class="events__field">
            Published start
            <input v-model="form.startsAt" type="datetime-local" />
          </label>
          <label class="events__field">
            Published end
            <input v-model="form.endsAt" type="datetime-local" />
          </label>
        </div>

        <div class="events__row">
          <label class="events__field">
            Active window opens
            <input v-model="form.activeWindowStartsAt" type="datetime-local" />
          </label>
          <label class="events__field">
            Active window closes
            <input v-model="form.activeWindowEndsAt" type="datetime-local" />
          </label>
        </div>

        <!--
          ORG-006: only a department assigned to this event may run its
          Incident Command, so the choices are this event's own. An event with
          none assigned yet gets an explanation rather than an empty select,
          and the explanation names where departments are added.
        -->
        <template v-if="editing !== 'new'">
          <label v-if="icOptions.length > 0" class="events__field">
            Incident Command Department
            <select v-model="form.icDepartmentId">
              <option value="">
                Inherit the organization default
              </option>
              <option
                v-for="option in icOptions"
                :key="option.id"
                :value="option.id"
              >
                {{ option.name }}
              </option>
            </select>
          </label>
          <p v-else class="events__hint" role="note">
            No department participates in this event yet, so there is none to
            designate for Incident Command. Add one under Departments on the
            event below.
          </p>
        </template>

        <div class="events__actions">
          <button type="submit" :disabled="saving">
            {{ saving ? "Saving" : "Save" }}
          </button>
          <button type="button" :disabled="saving" @click="cancel()">
            Cancel
          </button>
        </div>
      </form>

      <p v-if="events.length === 0" class="events__notice" role="status">
        This organization is not running any events yet.
      </p>

      <article
        v-for="event in events"
        :key="event.id"
        class="events__row-card"
        :data-archived="event.archived"
      >
        <header class="events__card-header">
          <h2 class="events__name">{{ event.name }}</h2>
          <span class="events__phase" :data-phase="event.authority.phase">
            {{ phaseLabel(event) }}
          </span>
        </header>

        <dl class="events__facts">
          <div>
            <dt>Address</dt>
            <dd>{{ event.slug }}</dd>
          </div>
          <div>
            <dt>Published</dt>
            <dd>{{ formatWindow(event.startsAt, event.endsAt) }}</dd>
          </div>
          <div>
            <dt>Active window</dt>
            <dd>
              {{
                formatWindow(event.activeWindowStartsAt, event.activeWindowEndsAt)
              }}
            </dd>
          </div>
          <div>
            <dt>Time zone</dt>
            <dd>{{ event.timezone }}</dd>
          </div>
          <div v-if="event.minimumStaffAge !== null">
            <dt>Minimum staff age</dt>
            <dd>{{ event.minimumStaffAge }}</dd>
          </div>
          <div>
            <dt>Incident Command</dt>
            <dd v-if="event.effectiveIcDepartment">
              {{ event.effectiveIcDepartment.name
              }}<template v-if="event.effectiveIcDepartment.inherited">
                — inherited from the organization default</template
              >
            </dd>
            <dd v-else>Not designated</dd>
          </div>
          <div v-if="event.placementDepartment">
            <dt>Placement</dt>
            <dd>{{ event.placementDepartment.name }}</dd>
          </div>
        </dl>

        <!--
          Which departments work this event (M18.31; data/API 10.6). Adding and
          removing are immediate commands rather than fields of the event form:
          they are separate acts with their own audit entries, and the form's
          Incident Command select reads this list.
        -->
        <section class="events__departments">
          <h3 class="events__departments-heading">Departments</h3>

          <p
            v-if="
              participationError && participationError.eventId === event.id
            "
            class="events__error"
            role="alert"
          >
            {{ participationError.message }}
          </p>

          <p
            v-if="event.participatingDepartments.length === 0"
            class="events__hint"
            role="note"
          >
            No department works this event yet. A department has to be here
            before it can run the event's Incident Command.
          </p>

          <ul v-else class="events__department-list">
            <li
              v-for="department in event.participatingDepartments"
              :key="department.id"
              class="events__department"
            >
              <span class="events__department-name">{{ department.name }}</span>
              <span v-if="department.isIncidentCommand" class="events__tag">
                Incident Command
              </span>
              <span v-if="department.isPlacement" class="events__tag">
                Placement
              </span>
              <span
                v-if="department.removalRefusal"
                class="events__hint"
                role="note"
              >
                {{ department.removalRefusal }}
              </span>
              <button
                v-else-if="!event.archived"
                type="button"
                :disabled="changingParticipation"
                @click="removeDepartment(event, department)"
              >
                Remove
              </button>
            </li>
          </ul>

          <form
            v-if="!event.archived && event.assignableDepartments.length > 0"
            class="events__add-department"
            @submit.prevent="addDepartment(event)"
          >
            <label class="events__field">
              Add a department
              <select v-model="departmentToAdd[event.id]">
                <option value="">Choose a department</option>
                <option
                  v-for="option in event.assignableDepartments"
                  :key="option.id"
                  :value="option.id"
                >
                  {{ option.name }}
                </option>
              </select>
            </label>
            <button
              type="submit"
              :disabled="
                changingParticipation || !departmentToAdd[event.id]
              "
            >
              Add
            </button>
          </form>
          <p
            v-else-if="!event.archived"
            class="events__hint"
            role="note"
          >
            Every active department of this organization already works this
            event.
          </p>
        </section>

        <p v-if="event.archived" class="events__readonly" role="note">
          Archived. Archived events are kept for their history and are corrected
          in the support console.
        </p>
        <template v-else>
          <!--
            Context rather than a gate. During an event's active window the
            on-site node is authoritative for its records — but not for the
            event row itself, which stays writable everywhere precisely so the
            window can be closed from the node somebody is standing at (M12.6).
            Naming the node says what is happening elsewhere without taking away
            the one edit that ends it.
          -->
          <p
            v-if="event.authority.authoritativeNode"
            class="events__readonly"
            role="note"
          >
            This event is inside its active window, so
            {{ event.authority.authoritativeNode }} is authoritative for its
            shifts, attendance, and hours. Editing the event itself here still
            works, which is how the window is closed.
          </p>
          <div v-if="editing === null" class="events__actions">
            <button type="button" @click="startEdit(event)">Edit</button>
          </div>
        </template>
      </article>
    </template>
  </section>
</template>

<style scoped>
.events {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-3, 0.75rem);
  padding: var(--m-space-4, 1rem);
}

.events__eyebrow {
  margin: 0;
  color: var(--m-color-muted-foreground, #5b6b66);
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.06em;
}

.events__heading {
  margin: 0;
  font-size: 1.5rem;
}

.events__lede,
.events__default-ic {
  margin: 0;
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.events__toolbar,
.events__actions {
  display: flex;
  gap: var(--m-space-2, 0.5rem);
  flex-wrap: wrap;
}

.events__form,
.events__row-card {
  border: 1px solid var(--m-color-border, #d5ddda);
  border-radius: var(--m-radius-2, 0.375rem);
  padding: var(--m-space-3, 0.75rem);
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
}

.events__row-card[data-archived="true"] {
  opacity: 0.7;
}

.events__form-heading,
.events__name {
  margin: 0;
  font-size: 1.05rem;
}

.events__card-header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: var(--m-space-2, 0.5rem);
}

.events__phase {
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.75rem;
  font-weight: 800;
  text-transform: uppercase;
}

.events__row {
  display: flex;
  gap: var(--m-space-3, 0.75rem);
  flex-wrap: wrap;
}

.events__field {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.9rem;
  flex: 1 1 16rem;
}

.events__field input,
.events__field select {
  padding: 0.4rem;
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}

.events__hint {
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.8rem;
}

.events__facts {
  margin: 0;
  display: grid;
  grid-template-columns: minmax(10rem, auto) 1fr;
  gap: 0.25rem var(--m-space-3, 0.75rem);
}

.events__facts > div {
  display: contents;
}

.events__facts dt {
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.events__facts dd {
  margin: 0;
}

.events__readonly {
  margin: 0;
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.events__departments {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
  border-top: 1px solid var(--m-color-border, #d5ddda);
  padding-top: var(--m-space-2, 0.5rem);
}

.events__departments-heading {
  margin: 0;
  font-size: 0.95rem;
}

.events__department-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
}

.events__department {
  display: flex;
  align-items: center;
  gap: var(--m-space-2, 0.5rem);
  flex-wrap: wrap;
}

.events__department-name {
  font-weight: 600;
}

.events__tag {
  border: 1px solid var(--m-color-border, #d5ddda);
  border-radius: var(--m-radius-1, 0.25rem);
  padding: 0.05rem 0.35rem;
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.events__add-department {
  display: flex;
  align-items: flex-end;
  gap: var(--m-space-2, 0.5rem);
  flex-wrap: wrap;
}

.events__notice,
.events__error,
.events__saved {
  margin: 0;
  padding: var(--m-space-3, 0.75rem);
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}
</style>
