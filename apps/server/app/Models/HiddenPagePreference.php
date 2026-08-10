<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's decision about whether one page appears in their navigation
 * (M18.69).
 *
 * Personal view state and nothing more: invisible to every other user, not
 * audited, and read by no feature other than the session document that hands it
 * back to the client it came from. One row per user per page key, holding the
 * decision itself — a page can be hidden by default, so "shown" is a decision
 * somebody makes and the row has to be able to say it.
 *
 * The absence of a row is not "shown". It is "undecided", which
 * {@see \App\Domain\Navigation\HideablePageCatalog::resolve()} answers from the
 * catalog's default.
 */
class HiddenPagePreference extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'page_key',
        'hidden',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hidden' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
