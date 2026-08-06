<script setup lang="ts">
import { computed, ref } from "vue";
import { useRoute, useRouter } from "vue-router";

import {
  reauthenticateWorkstationSession,
  workstationSessionState,
} from "@/session/workstationSession";

/*
 * `kiosk.reauth` — confirming the active user before a privileged action
 * (M18.32; UI implementation contract 12.8, 18.2; UI-017).
 *
 * "Privileged actions may require re-authentication." On a machine strangers
 * stand in front of, that means proving the person at the keyboard is still the
 * person the session belongs to — not proving that a session exists, which the
 * session bar already shows.
 *
 * Alpha 1 has no separate Meridian PIN and 18.2 rules one out as an independent
 * central credential, so what is asked for is a fresh login code: already scoped
 * to this user, this event, and this workstation, single use, and generated in
 * seconds from the phone in somebody's pocket against a node with no route to
 * central (AUTH-027). Nothing new is invented and nothing new is stored.
 *
 * The node decides. It checks the code against the session's own user, so a
 * valid code belonging to somebody else confirms nothing — and the refusal says
 * to use Switch user, because a handover is an explicit end (technical spec
 * 13.3).
 *
 * Where it returns to is a route name carried in the query, and it is only ever
 * followed when the node confirmed. A `return` naming an unknown route lands on
 * the workstation dashboard rather than nowhere.
 */

const router = useRouter();
const route = useRoute();

const code = ref("");
const confirmed = ref(false);

const user = computed(() => workstationSessionState.user);
const entering = computed(() => workstationSessionState.entering);
const canSubmit = computed(() => code.value.trim() !== "" && !entering.value);

/** The surface that asked for the confirmation, when it named itself. */
const returnTo = computed<string | null>(() => {
  const name = route.query.return;

  return typeof name === "string" && name !== "" ? name : null;
});

const returnRoute = computed(() => {
  const name = returnTo.value;

  return name !== null && router.hasRoute(name)
    ? { name }
    : { name: "kiosk.home" };
});

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return;
  }

  const outcome = await reauthenticateWorkstationSession(code.value);

  // Cleared either way. A refused code is spent or wrong, and leaving it in the
  // field invites the same attempt again, which is how a workstation reaches its
  // entry limit (AUTH-029).
  code.value = "";

  if (outcome !== "confirmed") {
    return;
  }

  confirmed.value = true;

  await router.push(returnRoute.value);
}

function cancel(): void {
  void router.push({ name: "kiosk.home" });
}
</script>

<template>
  <section class="kiosk-reauth" aria-labelledby="kiosk-reauth-heading">
    <h1 id="kiosk-reauth-heading" class="kiosk-reauth__heading">
      Confirm it is still you
    </h1>

    <p class="kiosk-reauth__lede">
      This action needs {{ user?.name ?? "the signed-in user" }} to confirm.
      Generate a login code on your own device and enter it here. It confirms
      this session; it does not start a new one.
    </p>

    <form class="kiosk-reauth__form" @submit.prevent="submit">
      <label class="kiosk-reauth__label" for="kiosk-reauth-code">Login code</label>
      <input
        id="kiosk-reauth-code"
        v-model="code"
        class="kiosk-reauth__code"
        type="text"
        inputmode="text"
        autocomplete="off"
        autocapitalize="characters"
        spellcheck="false"
        maxlength="16"
        :aria-describedby="
          workstationSessionState.entryError ? 'kiosk-reauth-error' : undefined
        "
        :aria-invalid="workstationSessionState.entryError !== null"
      />

      <p
        v-if="workstationSessionState.entryError"
        id="kiosk-reauth-error"
        class="kiosk-reauth__error"
        role="alert"
      >
        {{ workstationSessionState.entryError }}
      </p>

      <p v-if="confirmed" class="kiosk-reauth__confirmed" role="status">
        Confirmed. Returning you to what you were doing.
      </p>

      <div class="kiosk-reauth__actions">
        <button class="kiosk-reauth__submit" type="submit" :disabled="!canSubmit">
          {{ entering ? "Confirming…" : "Confirm" }}
        </button>

        <button class="kiosk-reauth__cancel" type="button" @click="cancel">
          Cancel
        </button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.kiosk-reauth {
  width: var(--m-content-narrow);
}

.kiosk-reauth__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.kiosk-reauth__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.kiosk-reauth__form {
  display: grid;
  gap: var(--m-space-2);
}

.kiosk-reauth__label {
  font-size: var(--m-text-sm);
  font-weight: 800;
}

/* The same field as code entry: typed with gloves on, under glare. */
.kiosk-reauth__code {
  min-height: 3.5rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font-family: var(--m-font-mono, monospace);
  font-size: var(--m-text-xl);
  letter-spacing: 0.25em;
  text-transform: uppercase;
}

.kiosk-reauth__code:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.kiosk-reauth__error {
  margin: 0;
  color: var(--m-status-critical, var(--m-text-primary));
  font-weight: 700;
}

.kiosk-reauth__confirmed {
  margin: 0;
  color: var(--m-text-secondary);
  font-weight: 700;
}

.kiosk-reauth__actions {
  display: grid;
  gap: var(--m-space-3);
}

.kiosk-reauth__submit,
.kiosk-reauth__cancel {
  min-height: 3.5rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font-size: var(--m-text-lg);
  font-weight: 900;
}

.kiosk-reauth__submit {
  background: var(--m-action-primary-bg, var(--m-surface-raised));
  color: var(--m-action-primary-fg, var(--m-text-primary));
}

.kiosk-reauth__submit:disabled {
  opacity: 0.6;
}

.kiosk-reauth__submit:focus-visible,
.kiosk-reauth__cancel:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 44rem) {
  .kiosk-reauth__actions {
    display: flex;
    flex-wrap: wrap;
  }
}
</style>
