<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Modules\ModuleAuthorityException;
use App\Services\Modules\ModuleEnablementException;
use App\Services\Modules\ModuleGovernance;
use App\Services\Modules\ModuleState;
use App\Services\Modules\ModuleStateService;
use App\Services\Node\EventAuthorityException;
use App\Services\Organizations\OrganizationConfigurationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organization's own module choice, on the ORG-018 configuration surface
 * (M19.15; ORG-018, ORG-020, ORG-021; MOD-008, MOD-010, MOD-011; data/API 6.8,
 * 10.1A).
 *
 * `GET /api/organizations/{organization}/modules` answers with the modules this
 * organization is entitled to, whether it is running each one, and the
 * governance state — whether this node holds module authority and whether an
 * active event window is freezing changes — so the surface explains a freeze
 * before an organizer discovers it by having a save refused.
 *
 * `POST /api/commands/update-organization-modules` applies a partial update: a
 * module the request does not name is left alone, and a module already in the
 * state the request asks for is not written, so the audit trail holds
 * transitions rather than submissions.
 *
 * **Only entitled modules appear.** MOD-008 says a module the organization is
 * not entitled to is not presented as an organizer choice, so it is absent from
 * the read rather than present and disabled. An organizer cannot see around
 * entitlement, and the surface says plainly that the list is what Meridian makes
 * available rather than pretending it is the whole catalogue.
 *
 * **This is not the module gate.** Neither endpoint is gated on any module
 * (data/API 5.9): a surface that vanished when the module it administers was
 * turned off would be a surface nobody could turn a module back on from. The
 * enforcement that makes an inactive module absent lives on the routes the
 * module owns (M19.12).
 *
 * Authorization is `organization.configuration.manage` through
 * {@see OrganizationConfigurationAccess}, which is data/API 6.8's rule and the
 * same capability that governs the rest of ORG-018 configuration — enablement is
 * one of that surface's values, not a separate authority.
 */
final class OrganizationModuleController extends Controller
{
    public function show(
        Request $request,
        Organization $organization,
        OrganizationConfigurationAccess $access,
        ModuleStateService $modules,
        ModuleGovernance $governance,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canManageConfiguration($user, $organization)) {
            return $this->refusal();
        }

        return response()->json($this->payload($organization, $modules, $governance));
    }

    public function update(
        Request $request,
        OrganizationConfigurationAccess $access,
        ModuleStateService $modules,
        ModuleGovernance $governance,
    ): JsonResponse {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'modules' => ['required', 'array'],
            // Values are booleans; keys are checked against the catalogue by the
            // service, which iterates the catalogue rather than the submission
            // so a key this build does not know decides nothing (MOD-003).
            'modules.*' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $access->canManageConfiguration($user, $organization)) {
            return $this->refusal();
        }

        $reason = trim((string) ($validated['reason'] ?? ''));

        try {
            $modules->setEnablement(
                organization: $organization,
                enablement: array_map(
                    static fn (mixed $enabled): bool => (bool) $enabled,
                    $validated['modules'],
                ),
                actor: $user instanceof User ? $user : null,
                reason: $reason === '' ? null : $reason,
            );
        } catch (ModuleAuthorityException|EventAuthorityException $exception) {
            // The governance refusals answer 409 the way the rest of the ORG-018
            // surface does: the request was well-formed, module state is just
            // not changeable on this node or in this window (MOD-010).
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (ModuleEnablementException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($organization, $modules, $governance));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Organization $organization,
        ModuleStateService $modules,
        ModuleGovernance $governance,
    ): array {
        $organizationId = (string) $organization->getKey();
        $frozenBy = $governance->activeEventFor($organizationId);

        return [
            'organization_id' => $organizationId,
            'modules' => array_map(
                static fn (ModuleState $state): array => [
                    'key' => $state->key(),
                    // MOD-022: Meridian's own term for the module. The key is an
                    // identifier the command is keyed by, never a label.
                    'name' => $state->label(),
                    'summary' => $state->module->summary(),
                    'enabled' => $state->enabled,
                    'changed_at' => $state->enablementChangedAt?->toIso8601String(),
                    'changed_by' => $state->enablementChangedBy,
                ],
                $modules->entitledStateFor($organization),
            ),
            'governance' => [
                'editable' => $governance->isEditable($organizationId),
                'holds_authority' => $governance->holdsModuleAuthority(),
                'frozen_by_event' => $frozenBy === null ? null : [
                    'id' => (string) $frozenBy->getKey(),
                    'name' => $frozenBy->name,
                ],
            ],
        ];
    }

    private function refusal(): JsonResponse
    {
        return response()->json([
            'message' => 'Only organizers may choose which modules this organization runs.',
        ], 403);
    }
}
