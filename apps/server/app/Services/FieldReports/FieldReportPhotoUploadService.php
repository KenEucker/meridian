<?php

namespace App\Services\FieldReports;

use App\Exceptions\FieldReportPhotoProcessingException;
use App\Exceptions\FieldReportPhotoUploadException;
use App\Models\Attachment;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\FieldReport;
use App\Models\Node;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Accepts a separately synced Field Report photo and stores it server-side
 * (technical spec 18.2, 18.5; data/API 10.17).
 *
 * Text report acceptance may complete before photos arrive. Upload verifies the
 * client checksum of the received bytes, re-processes defensively, persists the
 * blob on the private attachments disk, and records immutable attachment
 * metadata. Duplicate delivery of the same attachment UUID is idempotent.
 */
final class FieldReportPhotoUploadService
{
    public function __construct(
        private readonly FieldReportPhotoProcessor $processor,
    ) {}

    /**
     * @param  array{
     *     id: string,
     *     field_report_id: string,
     *     uploaded_by_user_id: string,
     *     origin_device_id: string,
     *     origin_node_id: string,
     *     bytes: string,
     *     checksum_sha256: string,
     *     declared_mime_type?: string|null,
     *     device_uploaded_at?: DateTimeInterface|string|null
     * }  $attributes
     */
    public function upload(array $attributes, ?DateTimeInterface $receivedAt = null): Attachment
    {
        $id = (string) ($attributes['id'] ?? '');

        if (! Str::isUuid($id)) {
            throw FieldReportPhotoUploadException::invalid(
                'Field Report photo id must be a valid UUID.',
            );
        }

        $bytes = $attributes['bytes'] ?? null;
        if (! is_string($bytes) || $bytes === '') {
            throw FieldReportPhotoUploadException::invalid(
                'Field Report photo bytes are required.',
            );
        }

        $declaredChecksum = strtolower((string) ($attributes['checksum_sha256'] ?? ''));
        if (! preg_match('/^[a-f0-9]{64}$/', $declaredChecksum)) {
            throw FieldReportPhotoUploadException::invalid(
                'Field Report photo checksum must be a SHA-256 hex digest.',
            );
        }

        $actualChecksum = hash('sha256', $bytes);
        if (! hash_equals($declaredChecksum, $actualChecksum)) {
            throw FieldReportPhotoUploadException::invalid(
                'Field Report photo checksum does not match the uploaded bytes.',
            );
        }

        return DB::transaction(function () use ($attributes, $id, $bytes, $declaredChecksum, $receivedAt): Attachment {
            $existing = Attachment::query()->whereKey($id)->first();
            if ($existing !== null) {
                return $this->acceptedExisting($existing, $attributes, $declaredChecksum);
            }

            $report = FieldReport::query()
                ->whereKey($this->requiredId($attributes, 'field_report_id'))
                ->lockForUpdate()
                ->first();

            if ($report === null) {
                throw FieldReportPhotoUploadException::invalid(
                    'Field Report does not exist for photo upload.',
                );
            }

            $existing = Attachment::query()->whereKey($id)->first();
            if ($existing !== null) {
                return $this->acceptedExisting($existing, $attributes, $declaredChecksum);
            }

            $user = $this->find(User::class, $this->requiredId($attributes, 'uploaded_by_user_id'), 'uploader');
            $device = $this->find(Device::class, $this->requiredId($attributes, 'origin_device_id'), 'origin device');
            $node = $this->find(Node::class, $this->requiredId($attributes, 'origin_node_id'), 'origin node');

            $this->assertUploadContext($report, $user, $device, $node);

            $existingCount = Attachment::query()
                ->where('attachable_type', Attachment::MORPH_FIELD_REPORT)
                ->where('attachable_id', $report->id)
                ->whereNull('deleted_at')
                ->count();

            try {
                $this->processor->assertCanAdd($existingCount, 0, 1);
                $processed = $this->processor->process(
                    $bytes,
                    isset($attributes['declared_mime_type'])
                        ? (string) $attributes['declared_mime_type']
                        : null,
                );
            } catch (FieldReportPhotoProcessingException $exception) {
                throw FieldReportPhotoUploadException::invalid($exception->getMessage());
            }

            $uploadedAt = $this->uploadedAt($attributes['device_uploaded_at'] ?? null, $receivedAt);
            $slot = $existingCount + 1;
            $filename = FieldReportPhotoFilename::make(
                $report,
                $slot,
                $processed['mime_type'],
                $uploadedAt,
            );
            $storagePath = 'field-reports/'.$report->event_id.'/'.$filename;
            $disk = (string) config('filesystems.attachments_disk', 'attachments');

            Storage::disk($disk)->put($storagePath, $processed['bytes']);

            return Attachment::query()->create([
                'id' => $id,
                'attachable_type' => Attachment::MORPH_FIELD_REPORT,
                'attachable_id' => $report->id,
                'uploaded_by_user_id' => $user->id,
                'filename' => $filename,
                'mime_type' => $processed['mime_type'],
                'byte_size' => $processed['byte_size'],
                'storage_disk' => $disk,
                'storage_path' => $storagePath,
                'checksum' => $processed['checksum_sha256'],
                'metadata_json' => [
                    'width' => $processed['width'],
                    'height' => $processed['height'],
                    'slot' => $slot,
                    'client_checksum_sha256' => $declaredChecksum,
                    'attachments_pending_cleared' => false,
                ],
                'origin_device_id' => $device->id,
                'origin_node_id' => $node->id,
                'created_at' => $uploadedAt,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function acceptedExisting(
        Attachment $attachment,
        array $attributes,
        string $declaredChecksum,
    ): Attachment {
        if (! $attachment->isFieldReportPhoto()) {
            throw FieldReportPhotoUploadException::invalid(
                'The attachment UUID already exists for a different attachable type.',
            );
        }

        $sameUpload = (string) ($attributes['field_report_id'] ?? '') === (string) $attachment->attachable_id
            && (string) ($attributes['uploaded_by_user_id'] ?? '') === (string) $attachment->uploaded_by_user_id
            && (string) ($attributes['origin_device_id'] ?? '') === (string) $attachment->origin_device_id
            && (string) ($attributes['origin_node_id'] ?? '') === (string) $attachment->origin_node_id
            && ($attachment->metadata_json['client_checksum_sha256'] ?? null) === $declaredChecksum;

        if (! $sameUpload) {
            throw FieldReportPhotoUploadException::invalid(
                'The Field Report photo UUID was already accepted with different source data.',
            );
        }

        return $attachment;
    }

    private function assertUploadContext(
        FieldReport $report,
        User $user,
        Device $device,
        Node $node,
    ): void {
        if ((string) $report->submitted_by_user_id !== (string) $user->id) {
            throw FieldReportPhotoUploadException::invalid(
                'Only the original Field Report author may upload photos for it.',
            );
        }

        $trustedDevice = DeviceTrust::query()
            ->active()
            ->where('user_id', $user->id)
            ->where('device_id', $device->id)
            ->exists();

        if (! $trustedDevice) {
            throw FieldReportPhotoUploadException::invalid(
                'Field Report photo origin device is not actively trusted for the uploading user.',
            );
        }

        if ($node->isRevoked()) {
            throw FieldReportPhotoUploadException::invalid(
                'Field Report photo origin node is revoked.',
            );
        }

        if ((string) $report->origin_device_id !== (string) $device->id) {
            throw FieldReportPhotoUploadException::invalid(
                'Field Report photo origin device must match the report origin device.',
            );
        }

        if ((string) $report->origin_node_id !== (string) $node->id) {
            throw FieldReportPhotoUploadException::invalid(
                'Field Report photo origin node must match the report origin node.',
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
            throw FieldReportPhotoUploadException::invalid("Field Report photo {$label} does not exist.");
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
            throw FieldReportPhotoUploadException::invalid(
                "Field Report photo {$key} must be a valid UUID.",
            );
        }

        return $value;
    }

    private function uploadedAt(mixed $deviceUploadedAt, ?DateTimeInterface $receivedAt): CarbonImmutable
    {
        if (is_string($deviceUploadedAt) || $deviceUploadedAt instanceof DateTimeInterface) {
            try {
                return CarbonImmutable::parse($deviceUploadedAt);
            } catch (\Throwable) {
                throw FieldReportPhotoUploadException::invalid(
                    'Field Report photo device upload timestamp is invalid.',
                );
            }
        }

        return CarbonImmutable::instance($receivedAt ?? now());
    }
}
