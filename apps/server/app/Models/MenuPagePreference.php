<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's decision about whether one page appears in their menus (M18.69).
 *
 * The narrower sibling of {@see HiddenPagePreference}: that one records a page
 * the reader has put away entirely, and this one records a page they want off
 * the menus while it stays on the home directory. Same shape, same personal
 * scope — invisible to every other user, not audited, and read by no feature
 * other than the session document that hands it back to the client it came
 * from.
 *
 * The absence of a row is "undecided" rather than "in the menu", which
 * {@see \App\Domain\Navigation\MenuPageCatalog::resolve()} answers from the
 * catalog's default.
 */
class MenuPagePreference extends Model
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
