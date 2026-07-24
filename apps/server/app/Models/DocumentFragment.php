<?php

namespace App\Models;

use App\Models\Concerns\FiltersByAudienceScope;
use App\Jobs\BumpPublishedDocumentFragmentRevisions;
use App\Services\Documents\DocumentFragmentReferenceParser;
use Database\Factories\DocumentFragmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

/**
 * Reusable Markdown text shared by policy and procedure documents
 * (POL-014 through POL-018, POL-038, and POL-039).
 */
class DocumentFragment extends Model
{
    use AsSource;
    use Filterable;
    use FiltersByAudienceScope;

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_TEAM = 'team';

    /** @use HasFactory<DocumentFragmentFactory> */
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
        'name',
        'slug',
        'markdown_source',
        'version',
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
        'name' => Like::class,
        'slug' => Like::class,
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
        'name',
        'slug',
        'version',
        'updated_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DocumentFragment $fragment): void {
            app(DocumentFragmentReferenceParser::class)
                ->assertFragmentMarkdownHasNoReferences($fragment->markdown_source);
        });

        static::creating(function (DocumentFragment $fragment): void {
            $fragment->version = 1;
        });

        static::updating(function (DocumentFragment $fragment): void {
            $originalVersion = (int) $fragment->getOriginal('version');

            if ($fragment->isDirty('markdown_source')) {
                $fragment->version = $originalVersion + 1;

                return;
            }

            if ($fragment->isDirty('version')) {
                $fragment->version = $originalVersion;
            }
        });

        static::updated(function (DocumentFragment $fragment): void {
            if (! $fragment->wasChanged('markdown_source')) {
                return;
            }

            BumpPublishedDocumentFragmentRevisions::dispatch(
                $fragment->id,
                $fragment->version,
            )->afterCommit();
        });
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

    public function references(): HasMany
    {
        return $this->hasMany(DocumentFragmentReference::class, 'fragment_id');
    }
}
