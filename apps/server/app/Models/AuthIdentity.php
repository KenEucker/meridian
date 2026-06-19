<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthIdentity extends Model
{
    /** @use HasFactory<\Database\Factories\AuthIdentityFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const PROVIDER_EMAIL = 'email';

    public const PROVIDER_GOOGLE = 'google';

    public const PROVIDER_DISCORD = 'discord';

    /**
     * @var list<string>
     */
    public const PROVIDERS = [
        self::PROVIDER_EMAIL,
        self::PROVIDER_GOOGLE,
        self::PROVIDER_DISCORD,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_subject',
        'provider_email',
        'provider_email_verified',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_email_verified' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
