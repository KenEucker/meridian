<?php

namespace Tests\Feature;

use App\Domain\Navigation\HideablePageCatalog;
use App\Domain\Navigation\MenuPageCatalog;
use App\Models\Device;
use App\Models\MenuPagePreference;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Keeping a page out of your own menus (M18.69).
 *
 * Source: technical spec 21D.10 (personal view state is not audited); data/API
 * 5.5 (the session document).
 *
 * The same properties {@see PageVisibilityPreferenceTest} holds for hiding a
 * page — a property of the login, starting at the catalog's defaults, surviving
 * a round trip through the session document, and reaching nobody but the caller
 * — plus the one that separates the two commands: this preference and that one
 * are stored apart and reported apart, so a client can tell a shortened menu
 * from a page somebody put away.
 */
class MenuPageVisibilityPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_who_has_decided_nothing_has_a_full_menu(): void
    {
        $user = User::factory()->create();

        $response = $this->me($user);

        $response->assertOk();
        // Nothing starts out of the menus. A reader arriving at their first
        // event is shown the whole of what they may work out of.
        $response->assertJsonPath('preferences.menu_hidden_pages', []);
    }

    public function test_taking_a_page_out_of_the_menus_is_stored_and_reported(): void
    {
        $user = User::factory()->create();

        $response = $this->command($user, MenuPageCatalog::PAGE_LOGISTICS, true);

        $response->assertOk();
        $response->assertJsonPath('menu_hidden_pages', [
            MenuPageCatalog::PAGE_LOGISTICS,
        ]);

        $this->assertDatabaseHas('menu_page_preferences', [
            'user_id' => $user->getKey(),
            'page_key' => MenuPageCatalog::PAGE_LOGISTICS,
            'hidden' => true,
        ]);

        $this->me($user)->assertJsonPath('preferences.menu_hidden_pages', [
            MenuPageCatalog::PAGE_LOGISTICS,
        ]);
    }

    public function test_a_decision_can_be_reversed_without_stacking_rows(): void
    {
        $user = User::factory()->create();

        $this->command($user, MenuPageCatalog::PAGE_PLANNING, true)->assertOk();
        $this->command($user, MenuPageCatalog::PAGE_PLANNING, false)->assertOk();

        // Stored as a decision rather than as the absence of one, so the answer
        // holds if the default ever moves underneath this user.
        $this->assertSame(
            1,
            MenuPagePreference::query()->where('user_id', $user->getKey())->count(),
        );

        $this->me($user)->assertJsonPath('preferences.menu_hidden_pages', []);
    }

    /**
     * The two preferences are two answers, and the node keeps them apart.
     *
     * This is the property the second table exists for. A reader who has put
     * the dashboards away entirely and separately trimmed Logistics out of
     * their menus has said two different things, and a client that received one
     * merged list could not render either control honestly — Settings would
     * show a hidden page as merely absent from the menu, and switching it back
     * on would appear to do nothing.
     */
    public function test_a_hidden_page_and_a_page_out_of_the_menus_are_reported_apart(): void
    {
        $user = User::factory()->create();

        $this->command($user, MenuPageCatalog::PAGE_LOGISTICS, true)->assertOk();

        $response = $this->me($user);

        $response->assertJsonPath('preferences.hidden_pages', [
            HideablePageCatalog::PAGE_DASHBOARD,
        ]);
        $response->assertJsonPath('preferences.menu_hidden_pages', [
            MenuPageCatalog::PAGE_LOGISTICS,
        ]);
    }

    /**
     * The same key means the same page in both catalogs, and answering one
     * question about it does not answer the other.
     *
     * Somebody who wants a dashboard but does not want it in their menu is
     * making an ordinary request, and the two rows that record it live in
     * different tables and are read back separately.
     */
    public function test_the_dashboard_can_be_shown_and_still_kept_out_of_the_menus(): void
    {
        $user = User::factory()->create();

        $this->pageCommand($user, HideablePageCatalog::PAGE_DASHBOARD, false)->assertOk();
        $this->command($user, MenuPageCatalog::PAGE_DASHBOARD, true)->assertOk();

        $response = $this->me($user);

        $response->assertJsonPath('preferences.hidden_pages', []);
        $response->assertJsonPath('preferences.menu_hidden_pages', [
            MenuPageCatalog::PAGE_DASHBOARD,
        ]);
    }

    public function test_a_page_the_menu_catalog_does_not_know_is_refused(): void
    {
        $user = User::factory()->create();

        $this->command($user, 'the-page-that-does-not-exist', true)
            ->assertStatus(422);

        $this->assertSame(0, MenuPagePreference::query()->count());
    }

    public function test_the_command_needs_a_session(): void
    {
        $this->postJson(route('api.commands.set-menu-page-visibility'), [
            'page_key' => MenuPageCatalog::PAGE_LOGISTICS,
            'hidden' => true,
        ])->assertStatus(401);
    }

    /**
     * The command names no subject, so it can only ever reach the caller.
     *
     * This is the assertion that would fail first if anybody ever added a
     * `user_id` to the request shape: one person's menu is not a thing another
     * person gets to decide, and there is no role that changes that.
     */
    public function test_one_users_preference_does_not_reach_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->command($user, MenuPageCatalog::PAGE_LOGISTICS, true)->assertOk();

        $this->me($other)->assertJsonPath('preferences.menu_hidden_pages', []);
    }

    private function command(User $user, string $pageKey, bool $hidden): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson(route('api.commands.set-menu-page-visibility'), [
                'page_key' => $pageKey,
                'hidden' => $hidden,
            ]);
    }

    private function pageCommand(User $user, string $pageKey, bool $hidden): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson(route('api.commands.set-page-visibility'), [
                'page_key' => $pageKey,
                'hidden' => $hidden,
            ]);
    }

    private function me(User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson(route('api.me'));
    }

    private function tokenFor(User $user): string
    {
        return app(ApiTokenIssuer::class)
            ->issue($user, Device::factory()->create())
            ->plainTextToken;
    }
}
