<?php

declare(strict_types=1);

namespace App\Domain\EventHorizon;

use Illuminate\Support\Carbon;

/**
 * One item on one staff member's readiness list (HORIZON-004; technical spec
 * 21D.2).
 *
 * The registration contract fixes what an item carries: a stable identity for
 * the underlying record, a state, the evaluation behind that state in
 * operational language, what would complete it, a deadline where the kind has
 * one, and an action link to the surface that resolves it. All of the words are
 * composed here on the node, so two clients cannot describe the same condition
 * differently — the client renders what it is sent and re-derives nothing
 * (CLIENT-006).
 *
 * The action link is a UI contract section 12 screen id plus the route
 * parameters that name the record, not authority: following it enters the
 * linked surface under that surface's own authorization (HORIZON-004), exactly
 * as 21C.7 requires of metric links.
 */
final class EventHorizonItem
{
    /**
     * @param  array<string, string>  $actionParams
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $identity,
        public readonly EventHorizonItemState $state,
        public readonly string $title,
        public readonly string $evaluation,
        public readonly string $completion,
        public readonly ?Carbon $dueAt,
        public readonly string $actionSurface,
        public readonly string $actionLabel,
        public readonly array $actionParams = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'identity' => $this->identity,
            'state' => $this->state->value,
            'title' => $this->title,
            'evaluation' => $this->evaluation,
            'completion' => $this->completion,
            'due_at' => $this->dueAt?->toIso8601String(),
            'action' => [
                'surface' => $this->actionSurface,
                'label' => $this->actionLabel,
                'params' => (object) $this->actionParams,
            ],
        ];
    }
}
