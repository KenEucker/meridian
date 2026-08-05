<?php

namespace App\Services\Equipment;

use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What a desk should read on an equipment checkout right now (M18.24;
 * EQUIP-005, EQUIP-009; UI contract 9.6).
 *
 * EQUIP-005 fixes the stored states at five — Available, Checked out, Returned,
 * Missing, Damaged — and says the rest of what an operator wants to know is
 * derived: "Presentation distinctions such as overdue, lost, or unknown shall be
 * derived from a stored state plus the associated shift or event window, and
 * shall not be added as stored states."
 *
 * That sentence is a schema decision as much as a vocabulary one. Overdue is not
 * a thing that happens to a record; it is a thing that becomes true of an
 * unchanged record as a clock passes a time. Storing it would mean something has
 * to write it — a nightly job, a read that quietly mutates, an operator noticing
 * — and every one of those produces a row that says "checked out" for six hours
 * after it stopped being true. Computing it on read cannot be late.
 *
 * The window it is measured against is the assignment scope EQUIP-009 records: a
 * shift-assigned checkout is owed back when its shift ends, an event-assigned one
 * when the event's operations close. That is the whole reason the scope exists —
 * "so that a checkout still open after its shift ends can be distinguished from
 * one still open after the event ends".
 *
 * Three derived readings come out of it, and one of them is the honest one:
 *
 *  - **Out.** Open, with a due time still ahead of it.
 *  - **Overdue.** Open, and past the end of the window it was issued against.
 *  - **Unknown.** Open, and there is no window end to measure against — a shift
 *    with no recorded end, or an event-assigned checkout on an event whose
 *    window and dates are both unset. Meridian cannot say whether this is late,
 *    so it says it cannot say. Reporting it as on-time would be a guess wearing
 *    the clothes of a fact.
 *
 * A closed checkout reads its return condition, which is a stored state and is
 * not re-derived here.
 */
final class EquipmentCheckoutPresentation
{
    /** Open, and still inside the window it was issued against. */
    public const STATE_OUT = 'checked_out';

    /** Open, and past the end of that window (EQUIP-005). */
    public const STATE_OVERDUE = 'overdue';

    /** Open, with no window end to measure against (EQUIP-005). */
    public const STATE_UNKNOWN = 'unknown';

    public const STATE_RETURNED = EquipmentItem::STATUS_RETURNED;

    public const STATE_MISSING = EquipmentItem::STATUS_MISSING;

    public const STATE_DAMAGED = EquipmentItem::STATUS_DAMAGED;

    /**
     * Canonical labels. The five stored states keep UI contract 9.6's words;
     * the two derived ones are additions to the reading, not to the vocabulary
     * of stored state.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::STATE_OUT => 'Checked out',
            self::STATE_OVERDUE => 'Overdue',
            self::STATE_UNKNOWN => 'Unknown',
            self::STATE_RETURNED => 'Returned',
            self::STATE_MISSING => 'Missing',
            self::STATE_DAMAGED => 'Damaged',
        ];
    }

    public static function label(string $state): string
    {
        return self::labels()[$state] ?? EquipmentItem::statusLabel($state);
    }

    /**
     * The whole derived reading for one checkout.
     *
     * `$shift` and `$event` are passed rather than lazy-loaded so a desk read
     * that already has them does not issue a query per row. Either may be null;
     * a missing window is exactly the case that produces `unknown`.
     *
     * @return array{
     *     assignment_scope: string,
     *     assignment_scope_label: string,
     *     state: string,
     *     state_label: string,
     *     due_at: string|null,
     *     overdue: bool,
     *     quantity: int,
     *     quantity_returned: int,
     *     quantity_outstanding: int
     * }
     */
    public static function describe(
        EquipmentCheckout $checkout,
        ?Shift $shift,
        ?Event $event,
        ?CarbonInterface $now = null,
    ): array {
        $now ??= Carbon::now();
        $scope = $checkout->assignmentScope();
        $dueAt = self::dueAt($checkout, $shift, $event);
        $state = self::state($checkout, $dueAt, $now);

        return [
            'assignment_scope' => $scope,
            'assignment_scope_label' => EquipmentCheckout::assignmentScopeLabels()[$scope],
            'state' => $state,
            'state_label' => self::label($state),
            'due_at' => $dueAt?->toIso8601String(),
            'overdue' => $state === self::STATE_OVERDUE,
            'quantity' => (int) ($checkout->quantity ?? 1),
            'quantity_returned' => (int) ($checkout->quantity_returned ?? 0),
            'quantity_outstanding' => $checkout->quantityOutstanding(),
        ];
    }

    /**
     * When this checkout is owed back, or null when nothing says.
     *
     * A shift-assigned checkout answers to its shift's end even when that shift
     * ended before the item went out — EQUIP-007 lets equipment be handed over
     * before, during, or after a shift, and one handed over afterwards is owed
     * back immediately rather than never.
     *
     * An event-assigned checkout answers to the close of the event's operations:
     * the active event window's end where the organization set one, and the
     * event's own end date otherwise. Neither set is the `unknown` case.
     */
    public static function dueAt(
        EquipmentCheckout $checkout,
        ?Shift $shift,
        ?Event $event,
    ): ?CarbonInterface {
        if ($checkout->assignmentScope() === EquipmentCheckout::SCOPE_SHIFT) {
            return $shift?->ends_at;
        }

        return $event?->active_event_window_ends_at ?? $event?->ends_at;
    }

    private static function state(
        EquipmentCheckout $checkout,
        ?CarbonInterface $dueAt,
        CarbonInterface $now,
    ): string {
        if ($checkout->returned_at !== null) {
            // The stored condition, unchanged. A returned checkout has an
            // answer on file and nothing here improves on it.
            return $checkout->return_condition ?? self::STATE_RETURNED;
        }

        if ($dueAt === null) {
            return self::STATE_UNKNOWN;
        }

        return $dueAt->isBefore($now) ? self::STATE_OVERDUE : self::STATE_OUT;
    }
}
