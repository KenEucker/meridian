<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Domain\Staffing\ProfileChangePolicy;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\Organization;
use App\Services\Node\EventAuthorityException;
use App\Services\Organizations\OrganizationConfigurationAccess;
use App\Services\Organizations\OrganizationConfigurationException;
use App\Services\Organizations\OrganizationConfigurationGovernance;
use App\Services\Organizations\OrganizationConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization configuration read and update (M18.14; ORG-017, ORG-018,
 * ORG-020, ORG-021).
 *
 * `GET /api/organizations/{organization}/configuration` answers with the
 * current values, the departments and credit policies eligible to be chosen,
 * and the governance state — whether this node holds configuration authority
 * and whether an active event window is freezing edits — so the surface can
 * explain a freeze before an organizer discovers it by having a save refused.
 *
 * `POST /api/commands/update-organization-configuration` applies a partial
 * update: only the keys the request carries are touched, and a present null
 * clears the value. The one exception is the hours correction grace period,
 * which cannot be cleared because ORG-017 says an organization always has one.
 */
final class OrganizationConfigurationController extends Controller
{
    public function show(
        Request $request,
        Organization $organization,
        OrganizationConfigurationAccess $access,
        OrganizationConfigurationGovernance $governance,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canManageConfiguration($user, $organization)) {
            return $this->refusal();
        }

        return response()->json($this->payload($organization, $governance));
    }

    public function update(
        Request $request,
        OrganizationConfigurationAccess $access,
        OrganizationConfigurationGovernance $governance,
        OrganizationConfigurationService $configuration,
    ): JsonResponse {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'active_inactive_threshold_years' => ['sometimes', 'nullable', 'integer'],
            'prospective_inactive_threshold_years' => ['sometimes', 'nullable', 'integer'],
            'calendar_year_start_month' => ['sometimes', 'nullable', 'integer'],
            'calendar_year_start_day' => ['sometimes', 'nullable', 'integer'],
            'hours_correction_grace_period_days' => ['sometimes', 'nullable', 'integer'],
            'event_horizon_lead_days' => ['sometimes', 'nullable', 'integer'],
            'default_credit_policy_id' => ['sometimes', 'nullable', 'uuid'],
            'organizers_department_id' => ['sometimes', 'nullable', 'uuid'],
            'default_ic_department_id' => ['sometimes', 'nullable', 'uuid'],
            'default_placement_department_id' => ['sometimes', 'nullable', 'uuid'],
            // The VOL-027 policies and the VOL-028 allowance. Values are
            // checked in the service, which is where the refusal naming the
            // four accepted policies is worded.
            'handle_change_policy' => ['sometimes', 'nullable', 'string'],
            'profile_picture_change_policy' => ['sometimes', 'nullable', 'string'],
            'handle_self_service_change_limit' => ['sometimes', 'nullable', 'integer'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $access->canManageConfiguration($user, $organization)) {
            return $this->refusal();
        }

        $changes = $validated;
        unset($changes['organization_id']);

        try {
            $organization = $configuration->update($organization, $changes, $user, AuditEvent::SOURCE_API);
        } catch (EventAuthorityException $exception) {
            // Governance refusals answer 409 the way branding's do (ORG-021 /
            // BRAND-021 are the same rule): the request was well-formed, the
            // record is just not editable here and now.
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (OrganizationConfigurationException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                $exception->isAuthorityRefusal ? 409 : 422,
            );
        }

        return response()->json($this->payload($organization, $governance));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Organization $organization,
        OrganizationConfigurationGovernance $governance,
    ): array {
        $organizationId = (string) $organization->getKey();

        $departments = Department::query()
            ->active()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Shift-level policies are overrides of a specific shift (CREDIT-003)
        // and cannot be an organization's default, so only organization-level
        // policies are offered.
        $creditPolicies = CreditPolicy::query()
            ->active()
            ->where('organization_id', $organizationId)
            ->whereNull('shift_id')
            ->orderBy('name')
            ->get(['id', 'name']);

        $frozenBy = $governance->activeEventFor($organizationId);

        return [
            'organization_id' => $organizationId,
            'configuration' => [
                'active_inactive_threshold_years' => $organization->active_inactive_threshold_years,
                'prospective_inactive_threshold_years' => $organization->prospective_inactive_threshold_years,
                'calendar_year_start_month' => $organization->calendar_year_start_month,
                'calendar_year_start_day' => $organization->calendar_year_start_day,
                'hours_correction_grace_period_days' => $organization->hoursCorrectionGracePeriodDays(),
                'event_horizon_lead_days' => $organization->eventHorizonLeadDays(),
                'default_credit_policy_id' => $organization->default_credit_policy_id !== null
                    ? (string) $organization->default_credit_policy_id
                    : null,
                'organizers_department_id' => $organization->organizers_department_id !== null
                    ? (string) $organization->organizers_department_id
                    : null,
                'default_ic_department_id' => $organization->default_ic_department_id !== null
                    ? (string) $organization->default_ic_department_id
                    : null,
                'default_placement_department_id' => $organization->default_placement_department_id !== null
                    ? (string) $organization->default_placement_department_id
                    : null,
                /*
                 * The resolved values rather than the stored ones (VOL-027,
                 * VOL-028). An organization that never chose reads as the
                 * documented default here, which is what it behaves as; the
                 * surface should show the rule in force, not a blank that
                 * makes a person guess what happens.
                 */
                'handle_change_policy' => $organization->handleChangePolicy()->value,
                'profile_picture_change_policy' => $organization->profilePictureChangePolicy()->value,
                'handle_self_service_change_limit' => $organization->handleSelfServiceChangeLimit(),
            ],
            'options' => [
                'departments' => $departments
                    ->map(fn (Department $department): array => [
                        'id' => (string) $department->id,
                        'name' => $department->name,
                    ])
                    ->values()
                    ->all(),
                'credit_policies' => $creditPolicies
                    ->map(fn (CreditPolicy $policy): array => [
                        'id' => (string) $policy->id,
                        'name' => $policy->name,
                    ])
                    ->values()
                    ->all(),
                /*
                 * The four approval policies with the words that explain them
                 * (VOL-027). Sent by the node rather than written into the
                 * client, so the choice an organizer reads and the rule the
                 * server enforces cannot drift apart, and so a policy added
                 * later reaches every surface at once.
                 */
                'change_policies' => array_map(
                    fn (ProfileChangePolicy $policy): array => [
                        'value' => $policy->value,
                        'label' => $policy->label(),
                        'handle_description' => $policy->description('handle'),
                        'picture_description' => $policy->description('profile picture'),
                    ],
                    ProfileChangePolicy::cases(),
                ),
            ],
            'governance' => [
                'editable' => $governance->isEditable($organizationId),
                'holds_authority' => $governance->holdsConfigurationAuthority(),
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
            'message' => 'Only organizers may edit this organization\'s configuration.',
        ], 403);
    }
}
