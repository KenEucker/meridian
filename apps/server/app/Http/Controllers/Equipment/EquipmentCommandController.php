<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
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
            'checked_out_at' => ['nullable', 'date'],
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
        ]);

        try {
            $result = $equipmentCheckouts->returnEquipment(
                checkout: EquipmentCheckout::query()->findOrFail((string) $validated['equipment_checkout_id']),
                actor: $user,
                returnCondition: (string) $validated['return_condition'],
                returnedAt: isset($validated['returned_at'])
                    ? Carbon::parse((string) $validated['returned_at'])
                    : null,
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
            'equipment_status' => $result->equipmentItem->status,
            'checked_out_at' => optional($result->checkout->checked_out_at)?->toIso8601String(),
            'checked_out_by_user_id' => $result->checkout->checked_out_by_user_id,
            'returned_at' => optional($result->checkout->returned_at)?->toIso8601String(),
            'returned_by_user_id' => $result->checkout->returned_by_user_id,
            'return_condition' => $result->checkout->return_condition,
            'created_state_change' => $result->createdStateChange,
        ];
    }
}
