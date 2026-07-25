<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Services\Equipment\EquipmentInventoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Department equipment inventory reads (M11.18; UI contract 12.4
 * `department.equipment`). The list shows the inventory a maintainer is about
 * to hand to Logistics, including which items are currently checked out so
 * setup and operations do not fight over the same record.
 */
final class EquipmentInventoryReadController extends Controller
{
    public function index(
        Request $request,
        Department $department,
        EquipmentInventoryAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canManageInventory($user, $department)) {
            return response()->json([
                'message' => 'You do not have permission to manage equipment inventory for this department.',
            ], 403);
        }

        $status = (string) $request->query('status', 'all');

        if (! in_array($status, ['all', 'active', 'archived'], true)) {
            return response()->json([
                'message' => 'Status filter must be all, active, or archived.',
            ], 422);
        }

        $query = EquipmentItem::query()
            ->where('department_id', $department->id)
            ->orderBy('name');

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'archived') {
            $query->whereNotNull('archived_at');
        }

        $items = $query->get();
        $openCheckoutItemIds = $this->openCheckoutItemIds($items->pluck('id')->all());

        return response()->json([
            'department' => [
                'id' => (string) $department->id,
                'organization_id' => (string) $department->organization_id,
                'name' => $department->name,
                'code' => $department->code,
                'archived_at' => $department->archived_at?->toIso8601String(),
            ],
            'access' => [
                'can_manage' => true,
            ],
            'events' => $this->eventOptions($department),
            'maintainable_statuses' => [
                EquipmentItem::STATUS_AVAILABLE,
                EquipmentItem::STATUS_MISSING,
                EquipmentItem::STATUS_DAMAGED,
            ],
            'status_labels' => EquipmentItem::statusLabels(),
            'equipment' => $items
                ->map(fn (EquipmentItem $item): array => $this->payload(
                    $item,
                    in_array((string) $item->id, $openCheckoutItemIds, true),
                ))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @param  list<mixed>  $itemIds
     * @return list<string>
     */
    private function openCheckoutItemIds(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return EquipmentCheckout::query()
            ->whereIn('equipment_item_id', $itemIds)
            ->whereNull('returned_at')
            ->pluck('equipment_item_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EquipmentItem $item, bool $hasOpenCheckout): array
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
            'has_open_checkout' => $hasOpenCheckout,
            'archived_at' => $item->archived_at?->toIso8601String(),
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventOptions(Department $department): array
    {
        return Event::query()
            ->where('organization_id', $department->organization_id)
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (Event $event): array => [
                'id' => (string) $event->id,
                'name' => $event->name,
            ])
            ->values()
            ->all();
    }
}
