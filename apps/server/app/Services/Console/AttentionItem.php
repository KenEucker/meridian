<?php

declare(strict_types=1);

namespace App\Services\Console;

/**
 * One thing on the God Mode landing screen that is asking for a human
 * (GOD-005, GOD-009).
 *
 * An item exists only when something needs attention. There is no "passing"
 * item: a healthy deployment produces an empty list, which is what lets the
 * landing screen say all-clear instead of rendering a wall of green ticks
 * (GOD-011).
 *
 * Every item carries the route that resolves it, because an attention list that
 * names a problem without naming the screen that fixes it makes an operator
 * hunt through the console for the right form (GOD-009).
 */
final class AttentionItem
{
    /**
     * @param  string  $key  Stable identifier for tests and for callers that
     *                       need to recognise a specific finding.
     * @param  string  $label  Short statement of what is wrong.
     * @param  string  $detail  What the current state actually is, in the
     *                          operator's terms.
     * @param  string  $resolveRoute  Route name of the screen that resolves it.
     * @param  array<string, mixed>  $resolveRouteParameters
     * @param  string  $resolveLabel  What that screen is called.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $detail,
        public readonly string $resolveRoute,
        public readonly array $resolveRouteParameters = [],
        public readonly string $resolveLabel = 'Open',
    ) {}

    public function resolveUrl(): string
    {
        return route($this->resolveRoute, $this->resolveRouteParameters);
    }

    /**
     * @return array{key: string, label: string, detail: string, resolve_label: string, resolve_url: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'detail' => $this->detail,
            'resolve_label' => $this->resolveLabel,
            'resolve_url' => $this->resolveUrl(),
        ];
    }
}
