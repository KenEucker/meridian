<?php

namespace App\Models;

use Database\Factories\FieldReportAppendFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Immutable Field Report append (data/API specification section 10.15,
 * technical spec section 17.4, FR-007 through FR-009).
 *
 * Appends never mutate the parent Field Report body. Only the original
 * submitter may create them. Name References in append bodies are indexed
 * after acceptance (M9.6A). Photos and incident-note propagation of appended
 * content belong to later M9/M11 tasks.
 */
class FieldReportAppend extends Model
{
    /** @use HasFactory<FieldReportAppendFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Appends only record a creation timestamp.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'field_report_id',
        'appended_by_user_id',
        'body',
        'device_submitted_at',
        'server_received_at',
        'origin_device_id',
        'origin_node_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_submitted_at' => 'datetime',
            'server_received_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Field report appends are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Field report appends are immutable and cannot be deleted.');
        });
    }

    public function fieldReport(): BelongsTo
    {
        return $this->belongsTo(FieldReport::class);
    }

    public function appendedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appended_by_user_id');
    }

    public function originDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'origin_device_id');
    }

    public function originNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'origin_node_id');
    }
}
