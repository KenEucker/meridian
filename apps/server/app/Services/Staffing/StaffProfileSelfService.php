<?php

namespace App\Services\Staffing;

use App\Models\AuditEvent;
use App\Models\Staff;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * A staff member maintaining their own profile (M18.20; VOL-015, VOL-016,
 * VOL-026; data/API 10.4).
 *
 * The self-editable set is exactly the presentation fields VOL-015 names —
 * preferred name, phone, and city/state — and an edit applies immediately, with
 * no review. Everything else on the record is somebody else's decision: legal
 * name, email, and date of birth are identity and eligibility data that change
 * only through an organizer or God Mode (VOL-016), and the handle and profile
 * picture change through `staff_profile_change_requests` when M18.20B and
 * M18.20C build that path. This service refuses to write any of them; the
 * controller additionally refuses the request that names them, so a submitted
 * value is an error the submitter sees rather than a key that quietly fell out.
 *
 * Whose profile may be edited is the one question of authority here: the
 * caller's own, meaning a staff record their login speaks for, and no other.
 */
final class StaffProfileSelfService
{
    /** The VOL-015 set. Order is the order the audit snapshot reads in. */
    public const SELF_EDITABLE_FIELDS = ['preferred_name', 'phone', 'city', 'state'];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Apply a staff member's edit to their own profile.
     *
     * @param  array<string, string|null>  $attributes  keys from
     *     {@see self::SELF_EDITABLE_FIELDS}; an absent key leaves that field
     *     alone, a null or blank value clears it (the four are nullable by
     *     data/API 10.4).
     */
    public function updateOwnProfile(
        Staff $staff,
        User $actor,
        array $attributes,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Staff {
        if (! $actor->staffProfiles()->whereKey($staff->id)->exists()) {
            throw new StaffProfileSelfException('You can only edit your own staff profile.');
        }

        foreach (array_keys($attributes) as $field) {
            if (! in_array($field, self::SELF_EDITABLE_FIELDS, true)) {
                throw new StaffProfileSelfException(
                    "`{$field}` is not a field you can change here. Preferred name, phone, and city/state are self-service; everything else changes through an organizer.",
                );
            }
        }

        return DB::transaction(function () use ($staff, $actor, $attributes, $sourceContext): Staff {
            $staff = Staff::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();

            if ($staff->isArchived()) {
                throw new StaffProfileSelfException('Archived staff profiles cannot be edited.');
            }

            $before = [];
            $after = [];

            foreach (self::SELF_EDITABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }

                $value = $this->nullableTrim($attributes[$field]);

                if ($staff->{$field} === $value) {
                    continue;
                }

                $before[$field] = $staff->{$field};
                $after[$field] = $value;
                $staff->{$field} = $value;
            }

            // An edit that changed nothing is accepted and records nothing:
            // pressing Save twice is not two profile changes (VOL-026 audits
            // changed values, and here there are none).
            if ($after === []) {
                return $staff;
            }

            $staff->save();

            $this->audit->recordForEntity(
                entity: $staff,
                action: 'staff.profile.self_updated',
                actorUser: $actor,
                before: $before,
                after: $after,
                sourceContext: $sourceContext,
            );

            return $staff->refresh();
        });
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
