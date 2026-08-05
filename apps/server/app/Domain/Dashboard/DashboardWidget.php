<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * One compiled widget: a definition with this event's answer in it (M18.28).
 *
 * The required anatomy of widget spec 4, minus the parts that are fixed by the
 * definition. What is added here is the attention level, whether the widget is
 * quiet, the sentence it leads with, and the small capped list behind it (widget
 * spec 12, "cap visible items; provide a clear route to the full list").
 *
 * A quiet widget carries no items, no metric, and no summary. That is not an
 * empty payload standing in for a missing one — it is the widget's answer, and
 * the quiet sentence it prints comes from the contract rather than from the
 * compiler, so two quiet widgets of the same kind cannot word it differently.
 */
final class DashboardWidget
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{value: int, label: string}|null  $metric
     */
    private function __construct(
        public readonly DashboardWidgetDefinition $definition,
        public readonly DashboardAttention $attention,
        public readonly bool $quiet,
        public readonly ?string $summary,
        public readonly array $items,
        public readonly ?array $metric,
    ) {}

    /**
     * Nothing needs attention here.
     *
     * The attention level is always Routine: a widget that has found nothing is
     * not a widget with a mild problem.
     */
    public static function quiet(DashboardWidgetDefinition $definition): self
    {
        return new self(
            definition: $definition,
            attention: DashboardAttention::Routine,
            quiet: true,
            summary: null,
            items: [],
            metric: null,
        );
    }

    /**
     * Something to report, at a stated attention level.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array{value: int, label: string}|null  $metric
     */
    public static function reporting(
        DashboardWidgetDefinition $definition,
        DashboardAttention $attention,
        string $summary,
        array $items = [],
        ?array $metric = null,
    ): self {
        return new self(
            definition: $definition,
            attention: $attention,
            quiet: false,
            summary: $summary,
            items: $items,
            metric: $metric,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->definition->describe(),
            'attention' => $this->attention->value,
            'attention_label' => $this->attention->label(),
            'quiet' => $this->quiet,
            'summary' => $this->summary,
            'items' => $this->items,
            'metric' => $this->metric,
        ];
    }
}
