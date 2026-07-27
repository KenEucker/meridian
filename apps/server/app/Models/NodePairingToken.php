<?php

namespace App\Models;

use Database\Factories\NodePairingTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time pairing token created by a central node so an on-site or
 * standalone node can pair with it (technical spec 7.3, 7.4).
 *
 * Only the token hash is stored. The plaintext token is returned once at issue
 * time and is never recoverable from the database afterwards. Alpha 1 does not
 * require quick expiry, so `expires_at` is normally null.
 */
class NodePairingToken extends Model
{
    /** @use HasFactory<NodePairingTokenFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'token_hash',
        'issued_by_node_id',
        'issued_by_user_id',
        'label',
        'expires_at',
        'used_at',
        'paired_node_id',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function issuedByNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'issued_by_node_id');
    }

    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function pairedNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'paired_node_id');
    }

    /**
     * @param  Builder<NodePairingToken>  $query
     * @return Builder<NodePairingToken>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(?Carbon $at = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lte($at ?? now());
    }

    public function isActive(?Carbon $at = null): bool
    {
        return ! $this->isUsed() && ! $this->isRevoked() && ! $this->isExpired($at);
    }
}
