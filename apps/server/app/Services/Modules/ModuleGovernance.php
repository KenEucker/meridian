<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Models\Event;
use App\Models\Node;
use App\Services\Node\EventAuthority;
use App\Services\Node\EventAuthorityException;
use App\Services\Node\NodeSetupService;

/**
 * Where and when an organization's module state may be changed (MOD-010;
 * technical spec 15A.3; data/API 10.1A).
 *
 * Two refusals, and they are different refusals.
 *
 * **Central authority.** Module state is organization governance data, so
 * central is the node that owns it. A standalone or development node is its own
 * central and is allowed, which is what keeps a single-node install usable.
 *
 * **Edit freeze.** While an organization is inside an active event window,
 * nobody changes its module state — not God Mode on central, not an organizer
 * on-site. Technical spec 15A.3 puts module state under the same rule as
 * organization configuration, and 15A.8 leans on it: a module that cannot go
 * inactive mid-event is a module whose records cannot vanish from under a
 * device that is holding them, which is what makes MOD-017's outbox conflict a
 * rare edge rather than the ordinary consequence of a busy weekend.
 *
 * MOD-010 names entitlement *and* enablement, so this guard sits below both
 * writers rather than on either surface. God Mode entitlement (M19.13) is the
 * first through it; organizer enablement (M19.15) is the second.
 *
 * Modelled on {@see \App\Services\Branding\BrandingGovernance}, which applies
 * the same pair of rules to the branding profile, and for the same reason it
 * does: an assertion at the service rather than a model-event guard, so
 * freezing module state does not freeze every other write to the organization.
 */
class ModuleGovernance
{
    /**
     * Node roles that hold module state authority.
     *
     * @var list<string>
     */
    private const AUTHORITATIVE_ROLES = [
        Node::ROLE_CENTRAL,
        Node::ROLE_STANDALONE,
        Node::ROLE_DEVELOPMENT,
    ];

    public function __construct(
        private readonly NodeSetupService $nodes,
        private readonly EventAuthority $authority,
    ) {}

    /**
     * @throws ModuleAuthorityException when this node may not change module state
     * @throws EventAuthorityException when the organization is inside its active event window
     */
    public function assertEditable(string $organizationId, string $organizationName): void
    {
        $this->assertCentralAuthority($organizationName);
        $this->assertNotFrozen($organizationId, $organizationName);
    }

    public function isEditable(string $organizationId): bool
    {
        return $this->holdsModuleAuthority()
            && ! $this->activeEventFor($organizationId) instanceof Event;
    }

    /**
     * The event whose window is blocking module changes right now, if any. The
     * console reads this to explain the freeze on the screen, rather than
     * letting an operator discover it by having a save refused.
     */
    public function activeEventFor(string $organizationId): ?Event
    {
        return $this->authority->activeEventForOrganization($organizationId);
    }

    public function holdsModuleAuthority(): bool
    {
        $node = $this->nodes->activeNode();

        // An unconfigured install has no node to disagree with. Refusing here
        // would make module state unreachable during first-run setup, which is
        // when an operator is most likely to be deciding what an organization
        // runs.
        if (! $node instanceof Node) {
            return true;
        }

        return in_array((string) $node->node_role, self::AUTHORITATIVE_ROLES, true);
    }

    /**
     * @throws ModuleAuthorityException
     */
    private function assertCentralAuthority(string $organizationName): void
    {
        if ($this->holdsModuleAuthority()) {
            return;
        }

        throw ModuleAuthorityException::notCentral(
            (string) $this->nodes->activeNode()?->node_role,
            $organizationName,
        );
    }

    /**
     * @throws EventAuthorityException
     */
    private function assertNotFrozen(string $organizationId, string $organizationName): void
    {
        $event = $this->activeEventFor($organizationId);

        if (! $event instanceof Event) {
            return;
        }

        throw EventAuthorityException::moduleStateFrozenDuringActiveEvent($event, $organizationName);
    }
}
