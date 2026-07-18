<?php

namespace App\Services\FieldReports;

use App\Exceptions\FieldReportAppendException;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\FieldReport;
use App\Models\FieldReportAppend;
use App\Models\Node;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accepts an immutable append-only addition to an existing Field Report
 * (technical spec 17.4; data/API 10.15; FR-007 through FR-009).
 *
 * Incident-note copy of appended content (FR-013) is deferred until incident
 * linking exists. Photos and Name Reference parsing remain later M9 tasks.
 */
final class FieldReportAppendService
{
    /**
     * @param  array{
     *     id: string,
     *     field_report_id: string,
     *     appended_by_user_id: string,
     *     body: string,
     *     device_submitted_at: DateTimeInterface|string,
     *     origin_device_id: string,
     *     origin_node_id: string
     * }  $attributes
     */
    public function append(array $attributes, ?DateTimeInterface $receivedAt = null): FieldReportAppend
    {
        $id = (string) ($attributes['id'] ?? '');

        if (! Str::isUuid($id)) {
            throw FieldReportAppendException::invalid('Field Report append id must be a valid UUID.');
        }

        return DB::transaction(function () use ($attributes, $id, $receivedAt): FieldReportAppend {
            $existing = FieldReportAppend::query()->whereKey($id)->first();

            if ($existing !== null) {
                return $this->acceptedExisting($existing, $attributes);
            }

            $fieldReportId = $this->requiredId($attributes, 'field_report_id');
            $report = FieldReport::query()
                ->whereKey($fieldReportId)
                ->lockForUpdate()
                ->first();

            if ($report === null) {
                throw FieldReportAppendException::invalid('Field Report does not exist.');
            }

            // A concurrent retry can pass the first lookup before the original
            // transaction commits. Recheck after serializing on the parent report.
            $existing = FieldReportAppend::query()->whereKey($id)->first();
            if ($existing !== null) {
                return $this->acceptedExisting($existing, $attributes);
            }

            $author = $this->find(User::class, $this->requiredId($attributes, 'appended_by_user_id'), 'author');
            $device = $this->find(Device::class, $this->requiredId($attributes, 'origin_device_id'), 'origin device');
            $node = $this->find(Node::class, $this->requiredId($attributes, 'origin_node_id'), 'origin node');

            $this->assertAuthor($report, $author);
            $this->assertSourceContext($report, $author, $device, $node);

            $body = (string) ($attributes['body'] ?? '');
            if (trim($body) === '') {
                throw FieldReportAppendException::invalid('Field Report append body text is required.');
            }

            $submittedAt = $this->submittedAt($attributes['device_submitted_at'] ?? null);
            $acceptedAt = CarbonImmutable::instance($receivedAt ?? now());

            return FieldReportAppend::query()->create([
                'id' => $id,
                'field_report_id' => $report->id,
                'appended_by_user_id' => $author->id,
                'body' => $body,
                'device_submitted_at' => $submittedAt,
                'server_received_at' => $acceptedAt,
                'origin_device_id' => $device->id,
                'origin_node_id' => $node->id,
                'created_at' => $acceptedAt,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function acceptedExisting(FieldReportAppend $append, array $attributes): FieldReportAppend
    {
        $sameSubmission = (string) ($attributes['field_report_id'] ?? '') === (string) $append->field_report_id
            && (string) ($attributes['appended_by_user_id'] ?? '') === (string) $append->appended_by_user_id
            && ($attributes['body'] ?? null) === $append->body
            && (string) ($attributes['origin_device_id'] ?? '') === (string) $append->origin_device_id
            && (string) ($attributes['origin_node_id'] ?? '') === (string) $append->origin_node_id
            && $append->device_submitted_at->equalTo(
                $this->submittedAt($attributes['device_submitted_at'] ?? null),
            );

        if (! $sameSubmission) {
            throw FieldReportAppendException::invalid(
                'The Field Report append UUID was already accepted with different source data.',
            );
        }

        return $append;
    }

    private function assertAuthor(FieldReport $report, User $author): void
    {
        if ((string) $report->submitted_by_user_id !== (string) $author->id) {
            throw FieldReportAppendException::invalid(
                'Only the original Field Report author may append to the report.',
            );
        }
    }

    private function assertSourceContext(
        FieldReport $report,
        User $author,
        Device $device,
        Node $node,
    ): void {
        $trustedDevice = DeviceTrust::query()
            ->active()
            ->where('user_id', $author->id)
            ->where('device_id', $device->id)
            ->exists();

        if (! $trustedDevice) {
            throw FieldReportAppendException::invalid(
                'Field Report append origin device is not actively trusted for the author.',
            );
        }

        if ($node->isRevoked()) {
            throw FieldReportAppendException::invalid('Field Report append origin node is revoked.');
        }

        $event = $report->event;

        if ($event === null) {
            throw FieldReportAppendException::invalid('Field Report event does not exist.');
        }

        if ($node->organization_id !== null && (string) $node->organization_id !== (string) $event->organization_id) {
            throw FieldReportAppendException::invalid(
                'Field Report append origin node belongs to a different organization.',
            );
        }

        if ($node->event_id !== null && (string) $node->event_id !== (string) $event->id) {
            throw FieldReportAppendException::invalid(
                'Field Report append origin node belongs to a different event.',
            );
        }
    }

    private function submittedAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            throw FieldReportAppendException::invalid(
                'Field Report append device submission timestamp is required.',
            );
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw FieldReportAppendException::invalid(
                'Field Report append device submission timestamp is invalid.',
            );
        }
    }

    /**
     * @param  class-string<User|Device|Node>  $model
     */
    private function find(string $model, string $id, string $label): User|Device|Node
    {
        $record = $model::query()->find($id);

        if ($record === null) {
            throw FieldReportAppendException::invalid("Field Report append {$label} does not exist.");
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
            throw FieldReportAppendException::invalid("Field Report append {$key} must be a valid UUID.");
        }

        return $value;
    }
}
