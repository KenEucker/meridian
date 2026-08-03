<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Models\Event;
use App\Models\Node;
use App\Services\Branding\BrandingGovernance;
use App\Services\Node\EventAuthority;
use App\Services\Node\EventAuthorityException;
use App\Services\Node\NodeSetupService;

/**
 * Where and when organization configuration may be edited (M18.14; ORG-021).
 *
 * The same two refusals {@see BrandingGovernance}
 * makes, because ORG-021 names BRAND-021's rules as the ones that apply here.
 *
 * **Central authority.** Configuration is organization governance data, so
 * central is the node that owns it. An on-site node refuses the edit outright
 * rather than queueing an operation: event authority moves *event-scoped*
 * records to on-site during the window and organization configuration is not
 * event-scoped. A standalone or development node is its own central and is
 * allowed, which is what keeps a single-node install configurable.
 *
 * **Edit freeze.** While an organization is inside an active event window,
 * nobody edits its configuration — not on-site, not central. The grace period
 * decides when an event's hours freeze and the thresholds decide who goes
 * inactive; changing either mid-event would change the rules of an event that
 * is already being played by them. Applied as an explicit assertion at the
 * configuration service rather than a model-event guard, for the reason
 * BrandingGovernance gives: organizations carry far more than configuration,
 * and freezing every write to the model mid-event would block work ORG-021
 * never asked to freeze.
 */
final class OrganizationConfigurationGovernance
{
    /**
     * Node roles that hold configuration authority.
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
     * @throws OrganizationConfigurationException when this node may not edit configuration
     * @throws EventAuthorityException when the organization is inside its active event window
     */
    public function assertEditable(string $organizationId): void
    {
        if (! $this->holdsConfigurationAuthority()) {
            throw OrganizationConfigurationException::notCentral(
                (string) $this->nodes->activeNode()?->node_role,
            );
        }

        $event = $this->activeEventFor($organizationId);

        if ($event instanceof Event) {
            throw EventAuthorityException::governanceFrozenDuringActiveEvent(
                $event,
                'organization configuration',
            );
        }
    }

    public function isEditable(string $organizationId): bool
    {
        return $this->holdsConfigurationAuthority()
            && ! $this->activeEventFor($organizationId) instanceof Event;
    }

    /**
     * The event whose window is blocking configuration edits right now, if
     * any. The configuration surface reads this to explain the freeze before
     * an organizer discovers it by having a save refused.
     */
    public function activeEventFor(string $organizationId): ?Event
    {
        return $this->authority->activeEventForOrganization($organizationId);
    }

    public function holdsConfigurationAuthority(): bool
    {
        $node = $this->nodes->activeNode();

        // An unconfigured install has no node to disagree with. Refusing here
        // would make configuration unreachable during first-run setup, which
        // is exactly when an organization is most likely to set it.
        if (! $node instanceof Node) {
            return true;
        }

        return in_array((string) $node->node_role, self::AUTHORITATIVE_ROLES, true);
    }
}
