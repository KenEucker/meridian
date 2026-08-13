<?php

namespace App\Services\Marketing;

use App\Models\Node;
use App\Services\Node\NodeConfigResolver;
use App\Services\Node\NodeSetupService;
use App\Services\Organizations\OrganizationHostContext;

/**
 * Whether this request serves the public marketing surface (M18.23; PUBLIC-001,
 * PUBLIC-006; M19.8, ORG-024).
 *
 * Two nodes do not: an on-site node, and a deployable node locked to an event.
 *
 * They are refused for the same reason, from two directions. An on-site node is
 * a laptop on an event's own network, reachable by the people working that
 * event and by whoever else is on that Wi-Fi; it exists to run one event and
 * holds one event's records (technical spec 8.3, 8.7). A page inviting a
 * prospective organization to get in touch is not what that machine is for, and
 * serving it there would put a public form on a network Meridian went to some
 * trouble to keep operational. The event lock says the same thing about a
 * deployable node of any role: a node holding one event's records is deployed
 * to that event, so the marketing surface is out of reach there too, whatever
 * the role column says.
 *
 * The development node is the one exception to the lock half (M20, by product
 * owner direction). The seeded development scenario names an event on the
 * local node so field surfaces resolve — kiosk context, field sessions, the
 * Event Horizon — which made the deployment root's normal flow, the landing
 * page, unreachable in every seeded development environment without manually
 * clearing the lock first. That event context is scenario scaffolding, not a
 * deployment: a development node is its own central, and nothing production
 * ships carries the role. Every deployable role — central, standalone,
 * on-site — keeps the PUBLIC-006 refusal exactly.
 *
 * The role is read the way {@see \App\Services\EventMode\EventModeGuard} reads
 * it — through the node config resolver — so a node configured on-site by
 * environment before any node row exists is on-site here as well. A node that
 * has not been set up at all is not on-site and is not locked, so a fresh
 * install serves the surface; that is the deployment the requirement is
 * written for.
 *
 * An organization subdomain does not serve it either, on any node (ORG-024):
 * the marketing surface remains at the deployment root, and a host that
 * resolves an organization is that organization's address, not Meridian's. The
 * same node keeps serving the surface at its root host.
 */
class MarketingSurface
{
    public function __construct(
        private readonly NodeSetupService $nodes,
        private readonly NodeConfigResolver $configResolver,
        private readonly OrganizationHostContext $hostContext,
    ) {}

    public function isServed(): bool
    {
        return ! $this->hostContext->isOrganizationHost()
            && ! $this->isOnSiteNode()
            && ! ($this->isLockedToEvent() && ! $this->isDevelopmentNode());
    }

    /**
     * Refuse a request for the surface on a node that does not serve it.
     *
     * A 404 rather than a 403: on a node that does not serve it, the marketing
     * surface is not a page the visitor was denied, it is a page that is not
     * there.
     */
    public function abortUnlessServed(): void
    {
        abort_unless($this->isServed(), 404);
    }

    public function isOnSiteNode(): bool
    {
        return $this->effectiveNodeRole() === Node::ROLE_ONSITE;
    }

    public function isLockedToEvent(): bool
    {
        return $this->nodes->activeNode()?->event_id !== null;
    }

    /**
     * Whether this node's effective role is development — the one role whose
     * event context is scenario scaffolding rather than a deployment, and the
     * one node that serves the surface while holding one.
     */
    public function isDevelopmentNode(): bool
    {
        return $this->effectiveNodeRole() === Node::ROLE_DEVELOPMENT;
    }

    private function effectiveNodeRole(): string
    {
        $node = $this->nodes->activeNode();

        if ($node instanceof Node && is_string($node->node_role) && $node->node_role !== '') {
            return $node->node_role;
        }

        foreach ($this->configResolver->valuesFor($node) as $value) {
            if ($value['key'] === 'node_role') {
                $role = $value['value'];

                return is_string($role) && $role !== '' ? $role : Node::ROLE_DEVELOPMENT;
            }
        }

        return Node::ROLE_DEVELOPMENT;
    }
}
