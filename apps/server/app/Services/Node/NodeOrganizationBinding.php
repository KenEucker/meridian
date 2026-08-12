<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Models\Node;
use App\Models\Organization;
use App\Services\Modules\ActiveModuleResolver;

/**
 * The organization this node is bound to, when it serves exactly one
 * (technical spec 7.3, 15A.6; MOD-021).
 *
 * Two kinds of Meridian node ask different questions of the same data. Central
 * serves many organizations, so "which organization is this node's" has no
 * answer there and this reports none. A node prepared for one organization —
 * the on-site node at their event, a standalone install a single organization
 * runs — has exactly one organization's context, and that is what a console on
 * it may narrow itself to.
 *
 * It narrows *presentation only*. The rule the module system follows is that
 * enforcement is by organization and console visibility is by node binding
 * (technical spec 15A.6), so nothing here decides what any request may reach:
 * the route gate asks {@see ActiveModuleResolver} about the organization a
 * request names, on a bound node exactly as on central.
 *
 * **Where the binding comes from.** The local node record's `organization_id`
 * is the node's own copy, which is what a setup or pairing flow writes.
 * `meridian.node.organization_id` is the file-config boot default a prepared
 * deployment ships, and the system configuration override path can change it on
 * the fly, which is technical spec 7.3's file-first, database-second order.
 * The record wins where both are set, because it is the node saying what it is
 * rather than the image it was built from.
 *
 * **An id naming no organization is not a binding.** A deployment carrying a
 * stale id — an organization deleted, a config copied between installs — is
 * reported unbound rather than bound to nothing. That is the same safe
 * direction MOD-009's un-stated default takes: the failure mode of a value
 * nobody maintained is a console that shows too much, not one that hides a
 * module an operator came here to turn back on.
 */
class NodeOrganizationBinding
{
    /**
     * Resolved once per instance. The console asks for every menu item on every
     * page render, and the answer cannot change inside one request.
     */
    private bool $resolved = false;

    private ?string $organizationId = null;

    public function __construct(private readonly NodeSetupService $nodes) {}

    public function isBound(): bool
    {
        return $this->organizationId() !== null;
    }

    /**
     * The bound organization's id, or null on a node that serves many.
     */
    public function organizationId(): ?string
    {
        if (! $this->resolved) {
            $this->organizationId = $this->read();
            $this->resolved = true;
        }

        return $this->organizationId;
    }

    /**
     * The bound organization itself, for a surface that has to name it.
     */
    public function organization(): ?Organization
    {
        $organizationId = $this->organizationId();

        return $organizationId === null
            ? null
            : Organization::query()->find($organizationId);
    }

    private function read(): ?string
    {
        $node = $this->nodes->activeNode();

        $declared = $node instanceof Node && $node->organization_id !== null
            ? (string) $node->organization_id
            : trim((string) config('meridian.node.organization_id'));

        if ($declared === '') {
            return null;
        }

        return Organization::query()->whereKey($declared)->exists()
            ? $declared
            : null;
    }
}
