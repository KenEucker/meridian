<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRoute } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import {
  getEventParticipation,
  getOrganizationParticipation,
  submitApplication,
  type DepartmentInterestOption,
  type EventParticipation,
  type OrganizationParticipation,
  type ParticipationEvent,
} from "@/applications/participationModel";

/**
 * `public.participate` and `public.apply` — the surface somebody who does not
 * work here yet arrives on (M18.21A; APP-001, APP-011, APP-016, APP-018).
 *
 * One component for both addresses, because they are the same page with one
 * question answered in advance. `/apply/:organizationSlug` asks which thing you
 * are offering to do; `/apply/:organizationSlug/:eventSlug` has already been
 * told, and shows the form. Splitting them into two components would have
 * duplicated the branding header, the form, the validation, and the submitted
 * state to save one conditional.
 *
 * Three properties of this page are unusual for this client and all three
 * follow from who is looking at it:
 *
 *  1. **No session, and none expected.** Nothing here reads `sessionAccess`,
 *     the permission set, or the selected organization — the visitor has none
 *     of them. The organization comes from the URL and the node decides what to
 *     publish about it.
 *  2. **It carries the organization's identity, not Meridian's** (BRAND-002).
 *     A person invited to join Northwood should arrive at something that looks
 *     like Northwood, and the palette is applied from the branding profile the
 *     public read carries rather than from the signed-in theme, which does not
 *     exist here.
 *  3. **The response after submitting says nothing about what happened to the
 *     application.** A Do Not Staff auto-rejection and an ordinary submission
 *     produce the same screen, because any difference would disclose the
 *     status (STAT-006, NOTIFY-002).
 */
const route = useRoute();

const organizationSlug = computed(() =>
  String(route.params.organizationSlug ?? ""),
);
const routeEventSlug = computed(() => {
  const value = route.params.eventSlug;

  return typeof value === "string" && value !== "" ? value : null;
});

const organization = ref<OrganizationParticipation | null>(null);
const eventContext = ref<EventParticipation | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);

/** Which offer the visitor is making: an event slug, or the organization. */
const chosenEventSlug = ref<string | null>(null);
const legalName = ref("");
const email = ref("");
const selectedInterests = ref<string[]>([]);
const submitting = ref(false);
const submitError = ref<string | null>(null);
const submitted = ref(false);

const branding = computed(
  () => eventContext.value?.branding ?? organization.value?.branding ?? null,
);

const organizationName = computed(
  () =>
    eventContext.value?.organizationName ??
    organization.value?.organizationName ??
    "This organization",
);

const paletteStyle = computed(() => {
  const palette = branding.value?.palette;

  if (palette == null) {
    return {};
  }

  // The public page has no signed-in theme to inherit, so the organization's
  // own colours are applied directly as the custom properties the tokens read.
  return Object.fromEntries(
    Object.entries(palette).map(([field, value]) => [
      `--m-color-${field.replace(/_/g, "-")}`,
      value,
    ]),
  );
});

const events = computed<readonly ParticipationEvent[]>(
  () => organization.value?.events ?? [],
);

const departmentInterests = computed<readonly DepartmentInterestOption[]>(
  () => eventContext.value?.departmentInterests ?? [],
);

/** APP-018: the organization form appears only where the organization takes them. */
const offersOrganizationApplication = computed(
  () => organization.value?.acceptsOrganizationApplications === true,
);

const eventClosed = computed(
  () =>
    eventContext.value !== null &&
    eventContext.value.event.acceptingApplications === false,
);

const showForm = computed(() => {
  if (eventContext.value !== null) {
    return !eventClosed.value;
  }

  return chosenEventSlug.value !== null || offersOrganizationApplication.value;
});

const formHeading = computed(() => {
  if (eventContext.value !== null) {
    return `Apply to staff ${eventContext.value.event.name}`;
  }

  if (chosenEventSlug.value !== null) {
    const match = events.value.find(
      (entry) => entry.slug === chosenEventSlug.value,
    );

    return match ? `Apply to staff ${match.name}` : "Apply";
  }

  return `Apply to join ${organizationName.value}`;
});

function formatWindow(event: ParticipationEvent): string | null {
  if (event.startsAt == null) {
    return null;
  }

  const start = new Date(event.startsAt);
  const options: Intl.DateTimeFormatOptions = {
    day: "numeric",
    month: "short",
    year: "numeric",
  };

  if (event.endsAt == null) {
    return start.toLocaleDateString(undefined, options);
  }

  return `${start.toLocaleDateString(undefined, options)} – ${new Date(
    event.endsAt,
  ).toLocaleDateString(undefined, options)}`;
}

async function load(): Promise<void> {
  const slug = organizationSlug.value;

  if (slug === "") {
    return;
  }

  loading.value = true;
  loadError.value = null;
  submitted.value = false;

  try {
    if (routeEventSlug.value !== null) {
      eventContext.value = await getEventParticipation(
        slug,
        routeEventSlug.value,
      );
      organization.value = null;
      chosenEventSlug.value = routeEventSlug.value;
    } else {
      organization.value = await getOrganizationParticipation(slug);
      eventContext.value = null;
      chosenEventSlug.value = null;
    }
  } catch (error) {
    loadError.value = meridianErrorMessage(
      error,
      "This link does not lead anywhere we can find.",
    );
  } finally {
    loading.value = false;
  }
}

/** Choosing an event on the organization page loads that event's form. */
async function chooseEvent(slug: string | null): Promise<void> {
  submitError.value = null;
  chosenEventSlug.value = slug;
  selectedInterests.value = [];

  if (slug === null) {
    eventContext.value = null;

    return;
  }

  try {
    eventContext.value = await getEventParticipation(
      organizationSlug.value,
      slug,
    );
  } catch (error) {
    submitError.value = meridianErrorMessage(
      error,
      "That event could not be opened.",
    );
    chosenEventSlug.value = null;
  }
}

function toggleInterest(id: string): void {
  selectedInterests.value = selectedInterests.value.includes(id)
    ? selectedInterests.value.filter((entry) => entry !== id)
    : [...selectedInterests.value, id];
}

async function submit(): Promise<void> {
  submitting.value = true;
  submitError.value = null;

  try {
    await submitApplication(organizationSlug.value, {
      applicantLegalName: legalName.value,
      applicantEmail: email.value,
      eventSlug: chosenEventSlug.value,
      departmentInterestIds: selectedInterests.value,
    });

    submitted.value = true;
  } catch (error) {
    submitError.value = meridianErrorMessage(
      error,
      "That application could not be submitted.",
    );
  } finally {
    submitting.value = false;
  }
}

watch(
  () => [organizationSlug.value, routeEventSlug.value],
  () => {
    void load();
  },
  { immediate: true },
);
</script>

<template>
  <main
    class="participate"
    :style="paletteStyle"
    aria-labelledby="participate-heading"
  >
    <header class="participate__masthead">
      <img
        v-if="branding?.compactMarkUrl ?? branding?.fullLockupUrl"
        class="participate__mark"
        :src="branding?.compactMarkUrl ?? branding?.fullLockupUrl ?? ''"
        :alt="organizationName"
      />
      <span v-else class="participate__lettermark" aria-hidden="true">
        {{ branding?.lettermark ?? "M" }}
      </span>
      <p class="participate__org">{{ organizationName }}</p>
    </header>

    <p v-if="loading" class="participate__notice" role="status">Loading…</p>

    <p v-else-if="loadError" class="participate__notice" role="alert">
      {{ loadError }}
    </p>

    <template v-else-if="submitted">
      <h1 id="participate-heading" class="participate__heading">
        Your application is in
      </h1>
      <p class="participate__body">
        {{ organizationName }} has your application. Somebody will review it and
        you will hear by email at the address you gave.
      </p>
      <p class="participate__body participate__body--quiet">
        There is nothing else to do now, and nothing to sign in to yet — an
        account is created for you if your application is approved.
      </p>
    </template>

    <template v-else>
      <h1 id="participate-heading" class="participate__heading">
        {{
          eventContext !== null || chosenEventSlug !== null
            ? formHeading
            : `Join ${organizationName}`
        }}
      </h1>

      <p v-if="eventClosed" class="participate__notice" role="status">
        This event is no longer accepting applications.
      </p>

      <!--
        The organization page's own choice: which of the open events, or the
        organization itself where APP-018 allows it. An organization with
        neither is still a valid page — it says so rather than showing an
        empty list under a heading that promises one.
      -->
      <section
        v-if="eventContext === null && chosenEventSlug === null"
        class="participate__choices"
      >
        <p
          v-if="events.length === 0 && !offersOrganizationApplication"
          class="participate__notice"
          role="status"
        >
          {{ organizationName }} is not accepting applications right now.
        </p>

        <template v-else>
          <p class="participate__body">
            {{
              events.length > 0
                ? "Choose the event you would like to staff."
                : ""
            }}
            <template v-if="offersOrganizationApplication">
              You can also apply to join {{ organizationName }} without picking
              an event.
            </template>
          </p>

          <ul v-if="events.length > 0" class="participate__events">
            <li v-for="event in events" :key="event.slug">
              <button
                type="button"
                class="participate__event"
                @click="chooseEvent(event.slug)"
              >
                <span class="participate__event-name">{{ event.name }}</span>
                <span
                  v-if="formatWindow(event)"
                  class="participate__event-dates"
                >
                  {{ formatWindow(event) }}
                </span>
              </button>
            </li>
          </ul>

          <button
            v-if="offersOrganizationApplication"
            type="button"
            class="participate__organization-apply"
            @click="
              chooseEvent(null);
              chosenEventSlug = null;
              submitError = null;
            "
            :data-selected="chosenEventSlug === null"
          >
            Apply to join {{ organizationName }}
          </button>
        </template>
      </section>

      <form
        v-if="showForm"
        class="participate__form"
        @submit.prevent="submit()"
      >
        <p v-if="submitError" class="participate__error" role="alert">
          {{ submitError }}
        </p>

        <label class="participate__field">
          Your legal name
          <input v-model="legalName" type="text" required maxlength="255" />
        </label>

        <label class="participate__field">
          Email address
          <input v-model="email" type="email" required maxlength="255" />
          <span class="participate__hint">
            This is where the decision on your application is sent.
          </span>
        </label>

        <!--
          APP-011: optional, non-binding, no "no preference" option, no maximum,
          and hidden entirely when the event has no eligible departments.
        -->
        <fieldset
          v-if="departmentInterests.length > 0"
          class="participate__interests"
        >
          <legend>Department interest</legend>
          <p class="participate__hint">
            Optional. This tells the organization what you are drawn to and
            commits neither of you to anything. Leaving it empty means you are
            open to any.
          </p>
          <label
            v-for="option in departmentInterests"
            :key="option.id"
            class="participate__interest"
          >
            <input
              type="checkbox"
              :value="option.id"
              :checked="selectedInterests.includes(option.id)"
              @change="toggleInterest(option.id)"
            />
            {{ option.name }}
          </label>
        </fieldset>

        <button
          type="submit"
          class="participate__submit"
          :disabled="submitting"
        >
          {{ submitting ? "Sending…" : "Submit application" }}
        </button>
      </form>
    </template>
  </main>
</template>

<style scoped>
.participate {
  max-width: 42rem;
  margin: 0 auto;
  padding: var(--m-space-6, 1.5rem);
  display: flex;
  flex-direction: column;
  gap: var(--m-space-4, 1rem);
}

.participate__masthead {
  display: flex;
  align-items: center;
  gap: var(--m-space-3, 0.75rem);
}

.participate__mark {
  width: 3rem;
  height: 3rem;
  border-radius: var(--m-radius-2, 0.375rem);
  object-fit: contain;
}

.participate__lettermark {
  width: 3rem;
  height: 3rem;
  border-radius: var(--m-radius-2, 0.375rem);
  background: var(--m-color-primary, #2f5d50);
  color: var(--m-color-surface, #ffffff);
  display: grid;
  place-items: center;
  font-weight: 700;
}

.participate__org {
  margin: 0;
  font-weight: 600;
}

.participate__heading {
  margin: 0;
  font-size: 1.6rem;
  line-height: 1.25;
}

.participate__body {
  margin: 0;
}

.participate__body--quiet {
  color: var(--m-color-muted-foreground, #5b6b66);
}

.participate__notice,
.participate__error {
  margin: 0;
  padding: var(--m-space-3, 0.75rem);
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}

.participate__error {
  border-color: var(--m-color-status-danger, #a3372f);
}

.participate__events {
  list-style: none;
  margin: var(--m-space-3, 0.75rem) 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2, 0.5rem);
}

.participate__event,
.participate__organization-apply {
  width: 100%;
  text-align: left;
  padding: var(--m-space-3, 0.75rem);
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
  background: var(--m-color-surface, #ffffff);
  color: inherit;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.participate__organization-apply {
  margin-top: var(--m-space-3, 0.75rem);
}

.participate__event-name {
  font-weight: 600;
}

.participate__event-dates {
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.participate__form {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-3, 0.75rem);
}

.participate__field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.participate__field input {
  padding: 0.5rem;
  border-radius: var(--m-radius-2, 0.375rem);
  border: 1px solid var(--m-color-border, #d5ddda);
}

.participate__hint {
  color: var(--m-color-muted-foreground, #5b6b66);
  font-size: 0.9rem;
}

.participate__interests {
  border: 1px solid var(--m-color-border, #d5ddda);
  border-radius: var(--m-radius-2, 0.375rem);
  padding: var(--m-space-3, 0.75rem);
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.participate__interest {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.participate__submit {
  align-self: flex-start;
  padding: 0.6rem 1.2rem;
  border-radius: var(--m-radius-2, 0.375rem);
  border: none;
  background: var(--m-color-primary, #2f5d50);
  color: var(--m-color-surface, #ffffff);
  font-weight: 600;
  cursor: pointer;
}
</style>
