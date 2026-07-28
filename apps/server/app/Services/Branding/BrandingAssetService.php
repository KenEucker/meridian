<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Node;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Node\NodeSetupService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Upload, replace, and remove branding logo assets (M15A.5; BRAND-004,
 * BRAND-010, BRAND-020, BRAND-023).
 *
 * Assets go through the existing attachment path — same table, same private
 * disk, same checksum — so branding inherits the storage, immutability, and
 * provenance behavior that path already has rather than growing a parallel one.
 *
 * Replace and remove never destroy anything. An {@see Attachment} refuses both
 * update and delete (technical spec 18.4), so replacing uploads a new
 * attachment and repoints the owner's reference, and removing nulls the
 * reference and leaves the row. BRAND-004 explicitly does not require
 * preserving superseded assets, so leaving them is not a promise being kept —
 * it is the cheapest way to avoid a delete path that could take the wrong row.
 * A later reaping job can collect unreferenced branding attachments; nothing
 * depends on them still being there.
 *
 * The declared MIME type is not believed. Bytes are sniffed with `finfo` and
 * the file is decoded, because "it says it is a PNG" is not the same claim as
 * "it is a PNG", and this asset is served inline to every signed-in surface.
 */
class BrandingAssetService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly BrandingGovernance $governance,
        private readonly NodeSetupService $nodes,
    ) {}

    /**
     * Store a new asset for a slot and make it current, replacing whatever was
     * there.
     *
     * @throws BrandingValidationException|BrandingAuthorityException
     */
    public function put(
        Organization|Department $owner,
        string $slot,
        string $bytes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Attachment {
        $this->assertSlot($owner, $slot);
        $this->governance->assertEditable($this->organizationIdFor($owner), $this->describe($owner, $slot));

        $mimeType = $this->sniff($bytes);
        $this->assertPermitted($mimeType, strlen($bytes));

        return DB::transaction(function () use ($owner, $slot, $bytes, $mimeType, $actor, $sourceContext): Attachment {
            $previousId = $owner->getAttribute($this->columnFor($slot));
            $attachmentId = (string) Str::uuid();

            // The attachment id is part of the filename, not just the path.
            // `attachments` is unique on (attachable_type, attachable_id,
            // filename), and a timestamp alone collides when a logo is
            // replaced twice inside the same second — which is exactly what
            // an organizer correcting a mistaken upload does.
            $filename = sprintf(
                '%s_%s_%s.%s',
                $slot,
                now()->format('Ymd\THis\Z'),
                substr($attachmentId, 0, 8),
                BrandingAssetLimits::extensionFor($mimeType),
            );

            $disk = (string) config('filesystems.attachments_disk', 'attachments');
            $storagePath = 'branding/'.$owner->getKey().'/'.$attachmentId.'/'.$filename;

            Storage::disk($disk)->put($storagePath, $bytes);

            $attachment = Attachment::query()->create([
                'id' => $attachmentId,
                'attachable_type' => $this->morphFor($owner),
                'attachable_id' => $owner->getKey(),
                'uploaded_by_user_id' => $actor->getKey(),
                'filename' => $filename,
                'mime_type' => $mimeType,
                'byte_size' => strlen($bytes),
                'storage_disk' => $disk,
                'storage_path' => $storagePath,
                'checksum' => hash('sha256', $bytes),
                'metadata_json' => ['branding_slot' => $slot],
                // A logo is uploaded from a browser, so there is no trusted
                // field device to attribute it to.
                'origin_device_id' => null,
                'origin_node_id' => $this->originNodeId(),
                'created_at' => now(),
            ]);

            $owner->forceFill([
                $this->columnFor($slot) => $attachment->getKey(),
                'branding_updated_at' => now(),
            ])->save();

            $this->audit->recordForEntity(
                entity: $owner,
                action: $previousId === null ? 'branding.asset_added' : 'branding.asset_replaced',
                actorUser: $actor,
                organizationId: $this->organizationIdFor($owner),
                departmentId: $owner instanceof Department ? (string) $owner->getKey() : null,
                before: ['slot' => $slot, 'attachment_id' => $previousId !== null ? (string) $previousId : null],
                after: ['slot' => $slot, 'attachment_id' => (string) $attachment->getKey()],
                sourceContext: $sourceContext,
            );

            return $attachment;
        });
    }

    /**
     * Clear the current asset for a slot (BRAND-004).
     *
     * @throws BrandingAuthorityException
     */
    public function remove(
        Organization|Department $owner,
        string $slot,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): void {
        $this->assertSlot($owner, $slot);
        $this->governance->assertEditable($this->organizationIdFor($owner), $this->describe($owner, $slot));

        $column = $this->columnFor($slot);
        $previousId = $owner->getAttribute($column);

        if ($previousId === null) {
            return;
        }

        DB::transaction(function () use ($owner, $slot, $column, $previousId, $actor, $sourceContext): void {
            $owner->forceFill([
                $column => null,
                'branding_updated_at' => now(),
            ])->save();

            $this->audit->recordForEntity(
                entity: $owner,
                action: 'branding.asset_removed',
                actorUser: $actor,
                organizationId: $this->organizationIdFor($owner),
                departmentId: $owner instanceof Department ? (string) $owner->getKey() : null,
                before: ['slot' => $slot, 'attachment_id' => (string) $previousId],
                after: ['slot' => $slot, 'attachment_id' => null],
                sourceContext: $sourceContext,
            );
        });
    }

    /**
     * @throws BrandingValidationException
     */
    private function assertPermitted(string $mimeType, int $byteSize): void
    {
        if (! BrandingAssetLimits::permits($mimeType)) {
            throw BrandingValidationException::rejectedAsset(sprintf(
                'A branding logo must be one of %s. The uploaded file is %s.',
                implode(', ', BrandingAssetLimits::permittedMimeTypes()),
                $mimeType,
            ));
        }

        if ($byteSize <= 0) {
            throw BrandingValidationException::rejectedAsset('The uploaded branding logo is empty.');
        }

        if ($byteSize > BrandingAssetLimits::MAX_BYTES) {
            throw BrandingValidationException::rejectedAsset(sprintf(
                'A branding logo may be at most %d KB. The uploaded file is %d KB.',
                (int) (BrandingAssetLimits::MAX_BYTES / 1024),
                (int) ceil($byteSize / 1024),
            ));
        }
    }

    /**
     * The type the bytes actually are, not the type the request claimed.
     */
    private function sniff(string $bytes): string
    {
        $info = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $info->buffer($bytes);

        return is_string($detected) && $detected !== '' ? strtolower($detected) : 'application/octet-stream';
    }

    /**
     * @throws BrandingValidationException
     */
    private function assertSlot(Organization|Department $owner, string $slot): void
    {
        $permitted = $owner instanceof Organization
            ? [Attachment::BRANDING_SLOT_FULL_LOCKUP, Attachment::BRANDING_SLOT_COMPACT_MARK]
            : [Attachment::BRANDING_SLOT_DEPARTMENT_LOGO];

        if (! in_array($slot, $permitted, true)) {
            throw BrandingValidationException::unsupportedAsset($slot);
        }
    }

    private function columnFor(string $slot): string
    {
        return match ($slot) {
            Attachment::BRANDING_SLOT_FULL_LOCKUP => 'branding_full_lockup_attachment_id',
            Attachment::BRANDING_SLOT_COMPACT_MARK => 'branding_compact_mark_attachment_id',
            default => 'branding_logo_attachment_id',
        };
    }

    private function morphFor(Model $owner): string
    {
        return $owner instanceof Organization
            ? Attachment::MORPH_ORGANIZATION
            : Attachment::MORPH_DEPARTMENT;
    }

    private function organizationIdFor(Organization|Department $owner): string
    {
        return $owner instanceof Organization
            ? (string) $owner->getKey()
            : (string) $owner->organization_id;
    }

    private function describe(Organization|Department $owner, string $slot): string
    {
        return sprintf(
            '%s branding (%s)',
            $owner instanceof Organization ? 'organization' : 'department',
            str_replace('_', ' ', $slot),
        );
    }

    private function originNodeId(): ?string
    {
        $node = $this->nodes->activeNode();

        return $node instanceof Node ? (string) $node->getKey() : null;
    }
}
