<?php

namespace App\Models;

use Database\Factories\ProcedureDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

/**
 * Markdown governance document describing how operational work should be
 * performed (POL-002, POL-033). Mirrors the policy document model and its
 * rules per data/API specification section 11.3.
 */
class ProcedureDocument extends Model
{
    use AsSource;
    use Filterable;

    public const STATE_DRAFT = 'draft';

    public const STATE_PUBLISHED = 'published';

    public const STATE_ARCHIVED = 'archived';

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_TEAM = 'team';

    /** @use HasFactory<ProcedureDocumentFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'scope_type',
        'scope_id',
        'title',
        'slug',
        'markdown_source',
        'state',
        'document_revision',
        'fragment_revision',
        'published_at',
        'archived_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'organization_id' => Where::class,
        'scope_type' => Where::class,
        'scope_id' => Where::class,
        'title' => Like::class,
        'slug' => Like::class,
        'state' => Where::class,
        'published_at' => WhereDateStartEnd::class,
        'archived_at' => WhereDateStartEnd::class,
        'updated_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'id',
        'organization_id',
        'scope_type',
        'title',
        'slug',
        'state',
        'document_revision',
        'fragment_revision',
        'published_at',
        'archived_at',
        'updated_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_revision' => 'integer',
            'fragment_revision' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [
            self::STATE_DRAFT,
            self::STATE_PUBLISHED,
            self::STATE_ARCHIVED,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function stateLabels(): array
    {
        return [
            self::STATE_DRAFT => 'Draft',
            self::STATE_PUBLISHED => 'Published',
            self::STATE_ARCHIVED => 'Archived',
        ];
    }

    /**
     * @return list<string>
     */
    public static function scopeTypes(): array
    {
        return [
            self::SCOPE_ORGANIZATION,
            self::SCOPE_DEPARTMENT,
            self::SCOPE_TEAM,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scopeTypeLabels(): array
    {
        return [
            self::SCOPE_ORGANIZATION => 'Organization',
            self::SCOPE_DEPARTMENT => 'Department',
            self::SCOPE_TEAM => 'Team',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationScope(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'scope_id');
    }

    public function departmentScope(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'scope_id');
    }

    public function teamScope(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'scope_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @param  Builder<ProcedureDocument>  $query
     * @return Builder<ProcedureDocument>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('state', self::STATE_PUBLISHED);
    }

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->state === self::STATE_PUBLISHED;
    }

    public function isArchived(): bool
    {
        return $this->state === self::STATE_ARCHIVED;
    }

    public function version(): string
    {
        return sprintf('%d.%02d', $this->document_revision, $this->fragment_revision);
    }
}
