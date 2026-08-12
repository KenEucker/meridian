<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Domain\Dashboard\DashboardCatalog;
use App\Domain\Dashboard\DashboardWidget;
use App\Domain\Dashboard\DashboardWidgetDefinition;
use App\Domain\Modules\ModuleKey;
use App\Models\Event;
use Illuminate\Support\Carbon;

/**
 * What every widget group's compiler shares (M18.28).
 *
 * The two things worth having in one place are the item cap and the definition
 * lookup. Widget spec 12 caps a list widget and sends the reader to the full
 * list for the rest, so a widget that found forty short shifts shows five and
 * says how many there are; letting each compiler pick its own cap would make
 * the dashboard's density a matter of who wrote which widget.
 */
abstract class DashboardWidgetCompiler
{
    /**
     * How many rows a list widget shows before it defers to its own surface
     * (widget spec 12).
     *
     * Five is a glance. The count in the summary carries the rest, so nothing is
     * hidden — what is capped is how much of it is on a card.
     */
    protected const MAX_ITEMS = 5;

    /**
     * The modules the organization runs, for the one widget that composes
     * others (M19.18).
     *
     * Everything the compiler returns is filtered against this by
     * {@see DashboardService}, so a compiler does not have to remember to check.
     * It is readable here for the case that filter cannot cover: a core widget
     * whose content is a sum over module-owned ones has to leave the missing
     * terms out of the sum rather than out of the response.
     *
     * Everything active until told otherwise, so a compiler constructed in a
     * test without a module set behaves as an organization running everything —
     * which is MOD-009's default and the safe direction.
     *
     * @var list<ModuleKey>
     */
    private array $activeModules = [];

    private bool $modulesKnown = false;

    /**
     * Every widget this compiler is responsible for, whether or not it has
     * something to report.
     *
     * @return list<DashboardWidget>
     */
    abstract public function compile(Event $event, DashboardAudience $audience, Carbon $now): array;

    /**
     * @param  list<ModuleKey>  $modules
     */
    public function composingModules(array $modules): static
    {
        $this->activeModules = $modules;
        $this->modulesKnown = true;

        return $this;
    }

    protected function runs(ModuleKey $module): bool
    {
        return ! $this->modulesKnown || in_array($module, $this->activeModules, true);
    }

    protected function definition(string $id): DashboardWidgetDefinition
    {
        $definition = DashboardCatalog::definition($id);

        if ($definition === null) {
            throw new \LogicException("No dashboard widget is catalogued as {$id}.");
        }

        return $definition;
    }

    /** A widget with nothing to report, in the contract's own words. */
    protected function quiet(string $id): DashboardWidget
    {
        return DashboardWidget::quiet($this->definition($id));
    }

    /**
     * One row of a list widget.
     *
     * `status` is the short canonical label beside the row — an attendance
     * state, an incident priority, a shortfall — and is left off where the row
     * is only a name.
     *
     * @return array<string, mixed>
     */
    protected function item(string $label, ?string $detail = null, ?string $status = null): array
    {
        return [
            'label' => $label,
            'detail' => $detail,
            'status' => $status,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function capped(array $items): array
    {
        return array_slice($items, 0, self::MAX_ITEMS);
    }

    /**
     * `1 shift` / `4 shifts`, so a summary reads as a sentence.
     */
    protected function plural(int $count, string $singular, ?string $plural = null): string
    {
        return $count.' '.($count === 1 ? $singular : ($plural ?? $singular.'s'));
    }

    /**
     * A time in the event's own zone.
     *
     * Everything a dashboard reports happens at the event, and a shift that runs
     * to ten at night reads as ten at night whether the person looking at it is
     * on site or at home in another zone.
     */
    protected function eventTime(Event $event, ?Carbon $at): ?string
    {
        return $at?->copy()->setTimezone($event->timezone ?: config('app.timezone'))->format('D H:i');
    }
}
