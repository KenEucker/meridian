<?php

declare(strict_types=1);

namespace App\Services\Navigation;

use App\Domain\Navigation\HideablePageCatalog;
use App\Domain\Navigation\MenuPageCatalog;
use App\Models\HiddenPagePreference;
use App\Models\MenuPagePreference;
use App\Models\User;

/**
 * Reads and writes a user's own navigation preferences (M18.69).
 *
 * Every method here takes the user whose preference it is and no other subject,
 * because there is no other answer to "whose": a person decides what their own
 * menu shows, and nobody — organizer, lead, or administrator — decides it for
 * them. That is not a permission check this service performs. It is a shape it
 * refuses to have.
 *
 * Two preferences live here and they answer different questions. A hidden page
 * is one the reader has put away — gone from the menus and off the home
 * directory. A page kept out of the menus is still on Home, still linked, and
 * still reachable by address; the reader has said only that they do not work
 * out of it. Neither is applied here. The node stores both answers and hands
 * them back on the session document, and the client is what draws a menu.
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

    /**
     * The pages this user has kept out of their menus, with catalog defaults
     * filling anything they have not decided.
     *
     * Pages put away entirely are not folded in here, and the omission is
     * deliberate. These are two answers to two questions, and a client that
     * received them pre-mixed could no longer render the second one honestly —
     * Settings would show a page as "off the menu" when what the reader
     * actually did was hide it everywhere, and switching it back on would
     * appear to do nothing.
     *
     * @return list<string>
     */
    public function menuHiddenPagesFor(User $user): array
    {
        $choices = MenuPagePreference::query()
            ->where('user_id', $user->getKey())
            ->pluck('hidden', 'page_key')
            ->map(fn ($hidden): bool => (bool) $hidden)
            ->all();

        return MenuPageCatalog::resolve($choices);
    }

    /**
     * Record this user's decision about whether one page appears in their
     * menus.
     *
     * The row is written for both answers, on the same reasoning as
     * {@see setHidden()}: a reader who puts a page back has decided something,
     * and a stored decision is what keeps that answer stable if the default
     * ever changes underneath them.
     *
     * @throws UnknownHideablePageException when the key is not one the menu
     *                                      catalog knows
     */
    public function setMenuHidden(User $user, string $pageKey, bool $hidden): void
    {
        if (! MenuPageCatalog::knows($pageKey)) {
            throw new UnknownHideablePageException(
                "There is no menu page named {$pageKey}.",
            );
        }

        MenuPagePreference::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'page_key' => $pageKey,
            ],
            ['hidden' => $hidden],
        );
    }
}
