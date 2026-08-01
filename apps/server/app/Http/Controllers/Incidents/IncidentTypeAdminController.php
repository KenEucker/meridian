<?php

declare(strict_types=1);

namespace App\Http\Controllers\Incidents;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\IncidentType;
use App\Models\Organization;
use App\Services\Incidents\IncidentTypeAdminAccess;
use App\Services\Incidents\IncidentTypeAdminException;
use App\Services\Incidents\IncidentTypeAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization incident type administration (M18.14A; ORG-018, ORG-020).
 *
 * `GET /api/organizations/{organization}/incident-types` is the surface's one
 * read, and the four commands are its writes. Archived types are returned
 * alongside active ones with the flag that tells them apart, because a
 * maintainer needs to see what they retired in order to restore it — this is
 * the one read where the archived rows are the point.
 */
final class IncidentTypeAdminController extends Controller
{
    public function index(
        Request $request,
        Organization $organization,
        IncidentTypeAdminAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canManageIncidentTypes($user, $organization)) {
            return $this->refusal();
        }

        $types = IncidentType::query()
            ->where('organization_id', $organization->getKey())
            ->withCount('incidents')
            ->orderBy('name')
            ->get();

        return response()->json([
            'organization_id' => (string) $organization->getKey(),
            'incident_types' => $types
                ->map(fn (IncidentType $type): array => [
                    'id' => (string) $type->getKey(),
                    'name' => $type->name,
                    'archived' => $type->archived_at !== null,
                    'archived_at' => optional($type->archived_at)?->toIso8601String(),
                    'created_at' => optional($type->created_at)?->toIso8601String(),
                    // How many incidents already carry it, so a maintainer can
                    // see that archiving one is not a quiet no-op.
                    'incident_count' => (int) $type->incidents_count,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function create(
        Request $request,
        IncidentTypeAdminAccess $access,
        IncidentTypeAdminService $types,
    ): JsonResponse {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'name' => ['required', 'string'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $access->canManageIncidentTypes($user, $organization)) {
            return $this->refusal();
        }

        try {
            $type = $types->create(
                $organization,
                (string) $validated['name'],
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (IncidentTypeAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['id' => (string) $type->getKey()], 201);
    }

    public function rename(
        Request $request,
        IncidentTypeAdminAccess $access,
        IncidentTypeAdminService $types,
    ): JsonResponse {
        return $this->mutate(
            $request,
            $access,
            ['name' => ['required', 'string']],
            fn (IncidentType $type, $user, array $validated) => $types->rename(
                $type,
                (string) $validated['name'],
                $user,
                AuditEvent::SOURCE_API,
            ),
        );
    }

    public function archive(
        Request $request,
        IncidentTypeAdminAccess $access,
        IncidentTypeAdminService $types,
    ): JsonResponse {
        return $this->mutate(
            $request,
            $access,
            [],
            fn (IncidentType $type, $user) => $types->archive($type, $user, AuditEvent::SOURCE_API),
        );
    }

    public function restore(
        Request $request,
        IncidentTypeAdminAccess $access,
        IncidentTypeAdminService $types,
    ): JsonResponse {
        return $this->mutate(
            $request,
            $access,
            [],
            fn (IncidentType $type, $user) => $types->restore($type, $user, AuditEvent::SOURCE_API),
        );
    }

    /**
     * The shape every single-type command shares: resolve it, check the
     * organization it belongs to, run the change, report the refusal.
     *
     * @param  array<string, list<string>>  $rules
     */
    private function mutate(
        Request $request,
        IncidentTypeAdminAccess $access,
        array $rules,
        callable $apply,
    ): JsonResponse {
        $validated = $request->validate([
            'incident_type_id' => ['required', 'uuid', 'exists:incident_types,id'],
            ...$rules,
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $type = IncidentType::query()->findOrFail((string) $validated['incident_type_id']);
        $organization = Organization::query()->findOrFail((string) $type->organization_id);

        if (! $access->canManageIncidentTypes($user, $organization)) {
            return $this->refusal();
        }

        try {
            $type = $apply($type, $user, $validated);
        } catch (IncidentTypeAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'id' => (string) $type->getKey(),
            'name' => $type->name,
            'archived' => $type->archived_at !== null,
        ]);
    }

    private function refusal(): JsonResponse
    {
        return response()->json([
            'message' => 'Only organizers may maintain this organization\'s incident types.',
        ], 403);
    }
}
