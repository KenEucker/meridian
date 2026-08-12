<?php

declare(strict_types=1);

namespace App\Domain\EventHorizon;

use App\Domain\Modules\ModuleKey;

/**
 * The registration of one item kind (HORIZON-003; technical spec 21D.2).
 *
 * Registration carries the kind's identity, the domain it draws on, the module
 * that owns it, and its place in the fixed catalogue order. What it does not
 * carry is anything an organization could edit: there is no
 * `event_horizon_item_kinds` table, no per-organization threshold, and no
 * per-organization ordering (data/API 10.21), because a configurable catalogue
 * would let an organization describe readiness differently from what Meridian
 * enforces — the disagreement 21D.1 exists to prevent. A sixth kind is a code
 * change and a requirements change, in that order.
 */
final class EventHorizonItemKindDefinition
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        /** The MOD-002 module that owns the domain this kind reads (21D.9). */
        public readonly string $module,
        /** The requirement rows this kind's evaluation restates (HORIZON-003). */
        public readonly string $governedBy,
    ) {}

    /**
     * The owning module as the catalogue enum, or null where this build's
     * catalogue does not list the key.
     *
     * Null reads as core, which is the same direction {@see \App\Domain\Modules\DomainNamespace}
     * takes for an undeclared namespace: a kind whose module cannot be resolved
     * stays available rather than disappearing from every organization.
     */
    public function moduleKey(): ?ModuleKey
    {
        return ModuleKey::tryFrom($this->module);
    }

    /**
     * @return array<string, string>
     */
    public function describe(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'module' => $this->module,
            'governed_by' => $this->governedBy,
        ];
    }
}
