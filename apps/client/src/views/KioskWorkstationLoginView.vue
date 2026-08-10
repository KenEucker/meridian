<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";

import { encodeQrMatrix, qrMatrixToSvgPath, type QrMatrix } from "@/support/qrCode";
import { sharedWorkstationId } from "@/session/workstationIdentity";
import {
  presentWorkstationSignIn,
  resetWorkstationSignIn,
  SIGN_IN_POLL_INTERVAL_MS,
  tickWorkstationSignIn,
  workstationSignInState,
} from "@/session/workstationSignIn";
import {
  enterWorkstationLoginCode,
  workstationSessionState,
} from "@/session/workstationSession";

/*
 * `kiosk.workstation-login` — shared-workstation sign-in (UI implementation
 * contract 12.8; M16.9; M18.60; AUTH-030, AUTH-032; technical spec 13.3, 13.4).
 *
 * The locked state of a shared workstation, and the only surface it offers
 * while locked. Two paths in, one session out:
 *
 *  - **Scan.** The workstation opens a sign-in request on entering the locked
 *    state and renders it as a QR beside its own name and `short_code`. A
 *    phone holding a session scans, confirms, grants; the workstation collects
 *    and the session opens with no typing at all (kiosk guide 4.1A).
 *  - **Type.** The code field that has been here since M16.9, which is also
 *    the fallback the M18.53 rule requires: a node that cannot be reached, or
 *    a request that cannot be opened, leaves the field standing with a plain
 *    statement of what is unavailable — never a spinner, never a blank square.
 *
 * Either way, no personal device token is issued and this machine becomes
 * nobody's trusted device.
 *
 * The field is entered "with gloves and under glare" (kiosk guide 12), so the
 * code is one large, wide-tracked, case-insensitive field rather than eight
 * boxes that punish a mistyped character by losing the lot.
 *
 * A machine that does not know which workstation it is says so instead of
 * offering a field the node could only refuse. That is a setup problem, and it
 * is fixed on the setup surface rather than by guessing here.
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

/**
 * The QR, derived from the presented request. Computed rather than stored so
 * a replaced request re-renders the square by itself.
 */
const qrMatrix = computed<QrMatrix | null>(() => {
  const text = workstationSignInState.qrText;

  if (text === null) {
    return null;
  }

  try {
    return encodeQrMatrix(text);
  } catch {
    // A payload the encoder cannot hold is a build mistake, not a field
    // condition; the typed path stands either way.
    return null;
  }
});

const qrPath = computed(() => (qrMatrix.value === null ? "" : qrMatrixToSvgPath(qrMatrix.value)));

let pollTimer: ReturnType<typeof setInterval> | null = null;

async function tick(): Promise<void> {
  const outcome = await tickWorkstationSignIn();

  if (outcome === "signed_in") {
    await router.push({ name: "kiosk.home" });
  }
}

onMounted(async () => {
  if (workstationId.value === null) {
    return;
  }

  await presentWorkstationSignIn();

  pollTimer = setInterval(() => {
    void tick();
  }, SIGN_IN_POLL_INTERVAL_MS);
});

onBeforeUnmount(() => {
  if (pollTimer !== null) {
    clearInterval(pollTimer);
    pollTimer = null;
  }

  resetWorkstationSignIn();
});

// A session starting through the typed path drops the presented request: the
// QR belongs to the locked state, and the state is over.
watch(
  () => workstationSessionState.status,
  (status) => {
    if (status === "active") {
      resetWorkstationSignIn();
    }
  },
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
      Scan the code with the Meridian app on your phone, or enter your login
      code. Either signs you in to this workstation only, for this event, and
      ends after 5 minutes of inactivity.
    </p>

    <p v-if="workstationId === null" class="workstation-login__unconfigured" role="status">
      This machine is not set up as a trusted shared workstation, so it cannot
      accept a login code. A technician needs to configure it.
    </p>

    <template v-else>
      <figure
        v-if="workstationSignInState.status === 'presenting' && qrMatrix !== null"
        class="workstation-login__scan"
        data-testid="workstation-sign-in-qr"
      >
        <svg
          class="workstation-login__qr"
          :viewBox="`0 0 ${qrMatrix.size} ${qrMatrix.size}`"
          role="img"
          aria-label="Workstation sign-in code for the Meridian app"
          shape-rendering="crispEdges"
        >
          <path :d="qrPath" />
        </svg>
        <figcaption class="workstation-login__scan-caption">
          <strong class="workstation-login__scan-name">
            {{ workstationSignInState.workstationName ?? "This workstation" }}
          </strong>
          <span
            v-if="workstationSignInState.shortCode !== null"
            class="workstation-login__short-code"
            data-testid="workstation-short-code"
          >
            Workstation code: <code>{{ workstationSignInState.shortCode }}</code>
          </span>
          <span class="workstation-login__scan-hint">
            Scan with the Meridian app, or use the workstation code in the app
            if the camera cannot.
          </span>
        </figcaption>
      </figure>

      <p
        v-else-if="workstationSignInState.status === 'unavailable'"
        class="workstation-login__scan-unavailable"
        data-testid="workstation-sign-in-unavailable"
        role="status"
      >
        {{ workstationSignInState.unavailableReason }}
      </p>

      <form class="workstation-login__form" @submit.prevent="submit">
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
    </template>
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

.workstation-login__scan {
  display: grid;
  justify-items: center;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-4);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
}

/* The QR is white-backed on purpose: cameras need the contrast whatever the
   theme does. */
.workstation-login__qr {
  width: min(60vw, 16rem);
  height: auto;
  padding: var(--m-space-2);
  border-radius: 4px;
  background: #fff;
  fill: #000;
}

.workstation-login__scan-caption {
  display: grid;
  justify-items: center;
  gap: var(--m-space-1);
  text-align: center;
}

.workstation-login__scan-name {
  font-size: var(--m-text-lg);
}

.workstation-login__short-code {
  font-size: var(--m-text-md);
}

.workstation-login__short-code code {
  font-family: var(--m-font-mono, monospace);
  font-size: var(--m-text-lg);
  letter-spacing: 0.15em;
}

.workstation-login__scan-hint {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.workstation-login__scan-unavailable {
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
