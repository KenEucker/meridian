<?php

namespace App\Models;

use Database\Factories\DocumentAcknowledgmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;
use RuntimeException;

/**
 * Immutable record of a user's connected acceptance of a policy or procedure
 * document version (POL-043 through POL-045).
 *
 * `AsSource` and `Filterable` are for the God Mode review screen (M18.34),
 * which lists and narrows this table. Neither adds a write path: the model
 * refuses updates and deletes below.
 */
class DocumentAcknowledgment extends Model
{
    use AsSource;
    use Filterable;

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
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'document_type' => Like::class,
        'scope_type' => Where::class,
        'acknowledged_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'acknowledged_at',
        'document_type',
        'document_revision',
        'fragment_revision',
        'scope_type',
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

    public function organizationScope(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'scope_id');
    }

    public function departmentScope(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'scope_id');
    }

    /**
     * Organization narrowing for the God Mode review screen (M18.34).
     *
     * An acknowledgment records the scope it was asked in rather than the
     * organization it belongs to, so an organization reaches its department
     * acknowledgments only through the departments themselves. Both halves are
     * needed: POL-026 lets a requirement be set at either level, and a reviewer
     * narrowing to an organization is asking about all of its acknowledgments,
     * not only the ones asked organization-wide.
     *
     * @param  Builder<DocumentAcknowledgment>  $query
     * @return Builder<DocumentAcknowledgment>
     */
    public function scopeInOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where(function (Builder $scoped) use ($organizationId): void {
            $scoped
                ->where(fn (Builder $organization) => $organization
                    ->where('scope_type', self::SCOPE_ORGANIZATION)
                    ->where('scope_id', $organizationId))
                ->orWhere(fn (Builder $department) => $department
                    ->where('scope_type', self::SCOPE_DEPARTMENT)
                    ->whereIn('scope_id', Department::query()
                        ->select('id')
                        ->where('organization_id', $organizationId)));
        });
    }

    /**
     * @param  Builder<DocumentAcknowledgment>  $query
     * @return Builder<DocumentAcknowledgment>
     */
    public function scopeInDepartment(Builder $query, string $departmentId): Builder
    {
        return $query
            ->where('scope_type', self::SCOPE_DEPARTMENT)
            ->where('scope_id', $departmentId);
    }

    /**
     * The scope this acknowledgment was asked in, by name.
     *
     * Falls back to the recorded identifier rather than to nothing, because a
     * scope whose organization or department has since been removed is exactly
     * the sort of thing a repair screen exists to show.
     */
    public function describeScope(): string
    {
        $name = match ($this->scope_type) {
            self::SCOPE_ORGANIZATION => $this->organizationScope?->name,
            self::SCOPE_DEPARTMENT => $this->departmentScope?->name,
            default => null,
        };

        if ($name !== null) {
            return $name;
        }

        return $this->scope_id !== null
            ? (string) $this->scope_id
            : 'Unrecorded';
    }

    /**
     * The document this acknowledgment accepted, by title.
     *
     * Titles change; the revision beside it is what pins which words were
     * accepted (POL-045).
     */
    public function describeDocument(): string
    {
        $title = $this->document?->title;

        return is_string($title) && $title !== ''
            ? $title
            : (string) $this->document_id;
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
