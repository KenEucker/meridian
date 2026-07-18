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
}
