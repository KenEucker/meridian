<?php

namespace App\Models;

use Database\Factories\DocumentAcknowledgmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Immutable record of a user's connected acceptance of a policy or procedure
 * document version (POL-043 through POL-045).
 */
class DocumentAcknowledgment extends Model
{
    public const DOCUMENT_TYPE_POLICY = 'policy';

    public const DOCUMENT_TYPE_PROCEDURE = 'procedure';

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_DEPARTMENT = 'department';

    /** @use HasFactory<DocumentAcknowledgmentFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Acknowledgments are append-only operational history.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'staff_id',
        'document_type',
        'document_id',
        'document_revision',
        'fragment_revision',
        'scope_type',
        'scope_id',
        'acknowledged_at',
        'accepted_by_node_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_revision' => 'integer',
            'fragment_revision' => 'integer',
            'acknowledged_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function documentTypes(): array
    {
        return [
            self::DOCUMENT_TYPE_POLICY,
            self::DOCUMENT_TYPE_PROCEDURE,
        ];
    }

    /**
     * @return class-string<PolicyDocument|ProcedureDocument>|null
     */
    public static function documentModelForType(string $documentType): ?string
    {
        return match ($documentType) {
            self::DOCUMENT_TYPE_POLICY => PolicyDocument::class,
            self::DOCUMENT_TYPE_PROCEDURE => ProcedureDocument::class,
            default => null,
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function acceptedByNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'accepted_by_node_id');
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
            throw new RuntimeException('Document acknowledgments are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Document acknowledgments are immutable and cannot be deleted.');
        });
    }
}
