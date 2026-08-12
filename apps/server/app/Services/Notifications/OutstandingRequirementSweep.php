<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Modules\ModuleKey;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\NotificationDelivery;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Waiver;
use App\Models\WaiverCompletion;
use App\Services\Modules\ActiveModuleResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The two NOTIFY-001 notifications that are about a state rather than an
 * action: a required document acknowledgment outstanding, and a required waiver
 * outstanding or expired.
 *
 * Everything else in the set has an operation to hang off — an approval, a
 * cancellation, an addition — and sends from inside it. These two do not. A
 * waiver is not outstanding because anybody did anything; it is outstanding
 * because a date passed, or because a requirement was published to a
 * department somebody already belonged to. So they are swept for on a schedule,
 * on the node that owns sending (NOTIFY-008).
 *
 * The sweep is idempotent through the delivery records themselves rather than
 * through a cursor or a `notified_at` column. NOTIFY-007 already requires a
 * record of who was told what about which record, and that record answers "has
 * this person already been told?" exactly. A suppressed or unaddressable
 * delivery counts as told for this purpose, which is deliberate: switching
 * suppression off should not release a fortnight of daily reminders.
 *
 * Both requirements belong to Documents — acknowledgments and waivers are its
 * namespaces — so an organization that does not run it is skipped entirely
 * (MOD-018: a requirement an inactive module owns is not presented, and a
 * message is a presentation). The rows are read past and nothing is deleted, so
 * turning Documents back on resumes the sweep against the same requirements; and
 * because the skip writes no delivery record, a person who was never told is
 * still owed the message rather than counted as told.
 */
class OutstandingRequirementSweep
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly NotificationRecipientResolver $recipients,
        private readonly ActiveModuleResolver $modules,
    ) {}

    /**
     * @return array{acknowledgments: int, waivers: int}
     */
    public function run(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        return [
            'acknowledgments' => $this->sweepAcknowledgments(),
            'waivers' => $this->sweepWaivers($asOf),
        ];
    }

    /**
     * Staff in a requirement's scope who have never acknowledged its document
     * (POL-024, POL-025).
     *
     * One notification per staff member per requirement, for the life of the
     * requirement. A document that moves on does not re-require acknowledgment
     * (POL-045), so a second message would be about nothing having happened.
     */
    private function sweepAcknowledgments(): int
    {
        $sent = 0;

        $requirements = DocumentAcknowledgmentRequirement::query()
            ->active()
            ->with(['organization', 'document', 'departmentScope'])
            ->get();

        foreach ($requirements as $requirement) {
            if (! $this->runsDocuments((string) $requirement->organization_id)) {
                continue;
            }

            $document = $requirement->document;

            // A requirement pointing at an unpublished document is a fault
            // rather than a task, and the acknowledge command refuses it. The
            // read surface omits it for the same reason; mailing about it would
            // ask somebody to do something the node will not let them do.
            if ($document === null || ! $document->isPublished()) {
                continue;
            }

            foreach ($this->staffInScope($requirement) as $staff) {
                if ($this->alreadyNotified(NotificationType::DocumentAcknowledgmentOutstanding, $requirement->getKey(), $staff)) {
                    continue;
                }

                if ($this->hasAcknowledged($requirement, $staff)) {
                    continue;
                }

                $this->notifications->dispatch(
                    type: NotificationType::DocumentAcknowledgmentOutstanding,
                    subject: $requirement,
                    recipient: $this->recipients->forStaff($staff),
                    organization: $requirement->organization,
                    department: $requirement->scope_type === DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT
                        ? $requirement->departmentScope
                        : null,
                );

                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Staff whose shifts require a waiver they have not completed, or completed
     * and have since let lapse (WAIVER-003, WAIVER-006).
     *
     * Scoped to staff with an active assignment on a shift that requires the
     * waiver, rather than to every member of the organization. A waiver nobody
     * has signed up for work under is not outstanding of them — mailing the
     * whole organization about a waiver attached to one department's shifts
     * would be the digest NOTIFY-010 puts out of scope, arriving as ten
     * separate emails.
     */
    private function sweepWaivers(Carbon $asOf): int
    {
        $sent = 0;

        $assignments = ShiftAssignment::query()
            ->active()
            ->with(['staff', 'shift.requiredWaivers.organization', 'shift.event', 'shift.department'])
            ->whereHas('shift', function ($query): void {
                $query->whereNull('cancelled_at')->has('requiredWaivers');
            })
            ->get();

        /** @var array<string, array{waiver: Waiver, staff: Staff, department: Department|null}> $pairs */
        $pairs = [];

        foreach ($assignments as $assignment) {
            $staff = $assignment->staff;
            $shift = $assignment->shift;

            if (! $staff instanceof Staff || $shift === null) {
                continue;
            }

            foreach ($shift->requiredWaivers as $waiver) {
                if ($waiver->isArchived()) {
                    continue;
                }

                if (! $this->runsDocuments((string) $waiver->organization_id)) {
                    continue;
                }

                // One pair per waiver and staff member, however many shifts
                // required it. NOTIFY-001A's rule is about one action, but the
                // reason behind it applies here too: three shifts requiring the
                // same waiver is one thing outstanding.
                $pairs[$waiver->getKey().'|'.$staff->getKey()] ??= [
                    'waiver' => $waiver,
                    'staff' => $staff,
                    'department' => $shift->department,
                ];
            }
        }

        foreach ($pairs as $pair) {
            $waiver = $pair['waiver'];
            $staff = $pair['staff'];

            if ($waiver->isCompleteFor($staff, $asOf)) {
                continue;
            }

            if ($this->alreadyNotifiedSinceCompletion($waiver, $staff)) {
                continue;
            }

            $this->notifications->dispatch(
                type: NotificationType::WaiverOutstanding,
                subject: $waiver,
                recipient: $this->recipients->forStaff($staff),
                organization: $waiver->organization,
                department: $pair['department'],
            );

            $sent++;
        }

        return $sent;
    }

    private function runsDocuments(string $organizationId): bool
    {
        return $this->modules->isActive($organizationId, ModuleKey::Documents);
    }

    /**
     * @return Collection<int, Staff>
     */
    private function staffInScope(DocumentAcknowledgmentRequirement $requirement): Collection
    {
        if ($requirement->scope_type === DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT) {
            $staffIds = DepartmentMembership::query()
                ->active()
                ->where('department_id', $requirement->scope_id)
                ->pluck('staff_id');
        } else {
            $staffIds = StaffOrganizationStatus::query()
                ->where('organization_id', $requirement->scope_id)
                ->whereIn('status', [
                    StaffOrganizationStatus::STATUS_ACTIVE,
                    StaffOrganizationStatus::STATUS_PROSPECTIVE,
                ])
                ->pluck('staff_id');
        }

        if ($staffIds->isEmpty()) {
            return collect();
        }

        return Staff::query()->whereIn('id', $staffIds->all())->get();
    }

    private function hasAcknowledged(DocumentAcknowledgmentRequirement $requirement, Staff $staff): bool
    {
        $userIds = $staff->users()->pluck('users.id');

        if ($userIds->isEmpty()) {
            return false;
        }

        return DocumentAcknowledgment::query()
            ->whereIn('user_id', $userIds->all())
            ->where('document_type', $requirement->document_type)
            ->where('document_id', $requirement->document_id)
            ->where('scope_type', $requirement->scope_type)
            ->where('scope_id', $requirement->scope_id)
            ->exists();
    }

    private function alreadyNotified(NotificationType $type, mixed $subjectId, Staff $staff): bool
    {
        return NotificationDelivery::query()
            ->ofType($type)
            ->where('subject_entity_type', $type->subjectClass())
            ->where('subject_entity_id', (string) $subjectId)
            ->where('recipient_staff_id', $staff->getKey())
            ->exists();
    }

    /**
     * A waiver may legitimately notify twice: once when it was never completed,
     * and again years later when a completion has expired. The dividing line is
     * the completion — a delivery written before the staff member last
     * completed the waiver was about a different outstanding state, and does not
     * cover this one.
     */
    private function alreadyNotifiedSinceCompletion(Waiver $waiver, Staff $staff): bool
    {
        $query = NotificationDelivery::query()
            ->ofType(NotificationType::WaiverOutstanding)
            ->where('subject_entity_type', Waiver::class)
            ->where('subject_entity_id', (string) $waiver->getKey())
            ->where('recipient_staff_id', $staff->getKey());

        $lastCompletion = WaiverCompletion::query()
            ->where('waiver_id', $waiver->getKey())
            ->where('staff_id', $staff->getKey())
            ->max('completed_at');

        if ($lastCompletion !== null) {
            $query->where('created_at', '>', $lastCompletion);
        }

        return $query->exists();
    }
}
