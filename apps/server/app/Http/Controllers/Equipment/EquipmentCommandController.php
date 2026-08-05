<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Shift;
use App\Models\Staff;
use App\Services\Equipment\EquipmentCheckoutException;
use App\Services\Equipment\EquipmentCheckoutResult;
use App\Services\Equipment\EquipmentCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class EquipmentCommandController extends Controller
{
    public function checkout(
        Request $request,
        EquipmentCheckoutService $equipmentCheckouts,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'equipment_item_id' => ['required', 'uuid', 'exists:equipment_items,id'],
            'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'shift_id' => ['nullable', 'uuid', 'exists:shifts,id'],
            // The event this handoff is made under, for department stock that
            // names no event of its own (EQUIP-009). Ignored for a shift, which
            // carries its own event, and for an item scoped to one.
            'event_id' => ['nullable', 'uuid', 'exists:events,id'],
            'checked_out_at' => ['nullable', 'date'],
            // How many units of a pooled kind are being handed over
            // (EQUIP-011). A tracked unit is one thing and the service refuses
            // any other count for it.
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $result = $equipmentCheckouts->checkoutEquipment(
                equipmentItem: EquipmentItem::query()->findOrFail((string) $validated['equipment_item_id']),
                staff: Staff::query()->findOrFail((string) $validated['staff_id']),
                actor: $user,
                shift: isset($validated['shift_id'])
                    ? Shift::query()->findOrFail((string) $validated['shift_id'])
                    : null,
                checkedOutAt: isset($validated['checked_out_at'])
                    ? Carbon::parse((string) $validated['checked_out_at'])
                    : null,
                quantity: (int) ($validated['quantity'] ?? 1),
                event: isset($validated['event_id'])
                    ? Event::query()->findOrFail((string) $validated['event_id'])
                    : null,
            );
        } catch (EquipmentCheckoutException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($result), 201);
    }

    public function returnEquipment(
        Request $request,
        EquipmentCheckoutService $equipmentCheckouts,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'equipment_checkout_id' => ['required', 'uuid', 'exists:equipment_checkouts,id'],
            'return_condition' => ['required', Rule::in([
                EquipmentItem::STATUS_RETURNED,
                EquipmentItem::STATUS_MISSING,
                EquipmentItem::STATUS_DAMAGED,
            ])],
            'returned_at' => ['nullable', 'date'],
            // A pooled checkout may come back in parts (data/API 10.13); an
            // omitted quantity returns everything still out on it.
            'quantity' => ['nullable', 'integer', 'min:1'],
            // The reason carried by the audited pool adjustment a missing or
            // damaged pooled return produces (EQUIP-017).
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $equipmentCheckouts->returnEquipment(
                checkout: EquipmentCheckout::query()->findOrFail((string) $validated['equipment_checkout_id']),
                actor: $user,
                returnCondition: (string) $validated['return_condition'],
                returnedAt: isset($validated['returned_at'])
                    ? Carbon::parse((string) $validated['returned_at'])
                    : null,
                quantity: isset($validated['quantity']) ? (int) $validated['quantity'] : null,
                reason: $validated['reason'] ?? null,
            );
        } catch (EquipmentCheckoutException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($result), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EquipmentCheckoutResult $result): array
    {
        return [
            'equipment_item_id' => $result->equipmentItem->id,
            'equipment_checkout_id' => $result->checkout->id,
            'event_id' => $result->checkout->event_id,
            'staff_id' => $result->checkout->staff_id,
            'shift_id' => $result->checkout->shift_id,
            // Derived from the shift, not stored beside it (EQUIP-009).
            'assignment_scope' => $result->checkout->assignmentScope(),
            'quantity' => (int) ($result->checkout->quantity ?? 1),
            'quantity_returned' => $result->checkout->quantity_returned === null
                ? null
                : (int) $result->checkout->quantity_returned,
            'quantity_outstanding' => $result->checkout->quantityOutstanding(),
            'equipment_status' => $result->equipmentItem->status,
            'equipment_tracking' => $result->equipmentItem->tracking,
            'equipment_quantity_available' => $result->equipmentItem->availableQuantity(),
            'checked_out_at' => optional($result->checkout->checked_out_at)?->toIso8601String(),
            'checked_out_by_user_id' => $result->checkout->checked_out_by_user_id,
            'returned_at' => optional($result->checkout->returned_at)?->toIso8601String(),
            'returned_by_user_id' => $result->checkout->returned_by_user_id,
            'return_condition' => $result->checkout->return_condition,
            'created_state_change' => $result->createdStateChange,
        ];
    }
}
