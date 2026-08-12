<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Modules\ModuleKey;

/**
 * One row of the UI contract 13 widget inventory (M18.28).
 *
 * The contract's columns, kept as data rather than as prose spread across six
 * compilers: the id, the title, the scope, the permission sentence, the quiet
 * state, and the primary action. Everything here is fixed for Alpha 1 — widget
 * spec 6, "widgets are fixed by role" — so a definition carries no condition and
 * no configuration. What varies is the {@see DashboardWidget} compiled from it,
 * which is where the numbers, the attention level, and the quiet flag live.
 *
 * Two fields have no column in the contract and exist because a definition is
 * read by two different things.
 *
 *  - `evaluation` says whether the node or the device answers the widget. Four
 *    of the six kiosk widgets are the node's; `kiosk.node_status` and
 *    `kiosk.switch_user` are about the machine in front of the person, and a
 *    node reporting on its own reachability to a device that reached it would be
 *    answering the wrong question.
 *  - `deferredTo` names the task that will make a widget answerable, for the
 *    five in the inventory whose domain does not exist yet. Widget spec 4 is
 *    explicit that a widget which cannot answer its required anatomy should not
 *    ship, so those are carried here as inventory and never compiled. Naming the
 *    task is the difference between a gap and an omission.
 *  - `module` names the MOD-002 module whose records the widget reads, or null
 *    where it reads core records (M19.18). The dashboard is one surface
 *    composing every domain in the product, so it is the aggregator MOD-019 is
 *    most about: a widget owned by a module the organization does not run is
 *    omitted from the compiled dashboard rather than reported as quiet, because
 *    "No upcoming shifts" is a statement about a schedule and an organization
 *    without Scheduling does not have one. Undeclared is core, the same safe
 *    direction {@see \App\Domain\Modules\DomainNamespace} takes.
 */
final class DashboardWidgetDefinition
{
    /**
     * @param  string  $id  The contract's widget id, e.g. `staff.current_shift`.
     * @param  string  $scope  The contract's scope column, e.g. `user/event`.
     * @param  string  $permission  The contract's permission sentence.
     * @param  string  $quietState  What the widget says when nothing needs attention.
     * @param  string|null  $actionLabel  The contract's primary action, or null where it has none.
     * @param  string|null  $actionSurface  The UI contract section 12 screen id the action opens.
     * @param  string|null  $deferredTo  The task that will make this widget answerable, when one is outstanding.
     * @param  ModuleKey|null  $module  The module whose records this widget reads, or null where they are core.
     */
    public function __construct(
        public readonly string $id,
        public readonly DashboardWidgetGroup $group,
        public readonly string $title,
        public readonly string $scope,
        public readonly string $permission,
        public readonly string $quietState,
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionSurface = null,
        public readonly DashboardWidgetEvaluation $evaluation = DashboardWidgetEvaluation::Node,
        public readonly ?string $deferredTo = null,
        public readonly ?ModuleKey $module = null,
    ) {}

    /** Whether this widget has a domain behind it yet. */
    public function isAnswerable(): bool
    {
        return $this->deferredTo === null;
    }

    /**
     * The definition's own fields, which every compiled widget carries.
     *
     * The quiet sentence travels whether or not the widget is quiet, so a
     * surface rendering a widget that goes quiet between two reads does not have
     * to invent the reassurance itself.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'id' => $this->id,
            'group' => $this->group->value,
            'title' => $this->title,
            'scope' => $this->scope,
            'quiet_state' => $this->quietState,
            'action_label' => $this->actionLabel,
            'action_surface' => $this->actionSurface,
        ];
    }
}
