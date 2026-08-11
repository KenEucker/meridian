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
 * `dashboard` appears in both catalogs, and the difference between what it
 * means in each is worth stating. Next door it is the dashboard as an idea, all
 * four surfaces of it, because somebody who does not want a dashboard does not
 * want whichever one their role opens. Here it is the two that a menu can
 * carry — the personal one and the department's — and Incident Command's is its
 * own key below, because a reader can put that one in a menu and it is a
 * destination they choose rather than the one their role hands them. Nothing
 * here reads the other catalog's answer: a page put away entirely is already
 * gone from the menus, so the two never disagree about anything a reader sees.
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

    /*
     * Pages that are on Home and not in a menu, which a reader may put in one.
     *
     * The four below are the only Home-only pages offered, and what they have
     * in common is that they sit beside a menu entry already: two are personal
     * pages listed next to Me, and two are Incident Command pages reached from
     * inside the Incidents workspace. Somebody who reads policies daily, or who
     * lives on the IC dashboard for a weekend, is asking for one of these to be
     * a hub — which is the same request the entries above answer.
     *
     * Department and organization administration is deliberately not here. A
     * menu names places somebody works out of for a stretch of the event, and
     * an organizer holding thirty administration pages needs a directory rather
     * than a longer menu.
     */
    public const PAGE_ACKNOWLEDGMENTS = 'acknowledgments';

    public const PAGE_DOCUMENTS = 'documents';

    public const PAGE_IC_DASHBOARD = 'ic-dashboard';

    public const PAGE_IMS_FIELD_REPORTS = 'ims-field-reports';

    /**
     * Every menu page key, mapped to whether it is out of the menus for a user
     * who has expressed no preference.
     *
     * The hub pages start in. A reader arriving at their first event should be
     * shown the whole of what they may work out of; a menu somebody has to
     * assemble before it is useful is a menu that was empty when it mattered.
     *
     * The four Home-only pages start out, which is where they are today. Adding
     * one is a reader saying they work out of it, and defaulting them in would
     * lengthen every menu in the product to answer a request nobody made.
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
            self::PAGE_ACKNOWLEDGMENTS => true,
            self::PAGE_DOCUMENTS => true,
            self::PAGE_IC_DASHBOARD => true,
            self::PAGE_IMS_FIELD_REPORTS => true,
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
