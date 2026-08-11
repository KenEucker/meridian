<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

/**
 * The pages a user may take out of their own menus, and which of them start out
 * of them (M18.69).
 *
 * A catalog of *keys*, on the same terms as {@see HideablePageCatalog} next
 * door: no route, no menu position, no screen list. What separates the two is
 * the question they answer rather than the machinery they use.
 *
 *  - `HideablePageCatalog` answers "which pages has this reader put away", and
 *    a page named there is gone from the menus and off the home directory too.
 *  - This one answers "which pages has this reader kept out of the menus", and
 *    a page named here is still on Home. It is a shorter menu, not a smaller
 *    product — the reader who trims Logistics out of a menu they never open it
 *    from still reaches it from Home, from a link, and from the address bar.
 *
 * `dashboard` appears in both catalogs and means the same page in each, which
 * is the point of a shared vocabulary: one page, two questions somebody may
 * answer about it. Nothing here reads the other catalog's answer — a page put
 * away entirely is already gone from the menus, so the two never disagree about
 * anything a reader can see.
 *
 * Every key ships shown, and the defaults machinery is here anyway for the same
 * reason it is next door: a page can be given a menu-absent default later
 * without a data migration, and a stored decision keeps a reader's answer
 * stable when a default moves underneath them.
 */
final class MenuPageCatalog
{
    /** Personal pages, in the order the Staff menu builds them. */
    public const PAGE_ME = 'me';

    public const PAGE_EVENT_HORIZON = 'event-horizon';

    public const PAGE_EVENT_INFO = 'event-info';

    /**
     * The dashboards, as one key — the same four surfaces
     * {@see HideablePageCatalog::PAGE_DASHBOARD} covers, for the same reason.
     */
    public const PAGE_DASHBOARD = 'dashboard';

    public const PAGE_SHIFT_BOARD = 'shift-board';

    public const PAGE_FIELD_REPORTS = 'field-reports';

    public const PAGE_TRAININGS = 'trainings';

    /** Workflow hubs, in the order the Workflows menu builds them. */
    public const PAGE_DEPARTMENT_OVERVIEW = 'department-overview';

    public const PAGE_TEAM_OVERVIEW = 'team-overview';

    public const PAGE_PLANNING = 'planning';

    public const PAGE_LOGISTICS = 'logistics';

    public const PAGE_OPERATIONS = 'operations';

    public const PAGE_INCIDENTS = 'incidents';

    public const PAGE_ADMIN = 'admin';

    /**
     * Every menu page key, mapped to whether it is out of the menus for a user
     * who has expressed no preference.
     *
     * All of them start in. A reader arriving at their first event should be
     * shown the whole of what they may work out of; a menu somebody has to
     * assemble before it is useful is a menu that was empty when it mattered.
     *
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            self::PAGE_ME => false,
            self::PAGE_EVENT_HORIZON => false,
            self::PAGE_EVENT_INFO => false,
            self::PAGE_DASHBOARD => false,
            self::PAGE_SHIFT_BOARD => false,
            self::PAGE_FIELD_REPORTS => false,
            self::PAGE_TRAININGS => false,
            self::PAGE_DEPARTMENT_OVERVIEW => false,
            self::PAGE_TEAM_OVERVIEW => false,
            self::PAGE_PLANNING => false,
            self::PAGE_LOGISTICS => false,
            self::PAGE_OPERATIONS => false,
            self::PAGE_INCIDENTS => false,
            self::PAGE_ADMIN => false,
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
     * The pages a user with these explicit choices has kept out of their menus.
     *
     * Defaults fill every gap, so the answer is complete whether the user has
     * decided nothing, one thing, or everything — a client never has to know
     * what the default was in order to render the right state.
     *
     * @param  array<string, bool>  $choices  page key => hidden from the menus,
     *                                        for the pages this user has
     *                                        actually decided
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
