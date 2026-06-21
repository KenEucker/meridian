<?php

namespace App\Models;

use Database\Factories\DocumentVersionSnapshotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Immutable source and resolved-Markdown proof retained for a document version
 * that has been acknowledged (technical specification section 21.4).
 */
class DocumentVersionSnapshot extends Model
{
    /** @use HasFactory<DocumentVersionSnapshotFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_type',
        'document_id',
        'document_revision',
        'fragment_revision',
        'markdown_source_snapshot',
        'resolved_markdown_snapshot',
        'snapshot_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_revision' => 'integer',
            'fragment_revision' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<PolicyDocument|ProcedureDocument, $this>
     */
    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Document version snapshots are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Document version snapshots are immutable and cannot be deleted.');
        });
    }
}
