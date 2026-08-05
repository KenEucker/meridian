<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Staff;
use App\Services\Equipment\EquipmentInventoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Department equipment inventory reads (M11.18; UI contract 12.4
 * `department.equipment`; bound to the client in M16.17).
 *
 * The list shows the inventory a maintainer is about to hand to Logistics,
 * including which items are currently checked out so setup and operations do
 * not fight over the same record.
 *
 * An item that is out carries the checkout itself rather than a flag. The
 * refusals a maintainer meets on that item — no state change, no archive —
 * are answered by "Radio 12 has been out with Vera since Friday", which names
 * who to ask; a bare boolean leaves them with a locked row and nowhere to go.
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
        $openCheckouts = $this->openCheckouts($items->pluck('id')->all());

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
            // The two kinds a record may be (EQUIP-010; UI contract 9.6A), so
            // the form offers the choice from the node's vocabulary rather than
            // a list the client keeps its own copy of.
            'tracking_kinds' => EquipmentItem::trackingKinds(),
            'tracking_labels' => EquipmentItem::trackingLabels(),
            'equipment' => $items
                ->map(fn (EquipmentItem $item): array => $this->payload(
                    $item,
                    $openCheckouts[(string) $item->id] ?? null,
                ))
                ->values()
                ->all(),
        ]);
    }

    /**
     * The open checkout for each individually tracked item, keyed by item.
     *
     * A tracked item has at most one, because `EquipmentCheckoutService`
     * refuses a second while the first is open. A pool is deliberately absent:
     * several of its units may be out with several people at once, so there is
     * no single holder to name, and what a maintainer needs from a pool is the
     * quantity still available rather than a person (EQUIP-016).
     *
     * @param  list<mixed>  $itemIds
     * @return array<string, EquipmentCheckout>
     */
    private function openCheckouts(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return EquipmentCheckout::query()
            ->with(['staff', 'shift'])
            ->whereIn('equipment_item_id', $itemIds)
            ->whereNull('returned_at')
            ->whereHas('equipmentItem', fn ($query) => $query->individuallyTracked())
            ->get()
            ->keyBy(fn (EquipmentCheckout $checkout): string => (string) $checkout->equipment_item_id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EquipmentItem $item, ?EquipmentCheckout $openCheckout): array
    {
        return [
            'id' => (string) $item->id,
            'organization_id' => (string) $item->organization_id,
            'department_id' => $item->department_id !== null ? (string) $item->department_id : null,
            'event_id' => $item->event_id !== null ? (string) $item->event_id : null,
            'name' => $item->name,
            'tracking' => $item->tracking,
            'tracking_label' => EquipmentItem::trackingLabel($item->tracking),
            'asset_tag' => $item->asset_tag,
            'serial_number' => $item->serial_number,
            'quantity_total' => (int) $item->quantity_total,
            // Derived, never stored (EQUIP-016). A pool with units out still
            // reads Available; this is the number that has fallen.
            'quantity_available' => $item->availableQuantity(),
            'status' => $item->status,
            'status_label' => EquipmentItem::statusLabel($item->status),
            'open_checkout' => $openCheckout === null
                ? null
                : [
                    'id' => (string) $openCheckout->id,
                    'staff_id' => (string) $openCheckout->staff_id,
                    'staff_name' => $this->staffDisplayName($openCheckout->staff),
                    'checked_out_at' => $openCheckout->checked_out_at?->toIso8601String(),
                    // Shift-assigned and event-assigned checkouts are told
                    // apart by the shift, not by how long they have been open
                    // (EQUIP-009).
                    'shift_id' => $openCheckout->shift_id !== null ? (string) $openCheckout->shift_id : null,
                    'shift_title' => $openCheckout->shift?->title,
                ],
            'archived_at' => $item->archived_at?->toIso8601String(),
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    private function staffDisplayName(?Staff $staff): ?string
    {
        if ($staff === null) {
            return null;
        }

        return $staff->preferred_name !== null && $staff->preferred_name !== ''
            ? $staff->preferred_name
            : $staff->legal_name;
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
