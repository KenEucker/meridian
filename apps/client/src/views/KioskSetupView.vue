<script setup lang="ts">
import { computed, ref, watch } from "vue";

import {
  kioskContextState,
  loadKioskContextOptions,
  pinKioskContext,
  resolveKioskContext,
  type KioskContextOption,
} from "@/session/kioskContext";
import {
  configureSharedWorkstationId,
  sharedWorkstationId,
} from "@/session/workstationIdentity";
import { workstationSessionState } from "@/session/workstationSession";

/*
 * `kiosk.setup` — where a Kiosk waits until it knows what it is (M18.32; UI-019,
 * UI-020, UI-021; technical spec 13.1; UI contract 18.1).
 *
 * "If Meridian Kiosk starts without a pinned organization and event, it enters
 * setup. It must not infer context from viewport, network, authenticated user,
 * last route, or cached event data." This is the surface that sentence sends it
 * to, and the reason it is a surface at all rather than a redirect: an
 * unconfigured machine in a field at 2am needs to say what is wrong with it in
 * words a technician can act on.
 *
 * It answers three different states, which are three different problems:
 *
 *  1. **This machine is not a shared workstation.** It holds no workstation id,
 *     so there is nothing to ask the node about. That is fixed here, because the
 *     id is the one part of the configuration that lives on the machine
 *     (`workstationIdentity`) rather than on the node.
 *  2. **The node does not know this workstation, or has no context for it.** The
 *     first pin is God Mode's — a workstation with no pinned organization has no
 *     organization for an organizer's authority to be held in, and no login code
 *     can be scoped to it, so nobody can sign in here to fix it. The screen says
 *     so and names the machine, because reading the id out is what the person on
 *     the phone to the technician is going to be asked for.
 *  3. **It is pinned, and somebody wants it pinned elsewhere.** That is UI-021,
 *     and it needs a live session: the node answers to
 *     `organization.events.manage` and a Kiosk authenticates as whoever is
 *     signed in at it.
 *
 * Nothing on this screen grants anything. Pinned context frames the shell and
 * confers no authority (technical spec 13.3), and the node refuses a change from
 * anybody who does not hold the capability whatever this renders.
 */

const workstationId = computed(() => sharedWorkstationId.value);
const context = computed(() => kioskContextState.context);
const signedIn = computed(() => workstationSessionState.status === "active");

const idDraft = ref(workstationId.value ?? "");
const selectedEventId = ref<string | null>(null);
const selectedDepartmentId = ref<string | null>(null);

const options = computed<readonly KioskContextOption[]>(
  () => kioskContextState.options,
);

const selectedEvent = computed<KioskContextOption | null>(
  () => options.value.find((option) => option.id === selectedEventId.value) ?? null,
);

/**
 * Whether the node has told this machine it knows nothing about it.
 *
 * Distinct from an unreachable node, which leaves the stored answer standing:
 * "this workstation is not on record" is a verdict and "the node did not answer"
 * is not, and a technician needs to know which one they are looking at.
 */
const unknownToNode = computed(() => kioskContextState.status === "unknown");
const readingStored = computed(() => kioskContextState.status === "stored");

function saveWorkstationId(): void {
  configureSharedWorkstationId(idDraft.value);
  void resolveKioskContext();
}

function clearWorkstationId(): void {
  idDraft.value = "";
  configureSharedWorkstationId(null);
  void resolveKioskContext();
}

async function pin(): Promise<void> {
  const eventId = selectedEventId.value;

  if (eventId === null) {
    return;
  }

  await pinKioskContext({
    eventId,
    departmentId: selectedDepartmentId.value,
  });
}

/*
 * A department is only offerable for the event it works, so changing the event
 * clears the choice rather than carrying a department the node would refuse
 * (ORG-006's list, read the same way M18.31 reads it).
 */
watch(selectedEventId, () => {
  selectedDepartmentId.value = null;
});

watch(
  signedIn,
  (live) => {
    if (live) {
      void loadKioskContextOptions();
    }
  },
  { immediate: true },
);

void resolveKioskContext();
</script>

<template>
  <section class="kiosk-setup" aria-labelledby="kiosk-setup-heading">
    <h1 id="kiosk-setup-heading" class="kiosk-setup__heading">Kiosk setup</h1>
    <p class="kiosk-setup__lede">
      A Kiosk works in one organization and one event. Until this machine is
      pinned to both, it stays here — it will not work out which event it is at
      from the network, the last screen, or whoever signed in last.
    </p>

    <!-- 1. The machine does not know which workstation it is. -->
    <section class="kiosk-setup__block" aria-labelledby="kiosk-setup-identity">
      <h2 id="kiosk-setup-identity" class="kiosk-setup__subheading">
        This machine
      </h2>

      <p v-if="context" class="kiosk-setup__fact" data-testid="kiosk-setup-name">
        {{ context.workstationName || "Unnamed workstation" }}
      </p>

      <p v-if="unknownToNode" class="kiosk-setup__notice" role="status">
        This node holds no trusted shared workstation with that identifier. A
        technician registers the machine in God Mode, and pairing a device is not
        something the Kiosk can do for itself.
      </p>

      <p v-else-if="readingStored" class="kiosk-setup__notice" role="status">
        The node could not be reached, so this is what it last said about this
        machine.
      </p>

      <label class="kiosk-setup__label" for="kiosk-setup-workstation-id">
        Workstation identifier
      </label>
      <input
        id="kiosk-setup-workstation-id"
        v-model="idDraft"
        class="kiosk-setup__input"
        type="text"
        autocomplete="off"
        spellcheck="false"
      />
      <p class="kiosk-setup__hint">
        Identifies the machine to the node. It is not a credential — signing in
        still needs a login code the node issued to a named person.
      </p>

      <div class="kiosk-setup__actions">
        <button class="kiosk-setup__action" type="button" @click="saveWorkstationId">
          Save and check with the node
        </button>
        <button
          v-if="workstationId"
          class="kiosk-setup__action"
          type="button"
          @click="clearWorkstationId"
        >
          Clear
        </button>
      </div>
    </section>

    <!-- 2 and 3. What the node says this machine is pinned to. -->
    <section class="kiosk-setup__block" aria-labelledby="kiosk-setup-context">
      <h2 id="kiosk-setup-context" class="kiosk-setup__subheading">
        Pinned context
      </h2>

      <dl class="kiosk-setup__facts">
        <div class="kiosk-setup__fact-row">
          <dt>Organization</dt>
          <dd>{{ context?.organization?.name ?? "Not pinned" }}</dd>
        </div>
        <div class="kiosk-setup__fact-row">
          <dt>Event</dt>
          <dd data-testid="kiosk-setup-event">
            {{ context?.event?.name ?? "Not pinned" }}
          </dd>
        </div>
        <div class="kiosk-setup__fact-row">
          <dt>Department</dt>
          <dd>{{ context?.department?.name ?? "The whole site" }}</dd>
        </div>
      </dl>

      <!--
        No session means nobody is signed in to authorize a change, and on an
        unpinned workstation nobody can be: a login code is scoped to a pinned
        event. So this states who does it rather than offering a form that could
        only be refused.
      -->
      <p v-if="!signedIn" class="kiosk-setup__notice" role="status">
        Changing what this machine is pinned to needs an organizer signed in at
        it. A workstation that has never been pinned cannot be signed in to at
        all, so its first pin is a God Mode operator's on the node.
      </p>

      <template v-else>
        <p v-if="kioskContextState.error" class="kiosk-setup__error" role="alert">
          {{ kioskContextState.error }}
        </p>

        <form
          v-if="options.length > 0"
          class="kiosk-setup__form"
          @submit.prevent="pin"
        >
          <label class="kiosk-setup__label" for="kiosk-setup-event-select">
            Event
          </label>
          <select
            id="kiosk-setup-event-select"
            v-model="selectedEventId"
            class="kiosk-setup__input"
          >
            <option :value="null">Choose an event</option>
            <option v-for="option in options" :key="option.id" :value="option.id">
              {{ option.name }}
            </option>
          </select>

          <label class="kiosk-setup__label" for="kiosk-setup-department-select">
            Department
          </label>
          <select
            id="kiosk-setup-department-select"
            v-model="selectedDepartmentId"
            class="kiosk-setup__input"
            :disabled="selectedEvent === null"
          >
            <option :value="null">The whole site</option>
            <option
              v-for="department in selectedEvent?.departments ?? []"
              :key="department.id"
              :value="department.id"
            >
              {{ department.name }}
            </option>
          </select>
          <p class="kiosk-setup__hint">
            Only departments that work the chosen event are offered. Pinning ends
            the session on this machine, because it was signed in to the previous
            context.
          </p>

          <button
            class="kiosk-setup__action"
            type="submit"
            :disabled="selectedEventId === null || kioskContextState.saving"
          >
            {{ kioskContextState.saving ? "Pinning…" : "Pin this workstation" }}
          </button>
        </form>
      </template>
    </section>
  </section>
</template>

<style scoped>
.kiosk-setup {
  display: grid;
  gap: var(--m-space-4);
  width: var(--m-content-narrow);
}

.kiosk-setup__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.kiosk-setup__lede {
  margin: 0;
  color: var(--m-text-muted);
}

.kiosk-setup__block {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
}

.kiosk-setup__subheading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.kiosk-setup__fact {
  margin: 0;
  font-size: var(--m-text-lg);
  font-weight: 900;
}

.kiosk-setup__facts {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
}

.kiosk-setup__fact-row {
  display: grid;
  grid-template-columns: minmax(7rem, 10rem) 1fr;
  gap: var(--m-space-3);
}

.kiosk-setup__fact-row dt {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.kiosk-setup__fact-row dd {
  margin: 0;
  font-weight: 800;
}

.kiosk-setup__label {
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.kiosk-setup__input {
  min-height: 3rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
}

.kiosk-setup__input:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.kiosk-setup__hint,
.kiosk-setup__notice {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.kiosk-setup__error {
  margin: 0;
  color: var(--m-status-critical, var(--m-text-primary));
  font-weight: 700;
}

.kiosk-setup__form {
  display: grid;
  gap: var(--m-space-2);
}

.kiosk-setup__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.kiosk-setup__action {
  min-height: 3rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-action-primary-bg, var(--m-surface-base));
  color: var(--m-action-primary-fg, var(--m-text-primary));
  font-size: var(--m-text-md);
  font-weight: 900;
}

.kiosk-setup__action:disabled {
  opacity: 0.6;
}

.kiosk-setup__action:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (max-width: 40rem) {
  .kiosk-setup__fact-row {
    grid-template-columns: 1fr;
    gap: var(--m-space-1);
  }
}
</style>
