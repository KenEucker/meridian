<?php

namespace App\Models;

use Database\Factories\DocumentAcknowledgmentRequirementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Configures a policy or procedure that must be acknowledged during staff
 * signup or training (POL-023 through POL-027, POL-046, and POL-047).
 */
class DocumentAcknowledgmentRequirement extends Model
{
    public const DOCUMENT_TYPE_POLICY = 'policy';

    public const DOCUMENT_TYPE_PROCEDURE = 'procedure';

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_DEPARTMENT = 'department';

    public const CONTEXT_SIGNUP = 'signup';

    public const CONTEXT_TRAINING = 'training';

    /** @use HasFactory<DocumentAcknowledgmentRequirementFactory> */
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
        'document_type',
        'document_id',
        'requirement_context',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
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

    /**
     * @return list<string>
     */
    public static function scopeTypes(): array
    {
        return [
            self::SCOPE_ORGANIZATION,
            self::SCOPE_DEPARTMENT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function requirementContexts(): array
    {
        return [
            self::CONTEXT_SIGNUP,
            self::CONTEXT_TRAINING,
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

    /**
     * @return MorphTo<PolicyDocument|ProcedureDocument, $this>
     */
    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<DocumentAcknowledgmentRequirement>  $query
     * @return Builder<DocumentAcknowledgmentRequirement>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
