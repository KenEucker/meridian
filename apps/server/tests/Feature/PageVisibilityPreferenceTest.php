<?php

namespace Tests\Feature;

use App\Domain\Navigation\HideablePageCatalog;
use App\Models\Device;
use App\Models\HiddenPagePreference;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Hiding a page from your own navigation (M18.69).
 *
 * Source: technical spec 21D.10 (personal view state is not audited); data/API
 * 5.5 (the session document).
 *
 * The preference is a property of the login, it starts at the catalog's
 * defaults, it survives a round trip through the session document, and it acts
 * on nobody but the caller. That last one is the property with teeth: the
 * command takes no subject, so what is asserted is that one user's write is
 * invisible in another user's session.
 */
class PageVisibilityPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_who_has_decided_nothing_gets_the_catalog_defaults(): void
    {
        $user = User::factory()->create();

        $response = $this->me($user);

        $response->assertOk();
        // The dashboards start hidden, which is the one default the catalog
        // ships. A user who has never opened Settings still gets an answer.
        $response->assertJsonPath('preferences.hidden_pages', [
            HideablePageCatalog::PAGE_DASHBOARD,
        ]);
    }

    public function test_showing_a_page_hidden_by_default_is_stored_and_reported(): void
    {
        $user = User::factory()->create();

        $response = $this->command($user, HideablePageCatalog::PAGE_DASHBOARD, false);

        $response->assertOk();
        $response->assertJsonPath('hidden_pages', []);

        // Stored as a decision rather than as the absence of one, so the answer
        // holds if the default ever moves underneath this user.
        $this->assertDatabaseHas('hidden_page_preferences', [
            'user_id' => $user->getKey(),
            'page_key' => HideablePageCatalog::PAGE_DASHBOARD,
            'hidden' => false,
        ]);

        $this->me($user)->assertJsonPath('preferences.hidden_pages', []);
    }

    public function test_a_decision_can_be_reversed_without_stacking_rows(): void
    {
        $user = User::factory()->create();

        $this->command($user, HideablePageCatalog::PAGE_DASHBOARD, false)->assertOk();
        $this->command($user, HideablePageCatalog::PAGE_DASHBOARD, true)->assertOk();

        $this->assertSame(
            1,
            HiddenPagePreference::query()->where('user_id', $user->getKey())->count(),
        );

        $this->me($user)->assertJsonPath('preferences.hidden_pages', [
            HideablePageCatalog::PAGE_DASHBOARD,
        ]);
    }

    public function test_a_page_the_catalog_does_not_know_is_refused(): void
    {
        $user = User::factory()->create();

        $this->command($user, 'the-page-that-does-not-exist', true)
            ->assertStatus(422);

        $this->assertSame(0, HiddenPagePreference::query()->count());
    }

    public function test_the_command_needs_a_session(): void
    {
        $this->postJson(route('api.commands.set-page-visibility'), [
            'page_key' => HideablePageCatalog::PAGE_DASHBOARD,
            'hidden' => false,
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

        $this->command($user, HideablePageCatalog::PAGE_DASHBOARD, false)->assertOk();

        $this->me($other)->assertJsonPath('preferences.hidden_pages', [
            HideablePageCatalog::PAGE_DASHBOARD,
        ]);
    }

    private function command(User $user, string $pageKey, bool $hidden): TestResponse
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
