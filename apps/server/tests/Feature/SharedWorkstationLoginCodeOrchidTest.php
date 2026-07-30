<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationLoginCode;
use App\Models\User;
use App\Orchid\Screens\SharedWorkstationLoginCode\SharedWorkstationLoginCodeListScreen;
use App\Services\Auth\SharedWorkstationLoginCodeGenerator;
use App\Services\Auth\SharedWorkstationLoginCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

/**
 * God Mode shared-workstation login code generation, listing, and revocation
 * (M16.8).
 *
 * Source: AUTH-026, AUTH-028, AUTH-029; technical spec 13.2; data/API 12.4. God
 * mode generates a code for any known user — the assisted-recovery and
 * event-preparation path — and the code is shown once and stored only as a hash.
 */
class SharedWorkstationLoginCodeOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    private function workstation(string $name = 'onsite-command-1'): SharedWorkstation
    {
        $event = Event::factory()->create(['name' => 'Signal Camp 2026']);

        return SharedWorkstation::factory()->create([
            'name' => $name,
            'organization_id' => $event->organization_id,
            'event_id' => $event->getKey(),
        ]);
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                SharedWorkstationLoginCodeListScreen::PERMISSION => true,
            ],
        ]);
    }

    public function test_god_mode_generates_a_code_for_a_user_and_is_shown_it_once(): void
    {
        $workstation = $this->workstation();
        $subject = User::factory()->create(['name' => 'Robin Field']);

        // Redirects are followed, because the render the redirect lands on is the
        // one render the generated code exists in.
        $response = $this->screen('platform.shared-workstation-login-codes')
            ->actingAs($this->godModeUser(), 'web')
            ->method('generate', [
                'login_code' => [
                    'user_id' => $subject->getKey(),
                    'shared_workstation_id' => $workstation->getKey(),
                ],
            ]);

        $response->assertOk();
        $response->assertSee('Login code for Robin Field');
        $response->assertSee('onsite-command-1');

        $record = SharedWorkstationLoginCode::query()->sole();

        $this->assertSame($subject->getKey(), $record->user_id);
        $this->assertSame($workstation->getKey(), $record->shared_workstation_id);
        $this->assertSame($workstation->event_id, $record->event_id);

        // The code on the page is the code that was stored, and it is the only
        // place it will ever appear.
        $body = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/<code>[2-9A-Z]{4}-[2-9A-Z]{4}<\/code>/', $body);
        preg_match('/<code>([2-9A-Z]{4}-[2-9A-Z]{4})<\/code>/', $body, $matches);
        $this->assertSame(
            SharedWorkstationLoginCodeGenerator::hash($matches[1]),
            $record->code_hash,
        );

        // Shown once: the next visit to the screen has no code on it.
        $this->actingAs($this->godModeUser())
            ->get(route('platform.shared-workstation-login-codes'))
            ->assertOk()
            ->assertDontSee('Login code for');
    }

    public function test_the_screen_lists_codes_with_their_user_workstation_event_and_authority(): void
    {
        $workstation = $this->workstation();
        $subject = User::factory()->create(['name' => 'Robin Field', 'email' => 'robin@example.com']);
        $operator = User::factory()->create(['name' => 'Casey Technician']);

        app(SharedWorkstationLoginCodeService::class)->generateForUser($subject, $operator, $workstation);
        app(SharedWorkstationLoginCodeService::class)->generateForSelf($subject, $this->workstation('ic-desk-1'));

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.shared-workstation-login-codes'));

        $response->assertOk();
        $response->assertSee('Workstation Login Codes');
        $response->assertSee('Robin Field');
        $response->assertSee('robin@example.com');
        $response->assertSee('onsite-command-1');
        $response->assertSee('ic-desk-1');
        $response->assertSee('Signal Camp 2026');
        $response->assertSee('Casey Technician');
        $response->assertSee('Self-service');
        $response->assertSee('Active');
    }

    public function test_god_mode_can_revoke_a_code(): void
    {
        $workstation = $this->workstation();
        $operator = $this->godModeUser();

        $issued = app(SharedWorkstationLoginCodeService::class)->generateForUser(
            User::factory()->create(),
            User::factory()->create(),
            $workstation,
        );

        $this->screen('platform.shared-workstation-login-codes')
            ->actingAs($operator, 'web')
            ->withoutFollowingRedirects()
            ->method('revokeCode', ['login_code' => $issued->record->getKey()])
            ->assertRedirect(route('platform.shared-workstation-login-codes'));

        $this->assertNotNull($issued->record->fresh()->revoked_at);

        $audit = AuditEvent::query()
            ->where('action', SharedWorkstationLoginCodeService::AUDIT_REVOKED)
            ->sole();

        $this->assertSame($operator->getKey(), $audit->actor_user_id);
        $this->assertSame(SharedWorkstationLoginCodeService::REASON_GOD_MODE, $audit->reason);
        $this->assertSame(AuditEvent::SOURCE_ORCHID, $audit->source_context);
    }

    public function test_revoking_an_already_spent_code_changes_nothing(): void
    {
        $workstation = $this->workstation();
        $service = app(SharedWorkstationLoginCodeService::class);

        $issued = $service->generateForUser(User::factory()->create(), User::factory()->create(), $workstation);
        $service->redeem($workstation, $issued->plaintextCode);

        $this->screen('platform.shared-workstation-login-codes')
            ->actingAs($this->godModeUser(), 'web')
            ->withoutFollowingRedirects()
            ->method('revokeCode', ['login_code' => $issued->record->getKey()]);

        $this->assertNull($issued->record->fresh()->revoked_at);
        $this->assertSame(0, AuditEvent::query()
            ->where('action', SharedWorkstationLoginCodeService::AUDIT_REVOKED)
            ->count());
    }

    public function test_an_untrusted_workstation_is_not_offered_for_generation(): void
    {
        $event = Event::factory()->create();

        SharedWorkstation::factory()->untrusted()->create([
            'name' => 'retired-desk-9',
            'organization_id' => $event->organization_id,
            'event_id' => $event->getKey(),
        ]);

        $this->workstation();

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.shared-workstation-login-codes'));

        $response->assertOk();
        $response->assertSee('onsite-command-1');
        $response->assertDontSee('retired-desk-9');
    }

    public function test_the_screen_requires_its_god_mode_permission(): void
    {
        $withoutPermission = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($withoutPermission)
            ->get(route('platform.shared-workstation-login-codes'))
            ->assertForbidden();
    }

    /**
     * A screen method is its own request, so the permission is checked again
     * rather than assumed from the screen having rendered.
     */
    public function test_a_user_without_the_permission_cannot_generate_a_code(): void
    {
        $workstation = $this->workstation();
        $withoutPermission = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->screen('platform.shared-workstation-login-codes')
            ->actingAs($withoutPermission, 'web')
            ->withoutFollowingRedirects()
            ->method('generate', [
                'login_code' => [
                    'user_id' => User::factory()->create()->getKey(),
                    'shared_workstation_id' => $workstation->getKey(),
                ],
            ])
            ->assertForbidden();

        $this->assertSame(0, SharedWorkstationLoginCode::query()->count());
    }

    /**
     * Technical spec 13.2: codes are not printable or exportable as event prep
     * sheets, and raw codes are not logged. The console is where an export would
     * start, so no code value — and no stored hash — appears on the page.
     */
    public function test_the_console_never_displays_a_code_value_or_its_hash(): void
    {
        $workstation = $this->workstation();

        $issued = app(SharedWorkstationLoginCodeService::class)->generateForUser(
            User::factory()->create(),
            User::factory()->create(),
            $workstation,
        );

        $body = $this->actingAs($this->godModeUser())
            ->get(route('platform.shared-workstation-login-codes'))
            ->assertOk()
            ->getContent();

        $this->assertNotFalse($body);
        $this->assertStringNotContainsString($issued->plaintextCode, $body);
        $this->assertStringNotContainsString($issued->formattedCode(), $body);
        $this->assertStringNotContainsString($issued->record->code_hash, $body);
    }
}
