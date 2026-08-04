<?php

declare(strict_types=1);

namespace App\Http\Controllers\Credits;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPolicy;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\Organization;
use App\Models\User;
use App\Services\Attendance\HoursCorrectionWindow;
use App\Services\Credits\CreditCalculationException;
use App\Services\Credits\CreditCalculationService;
use App\Services\Credits\CreditPolicyAdminAccess;
use App\Services\Credits\CreditPolicyAdminException;
use App\Services\Credits\CreditPolicyAdminService;
use App\Services\Node\EventAuthorityException;
use App\Services\Organizations\OrganizationConfigurationException;
use App\Services\Organizations\OrganizationConfigurationGovernance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Organization credit policy administration and calculation runs (M18.16;
 * ORG-009, ORG-020; CREDIT-001 through CREDIT-003).
 *
 * `GET /api/organizations/{organization}/credit-policies` is the surface's one
 * read: the policies with the archived ones alongside (restoring one is half
 * of why a maintainer is here), the governance state so the surface can
 * explain a freeze before a save is refused, and the organization's events
 * with the node's own answer on whether each can be credited yet — the grace
 * period close, how many hours records are still open, and how much frozen
 * work has no ledger entry.
 *
 * The four policy commands are the writes, and
 * `POST /api/commands/calculate-event-credits` is the CREDIT-001 product
 * entry point the calculation service has been waiting for since M13.5.
 */
final class CreditPolicyAdminController extends Controller
{
    public function index(
        Request $request,
        Organization $organization,
        CreditPolicyAdminAccess $access,
        CreditPolicyAdminService $policies,
        OrganizationConfigurationGovernance $governance,
        HoursCorrectionWindow $correctionWindow,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canManageCreditPolicies($user, $organization)) {
            return $this->refusal();
        }

        return response()->json($this->payload($organization, $policies, $governance, $correctionWindow));
    }

    public function create(
        Request $request,
        CreditPolicyAdminAccess $access,
        CreditPolicyAdminService $policies,
    ): JsonResponse {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'name' => ['required', 'string'],
            'credit_multiplier' => ['required', 'numeric'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $access->canManageCreditPolicies($user, $organization)) {
            return $this->refusal();
        }

        try {
            $policy = $policies->create(
                $organization,
                (string) $validated['name'],
                $validated['credit_multiplier'],
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (EventAuthorityException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (OrganizationConfigurationException $exception) {
            // The governance refusals answer the way configuration's own do
            // (ORG-021): the node-authority refusal is a 409, not a bad value.
            return response()->json(
                ['message' => $exception->getMessage()],
                $exception->isAuthorityRefusal ? 409 : 422,
            );
        } catch (CreditPolicyAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['id' => (string) $policy->getKey()], 201);
    }

    public function update(
        Request $request,
        CreditPolicyAdminAccess $access,
        CreditPolicyAdminService $policies,
    ): JsonResponse {
        return $this->mutate(
            $request,
            $access,
            [
                'name' => ['required', 'string'],
                'credit_multiplier' => ['required', 'numeric'],
            ],
            fn (CreditPolicy $policy, User $user, array $validated) => $policies->update(
                $policy,
                (string) $validated['name'],
                $validated['credit_multiplier'],
                $user,
                AuditEvent::SOURCE_API,
            ),
        );
    }

    public function archive(
        Request $request,
        CreditPolicyAdminAccess $access,
        CreditPolicyAdminService $policies,
    ): JsonResponse {
        return $this->mutate(
            $request,
            $access,
            [],
            fn (CreditPolicy $policy, User $user) => $policies->archive($policy, $user, AuditEvent::SOURCE_API),
        );
    }

    public function restore(
        Request $request,
        CreditPolicyAdminAccess $access,
        CreditPolicyAdminService $policies,
    ): JsonResponse {
        return $this->mutate(
            $request,
            $access,
            [],
            fn (CreditPolicy $policy, User $user) => $policies->restore($policy, $user, AuditEvent::SOURCE_API),
        );
    }

    /**
     * Start a credit calculation run for one event (CREDIT-001).
     *
     * The calculation service holds the rules — the configured grace period
     * must have closed and every hours record must be frozen — and its refusal
     * is returned in its own words. The run itself is idempotent (CREDIT-004):
     * hours already carrying a calculated entry are counted and left alone, so
     * a second press of the button prices nothing twice.
     */
    public function calculate(
        Request $request,
        CreditPolicyAdminAccess $access,
        CreditCalculationService $calculation,
        OrganizationConfigurationGovernance $governance,
    ): JsonResponse {
        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $event = Event::query()->findOrFail((string) $validated['event_id']);
        $organization = Organization::query()->findOrFail((string) $event->organization_id);

        if (! $access->canManageCreditPolicies($user, $organization)) {
            return $this->refusal();
        }

        // Ledger entries are governance data central owns, the same way the
        // policies that price them are: a run on an on-site node would write
        // frozen rows central will never see, so it is refused outright rather
        // than queued (ORG-021's shape).
        if (! $governance->holdsConfigurationAuthority()) {
            return response()->json([
                'message' => 'Credit calculation runs on the central node. This node does not hold credit authority.',
            ], 409);
        }

        try {
            $result = $calculation->calculateForEvent($event, $user, null, AuditEvent::SOURCE_API);
        } catch (CreditCalculationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'event_id' => (string) $event->getKey(),
            'entries_created' => $result->createdCount(),
            'entries_already_calculated' => $result->alreadyCalculatedCount,
            'hours_without_credit_policy' => $result->unresolvedPolicyCount,
            'total_hours' => $result->totalHours,
            'total_credits' => $result->totalCredits,
            'calculated_at' => $result->calculatedAt->toIso8601String(),
        ]);
    }

    /**
     * The shape every single-policy command shares: resolve it, check the
     * organization it belongs to, run the change, report the refusal.
     *
     * @param  array<string, list<string>>  $rules
     */
    private function mutate(
        Request $request,
        CreditPolicyAdminAccess $access,
        array $rules,
        callable $apply,
    ): JsonResponse {
        $validated = $request->validate([
            'credit_policy_id' => ['required', 'uuid', 'exists:credit_policies,id'],
            ...$rules,
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $policy = CreditPolicy::query()->findOrFail((string) $validated['credit_policy_id']);
        $organization = Organization::query()->findOrFail((string) $policy->organization_id);

        if (! $access->canManageCreditPolicies($user, $organization)) {
            return $this->refusal();
        }

        try {
            $policy = $apply($policy, $user, $validated);
        } catch (EventAuthorityException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (OrganizationConfigurationException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                $exception->isAuthorityRefusal ? 409 : 422,
            );
        } catch (CreditPolicyAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'id' => (string) $policy->getKey(),
            'name' => $policy->name,
            'credit_multiplier' => (string) $policy->credit_multiplier,
            'archived' => $policy->isArchived(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Organization $organization,
        CreditPolicyAdminService $policies,
        OrganizationConfigurationGovernance $governance,
        HoursCorrectionWindow $correctionWindow,
    ): array {
        $organizationId = (string) $organization->getKey();

        // Named organization-level policies only. Shift-scoped rows are one
        // shift's custom rate (M18.16), administered from the shift that
        // carries them rather than from this catalog.
        $rows = CreditPolicy::query()
            ->where('organization_id', $organizationId)
            ->whereNull('shift_id')
            ->orderBy('name')
            ->get();

        $frozenBy = $governance->activeEventFor($organizationId);

        return [
            'organization_id' => $organizationId,
            'credit_policies' => $rows
                ->map(fn (CreditPolicy $policy): array => [
                    'id' => (string) $policy->getKey(),
                    'name' => $policy->name,
                    'credit_multiplier' => (string) $policy->credit_multiplier,
                    'archived' => $policy->isArchived(),
                    'archived_at' => $policy->archived_at?->toIso8601String(),
                    'is_default' => (string) $organization->default_credit_policy_id === (string) $policy->getKey(),
                    // How many shifts name it, so archiving one is not a
                    // quiet no-op.
                    'shift_count' => $policies->shiftCountFor($policy),
                ])
                ->values()
                ->all(),
            'events' => $this->eventRuns($organization, $correctionWindow),
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

    /**
     * The organization's events with the node's answer on whether each can be
     * credited yet, most recent first. The counts are the same facts the
     * calculation service will enforce, decided once here so the surface does
     * not keep a second copy of the rules (CLIENT-006).
     *
     * @return list<array<string, mixed>>
     */
    private function eventRuns(
        Organization $organization,
        HoursCorrectionWindow $correctionWindow,
    ): array {
        $now = Carbon::now();

        return Event::query()
            ->where('organization_id', (string) $organization->getKey())
            ->orderByDesc('starts_at')
            ->get()
            ->map(function (Event $event) use ($correctionWindow, $now): array {
                $eventId = (string) $event->getKey();

                $closesAt = $correctionWindow->closesAt($event);
                $graceClosed = $closesAt !== null && $now->greaterThanOrEqualTo($closesAt);

                $openHours = HoursWorked::query()
                    ->where('event_id', $eventId)
                    ->whereNull('frozen_at')
                    ->count();

                $creditedIds = CreditLedgerEntry::query()
                    ->calculated()
                    ->where('event_id', $eventId)
                    ->pluck('hours_worked_id');

                $uncredited = HoursWorked::query()
                    ->where('event_id', $eventId)
                    ->whereNotNull('frozen_at')
                    ->whereNotIn('id', $creditedIds->all())
                    ->count();

                return [
                    'id' => $eventId,
                    'name' => $event->name,
                    'ends_at' => $event->ends_at?->toIso8601String(),
                    'grace_closes_at' => $closesAt?->toIso8601String(),
                    'grace_closed' => $graceClosed,
                    'open_hours_count' => $openHours,
                    'uncredited_hours_count' => $uncredited,
                    'credited_hours_count' => $creditedIds->count(),
                    'can_calculate' => $graceClosed && $openHours === 0,
                ];
            })
            ->values()
            ->all();
    }

    private function refusal(): JsonResponse
    {
        return response()->json([
            'message' => 'Only organizers may maintain this organization\'s credit policies.',
        ], 403);
    }
}
