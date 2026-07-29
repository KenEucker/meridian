<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\NodeHealthReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The latest sanitized health report known for a node (technical spec 22A.11;
 * SYS-037 through SYS-040).
 */
class NodeHealthReport extends Model
{
    /** @use HasFactory<NodeHealthReportFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** A report older than this is presented as stale (SYS-040). */
    public const STALE_AFTER_MINUTES = 30;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'node_id',
        'report_uuid',
        'overall_status',
        'node_name',
        'node_role',
        'meridian_version',
        'config_schema_version',
        'category_statuses_json',
        'summary_json',
        'warnings_json',
        'generated_at',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_statuses_json' => 'array',
            'summary_json' => 'array',
            'warnings_json' => 'array',
            'generated_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'config_schema_version' => 'integer',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function isStale(): bool
    {
        return $this->generated_at instanceof CarbonImmutable
            && $this->generated_at->diffInMinutes(CarbonImmutable::now()) > self::STALE_AFTER_MINUTES;
    }
}
