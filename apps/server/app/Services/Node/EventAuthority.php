<?php

namespace App\Services\Node;

use App\Models\Event;
use App\Models\Node;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Decides which node is authoritative for an event's event-scoped records
 * (technical spec 10.2; data/API 7.4).
 *
 * An event moves through three phases. Before it starts, central prepares event
 * and governance data and pushes it to on-site. During the active event window,
 * the on-site primary node is authoritative for event-scoped records, central is
 * read-only for that event except for data arriving from the on-site primary
 * node, and edits not from the on-site primary node are refused. After the event
 * closes, post-event corrections happen on central.
 *
 * This service answers one question — who may write this event's records right
 * now — and it answers it the same way for both write paths. A user editing on
 * this node and an operation arriving from a peer are the same question asked
 * about a different node, so both resolve through {@see authoritativeNodeFor()}
 * rather than through two rules that could drift apart.
 *
 * Scope. Only the active event window is enforced here, which is what M12.6
 * asks for. Preparation and closed phases are reported but impose no refusal:
 * the specification says on-site *may receive* updates before the event starts
 * and *should no longer* edit event records after it closes, neither of which
 * describes an edit central refuses, and turning either into a refusal would
 * block writes the rest of Alpha 1 currently depends on. Disagreement between
 * local and remote state is recorded by {@see SyncConflictService} and settled
 * by {@see SyncConflictResolver}, which reads the same window to recommend a
 * default (technical spec 10.3).
 *
 * The window is also read by {@see GovernanceWriteGuard}, which freezes
 * policy/procedure and fragment edits while it is open (M12.7). That is a
 * different rule reading the same clock: authority moves event-scoped writes to
 * one node, whereas frozen governance content may not be edited on any node.
 *
 * Two conditions deliberately leave authority unenforced:
 *
 *   - An event with no `active_event_window_starts_at` never hands authority
 *     over, because nothing records when on-site would take it. An organizer who
 *     has not set a window has not declared an event window at all.
 *   - An event whose active window is running but which has no known on-site
 *     primary node leaves central writable. Authority is a handover to another
 *     node, and there is no node to hand it to; refusing here would make an
 *     event unmanageable on the only node that can reach it.
 */
class EventAuthority
{
    /** Central prepares and pushes event data; the window has not started. */
    public const PHASE_PREPARATION = 'preparation';

    /** The on-site primary node is authoritative for event-scoped records. */
    public const PHASE_ACTIVE = 'active';

    /** The window has ended; post-event corrections happen on central. */
    public const PHASE_CLOSED = 'closed';

    public function __construct(private readonly NodeSetupService $nodes) {}

    /**
     * Where this event sits in its authority lifecycle.
     *
     * An archived event is never active: archiving is how an event stops being
     * operational, and a stale window on an archived event should not hand
     * authority to a node that is no longer running it.
     */
    public function phaseFor(Event $event, ?DateTimeInterface $at = null): string
    {
        $startsAt = $event->active_event_window_starts_at;

        if ($startsAt === null || $event->isArchived()) {
            return self::PHASE_PREPARATION;
        }

        $now = CarbonImmutable::instance($at ?? now());

        if ($now->lessThan($startsAt)) {
            return self::PHASE_PREPARATION;
        }

        $endsAt = $event->active_event_window_ends_at;

        // An open-ended window stays active. The window closes when someone
        // records that it closed, not because an end time was never entered.
        if ($endsAt !== null && $now->greaterThan($endsAt)) {
            return self::PHASE_CLOSED;
        }

        return self::PHASE_ACTIVE;
    }

    public function isActive(Event $event, ?DateTimeInterface $at = null): bool
    {
        return $this->phaseFor($event, $at) === self::PHASE_ACTIVE;
    }

    /**
     * The node that may write this event's event-scoped records right now, or
     * null when no node holds exclusive authority and nothing is refused.
     */
    public function authoritativeNodeFor(Event $event, ?DateTimeInterface $at = null): ?Node
    {
        if (! $this->isActive($event, $at)) {
            return null;
        }

        return $this->onsitePrimaryNodeFor($event);
    }

    /**
     * The organization's event whose active window is open right now, or null
     * when the organization is not running an event.
     *
     * Governance content — policies, procedures, and fragments — is scoped to an
     * organization, department, or team rather than to an event, so the question
     * "is an event running?" has to be asked of the organization that owns the
     * content. An install may host more than one organization, and another
     * organization's event says nothing about this organization's documents.
     *
     * Candidates are narrowed in SQL only by what cannot make an event active at
     * all; whether the window is open is decided by {@see isActive()}, so an
     * event's phase has one definition rather than one here and one in a query.
     */
    public function activeEventForOrganization(?string $organizationId, ?DateTimeInterface $at = null): ?Event
    {
        if ($organizationId === null || $organizationId === '') {
            return null;
        }

        return Event::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('active_event_window_starts_at')
            ->whereNull('archived_at')
            ->orderBy('active_event_window_starts_at')
            ->get()
            ->first(fn (Event $event): bool => $this->isActive($event, $at));
    }

    /**
     * The on-site primary node for this event, as this install knows it.
     *
     * Alpha 1 has exactly one active on-site node per event (technical spec
     * 10.1), and `nodes.event_id` is how an on-site node records which event it
     * serves. Pairing does not carry an event, so an on-site node that has not
     * named one is accepted as the on-site node of the single event this
     * install is running; one that has named an event is authoritative only for
     * that event. A node that names the event is preferred over one that names
     * nothing.
     *
     * A peer must have completed pairing before it can hold authority, for the
     * same reason a peer must have completed pairing before it can exchange
     * operations (technical spec 7.3): pairing is what establishes that this
     * install accepts that node at all.
     */
    public function onsitePrimaryNodeFor(Event $event): ?Node
    {
        $candidates = Node::query()
            ->active()
            ->where('node_role', Node::ROLE_ONSITE)
            ->where(function ($query) use ($event): void {
                $query->whereNull('event_id')->orWhere('event_id', $event->getKey());
            })
            ->get()
            ->filter(fn (Node $node): bool => (bool) $node->is_local || $node->paired_at !== null);

        return $candidates->firstWhere('event_id', $event->getKey())
            ?? $candidates->first();
    }

    /**
     * Whether this install may write this event's event-scoped records itself,
     * as opposed to receiving them from the node that holds authority.
     */
    public function allowsLocalWrite(Event $event, ?DateTimeInterface $at = null): bool
    {
        $authority = $this->authoritativeNodeFor($event, $at);

        return $authority === null || $this->isThisInstall($authority);
    }

    /**
     * @throws EventAuthorityException when this node may not write the record itself
     */
    public function assertLocalWrite(Event $event, string $recordDescription, ?DateTimeInterface $at = null): void
    {
        $authority = $this->authoritativeNodeFor($event, $at);

        if ($authority === null || $this->isThisInstall($authority)) {
            return;
        }

        throw EventAuthorityException::readOnlyDuringActiveEvent($event, $authority, $recordDescription);
    }

    /**
     * Whether an operation that arrived from a peer may change this event's
     * records.
     *
     * This is the same rule read from the other side. On central during the
     * window, operations from the on-site primary node are the documented
     * exception to central being read-only, and everything else is an edit not
     * from the on-site primary node. On the on-site node itself, authority
     * resolves to this install, so an event-scoped operation arriving from
     * central is refused for exactly the same reason.
     */
    public function acceptsOperationFrom(Node $originNode, Event $event, ?DateTimeInterface $at = null): bool
    {
        $authority = $this->authoritativeNodeFor($event, $at);

        return $authority === null || $originNode->is($authority);
    }

    private function isThisInstall(Node $node): bool
    {
        return $node->is($this->nodes->activeNode());
    }
}
