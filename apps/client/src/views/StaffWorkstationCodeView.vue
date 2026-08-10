<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from "vue";
import { RouterLink } from "vue-router";

import { MeridianApiError, meridianJson } from "@/api/meridianApi";
import { sessionEventContext } from "@/session/sessionAccess";
import {
  acceptScannedWorkstationCode,
  grantScannedWorkstationSignIn,
  resetWorkstationGrantFlow,
  workstationGrantState,
} from "@/session/workstationGrantFlow";

/*
 * `staff.workstation-code` — sign in to a shared workstation from the device
 * in your hand (M18.61; AUTH-026 through AUTH-028, AUTH-033, AUTH-034; UI
 * contract 12.3; technical spec 13.4; kiosk guide 4.1, 4.1A).
 *
 * The surface UI contract 12.3 has listed since it was written and no
 * milestone produced. Three ways to get onto the workstation in front of you,
 * in the order a person standing there reaches for them:
 *
 *  1. **Scan.** The locked workstation shows a QR; scanning it, confirming the
 *     named workstation and event, and tapping Grant signs you in there with
 *     no typing at all. A QR naming a different node is refused with both node
 *     identities named (AUTH-034) — the code would otherwise be issued into
 *     the wrong database and fail at the keyboard with no reason given.
 *  2. **Type the workstation's code.** The `short_code` displayed beside the
 *     QR, for a dead or denied camera: it resolves the workstation and issues
 *     an ordinary targeted login code to type at its keyboard.
 *  3. **A code with no target.** An unbound code (AUTH-031) that works at the
 *     first trusted workstation it is entered at, within this event.
 *
 * A generated code is shown once, from this render only. It is never stored,
 * never retrievable again, and the surface says so plainly — the same rule
 * every other code display in Meridian follows (technical spec 13.2).
 *
 * **QR decoding choice, recorded rather than assumed (M18.61):** scanning uses
 * the browser's `BarcodeDetector` where the platform provides it, and falls
 * back to the typed `short_code` where it does not. A Capacitor barcode plugin
 * would add native build surface to `@meridian/mobile` and do nothing for the
 * browser client; a WASM decoder would be a dependency the technology baseline
 * has not admitted. The typed fallback is not a degraded mode — it is the same
 * fallback a scratched camera lens needs, and it is always on screen.
 */

const eventContext = computed(() => sessionEventContext.value);

/* ------------------------------------------------------------------ scan */

const scanning = ref(false);
const scanError = ref<string | null>(null);

let mediaStream: MediaStream | null = null;
let detectTimer: ReturnType<typeof setInterval> | null = null;

const videoElement = ref<HTMLVideoElement | null>(null);

interface DetectedBarcode {
  readonly rawValue: string;
}

interface BarcodeDetectorLike {
  detect(source: CanvasImageSource): Promise<DetectedBarcode[]>;
}

function barcodeDetector(): BarcodeDetectorLike | null {
  const ctor = (
    globalThis as { BarcodeDetector?: new (options?: { formats?: string[] }) => BarcodeDetectorLike }
  ).BarcodeDetector;

  if (ctor === undefined) {
    return null;
  }

  try {
    return new ctor({ formats: ["qr_code"] });
  } catch {
    return null;
  }
}

async function startScanning(): Promise<void> {
  scanError.value = null;

  const detector = barcodeDetector();

  if (detector === null) {
    scanError.value =
      "This device's browser cannot scan codes. Enter the workstation code shown on its screen instead.";

    return;
  }

  if (navigator.mediaDevices?.getUserMedia === undefined) {
    scanError.value =
      "This device offers no camera here. Enter the workstation code shown on its screen instead.";

    return;
  }

  try {
    mediaStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: "environment" },
    });
  } catch {
    // A denied camera permission reaches the typed fallback rather than a
    // dead screen (M18.61): the message names the path that still works.
    scanError.value =
      "The camera is unavailable or its permission was denied. Enter the workstation code shown on its screen instead.";

    return;
  }

  scanning.value = true;

  // The video element renders once `scanning` flips; attach on next tick.
  await new Promise((resolve) => setTimeout(resolve, 0));

  const video = videoElement.value;

  if (video === null) {
    stopScanning();

    return;
  }

  video.srcObject = mediaStream;
  await video.play().catch(() => undefined);

  detectTimer = setInterval(() => {
    void (async () => {
      if (videoElement.value === null) {
        return;
      }

      try {
        const detected = await detector.detect(videoElement.value);
        const text = detected[0]?.rawValue;

        if (text === undefined) {
          return;
        }

        stopScanning();

        const accepted = await acceptScannedWorkstationCode(text);

        if (!accepted) {
          scanError.value = workstationGrantState.error;
        }
      } catch {
        // A frame that could not be read is just the next frame's problem.
      }
    })();
  }, 400);
}

function stopScanning(): void {
  scanning.value = false;

  if (detectTimer !== null) {
    clearInterval(detectTimer);
    detectTimer = null;
  }

  if (mediaStream !== null) {
    for (const track of mediaStream.getTracks()) {
      track.stop();
    }

    mediaStream = null;
  }
}

async function grant(): Promise<void> {
  await grantScannedWorkstationSignIn();
}

function dismissScan(): void {
  resetWorkstationGrantFlow();
}

/* ------------------------------------------------- the two code fallbacks */

interface GeneratedCode {
  readonly code: string;
  readonly expiresAt: string | null;
  /** The named workstation, or null for a code with no target. */
  readonly workstationName: string | null;
}

const shortCode = ref("");
const generating = ref(false);
const generateError = ref<string | null>(null);

/**
 * Held in this component and nowhere else, on purpose: a generated code is
 * shown once, and leaving the surface is what makes it unretrievable.
 */
const generated = ref<GeneratedCode | null>(null);

function readGeneratedCode(payload: unknown): GeneratedCode | null {
  if (typeof payload !== "object" || payload === null || Array.isArray(payload)) {
    return null;
  }

  const record = payload as Record<string, unknown>;
  const code = typeof record.code === "string" && record.code !== "" ? record.code : null;

  if (code === null) {
    return null;
  }

  const workstation =
    typeof record.shared_workstation === "object" &&
    record.shared_workstation !== null &&
    !Array.isArray(record.shared_workstation)
      ? (record.shared_workstation as Record<string, unknown>)
      : null;

  return {
    code,
    expiresAt: typeof record.expires_at === "string" ? record.expires_at : null,
    workstationName:
      workstation !== null && typeof workstation.name === "string" ? workstation.name : null,
  };
}

async function generate(body: Record<string, unknown>): Promise<void> {
  if (generating.value) {
    return;
  }

  generating.value = true;
  generateError.value = null;
  generated.value = null;

  try {
    const payload = await meridianJson<unknown>("/api/auth/shared-workstation-login-code", {
      method: "POST",
      body: JSON.stringify(body),
    });

    const code = readGeneratedCode(payload);

    if (code === null) {
      generateError.value = "The node did not return a code. Try again.";

      return;
    }

    generated.value = code;
    shortCode.value = "";
  } catch (error) {
    generateError.value =
      error instanceof MeridianApiError
        ? error.message
        : "This device could not reach the node to generate a code.";
  } finally {
    generating.value = false;
  }
}

const canGenerateForShortCode = computed(
  () => shortCode.value.trim() !== "" && eventContext.value !== null && !generating.value,
);

async function generateForShortCode(): Promise<void> {
  const event = eventContext.value;

  if (event === null || !canGenerateForShortCode.value) {
    return;
  }

  await generate({
    shared_workstation_short_code: shortCode.value.trim(),
    event_id: event.eventId,
  });
}

async function generateUntargeted(): Promise<void> {
  const event = eventContext.value;

  if (event === null) {
    generateError.value =
      "A code with no workstation needs an event. Choose an event context first.";

    return;
  }

  await generate({ event_id: event.eventId });
}

onBeforeUnmount(() => {
  stopScanning();
  resetWorkstationGrantFlow();
});
</script>

<template>
  <section class="workstation-code" aria-labelledby="workstation-code-heading">
    <nav class="workstation-code__back" aria-label="Back">
      <RouterLink :to="{ name: 'staff.me' }">Back to Me</RouterLink>
    </nav>

    <h1 id="workstation-code-heading">Sign in to a workstation</h1>
    <p class="workstation-code__lede">
      Use this device to sign yourself in to a shared workstation — a kiosk or
      a desk machine. Scan the code on its locked screen, or use one of the
      typed codes below. Nothing here signs anybody else in, and the
      workstation session it opens is separate from this device's.
    </p>

    <!-- ------------------------------------------------------ the scan -->
    <section class="workstation-code__panel" aria-labelledby="workstation-scan-heading">
      <h2 id="workstation-scan-heading">Scan the workstation's code</h2>

      <template v-if="workstationGrantState.step === 'idle'">
        <video
          v-if="scanning"
          ref="videoElement"
          class="workstation-code__viewfinder"
          data-testid="workstation-scan-viewfinder"
          muted
          playsinline
        />

        <p v-if="scanError !== null" class="workstation-code__notice" role="alert" data-testid="workstation-scan-error">
          {{ scanError }}
        </p>

        <button
          v-if="!scanning"
          class="workstation-code__action"
          type="button"
          data-testid="workstation-scan-start"
          @click="startScanning"
        >
          Scan workstation code
        </button>
        <button
          v-else
          class="workstation-code__action workstation-code__action--secondary"
          type="button"
          @click="stopScanning"
        >
          Stop scanning
        </button>
      </template>

      <template v-else-if="workstationGrantState.step === 'confirming'">
        <p class="workstation-code__confirm" data-testid="workstation-grant-confirm">
          Sign in at
          <strong>{{ workstationGrantState.workstationName ?? "this workstation" }}</strong>
          <template v-if="workstationGrantState.eventName !== null">
            for <strong>{{ workstationGrantState.eventName }}</strong>
          </template>
          as yourself?
        </p>

        <p
          v-if="workstationGrantState.error !== null"
          class="workstation-code__notice"
          role="alert"
        >
          {{ workstationGrantState.error }}
        </p>

        <div class="workstation-code__confirm-actions">
          <button
            class="workstation-code__action"
            type="button"
            data-testid="workstation-grant-confirm-button"
            :disabled="workstationGrantState.granting"
            @click="grant"
          >
            {{ workstationGrantState.granting ? "Signing you in…" : "Sign me in there" }}
          </button>
          <button
            class="workstation-code__action workstation-code__action--secondary"
            type="button"
            @click="dismissScan"
          >
            Cancel
          </button>
        </div>
      </template>

      <template v-else>
        <p class="workstation-code__granted" role="status" data-testid="workstation-grant-done">
          Done — the workstation is signing you in. It shows your name once it
          has.
        </p>
        <button
          class="workstation-code__action workstation-code__action--secondary"
          type="button"
          @click="dismissScan"
        >
          Scan another
        </button>
      </template>
    </section>

    <!-- ------------------------------------------- the typed fallbacks -->
    <section class="workstation-code__panel" aria-labelledby="workstation-typed-heading">
      <h2 id="workstation-typed-heading">Or get a login code to type</h2>

      <p
        v-if="generated !== null"
        class="workstation-code__generated"
        role="status"
        data-testid="workstation-generated-code"
      >
        <code class="workstation-code__generated-code">{{ generated.code }}</code>
        <span v-if="generated.workstationName !== null">
          Type it at <strong>{{ generated.workstationName }}</strong>.
        </span>
        <span v-else>
          Type it at any trusted workstation in this event — it binds to the
          first one it is used at.
        </span>
        <strong class="workstation-code__generated-warning">
          This code is shown once and will not be shown again.
        </strong>
      </p>

      <p v-if="generateError !== null" class="workstation-code__notice" role="alert">
        {{ generateError }}
      </p>

      <form
        class="workstation-code__short-code"
        data-testid="workstation-short-code-form"
        @submit.prevent="generateForShortCode"
      >
        <label class="workstation-code__label" for="workstation-short-code-input">
          Workstation code — shown on the workstation's locked screen
        </label>
        <div class="workstation-code__short-code-row">
          <input
            id="workstation-short-code-input"
            v-model="shortCode"
            class="workstation-code__input"
            type="text"
            inputmode="text"
            autocomplete="off"
            autocapitalize="characters"
            spellcheck="false"
            maxlength="16"
          />
          <button
            class="workstation-code__action"
            type="submit"
            :disabled="!canGenerateForShortCode"
          >
            {{ generating ? "Generating…" : "Get a code for it" }}
          </button>
        </div>
      </form>

      <div class="workstation-code__untargeted">
        <p class="workstation-code__hint">
          No code on the screen either? Generate one with no workstation named.
        </p>
        <button
          class="workstation-code__action workstation-code__action--secondary"
          type="button"
          data-testid="workstation-untargeted-generate"
          :disabled="generating"
          @click="generateUntargeted"
        >
          Generate a code with no workstation
        </button>
      </div>
    </section>
  </section>
</template>

<style scoped>
.workstation-code {
  width: var(--m-content-narrow);
  display: grid;
  gap: var(--m-space-4);
}

.workstation-code__back a {
  color: var(--m-text-muted);
}

.workstation-code h1 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.workstation-code__lede {
  margin: 0;
  color: var(--m-text-muted);
}

.workstation-code__panel {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
}

.workstation-code__panel h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.workstation-code__viewfinder {
  width: 100%;
  border-radius: var(--m-radius-md);
  background: #000;
  aspect-ratio: 4 / 3;
}

.workstation-code__notice {
  margin: 0;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-left: 4px solid var(--m-status-neutral);
  border-radius: var(--m-radius-md);
  color: var(--m-text-secondary);
}

.workstation-code__confirm {
  margin: 0;
  font-size: var(--m-text-lg);
}

.workstation-code__confirm-actions {
  display: flex;
  gap: var(--m-space-2);
  flex-wrap: wrap;
}

.workstation-code__granted {
  margin: 0;
  font-weight: 700;
}

.workstation-code__generated {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-left: 4px solid var(--m-status-positive, var(--m-border-default));
  border-radius: var(--m-radius-md);
}

.workstation-code__generated-code {
  font-family: var(--m-font-mono, monospace);
  font-size: var(--m-text-xl);
  letter-spacing: 0.2em;
}

.workstation-code__generated-warning {
  color: var(--m-text-secondary);
}

.workstation-code__label {
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.workstation-code__short-code {
  display: grid;
  gap: var(--m-space-2);
}

.workstation-code__short-code-row {
  display: flex;
  gap: var(--m-space-2);
  flex-wrap: wrap;
}

.workstation-code__input {
  flex: 1 1 12rem;
  min-height: 3rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base, var(--m-surface-raised));
  color: var(--m-text-primary);
  font-family: var(--m-font-mono, monospace);
  font-size: var(--m-text-lg);
  letter-spacing: 0.15em;
  text-transform: uppercase;
}

.workstation-code__action {
  min-height: 3rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-action-primary-bg, var(--m-surface-raised));
  color: var(--m-action-primary-fg, var(--m-text-primary));
  font-weight: 800;
}

.workstation-code__action--secondary {
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
}

.workstation-code__action:disabled {
  opacity: 0.6;
}

.workstation-code__untargeted {
  display: grid;
  gap: var(--m-space-2);
}

.workstation-code__hint {
  margin: 0;
  color: var(--m-text-muted);
}
</style>
