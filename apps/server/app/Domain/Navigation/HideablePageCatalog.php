<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

/**
 * The pages a user may hide from their own navigation, and which of them start
 * hidden (M18.69).
 *
 * This is a catalog of *keys*, and that is the whole of what the node knows.
 * There is no route here, no menu position, and no screen list — a page key is
 * an agreed name for a thing the client draws, and the client owns the mapping
 * from that name to the routes it actually hides. The session document says
 * nothing more about navigation than it did before (technical spec 11A.2): what
 * a user *may* reach is still derived from capability codes, and this says only
 * what they have asked not to be shown.
 *
 * The keys are validated against this list on the write, so a preference row
 * cannot name a page that does not exist. A key removed from the catalog leaves
 * its rows behind harmlessly — {@see defaults()} no longer mentions it, so it
 * stops appearing in anybody's resolved list.
 */
final class HideablePageCatalog
{
    /**
     * The dashboards, as one key.
     *
     * Four surfaces answer to it — the personal one, the department's, the
     * organizer's, and Incident Command's — because they are the same page seen
     * from four standings rather than four pages, and somebody who does not
     * want a dashboard does not want the one their role happens to open. A user
     * holding two of those standings would otherwise have to find and switch
     * off the same preference twice.
     *
     * Hidden by default. A dashboard is a second front door to surfaces the
     * navigation already lists, and the reader arriving at an event is better
     * served by the list than by a summary of it.
     */
    public const PAGE_DASHBOARD = 'dashboard';

    /**
     * Every hideable page key, mapped to whether it is hidden for a user who
     * has expressed no preference.
     *
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            self::PAGE_DASHBOARD => true,
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::defaults());
    }

    public static function knows(string $pageKey): bool
    {
        return array_key_exists($pageKey, self::defaults());
    }

    /**
     * The pages a user with these explicit choices has hidden.
     *
     * Defaults fill every gap, so the answer is complete whether the user has
     * decided nothing, one thing, or everything — a client never has to know
     * what the default was in order to render the right state.
     *
     * @param  array<string, bool>  $choices  page key => hidden, for the pages
     *                                        this user has actually decided
     * @return list<string>
     */
    public static function resolve(array $choices): array
    {
        $hidden = [];

        foreach (self::defaults() as $pageKey => $hiddenByDefault) {
            if ($choices[$pageKey] ?? $hiddenByDefault) {
                $hidden[] = $pageKey;
            }
        }

        return $hidden;
    }
}
