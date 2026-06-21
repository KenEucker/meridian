<?php

namespace App\Models;

use Database\Factories\NodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Node extends Model
{
    /** @use HasFactory<NodeFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const ROLE_DEVELOPMENT = 'development';

    public const ROLE_STANDALONE = 'standalone';

    public const ROLE_CENTRAL = 'central';

    public const ROLE_ONSITE = 'onsite';

    /**
     * @var list<string>
     */
    public const ROLES = [
        self::ROLE_DEVELOPMENT,
        self::ROLE_STANDALONE,
        self::ROLE_CENTRAL,
        self::ROLE_ONSITE,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'node_name',
        'node_role',
        'public_key',
        'organization_id',
        'event_id',
        'central_node_url',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    public function configValues(): HasMany
    {
        return $this->hasMany(NodeConfigValue::class);
    }

    public function acceptedDocumentAcknowledgments(): HasMany
    {
        return $this->hasMany(DocumentAcknowledgment::class, 'accepted_by_node_id');
    }

    /**
     * @param  Builder<Node>  $query
     * @return Builder<Node>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
