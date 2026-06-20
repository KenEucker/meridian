<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A resolved policy or procedure Markdown reference to a reusable fragment
 * (POL-019 through POL-022).
 *
 * The author-facing Markdown token uses a fragment slug; this record stores
 * the resolved UUID and the fragment version seen when the document was last
 * edited. Rendering always uses the current fragment text.
 */
class DocumentFragmentReference extends Model
{
    public const DOCUMENT_TYPE_POLICY = 'policy';

    public const DOCUMENT_TYPE_PROCEDURE = 'procedure';

    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_type',
        'document_id',
        'fragment_id',
        'token',
        'fragment_version_at_last_edit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fragment_version_at_last_edit' => 'integer',
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

    public function fragment(): BelongsTo
    {
        return $this->belongsTo(DocumentFragment::class);
    }
}
