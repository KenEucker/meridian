// What the node said about an accepted shift addition (M18.54; SLB-008;
// technical spec 20.5).
//
// Overlapping assignments are warned about rather than refused, and the warning
// is the node's: it knows every other shift the person is on, and the device
// holds only the desk's own horizon. Before M18.54 the desk read the warnings
// straight off the command's response, because the command was sent inline. Now
// it is queued, and the response arrives at the drain instead — which is a
// different call stack from the one the operator is standing in front of.
//
// So the acceptance handler leaves the warnings here, keyed by the command's
// idempotency key, and the desk collects them when its own call returns. Taking
// them removes them: a warning is about one addition at one moment, and a stale
// one re-shown against the next addition would be a claim about a shift nobody
// asked about.
//
// Nothing is kept for a command that was queued and not yet sent — there is
// nothing to keep, because the node has not answered. A device that drains
// hours later while nobody is looking simply drops them, which is the honest
// outcome: an advisory about an overlap is worth saying to the operator who
// made the addition, and worth nothing to whoever opens the desk tomorrow.

/** The most recent unread warning set per command, bounded so it cannot grow. */
const WARNINGS = new Map<string, readonly string[]>();

/**
 * How many unclaimed warning sets are kept.
 *
 * Small on purpose. The only reader is the call that made the addition, and one
 * that never came back for its warnings is one whose surface has already gone.
 */
const RETENTION = 20;

export function recordShiftAdditionWarnings(
  idempotencyKey: string,
  warnings: readonly string[],
): void {
  if (warnings.length === 0) {
    return;
  }

  WARNINGS.set(idempotencyKey, Object.freeze([...warnings]));

  const excess = WARNINGS.size - RETENTION;

  if (excess > 0) {
    for (const key of [...WARNINGS.keys()].slice(0, excess)) {
      WARNINGS.delete(key);
    }
  }
}

/** Collect and clear one command's warnings. Empty when the node raised none. */
export function takeShiftAdditionWarnings(
  idempotencyKey: string,
): readonly string[] {
  const warnings = WARNINGS.get(idempotencyKey) ?? [];
  WARNINGS.delete(idempotencyKey);

  return warnings;
}
