<?php

declare(strict_types=1);

namespace App\Services\Navigation;

use App\Domain\Navigation\HideablePageCatalog;
use App\Models\HiddenPagePreference;
use App\Models\User;

/**
 * Reads and writes a user's own navigation preferences (M18.69).
 *
 * Every method here takes the user whose preference it is and no other subject,
 * because there is no other answer to "whose": a person decides what their own
 * menu shows, and nobody — organizer, lead, or administrator — decides it for
 * them. That is not a permission check this service performs. It is a shape it
 * refuses to have.
 */
final class PageVisibilityService
{
    /**
     * The pages this user has hidden, with catalog defaults filling anything
     * they have not decided.
     *
     * @return list<string>
     */
    public function hiddenPagesFor(User $user): array
    {
        $choices = HiddenPagePreference::query()
            ->where('user_id', $user->getKey())
            ->pluck('hidden', 'page_key')
            ->map(fn ($hidden): bool => (bool) $hidden)
            ->all();

        return HideablePageCatalog::resolve($choices);
    }

    /**
     * Record this user's decision about one page.
     *
     * The row is written for both answers rather than deleted when the value
     * returns to the default. A user who switches the dashboards back on has
     * decided something, and a stored decision is what keeps that answer stable
     * if the default ever changes underneath them.
     *
     * @throws UnknownHideablePageException when the key is not one the catalog
     *                                      knows
     */
    public function setHidden(User $user, string $pageKey, bool $hidden): void
    {
        if (! HideablePageCatalog::knows($pageKey)) {
            throw new UnknownHideablePageException(
                "There is no hideable page named {$pageKey}.",
            );
        }

        HiddenPagePreference::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'page_key' => $pageKey,
            ],
            ['hidden' => $hidden],
        );
    }
}
