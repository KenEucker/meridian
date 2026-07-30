<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";

import { sharedWorkstationId } from "@/session/workstationIdentity";
import {
  enterWorkstationLoginCode,
  workstationSessionState,
} from "@/session/workstationSession";

/*
 * `kiosk.workstation-login` — shared-workstation login code entry (UI
 * implementation contract 12.8; M16.9; AUTH-030; technical spec 13.3).
 *
 * The locked state of a shared workstation, and the only surface it offers while
 * locked. Entering a code establishes a shared-workstation session; it issues no
 * personal device token and makes this machine nobody's trusted device.
 *
 * The field is entered "with gloves and under glare" (kiosk guide 12), so the
 * code is one large, wide-tracked, case-insensitive field rather than eight boxes
 * that punish a mistyped character by losing the lot.
 *
 * A machine that does not know which workstation it is says so instead of
 * offering a field the node could only refuse. That is a setup problem, and it is
 * fixed on the setup surface rather than by guessing here.
 */

const router = useRouter();

const code = ref("");
const workstationId = computed(() => sharedWorkstationId.value);
const canSubmit = computed(
  () =>
    workstationId.value !== null &&
    code.value.trim() !== "" &&
    !workstationSessionState.entering,
);

async function submit(): Promise<void> {
  const id = workstationId.value;

  if (id === null || !canSubmit.value) {
    return;
  }

  const outcome = await enterWorkstationLoginCode({
    sharedWorkstationId: id,
    code: code.value,
  });

  // The code is cleared either way. A refused code is spent or wrong, and
  // leaving it in the field invites the next attempt to be the same one, which
  // is how a workstation reaches its failed-entry limit (AUTH-029).
  code.value = "";

  if (outcome === "signed_in") {
    await router.push({ name: "kiosk.home" });
  }
}
</script>

<template>
  <section class="workstation-login" aria-labelledby="workstation-login-heading">
    <h1 id="workstation-login-heading" class="workstation-login__heading">
      Sign in to this workstation
    </h1>
    <p class="workstation-login__lede">
      Enter your login code. It signs you in to this workstation only, for this
      event, and ends after 5 minutes of inactivity.
    </p>

    <p v-if="workstationId === null" class="workstation-login__unconfigured" role="status">
      This machine is not set up as a trusted shared workstation, so it cannot
      accept a login code. A technician needs to configure it.
    </p>

    <form v-else class="workstation-login__form" @submit.prevent="submit">
      <label class="workstation-login__label" for="workstation-login-code">
        Login code
      </label>
      <input
        id="workstation-login-code"
        v-model="code"
        class="workstation-login__code"
        type="text"
        inputmode="text"
        autocomplete="off"
        autocapitalize="characters"
        spellcheck="false"
        maxlength="16"
        :aria-describedby="
          workstationSessionState.entryError ? 'workstation-login-error' : undefined
        "
        :aria-invalid="workstationSessionState.entryError !== null"
      />

      <p
        v-if="workstationSessionState.entryError"
        id="workstation-login-error"
        class="workstation-login__error"
        role="alert"
      >
        {{ workstationSessionState.entryError }}
      </p>

      <button class="workstation-login__submit" type="submit" :disabled="!canSubmit">
        {{ workstationSessionState.entering ? "Signing in…" : "Sign in" }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.workstation-login {
  width: var(--m-content-narrow);
}

.workstation-login__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.workstation-login__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.workstation-login__unconfigured {
  margin: 0 0 var(--m-space-4);
  padding: var(--m-space-3) var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-left: 4px solid var(--m-status-neutral);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
  color: var(--m-text-secondary);
}

.workstation-login__form {
  display: grid;
  gap: var(--m-space-2);
}

.workstation-login__label {
  font-size: var(--m-text-sm);
  font-weight: 800;
}

/* Large and wide-tracked: this is typed with gloves on, under glare. */
.workstation-login__code {
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

.workstation-login__code:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.workstation-login__error {
  margin: 0;
  color: var(--m-status-critical, var(--m-text-primary));
  font-weight: 700;
}

.workstation-login__submit {
  min-height: 3.5rem;
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-action-primary-bg, var(--m-surface-raised));
  color: var(--m-action-primary-fg, var(--m-text-primary));
  font-size: var(--m-text-lg);
  font-weight: 900;
}

.workstation-login__submit:disabled {
  opacity: 0.6;
}

.workstation-login__submit:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
