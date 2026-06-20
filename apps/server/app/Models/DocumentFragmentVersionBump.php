<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records a completed fragment-driven document revision bump so queued job
 * retries cannot increment a document more than once for one fragment version.
 */
class DocumentFragmentVersionBump extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'fragment_id',
        'fragment_version',
        'document_type',
        'document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fragment_version' => 'integer',
        ];
    }

    public function fragment(): BelongsTo
    {
        return $this->belongsTo(DocumentFragment::class);
    }
}
