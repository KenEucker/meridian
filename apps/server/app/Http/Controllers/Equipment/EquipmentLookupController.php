<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Services\Equipment\EquipmentLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Equipment lookup at checkout (M18.24C; EQUIP-012, EQUIP-013, EQUIP-015;
 * SLB-011, SLB-012; data/API 10.13).
 *
 * The node's answer, for a desk that can reach one. The desk resolves the same
 * value against the inventory it already holds when it cannot (EQUIP-015), and
 * both go through {@see EquipmentLookupService} so there is one set of rules
 * rather than a client copy that drifts.
 *
 * A caller without equipment authority for this department is refused; a caller
 * with it sees only that department's hand-out-able stock, whatever they typed.
 */
final class EquipmentLookupController extends Controller
{
    public function __invoke(
        Request $request,
        Event $event,
        Department $department,
        EquipmentLookupService $lookup,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $lookup->canLookUp($user, $event, $department)) {
            return response()->json([
                'message' => 'You do not have permission to check equipment out for this department.',
            ], 403);
        }

        $query = (string) $request->query('q', '');
        $candidates = $lookup->candidates($event, $department);
        $result = $lookup->resolve($candidates, $query);

        return response()->json([
            'query' => $query,
            'outcome' => $result['outcome'],
            'message' => $result['message'],
            'resolved' => $result['item'] === null ? null : $lookup->payload($result['item']),
            'matches' => array_map(
                fn (EquipmentItem $item): array => $lookup->payload($item),
                $result['matches'],
            ),
        ]);
    }
}
