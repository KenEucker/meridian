<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Models\Event;
use App\Models\User;

/**
 * Everything the caller's authorization resolves to, once, for one composition
 * of the offline read set (technical spec 9.5, 11A.7; CLIENT-021).
 *
 * Every section of the set is built from this and from nothing else. That is
 * the property CLIENT-021 asks for stated as structure rather than as care: a
 * section cannot reach past the caller's scope because it is handed the scope
 * and never the request.
 *
 * The associations are membership-derived and the role codes are resolver-
 * derived, and both are here because the set needs both. The regular-staff
 * sections of technical spec 9.3 are membership-scoped — a staff member holds
 * their own shifts and their own department's documents by belonging to them,
 * not by holding a role — while the role-additive sections M18.47 adds are
 * narrowed by the roles. Composing them from one resolved scope is what keeps
 * the two from answering differently about the same caller.
 */
final class OfflineReadSetScope
{
    /**
     * @param  list<string>  $staffIds
     * @param  list<string>  $organizationIds  every organization the set names
     * @param  list<string>  $standingOrganizationIds  the organizations the caller
     *                                                 holds a `staff_organization_statuses`
     *                                                 row in, which is what makes
     *                                                 them the audience for an
     *                                                 organization-scoped document
     * @param  list<string>  $departmentIds
     * @param  list<string>  $teamIds
     * @param  list<string>  $eventIds
     * @param  list<string>  $roleCodes  effective role codes at the context event
     * @param  array<string, string>  $departmentOrganizations  department id => organization id
     * @param  array<string, string>  $teamOrganizations  team id => organization id
     * @param  array<string, string>  $eventOrganizations  event id => organization id
     */
    public function __construct(
        public readonly User $user,
        public readonly array $staffIds,
        public readonly array $organizationIds,
        public readonly array $standingOrganizationIds,
        public readonly array $departmentIds,
        public readonly array $teamIds,
        public readonly array $eventIds,
        public readonly array $roleCodes,
        public readonly ?Event $contextEvent,
        public readonly ?string $nodeLockedEventId,
        public readonly array $departmentOrganizations = [],
        public readonly array $teamOrganizations = [],
        public readonly array $eventOrganizations = [],
    ) {}

    /**
     * Whether this login speaks for any staff record at all.
     *
     * A user with no staff profile holds no membership, and every section is
     * scoped by one, so the whole set is empty rather than partially populated.
     * Contributors short-circuit on this rather than issuing a dozen queries
     * with empty `IN ()` lists.
     */
    public function hasStaffProfile(): bool
    {
        return $this->staffIds !== [];
    }

    /**
     * The same scope with every association outside these organizations
     * removed (MOD-016).
     *
     * Module state is an organization-level boundary, and a staff member can
     * hold standing in more than one organization at a time. Narrowing the
     * scope and recomposing is how a module-owned section carries the rows of
     * the organizations running that module and none of the rows of the ones
     * that are not — the alternative, dropping the section whenever any
     * organization has the module off, would take records away from an
     * organization that runs it.
     *
     * The caller's own staff records and role codes are unchanged: those are
     * core (MOD-004), and a person does not stop being staff because an
     * organization turned a module off.
     *
     * @param  list<string>  $organizationIds
     */
    public function narrowedTo(array $organizationIds): self
    {
        $keep = static fn (string $organizationId): bool => in_array($organizationId, $organizationIds, true);

        $within = static function (array $ids, array $organizations) use ($keep): array {
            return array_values(array_filter(
                $ids,
                static fn (string $id): bool => isset($organizations[$id]) && $keep($organizations[$id]),
            ));
        };

        return new self(
            user: $this->user,
            staffIds: $this->staffIds,
            organizationIds: array_values(array_filter($this->organizationIds, $keep)),
            standingOrganizationIds: array_values(array_filter($this->standingOrganizationIds, $keep)),
            departmentIds: $within($this->departmentIds, $this->departmentOrganizations),
            teamIds: $within($this->teamIds, $this->teamOrganizations),
            eventIds: $within($this->eventIds, $this->eventOrganizations),
            roleCodes: $this->roleCodes,
            contextEvent: $this->contextEvent,
            nodeLockedEventId: $this->nodeLockedEventId,
            departmentOrganizations: $this->departmentOrganizations,
            teamOrganizations: $this->teamOrganizations,
            eventOrganizations: $this->eventOrganizations,
        );
    }
}
