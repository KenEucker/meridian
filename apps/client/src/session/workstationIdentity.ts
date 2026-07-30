// Which trusted shared workstation this machine is (M16.9; technical spec 13.3).
//
// Separate from `workstationSession` on purpose. This is who the *machine* is,
// which survives restarts and user changes; that is who is *signed in*, which
// survives neither. Conflating them is how a session ends up being restored from
// disk.
//
// It is not a credential. A login code entry names the workstation, but the node
// then requires the code itself and requires the workstation to be trusted
// (AUTH-030, technical spec 13.2), so knowing the id grants nothing. That is why
// it can be persisted at all — and it has to be, because a workstation that
// forgot which one it was on restart could not offer a login screen.
//
// Resolution order, highest first:
//
//   1. `configured` — written by the kiosk setup surface on this machine.
//   2. `runtime`    — injected by whatever served or packaged the client.
//
// Configuring outranks the injected value for the same reason it does in
// `nodeConnection`: a technician standing at the machine is more current than a
// build.

import { computed, ref } from "vue";

const storageKey = "meridian.workstation.id";

function readStoredId(): string | null {
  try {
    const stored = window.localStorage.getItem(storageKey);

    return stored === null || stored === "" ? null : stored;
  } catch {
    // Storage can be unavailable or full. A machine that cannot read its own
    // identity falls back to the injected one rather than failing to render.
    return null;
  }
}

const configuredId = ref<string | null>(readStoredId());

/**
 * The workstation this machine is, or null when it is not a shared workstation.
 */
export const sharedWorkstationId = computed<string | null>(() => {
  const injected = window.__MERIDIAN_RUNTIME_CONFIG__?.sharedWorkstationId;

  return configuredId.value ?? (injected === undefined || injected === "" ? null : injected);
});

/** Set, or clear, the workstation this machine is. */
export function configureSharedWorkstationId(id: string | null): void {
  const next = id === null || id.trim() === "" ? null : id.trim();

  configuredId.value = next;

  try {
    if (next === null) {
      window.localStorage.removeItem(storageKey);
    } else {
      window.localStorage.setItem(storageKey, next);
    }
  } catch {
    // The value still applies for this run. A machine that cannot persist it
    // needs configuring again after a restart, which is visible on the setup
    // surface rather than silent.
  }
}
