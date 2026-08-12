<?php

namespace Tests\Feature;

use App\Domain\Modules\ModuleKey;
use App\Domain\Navigation\HideablePageCatalog;
use App\Domain\Navigation\MenuPageCatalog;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Event;
use App\Models\EventCredential;
use App\Models\EventDepartmentAssignment;
use App\Models\Node;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use App\Services\Session\SessionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET /api/me` session resolution (M16.4).
 *
 * Source: CLIENT-001 through CLIENT-003; technical spec 11A.2; data/API 5.5.
 *
 * What is asserted here is the session document itself: identity, effective role
 * codes, capability codes from the permission catalog, the caller's own
 * associations, the context the node resolves — and the absence of any
 * navigation, screen list, or menu structure, which is the property that keeps
 * the catalog the single source of truth.
 */
class SessionResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // The suite shares one process and nothing in the framework restores a
        // frozen clock, so a test that freezes one hands it to whatever runs
        // next.
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_session_endpoint_returns_identity_roles_capabilities_and_associations(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);

        $response = $this->me($scenario['user']);

        $response->assertOk();
        $response->assertJsonPath('user.id', (string) $scenario['user']->getKey());
        $response->assertJsonPath('user.email', $scenario['user']->email);
        $response->assertJsonPath('user.staff_ids', [(string) $scenario['staff']->getKey()]);

        $response->assertJsonPath('roles.0.role_code', PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $response->assertJsonPath('roles.0.role_name', 'Department Logistics');
        $response->assertJsonPath('roles.0.scope_type', PermissionRole::SCOPE_DEPARTMENT);
        $response->assertJsonPath('roles.0.department_id', (string) $scenario['department']->getKey());
        $response->assertJsonPath('roles.0.organization_id', (string) $scenario['organization']->getKey());
        $response->assertJsonPath('roles.0.team_id', (string) $scenario['team']->getKey());
        $response->assertJsonPath('roles.0.event_id', null);

        // The reason the authority exists, so an elevated user reaching a denied
        // surface can be told what they hold (technical spec 15.2).
        $this->assertStringContainsString(
            'Department Logistics',
            (string) $response->json('roles.0.reason'),
        );

        $response->assertJsonPath('organizations.0.id', (string) $scenario['organization']->getKey());
        $response->assertJsonPath('organizations.0.status', StaffOrganizationStatus::STATUS_ACTIVE);
        $response->assertJsonPath('events.0.id', (string) $scenario['event']->getKey());
        $response->assertJsonPath('departments.0.id', (string) $scenario['department']->getKey());
        $response->assertJsonPath('departments.0.membership_status', DepartmentMembership::STATUS_ACTIVE);
        $response->assertJsonPath('teams.0.id', (string) $scenario['team']->getKey());

        $this->assertNotNull($response->json('refreshed_at'));
    }

    public function test_capabilities_are_the_catalog_codes_the_held_roles_carry(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);

        $response = $this->me($scenario['user']);

        $expected = PermissionCatalog::rolePermissions()[PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS];
        sort($expected);

        $this->assertSame($expected, $response->json('capabilities'));
        $this->assertSame(
            PermissionCatalog::rolePermissions()[PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS],
            $response->json('roles.0.capabilities'),
        );

        // A capability the held role does not carry is absent rather than
        // reported false, so a client cannot read a denial as a grant.
        $this->assertNotContains(
            PermissionCatalog::PERMISSION_ORGANIZATION_STAFF_MANAGE,
            $response->json('capabilities'),
        );
    }

    public function test_the_payload_carries_no_navigation_screen_list_or_menu_structure(): void
    {
        // CLIENT-003: the endpoint publishes codes. A precomputed surface list
        // would be a second permission model, divergent from the catalog the
        // server enforces from.
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LEAD);

        $response = $this->me($scenario['user']);

        $this->assertSame([
            'user',
            'roles',
            'capabilities',
            'organizations',
            'events',
            'departments',
            'teams',
            'context',
            // The device this request's token is bound to, and its trust for
            // this caller (AUTH-024). A fact about the credential rather than a
            // navigation decision, which is what the loop below still checks.
            'device',
            /*
             * What this caller has asked not to be shown (M18.69), which is a
             * different kind of thing from everything above it.
             *
             * The rule this test exists to hold is that the node does not
             * decide what the menu contains — a precomputed surface list would
             * be a second permission model, divergent from the catalog the
             * server enforces from. A preference decides nothing: it carries
             * page keys the user themselves chose, the node never applies them,
             * and every page named in it stays reachable by address and
             * enforced on arrival (CLIENT-006). The assertions below are what
             * keep the two apart — the payload holds keys the *user* named and
             * still no route, screen, or surface the *node* named.
             *
             * Two lists, because there are two questions a reader can answer
             * about a page: whether they have put it away at all, and whether
             * they work out of it. Neither is a decision this node makes.
             */
            'preferences',
            'refreshed_at',
        ], array_keys((array) $response->json()));

        $preferences = (array) $response->json('preferences');

        $this->assertSame(
            ['hidden_pages', 'menu_hidden_pages'],
            array_keys($preferences),
        );

        /*
         * The preference block is held to a different check from the rest of
         * the payload, and it has to be.
         *
         * It speaks the reader's vocabulary: `menu_hidden_pages` says "menu"
         * because a menu is the thing the *reader* asked about, and the sweep
         * below — which is looking for the node publishing one — would read the
         * word as exactly the failure it exists to catch. What has to hold here
         * is narrower and worth stating outright: every entry is a page key one
         * of the two catalogs knows, so there is no room in this block for a
         * route, a screen, or an ordered list of surfaces. Two lists of keys the
         * user themselves named is the whole of it.
         */
        foreach ((array) $preferences['hidden_pages'] as $pageKey) {
            $this->assertContains($pageKey, HideablePageCatalog::keys());
        }

        foreach ((array) $preferences['menu_hidden_pages'] as $pageKey) {
            $this->assertContains($pageKey, MenuPageCatalog::keys());
        }

        $published = (array) $response->json();
        unset($published['preferences']);

        foreach ($this->keysOf($published) as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/nav|menu|screen|route|surface|sidebar|tab|link/i',
                $key,
                "The session payload published a navigation-shaped key: {$key}",
            );
        }
    }

    public function test_one_user_cannot_read_another_users_associations(): void
    {
        $mine = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $theirs = $this->scenario(PermissionCatalog::ROLE_LEAD_ORGANIZER);

        $body = (string) $this->me($mine['user'])->getContent();

        foreach (['organization', 'event', 'department', 'team', 'staff', 'user'] as $association) {
            $this->assertStringNotContainsString(
                (string) $theirs[$association]->getKey(),
                $body,
                "The session document leaked another user's {$association}.",
            );
        }

        // Naming someone else's event does not widen the answer either: the
        // context parameter narrows to the caller's own associations.
        $this->me($mine['user'], ['event_id' => (string) $theirs['event']->getKey()])
            ->assertNotFound()
            ->assertJsonPath('reason_code', 'event_context_unavailable');
    }

    /**
     * Device trust travels on the session document (AUTH-021, AUTH-024;
     * technical spec 12.2, 14; data/API 12.2).
     *
     * The readiness checklist lists "device trusted" as one of its eight items
     * and had nothing to answer with on a personal device: trust is a
     * `device_trusts` row and no endpoint published it. It belongs here because
     * the document is already per-caller and already cached durably, so a device
     * in a field reads its own trust from the node's last answer.
     */
    public function test_the_session_reports_trust_for_the_device_its_token_is_bound_to(): void
    {
        /*
         * Frozen because signing in renews trust (AUTH-024; technical spec
         * 12.2): `meFrom` issues a token, issuance calls `DeviceTrustService`,
         * and the row this reads back is rewritten six weeks from *that*
         * instant rather than from the factory's. The two instants are
         * milliseconds apart and agree to the second nearly always — which is
         * what made this intermittent rather than simply wrong. Stopping the
         * clock makes the renewal land on the value the factory wrote, so the
         * assertion compares expiries instead of racing a second boundary.
         */
        $this->freezeTime();

        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $device = Device::factory()->create(['device_label' => "Dana's phone"]);
        $trust = DeviceTrust::factory()->create([
            'user_id' => $scenario['user']->getKey(),
            'device_id' => $device->getKey(),
            'expires_at' => now()->addWeeks(6),
            'revoked_at' => null,
        ]);

        $response = $this->meFrom($scenario['user'], $device);

        $response->assertOk();
        $response->assertJsonPath('device.id', (string) $device->getKey());
        $response->assertJsonPath('device.label', "Dana's phone");
        $response->assertJsonPath('device.trusted', true);
        $response->assertJsonPath('device.trust_state', 'trusted');
        $response->assertJsonPath(
            'device.trusted_until',
            $trust->expires_at?->toIso8601String(),
        );
    }

    /**
     * A revoked device stays revoked through a sign-in, and the session says so.
     *
     * `DeviceTrustService` refuses to renew a revoked pair on purpose — "a
     * sign-in undoing it would make revocation last exactly until its holder
     * opened the app" — and this is the property the readiness item reports.
     * It is also why revoked reads differently from lapsed for the person: this
     * one is not fixed by signing in again.
     */
    public function test_a_revoked_device_reports_revoked_even_after_signing_in_from_it(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $device = Device::factory()->create();

        DeviceTrust::factory()->create([
            'user_id' => $scenario['user']->getKey(),
            'device_id' => $device->getKey(),
            'expires_at' => now()->addWeeks(6),
            'revoked_at' => now()->subHour(),
        ]);

        $this->meFrom($scenario['user'], $device)
            ->assertJsonPath('device.trusted', false)
            ->assertJsonPath('device.trust_state', 'revoked');
    }

    /**
     * A lapsed trust reads expired rather than trusted (AUTH-024).
     *
     * Resolved directly rather than over HTTP, because issuing a token *is* the
     * sign-in that renews trust — the state this covers is the one a device
     * reaches by running for six weeks on a cached session without signing in
     * again, which no request can reproduce.
     */
    public function test_a_lapsed_trust_reports_expired_rather_than_trusted(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $device = Device::factory()->create();

        DeviceTrust::factory()->create([
            'user_id' => $scenario['user']->getKey(),
            'device_id' => $device->getKey(),
            'expires_at' => now()->subDay(),
            'revoked_at' => null,
        ]);

        $document = app(SessionResolver::class)->resolve($scenario['user'], null, $device);

        $this->assertFalse($document['device']['trusted']);
        $this->assertSame('expired', $document['device']['trust_state']);
    }

    /**
     * `trusted_until` is the expiry on record, not six weeks from the read
     * (AUTH-024; technical spec 12.2).
     *
     * Resolved directly for the same reason the lapsed case above is: issuing a
     * token renews trust to exactly six weeks out, so no request can hand the
     * resolver an expiry that a recomputed `now()->addWeeks(6)` would fail to
     * match by coincidence. That coincidence is worth denying a test, because a
     * resolver that recomputed would report a full six weeks remaining on every
     * device whatever `device_trusts` said, and the person watching their window
     * close would never see it move.
     */
    public function test_trusted_until_reports_the_stored_expiry_rather_than_a_fresh_window(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $device = Device::factory()->create();

        $trust = DeviceTrust::factory()->create([
            'user_id' => $scenario['user']->getKey(),
            'device_id' => $device->getKey(),
            'expires_at' => now()->addDays(3),
            'revoked_at' => null,
        ]);

        $document = app(SessionResolver::class)->resolve($scenario['user'], null, $device);

        $this->assertSame(
            $trust->expires_at?->toIso8601String(),
            $document['device']['trusted_until'],
        );
    }

    /**
     * Trust is per user/device pair (technical spec 12.1), so somebody else's
     * trust for this hardware is not this caller's. A shared laptop two people
     * sign in from holds two rows, and each reads their own.
     */
    public function test_another_users_trust_for_the_same_device_is_not_this_callers(): void
    {
        $mine = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $theirs = $this->scenario(PermissionCatalog::ROLE_LEAD_ORGANIZER);
        $device = Device::factory()->create();

        // Theirs is active; this caller's own is revoked. A resolver reading the
        // wrong row would report the device as trusted for somebody it is not.
        DeviceTrust::factory()->create([
            'user_id' => $theirs['user']->getKey(),
            'device_id' => $device->getKey(),
            'expires_at' => now()->addWeeks(6),
            'revoked_at' => null,
        ]);
        DeviceTrust::factory()->create([
            'user_id' => $mine['user']->getKey(),
            'device_id' => $device->getKey(),
            'expires_at' => now()->addWeeks(6),
            'revoked_at' => now()->subHour(),
        ]);

        $this->meFrom($mine['user'], $device)
            ->assertJsonPath('device.trusted', false)
            ->assertJsonPath('device.trust_state', 'revoked');
    }

    /**
     * A shared-workstation session names no device, and the checklist reads that
     * as "nothing was asked" rather than as a device that failed a check.
     */
    public function test_a_session_resolved_without_a_device_carries_none(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);

        $document = app(SessionResolver::class)->resolve($scenario['user']);

        $this->assertNull($document['device']);
    }

    public function test_the_endpoint_requires_a_bearer_token(): void
    {
        $this->getJson(route('api.me'))->assertUnauthorized();
    }

    public function test_a_browser_session_does_not_authenticate_the_session_endpoint(): void
    {
        // AUTH-018: clients authenticate with a bearer token, not the console's
        // session cookie.
        $this->actingAs(User::factory()->create())
            ->getJson(route('api.me'))
            ->assertUnauthorized();
    }

    public function test_a_revoked_token_stops_resolving_a_session(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $token = $this->tokenFor($scenario['user']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.me'))
            ->assertOk();

        $scenario['user']->tokens()->update(['revoked_at' => now()]);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.me'))
            ->assertUnauthorized();
    }

    public function test_a_user_with_no_staff_profile_resolves_an_empty_session(): void
    {
        $response = $this->me(User::factory()->create());

        $response->assertOk();
        $response->assertJsonPath('roles', []);
        $response->assertJsonPath('capabilities', []);
        $response->assertJsonPath('organizations', []);
        $response->assertJsonPath('events', []);
        $response->assertJsonPath('departments', []);
        $response->assertJsonPath('teams', []);
        $response->assertJsonPath('context.event_id', null);
        $response->assertJsonPath('context.switching_available', false);
    }

    public function test_archived_memberships_and_revoked_grants_are_not_associations(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);

        TeamMembership::query()->update(['archived_at' => now()]);
        DepartmentMembership::query()->update(['archived_at' => now()]);

        $response = $this->me($scenario['user']);

        $response->assertJsonPath('roles', []);
        $response->assertJsonPath('departments', []);
        $response->assertJsonPath('teams', []);
        // The department is no longer theirs, so neither is the event it runs.
        $response->assertJsonPath('events', []);
    }

    public function test_a_team_lead_designation_is_reported_on_the_team(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_SHIFT_LEAD, membershipRole: 'lead');

        $response = $this->me($scenario['user']);

        $response->assertJsonPath('teams.0.is_lead', true);
        $response->assertJsonPath('roles.0.role_code', PermissionCatalog::ROLE_SHIFT_LEAD);
        $response->assertJsonPath('roles.0.scope_type', PermissionRole::SCOPE_TEAM);
    }

    public function test_context_resolves_from_the_node_event_lock(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $this->lockNodeTo($scenario['event']);

        $response = $this->me($scenario['user']);

        $response->assertJsonPath('context.event_id', (string) $scenario['event']->getKey());
        $response->assertJsonPath('context.organization_id', (string) $scenario['organization']->getKey());
        $response->assertJsonPath('context.department_id', (string) $scenario['department']->getKey());
        $response->assertJsonPath('context.node_locked', true);
        $response->assertJsonPath('context.node_locked_event_id', (string) $scenario['event']->getKey());
        $response->assertJsonPath('events.0.is_node_locked', true);
    }

    public function test_event_scoped_roles_resolve_only_at_the_event_they_were_granted_for(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $event = $scenario['event'];
        $event->forceFill(['ic_department_id' => $scenario['department']->getKey()])->save();

        TeamGrant::factory()->create([
            'team_id' => $scenario['team']->getKey(),
            'event_id' => $event->getKey(),
            'permission_role_id' => $this->role(PermissionCatalog::ROLE_IC_OPERATOR)->getKey(),
        ]);

        // A second association leaves the context ambiguous, so no event is
        // resolved and the event-scoped grant is not in effect.
        $other = Event::factory()->for($scenario['organization'])->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $other->getKey(),
            'department_id' => $scenario['department']->getKey(),
        ]);

        $withoutContext = $this->me($scenario['user']);
        $withoutContext->assertJsonPath('context.event_id', null);
        $withoutContext->assertJsonMissing(['role_code' => PermissionCatalog::ROLE_IC_OPERATOR]);
        $this->assertNotContains(
            PermissionCatalog::PERMISSION_INCIDENTS_CREATE,
            $withoutContext->json('capabilities'),
        );

        // Asked for at the other event, it is still not in effect: the grant
        // names one event and standing does not travel between them.
        $this->me($scenario['user'], ['event_id' => (string) $other->getKey()])
            ->assertJsonMissing(['role_code' => PermissionCatalog::ROLE_IC_OPERATOR]);

        $withContext = $this->me($scenario['user'], ['event_id' => (string) $event->getKey()]);
        $withContext->assertJsonFragment(['role_code' => PermissionCatalog::ROLE_IC_OPERATOR]);
        $this->assertContains(
            PermissionCatalog::PERMISSION_INCIDENTS_CREATE,
            $withContext->json('capabilities'),
        );

        // And it is in effect when the node's own lock supplies the context.
        $this->lockNodeTo($event);

        $this->me($scenario['user'])
            ->assertJsonPath('context.event_id', (string) $event->getKey())
            ->assertJsonFragment(['role_code' => PermissionCatalog::ROLE_IC_OPERATOR]);
    }

    public function test_a_second_event_is_resolved_when_the_client_asks_for_it(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $second = Event::factory()->for($scenario['organization'])->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $second->getKey(),
            'department_id' => $scenario['department']->getKey(),
        ]);

        // Two events and no node lock: the client is told to choose, and may.
        $unresolved = $this->me($scenario['user']);
        $unresolved->assertJsonPath('context.event_id', null);
        $unresolved->assertJsonPath('context.switching_available', true);

        $this->me($scenario['user'], ['event_id' => (string) $second->getKey()])
            ->assertOk()
            ->assertJsonPath('context.event_id', (string) $second->getKey());
    }

    public function test_a_node_locked_to_an_event_offers_no_switching_and_refuses_another_context(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $second = Event::factory()->for($scenario['organization'])->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $second->getKey(),
            'department_id' => $scenario['department']->getKey(),
        ]);
        $this->lockNodeTo($scenario['event']);

        $response = $this->me($scenario['user']);
        $response->assertJsonPath('context.switching_available', false);
        $response->assertJsonPath('context.event_id', (string) $scenario['event']->getKey());

        $this->me($scenario['user'], ['event_id' => (string) $second->getKey()])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'node_locked_to_event')
            ->assertJsonPath('node_locked_event_id', (string) $scenario['event']->getKey());
    }

    public function test_a_credential_is_an_event_association_of_its_own(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $credentialed = Event::factory()->for($scenario['organization'])->create();

        EventCredential::factory()->create([
            'event_id' => $credentialed->getKey(),
            'staff_id' => $scenario['staff']->getKey(),
        ]);

        $eventIds = collect($this->me($scenario['user'])->json('events'))->pluck('id');

        $this->assertTrue($eventIds->contains((string) $credentialed->getKey()));
    }

    public function test_the_events_organization_is_always_listed_alongside_it(): void
    {
        // The document closes over itself: a client never has to display an
        // organization it was not told about.
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        StaffOrganizationStatus::query()->delete();

        $response = $this->me($scenario['user']);

        $organizationIds = collect($response->json('organizations'))->pluck('id');

        foreach ($response->json('events') as $event) {
            $this->assertTrue($organizationIds->contains($event['organization_id']));
        }

        // With no status row the association is still reported, without a status.
        $response->assertJsonPath('organizations.0.id', (string) $scenario['organization']->getKey());
        $response->assertJsonPath('organizations.0.status', null);
    }

    public function test_each_organization_carries_the_modules_it_runs(): void
    {
        // MOD-015: the active module set rides session resolution, so a client
        // builds navigation from capability the organization runs rather than
        // filtering it afterwards (technical spec 11A.3, 15A.8).
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LEAD);

        $response = $this->me($scenario['user']);

        // No rows written: MOD-009's default is entitled and enabled, so a
        // freshly created organization runs the whole catalogue.
        $response->assertJsonPath('organizations.0.modules', ModuleKey::keys());
    }

    public function test_a_disabled_module_is_absent_from_the_organizations_module_set(): void
    {
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LEAD);

        OrganizationModule::factory()->create([
            'organization_id' => $scenario['organization']->getKey(),
            'module_key' => ModuleKey::Scheduling->value,
            'entitled' => true,
            'enabled' => false,
        ]);

        $modules = (array) $this->me($scenario['user'])->json('organizations.0.modules');

        $this->assertNotContains(ModuleKey::Scheduling->value, $modules);
        $this->assertContains(ModuleKey::Documents->value, $modules);
    }

    public function test_an_unentitled_module_is_absent_even_where_the_organization_enabled_it(): void
    {
        // Active is `entitled && enabled` (MOD-005), and the client is told the
        // active set rather than either half: what a member may reach does not
        // depend on which of the two decisions removed it.
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LEAD);

        OrganizationModule::factory()->create([
            'organization_id' => $scenario['organization']->getKey(),
            'module_key' => ModuleKey::Insights->value,
            'entitled' => false,
            'enabled' => true,
        ]);

        $this->assertNotContains(
            ModuleKey::Insights->value,
            (array) $this->me($scenario['user'])->json('organizations.0.modules'),
        );
    }

    public function test_each_organization_is_answered_for_on_its_own_state(): void
    {
        /*
         * A caller reaching two organizations gets two answers, because a
         * department's navigation is gated on the organization that department
         * belongs to. One shared set would let a module one organization turned
         * off take the surface away in the other.
         */
        $scenario = $this->scenario(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);
        $other = Organization::factory()->create();

        StaffOrganizationStatus::factory()->active()->create([
            'organization_id' => $other->getKey(),
            'staff_id' => $scenario['staff']->getKey(),
        ]);

        OrganizationModule::factory()->create([
            'organization_id' => $other->getKey(),
            'module_key' => ModuleKey::Equipment->value,
            'entitled' => true,
            'enabled' => false,
        ]);

        $modules = collect($this->me($scenario['user'])->json('organizations'))
            ->mapWithKeys(fn (array $organization): array => [
                $organization['id'] => $organization['modules'],
            ]);

        $this->assertContains(
            ModuleKey::Equipment->value,
            $modules[(string) $scenario['organization']->getKey()],
        );
        $this->assertNotContains(
            ModuleKey::Equipment->value,
            $modules[(string) $other->getKey()],
        );
    }

    /**
     * A user holding one staff profile, in one department of one organization,
     * on one team carrying the named role, with the department assigned to one
     * event.
     *
     * @return array{
     *     user: User,
     *     staff: Staff,
     *     organization: Organization,
     *     department: Department,
     *     team: Team,
     *     event: Event
     * }
     */
    private function scenario(string $roleCode, string $membershipRole = 'member'): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();
        $event = Event::factory()->for($organization)->create();
        $staff = Staff::factory()->create();
        $user = User::factory()->create();

        $user->staffProfiles()->attach($staff);

        StaffOrganizationStatus::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'staff_id' => $staff->getKey(),
        ]);

        $departmentMembership = DepartmentMembership::factory()->create([
            'department_id' => $department->getKey(),
            'staff_id' => $staff->getKey(),
        ]);

        TeamMembership::factory()->create([
            'team_id' => $team->getKey(),
            'staff_id' => $staff->getKey(),
            'department_membership_id' => $departmentMembership->getKey(),
            'membership_role' => $membershipRole,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->getKey(),
            'permission_role_id' => $this->role($roleCode)->getKey(),
        ]);

        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->getKey(),
            'department_id' => $department->getKey(),
        ]);

        return [
            'user' => $user,
            'staff' => $staff,
            'organization' => $organization,
            'department' => $department,
            'team' => $team,
            'event' => $event,
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    private function me(User $user, array $query = []): TestResponse
    {
        // One process serves every request in a test method, and the guard
        // caches the user it resolved. A client makes each request against a
        // fresh process, so the guard is forgotten to match.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson(route('api.me', $query));
    }

    /** `GET /api/me` from a named device, for the device-trust assertions. */
    private function meFrom(User $user, Device $device): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $token = app(ApiTokenIssuer::class)->issue($user, $device)->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.me'));
    }

    private function tokenFor(User $user): string
    {
        return app(ApiTokenIssuer::class)
            ->issue($user, Device::factory()->create())
            ->plainTextToken;
    }

    private function lockNodeTo(Event $event): Node
    {
        return Node::factory()->onsite()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->getKey(),
        ]);
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }

    /**
     * Every key appearing anywhere in a decoded response body.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private function keysOf(array $payload): array
    {
        $keys = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $keys = array_merge($keys, $this->keysOf($value));
            }
        }

        return array_values(array_unique($keys));
    }
}
