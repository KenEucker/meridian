<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Event;
use App\Models\Node;
use App\Services\Node\EventAuthority;
use App\Services\Node\EventAuthorityException;
use App\Services\Node\NodeSetupService;

/**
 * Where and when a branding profile may be edited (M15A.11; BRAND-021).
 *
 * Two refusals, and they are different refusals.
 *
 * **Central authority.** Branding is organization governance data, so central
 * is the node that owns it. An on-site node refuses the edit outright rather
 * than queueing an operation, because event authority moves *event-scoped*
 * records to on-site during the window and branding is not event-scoped; an
 * on-site edit would be a second source of truth for a record central will
 * push down again. A standalone or development node is its own central and is
 * allowed, which is what keeps a single-node install usable.
 *
 * **Edit freeze.** While an organization is inside an active event window,
 * nobody edits its branding — not on-site, not central. This is the same rule
 * {@see \App\Services\Node\GovernanceWriteGuard} applies to policy and
 * procedure content, and it is applied here as an explicit assertion at the
 * branding service rather than as a model-event guard. Organizations and
 * departments carry far more than branding: freezing every write to those two
 * models mid-event would block department creation, IC department selection,
 * and staff administration, none of which BRAND-021 asks to freeze.
 */
class BrandingGovernance
{
    /**
     * Node roles that hold branding authority.
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
     * @throws BrandingAuthorityException when this node may not edit branding
     * @throws EventAuthorityException when the organization is inside its active event window
     */
    public function assertEditable(string $organizationId, string $recordDescription): void
    {
        $this->assertCentralAuthority($recordDescription);
        $this->assertNotFrozen($organizationId, $recordDescription);
    }

    public function isEditable(string $organizationId): bool
    {
        return $this->holdsBrandingAuthority()
            && ! $this->activeEventFor($organizationId) instanceof Event;
    }

    /**
     * The event whose window is blocking branding edits right now, if any.
     * Admin surfaces read this to explain the freeze before an organizer
     * discovers it by having a save refused.
     */
    public function activeEventFor(string $organizationId): ?Event
    {
        return $this->authority->activeEventForOrganization($organizationId);
    }

    public function holdsBrandingAuthority(): bool
    {
        $node = $this->nodes->activeNode();

        // An unconfigured install has no node to disagree with. Refusing here
        // would make branding unreachable during first-run setup, which is
        // exactly when an organization is most likely to set it.
        if (! $node instanceof Node) {
            return true;
        }

        return in_array((string) $node->node_role, self::AUTHORITATIVE_ROLES, true);
    }

    /**
     * @throws BrandingAuthorityException
     */
    private function assertCentralAuthority(string $recordDescription): void
    {
        if ($this->holdsBrandingAuthority()) {
            return;
        }

        throw BrandingAuthorityException::notCentral(
            (string) $this->nodes->activeNode()?->node_role,
            $recordDescription,
        );
    }

    /**
     * @throws EventAuthorityException
     */
    private function assertNotFrozen(string $organizationId, string $recordDescription): void
    {
        $event = $this->activeEventFor($organizationId);

        if (! $event instanceof Event) {
            return;
        }

        throw EventAuthorityException::governanceFrozenDuringActiveEvent($event, $recordDescription);
    }
}
