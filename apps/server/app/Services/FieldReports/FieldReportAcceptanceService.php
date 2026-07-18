<?php

namespace App\Services\FieldReports;

use App\Exceptions\FieldReportAcceptanceException;
use App\Models\Department;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Node;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Services\NameReferences\NameReferenceIndexService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accepts a finalized device-created Field Report and assigns its event-local
 * FRA number (technical spec 17.5; data/API 4.5, 5.3, and 10.15).
 *
 * Required title is normalized (trimmed, 1–200 characters) and persisted with
 * the original body; both are immutable after acceptance (FR-003, FR-007).
 * Name References in the accepted body are parsed into the rebuildable derived
 * index immediately after submission (NR-007; technical spec 17.7). Titles are
 * not parsed for Name References.
 */
final class FieldReportAcceptanceService
{
    public function __construct(
        private readonly NameReferenceIndexService $nameReferences,
    ) {}

    /**
     * @param  array{
     *     id: string,
     *     event_id: string,
     *     department_id?: string|null,
     *     team_id?: string|null,
     *     submitted_by_user_id: string,
     *     staff_id: string,
     *     temporary_local_number?: string|null,
     *     title: string,
     *     body: string,
     *     device_submitted_at: DateTimeInterface|string,
     *     origin_device_id: string,
     *     origin_node_id: string
     * }  $attributes
     */
    public function accept(array $attributes, ?DateTimeInterface $receivedAt = null): FieldReport
    {
        $id = (string) ($attributes['id'] ?? '');

        if (! Str::isUuid($id)) {
            throw FieldReportAcceptanceException::invalid('Field Report id must be a valid UUID.');
        }

        return DB::transaction(function () use ($attributes, $id, $receivedAt): FieldReport {
            $existing = FieldReport::query()->whereKey($id)->first();

            if ($existing !== null) {
                return $this->acceptedExisting($existing, $attributes);
            }

            $event = Event::query()
                ->whereKey($this->requiredId($attributes, 'event_id'))
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                throw FieldReportAcceptanceException::invalid('Field Report event does not exist.');
            }

            // A concurrent retry can pass the first lookup before the original
            // transaction commits. Recheck after serializing on the event row.
            $existing = FieldReport::query()->whereKey($id)->first();
            if ($existing !== null) {
                return $this->acceptedExisting($existing, $attributes);
            }

            $user = $this->find(User::class, $this->requiredId($attributes, 'submitted_by_user_id'), 'author');
            $staff = $this->find(Staff::class, $this->requiredId($attributes, 'staff_id'), 'staff record');
            $device = $this->find(Device::class, $this->requiredId($attributes, 'origin_device_id'), 'origin device');
            $node = $this->find(Node::class, $this->requiredId($attributes, 'origin_node_id'), 'origin node');

            $this->assertAuthorContext($event, $user, $staff, $device, $node);

            [$department, $team] = $this->resolveOperationalContext(
                $event,
                $attributes['department_id'] ?? null,
                $attributes['team_id'] ?? null,
            );

            $title = FieldReportTitle::normalize($attributes['title'] ?? null);

            $body = (string) ($attributes['body'] ?? '');
            if (trim($body) === '') {
                throw FieldReportAcceptanceException::invalid('Field Report body text is required.');
            }

            $submittedAt = $this->submittedAt($attributes['device_submitted_at'] ?? null);

            $acceptedAt = CarbonImmutable::instance($receivedAt ?? now());

            $report = FieldReport::query()->create([
                'id' => $id,
                'event_id' => $event->id,
                'department_id' => $department?->id,
                'team_id' => $team?->id,
                'submitted_by_user_id' => $user->id,
                'staff_id' => $staff->id,
                'fra_number' => $this->nextFraNumber($event),
                'temporary_local_number' => $attributes['temporary_local_number'] ?? null,
                'title' => $title,
                'body' => $body,
                'device_submitted_at' => $submittedAt,
                'server_received_at' => $acceptedAt,
                'origin_device_id' => $device->id,
                'origin_node_id' => $node->id,
                'sync_status' => 'accepted',
                'created_at' => $acceptedAt,
            ]);

            $this->nameReferences->synchronizeFieldReport($report);

            return $report;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function acceptedExisting(FieldReport $report, array $attributes): FieldReport
    {
        if ($report->fra_number === null || $report->sync_status !== 'accepted') {
            throw FieldReportAcceptanceException::invalid(
                'The Field Report UUID already exists without a completed server acceptance.',
            );
        }

        $sameSubmission = (string) ($attributes['event_id'] ?? '') === (string) $report->event_id
            && ($attributes['department_id'] ?? null) === $report->department_id
            && ($attributes['team_id'] ?? null) === $report->team_id
            && (string) ($attributes['submitted_by_user_id'] ?? '') === (string) $report->submitted_by_user_id
            && (string) ($attributes['staff_id'] ?? '') === (string) $report->staff_id
            && ($attributes['temporary_local_number'] ?? null) === $report->temporary_local_number
            && $this->sameTitle($attributes['title'] ?? null, (string) $report->title)
            && ($attributes['body'] ?? null) === $report->body
            && (string) ($attributes['origin_device_id'] ?? '') === (string) $report->origin_device_id
            && (string) ($attributes['origin_node_id'] ?? '') === (string) $report->origin_node_id
            && $report->device_submitted_at->equalTo(
                $this->submittedAt($attributes['device_submitted_at'] ?? null),
            );

        if (! $sameSubmission) {
            throw FieldReportAcceptanceException::invalid(
                'The Field Report UUID was already accepted with different source data.',
            );
        }

        // Idempotent retries re-synchronize so a missing derived index can repair.
        $this->nameReferences->synchronizeFieldReport($report);

        return $report;
    }

    private function sameTitle(mixed $incoming, string $stored): bool
    {
        if (! is_string($incoming)) {
            return false;
        }

        try {
            return FieldReportTitle::normalize($incoming) === $stored;
        } catch (FieldReportAcceptanceException) {
            return false;
        }
    }

    private function submittedAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            throw FieldReportAcceptanceException::invalid(
                'Field Report device submission timestamp is required.',
            );
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw FieldReportAcceptanceException::invalid('Field Report device submission timestamp is invalid.');
        }
    }

    /**
     * @param  class-string<User|Staff|Device|Node>  $model
     */
    private function find(string $model, string $id, string $label): User|Staff|Device|Node
    {
        $record = $model::query()->find($id);

        if ($record === null) {
            throw FieldReportAcceptanceException::invalid("Field Report {$label} does not exist.");
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function requiredId(array $attributes, string $key): string
    {
        $value = $attributes[$key] ?? null;

        if (! is_string($value) || ! Str::isUuid($value)) {
            throw FieldReportAcceptanceException::invalid("Field Report {$key} must be a valid UUID.");
        }

        return $value;
    }

    private function assertAuthorContext(
        Event $event,
        User $user,
        Staff $staff,
        Device $device,
        Node $node,
    ): void {
        if (! $staff->users()->whereKey($user->id)->exists()) {
            throw FieldReportAcceptanceException::invalid(
                'Field Report staff record is not linked to the submitting user.',
            );
        }

        $trustedDevice = DeviceTrust::query()
            ->active()
            ->where('user_id', $user->id)
            ->where('device_id', $device->id)
            ->exists();

        if (! $trustedDevice) {
            throw FieldReportAcceptanceException::invalid(
                'Field Report origin device is not actively trusted for the submitting user.',
            );
        }

        if ($node->isRevoked()) {
            throw FieldReportAcceptanceException::invalid('Field Report origin node is revoked.');
        }

        if ($node->organization_id !== null && (string) $node->organization_id !== (string) $event->organization_id) {
            throw FieldReportAcceptanceException::invalid(
                'Field Report origin node belongs to a different organization.',
            );
        }

        if ($node->event_id !== null && (string) $node->event_id !== (string) $event->id) {
            throw FieldReportAcceptanceException::invalid(
                'Field Report origin node belongs to a different event.',
            );
        }
    }

    /**
     * @return array{Department|null, Team|null}
     */
    private function resolveOperationalContext(
        Event $event,
        mixed $departmentId,
        mixed $teamId,
    ): array {
        if ($departmentId === null && $teamId === null) {
            return [null, null];
        }

        if (! is_string($departmentId) || ! Str::isUuid($departmentId)) {
            throw FieldReportAcceptanceException::invalid(
                'Field Report department_id must be a valid UUID when team context is supplied.',
            );
        }

        $department = Department::query()
            ->whereKey($departmentId)
            ->where('organization_id', $event->organization_id)
            ->first();

        if ($department === null || ! $event->departmentAssignments()
            ->where('department_id', $department->id)
            ->whereNull('archived_at')
            ->exists()) {
            throw FieldReportAcceptanceException::invalid(
                'Field Report department is not an active department for the event.',
            );
        }

        if ($teamId === null) {
            return [$department, null];
        }

        if (! is_string($teamId) || ! Str::isUuid($teamId)) {
            throw FieldReportAcceptanceException::invalid('Field Report team_id must be a valid UUID.');
        }

        $team = Team::query()
            ->whereKey($teamId)
            ->where('department_id', $department->id)
            ->whereNull('archived_at')
            ->first();

        if ($team === null) {
            throw FieldReportAcceptanceException::invalid(
                'Field Report team is not active in the supplied department.',
            );
        }

        return [$department, $team];
    }

    private function nextFraNumber(Event $event): string
    {
        if ($event->starts_at === null || $event->timezone === null) {
            throw FieldReportAcceptanceException::invalid(
                'Field Report event must have a scheduled start and timezone before FRA assignment.',
            );
        }

        $nextSequence = FieldReport::query()
            ->where('event_id', $event->id)
            ->whereNotNull('fra_number')
            ->pluck('fra_number')
            ->reduce(function (int $maximum, string $number): int {
                return preg_match('/^FRA-\d{4}-(\d{6})$/', $number, $matches) === 1
                    ? max($maximum, (int) $matches[1])
                    : $maximum;
            }, 0) + 1;

        if ($nextSequence > 999999) {
            throw FieldReportAcceptanceException::invalid(
                'Field Report FRA sequence is exhausted for this event.',
            );
        }

        $eventYear = $event->starts_at->copy()->setTimezone($event->timezone)->year;

        return sprintf('FRA-%04d-%06d', $eventYear, $nextSequence);
    }
}
