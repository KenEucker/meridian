<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Domain\Dashboard\DashboardCatalog;
use App\Domain\Dashboard\DashboardWidget;
use App\Domain\Dashboard\DashboardWidgetGroup;
use App\Domain\Modules\ModuleKey;
use App\Models\Event;
use App\Models\User;
use App\Services\Modules\ActiveModuleResolver;
use Illuminate\Support\Carbon;

/**
 * Compiles one reader's dashboard for one event (M18.28; UI contract 13.1
 * through 13.6).
 *
 * The whole dashboard is compiled on read and nothing about it is stored. There
 * is no widget table, no per-user layout, and no cached result: widget spec 6
 * fixes the inventory by role for Alpha 1, so there is nothing a person could
 * have configured, and every number on it is true only for the moment it was
 * asked for.
 *
 * Groups are compiled in contract order — staff, department lead, department
 * operations, organizer, IC, kiosk — and a group the reader does not hold is
 * absent rather than empty. CLIENT-005 is the reason: an unavailable thing is
 * absent, not disabled, and an empty department group would tell somebody with
 * no standing in that department how many shifts it has trouble with.
 *
 * The dashboard is the widest aggregator in the product — one surface reading
 * every domain — so MOD-019 lands here squarely (M19.18). A widget whose module
 * the organization does not run is dropped from the compiled list, for the same
 * reason a group the reader does not hold is: a quiet "No upcoming shifts" card
 * is a statement about a schedule, and an organization without Scheduling has
 * none to be quiet about. The endpoint itself is core and never refuses on
 * module state (data/API 5.9) — a dashboard with fewer cards on it is still a
 * dashboard.
 */
final class DashboardService
{
    public function __construct(private readonly ActiveModuleResolver $modules) {}

    /**
     * Every widget this reader may see, in contract order.
     *
     * @return list<DashboardWidget>
     */
    public function compile(User $user, Event $event, DashboardAudience $audience, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $active = $this->modules->activeFor((string) $event->organization_id);
        $widgets = [];

        foreach ($audience->groups() as $group) {
            foreach ($this->compilerFor($group, $user, $active)->compile($event, $audience, $now) as $widget) {
                $module = $widget->definition->module;

                if ($module !== null && ! in_array($module, $active, true)) {
                    continue;
                }

                $widgets[] = $widget;
            }
        }

        return $widgets;
    }

    /**
     * The inventory a surface reads to know what it is not being sent.
     *
     * Carried on the response rather than compiled into the client, because the
     * five deferred widgets and the two the device answers are facts about this
     * node's build. A client holding its own copy would go on describing a
     * Briefing widget as "coming with M15.6" after M15.6 landed.
     *
     * @return list<array<string, mixed>>
     */
    public function inventory(): array
    {
        return DashboardCatalog::describe();
    }

    /**
     * A compiler per group, built here rather than injected.
     *
     * They hold no state worth sharing between requests and only one of them
     * takes a dependency at all — the staff group reads the caller's own
     * acknowledgments, which are recorded against the user rather than against a
     * staff profile.
     *
     * The active module set is handed to each of them because dropping a
     * compiled widget is not always enough: `kiosk.current_tasks` is a core
     * widget whose whole content is the sum of two module-owned ones, so it has
     * to know not to count what it will not be sent (MOD-019).
     *
     * @param  list<ModuleKey>  $active
     */
    private function compilerFor(
        DashboardWidgetGroup $group,
        User $user,
        array $active,
    ): DashboardWidgetCompiler {
        $compiler = match ($group) {
            DashboardWidgetGroup::Staff => new StaffDashboardWidgets($user),
            DashboardWidgetGroup::DepartmentLead => new DepartmentLeadDashboardWidgets,
            DashboardWidgetGroup::DepartmentOperations => new DepartmentOperationsDashboardWidgets,
            DashboardWidgetGroup::Organizer => new OrganizerDashboardWidgets,
            DashboardWidgetGroup::IncidentCommand => new IncidentCommandDashboardWidgets,
            DashboardWidgetGroup::Kiosk => new KioskDashboardWidgets,
        };

        return $compiler->composingModules($active);
    }
}
