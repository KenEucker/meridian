<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\User;
use App\Services\Equipment\EquipmentInventoryAccess;
use App\Services\Equipment\EquipmentInventoryException;
use App\Services\Equipment\EquipmentInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Product-path equipment inventory setup commands (M11.18; EQUIP-001 through
 * EQUIP-005, EQUIP-007; UI contract 12.4 `department.equipment`).
 *
 * Inventory is always addressed by department, so a maintainer can only create
 * or change equipment in a department they are permitted to manage. Checkout
 * and check-in stay on the existing Logistics commands.
 */
final class EquipmentInventoryCommandController extends Controller
{
    public function create(
        Request $request,
        EquipmentInventoryAccess $access,
        EquipmentInventoryService $inventory,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'asset_tag' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'event_id' => ['nullable', 'uuid', Rule::exists(Event::class, 'id')],
        ]);

        $department = Department::query()->findOrFail((string) $validated['department_id']);

        if (! $access->canManageInventory($user, $department)) {
            return response()->json([
                'message' => 'You do not have permission to manage equipment inventory for this department.',
            ], 403);
        }

        try {
            $item = $inventory->create($department, [
                'name' => (string) $validated['name'],
                'asset_tag' => $validated['asset_tag'] ?? null,
                'serial_number' => $validated['serial_number'] ?? null,
                'event_id' => $validated['event_id'] ?? null,
            ], $user, AuditEvent::SOURCE_API);
        } catch (EquipmentInventoryException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($item), 201);
    }

    public function update(
        Request $request,
        EquipmentInventoryAccess $access,
        EquipmentInventoryService $inventory,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'equipment_item_id' => ['required', 'uuid', Rule::exists(EquipmentItem::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'asset_tag' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'event_id' => ['nullable', 'uuid', Rule::exists(Event::class, 'id')],
            'status' => ['nullable', 'string', Rule::in(EquipmentItem::statuses())],
        ]);

        $item = EquipmentItem::query()
            ->with('department')
            ->findOrFail((string) $validated['equipment_item_id']);

        if (! $this->authorizeItem($access, $user, $item)) {
            return response()->json([
                'message' => 'You do not have permission to manage this equipment.',
            ], 403);
        }

        try {
            $item = $inventory->update($item, [
                'name' => (string) $validated['name'],
                'asset_tag' => $validated['asset_tag'] ?? null,
                'serial_number' => $validated['serial_number'] ?? null,
                'event_id' => $validated['event_id'] ?? null,
                'status' => $validated['status'] ?? null,
            ], $user, AuditEvent::SOURCE_API);
        } catch (EquipmentInventoryException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($item));
    }

    public function archive(
        Request $request,
        EquipmentInventoryAccess $access,
        EquipmentInventoryService $inventory,
    ): JsonResponse {
        return $this->transition(
            $request,
            $access,
            fn (EquipmentItem $item, User $actor): EquipmentItem => $inventory->archive(
                $item,
                $actor,
                AuditEvent::SOURCE_API,
            ),
        );
    }

    public function restore(
        Request $request,
        EquipmentInventoryAccess $access,
        EquipmentInventoryService $inventory,
    ): JsonResponse {
        return $this->transition(
            $request,
            $access,
            fn (EquipmentItem $item, User $actor): EquipmentItem => $inventory->restore(
                $item,
                $actor,
                AuditEvent::SOURCE_API,
            ),
        );
    }

    /**
     * Bulk inventory import from spreadsheet CSV text (EQUIP-001).
     */
    public function import(
        Request $request,
        EquipmentInventoryAccess $access,
        EquipmentInventoryService $inventory,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'event_id' => ['nullable', 'uuid', Rule::exists(Event::class, 'id')],
            'csv' => ['required', 'string'],
        ]);

        $department = Department::query()->findOrFail((string) $validated['department_id']);

        if (! $access->canManageInventory($user, $department)) {
            return response()->json([
                'message' => 'You do not have permission to manage equipment inventory for this department.',
            ], 403);
        }

        $event = isset($validated['event_id']) && $validated['event_id'] !== null
            ? Event::query()->findOrFail((string) $validated['event_id'])
            : null;

        try {
            $result = $inventory->import($department, $event, (string) $validated['csv'], $user, AuditEvent::SOURCE_API);
        } catch (EquipmentInventoryException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result, 201);
    }

    /**
     * @param  callable(EquipmentItem, User): EquipmentItem  $operation
     */
    private function transition(
        Request $request,
        EquipmentInventoryAccess $access,
        callable $operation,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'equipment_item_id' => ['required', 'uuid', Rule::exists(EquipmentItem::class, 'id')],
        ]);

        $item = EquipmentItem::query()
            ->with('department')
            ->findOrFail((string) $validated['equipment_item_id']);

        if (! $this->authorizeItem($access, $user, $item)) {
            return response()->json([
                'message' => 'You do not have permission to manage this equipment.',
            ], 403);
        }

        try {
            $item = $operation($item, $user);
        } catch (EquipmentInventoryException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($item));
    }

    private function authorizeItem(
        EquipmentInventoryAccess $access,
        User $user,
        EquipmentItem $item,
    ): bool {
        // Organization- or event-owned equipment with no department stays God
        // Mode repair tooling; the product path only manages department stock.
        return $item->department !== null
            && $access->canManageInventory($user, $item->department);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EquipmentItem $item): array
    {
        return [
            'id' => (string) $item->id,
            'organization_id' => (string) $item->organization_id,
            'department_id' => $item->department_id !== null ? (string) $item->department_id : null,
            'event_id' => $item->event_id !== null ? (string) $item->event_id : null,
            'name' => $item->name,
            'asset_tag' => $item->asset_tag,
            'serial_number' => $item->serial_number,
            'status' => $item->status,
            'status_label' => EquipmentItem::statusLabel($item->status),
            'archived_at' => $item->archived_at?->toIso8601String(),
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
