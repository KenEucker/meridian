<?php

namespace App\Services\Waiver;

use App\Models\Organization;
use App\Models\Staff;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverCompletion;
use App\Services\Documents\DocumentScopeValidator;
use Illuminate\Support\Carbon;

class WaiverService
{
    public function __construct(private readonly DocumentScopeValidator $scopes) {}

    /**
     * Create a waiver assigned at organization, department, or team scope (WAIVER-001).
     *
     * Signed document contents are not stored (WAIVER-004).
     */
    public function create(
        Organization $organization,
        string $scopeType,
        string $scopeId,
        string $name,
        ?string $description = null,
        ?int $expiresAfterDays = null,
    ): Waiver {
        if (! in_array($scopeType, Waiver::scopeTypes(), true)) {
            throw WaiverScopeException::unsupportedScopeType();
        }

        if (! $this->scopes->isValid((string) $organization->id, $scopeType, $scopeId)) {
            throw WaiverScopeException::invalidScopeTarget();
        }

        return Waiver::query()->create([
            'organization_id' => $organization->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'name' => $name,
            'description' => $description,
            'expires_after_days' => $expiresAfterDays,
        ]);
    }

    /**
     * Record a staff member's waiver completion (WAIVER-003).
     *
     * Completion is tracked as complete/incomplete through current completion
     * records. The expiration date is derived from the waiver's configured
     * expiry window (WAIVER-002). Signed document contents are not stored
     * (WAIVER-004).
     */
    public function recordCompletion(
        Waiver $waiver,
        Staff $staff,
        ?Carbon $completedAt = null,
        ?User $recordedBy = null,
    ): WaiverCompletion {
        $completedAt ??= Carbon::now();

        $expiresAt = $waiver->expires_after_days !== null
            ? $completedAt->copy()->addDays($waiver->expires_after_days)
            : null;

        return WaiverCompletion::query()->create([
            'waiver_id' => $waiver->id,
            'staff_id' => $staff->id,
            'completed_at' => $completedAt,
            'expires_at' => $expiresAt,
            'recorded_by_user_id' => $recordedBy?->id,
        ]);
    }

    /**
     * Whether the staff member currently satisfies the waiver (WAIVER-003).
     */
    public function isCompleteFor(Waiver $waiver, Staff $staff, ?Carbon $moment = null): bool
    {
        return $waiver->isCompleteFor($staff, $moment);
    }
}
