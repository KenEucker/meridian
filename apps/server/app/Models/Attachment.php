<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Shared attachment metadata (data/API specification section 10.17).
 *
 * Field Report photos are immutable once attached (technical spec 18.4).
 * Binary blobs live on a private filesystem disk; records carry checksums for
 * upload verification (technical spec 18.5).
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, HasUuids;

    public const MORPH_FIELD_REPORT = 'field_report';

    public const MORPH_INCIDENT = 'incident';

    /** Organization branding logo assets (BRAND-004, BRAND-023). */
    public const MORPH_ORGANIZATION = 'organization';

    /** Department branding logo assets (BRAND-010, BRAND-023). */
    public const MORPH_DEPARTMENT = 'department';

    /**
     * Branding logo slots. The slot is stored in `metadata_json` so one
     * attachable can hold more than one current asset — an organization has
     * both a full lockup and a compact mark (BRAND-004) — without the morph
     * type having to encode which is which.
     */
    public const BRANDING_SLOT_FULL_LOCKUP = 'full_lockup';

    public const BRANDING_SLOT_COMPACT_MARK = 'compact_mark';

    public const BRANDING_SLOT_DEPARTMENT_LOGO = 'department_logo';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'attachable_type',
        'attachable_id',
        'uploaded_by_user_id',
        'filename',
        'mime_type',
        'byte_size',
        'storage_disk',
        'storage_path',
        'checksum',
        'metadata_json',
        'origin_device_id',
        'origin_node_id',
        'created_at',
        'stricken_at',
        'deleted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'metadata_json' => 'array',
            'created_at' => 'datetime',
            'stricken_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Attachments are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Attachments cannot be deleted in Alpha 1.');
        });
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function originDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'origin_device_id');
    }

    public function originNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'origin_node_id');
    }

    public function isFieldReportPhoto(): bool
    {
        return $this->attachable_type === self::MORPH_FIELD_REPORT
            || $this->attachable instanceof FieldReport;
    }

    public function isBrandingAsset(): bool
    {
        return in_array(
            $this->attachable_type,
            [self::MORPH_ORGANIZATION, self::MORPH_DEPARTMENT],
            true,
        );
    }

    public function brandingSlot(): ?string
    {
        $slot = $this->metadata_json['branding_slot'] ?? null;

        return is_string($slot) && $slot !== '' ? $slot : null;
    }
}
