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
     * An on-site node pairs with central, and a standalone node may be paired
     * with central later (technical spec 7.3, 8.1).
     *
     * @var list<string>
     */
    public const PAIRABLE_ROLES = [
        self::ROLE_STANDALONE,
        self::ROLE_ONSITE,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'node_name',
        'node_role',
        'is_local',
        'public_key',
        'organization_id',
        'event_id',
        'central_node_url',
        'paired_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_local' => 'boolean',
            'paired_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function configValues(): HasMany
    {
        return $this->hasMany(NodeConfigValue::class);
    }

    /**
     * Pairing tokens this node issued as a central node.
     */
    public function issuedPairingTokens(): HasMany
    {
        return $this->hasMany(NodePairingToken::class, 'issued_by_node_id');
    }

    public function acceptedDocumentAcknowledgments(): HasMany
    {
        return $this->hasMany(DocumentAcknowledgment::class, 'accepted_by_node_id');
    }

    /**
     * Sync operations this node created (data/API 13.3).
     */
    public function originatedOperations(): HasMany
    {
        return $this->hasMany(NodeOperation::class, 'origin_node_id');
    }

    /**
     * Sync operations addressed to this node (data/API 13.3).
     */
    public function targetedOperations(): HasMany
    {
        return $this->hasMany(NodeOperation::class, 'target_node_id');
    }

    /**
     * @param  Builder<Node>  $query
     * @return Builder<Node>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * This install's own node, as opposed to a paired peer node record.
     *
     * @param  Builder<Node>  $query
     * @return Builder<Node>
     */
    public function scopeLocal(Builder $query): Builder
    {
        return $query->where('is_local', true);
    }

    /**
     * Peer node records this install learned about through pairing.
     *
     * @param  Builder<Node>  $query
     * @return Builder<Node>
     */
    public function scopeRemote(Builder $query): Builder
    {
        return $query->where('is_local', false);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isCentral(): bool
    {
        return $this->node_role === self::ROLE_CENTRAL;
    }

    /**
     * Roles that pair with a central node (technical spec 7.3, 8.1).
     */
    public function canPairWithCentral(): bool
    {
        return in_array($this->node_role, self::PAIRABLE_ROLES, true);
    }
}
