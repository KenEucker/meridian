<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Services\Branding\BrandingProfile;
use App\Services\Branding\BrandingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The event mark in product chrome (BRAND-028, BRAND-029, BRAND-030).
 *
 * The behaviour worth pinning is not that an event logo renders — it is the
 * three ways it must *not*: on an install that is not locked to an event, on an
 * install whose lock names another organization's event, and on the surfaces
 * BRAND-003 protects. Each of those would put the wrong identity in front of a
 * user, and the first two are invisible in any test that only checks the happy
 * path.
 */
class BrandingEventChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_locked_event_with_a_logo_supplies_the_chrome_mark(): void
    {
        $organization = Organization::factory()->create();
        $event = $this->eventWithLogo($organization, 'Desert Bloom');
        $this->lockLocalNodeTo($organization, $event);

        $profile = app(BrandingResolver::class)->forOrganization($organization);

        $this->assertNotNull($profile->lockedEvent);
        $this->assertSame('Desert Bloom', $profile->lockedEvent->name);
        $this->assertSame(
            (string) $event->fresh()->branding_logo_attachment_id,
            $profile->chromeMarkAttachmentId(),
        );
    }

    public function test_the_chrome_name_moves_with_the_chrome_mark(): void
    {
        // BRAND-030: a surface never shows one party's mark beside another
        // party's name. Whoever's mark is showing, their name is showing.
        $organization = Organization::factory()->create();
        $event = $this->eventWithLogo($organization, 'Desert Bloom');
        $this->lockLocalNodeTo($organization, $event);

        $profile = app(BrandingResolver::class)->forOrganization($organization);

        $this->assertTrue($profile->showsEventIdentity());
        $this->assertSame('Desert Bloom', $profile->chromeIdentityName());

        // Chrome only. Exports and system email name the organization, because
        // a document naming an event but no organization leaves its recipient
        // with no accountable party (BRAND-031).
        $this->assertSame('Meridian', $profile->identityName());
    }

    public function test_a_locked_event_without_a_logo_keeps_the_organization_name(): void
    {
        // Keyed on the logo, not on the lock: an event that has set nothing has
        // not asked to be presented as the product.
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Desert Bloom']);
        $organization->forceFill(['branding_display_name' => 'Deep Harbor'])->save();
        $this->lockLocalNodeTo($organization, $event);

        $profile = app(BrandingResolver::class)->forOrganization($organization->fresh());

        $this->assertFalse($profile->showsEventIdentity());
        $this->assertSame('Deep Harbor', $profile->chromeIdentityName());
    }

    public function test_an_event_mark_wins_for_an_organization_with_no_branding_profile(): void
    {
        // Uploading an event logo is a deliberate act; there is no reading of
        // it under which an organizer wanted it stored and not shown.
        $organization = Organization::factory()->create();
        $event = $this->eventWithLogo($organization, 'Desert Bloom');
        $this->lockLocalNodeTo($organization, $event);

        $profile = app(BrandingResolver::class)->forOrganization($organization);

        $this->assertFalse($profile->isBranded);
        $this->assertNotNull($profile->chromeMarkAttachmentId());
    }

    public function test_an_install_not_locked_to_an_event_keeps_the_organization_mark(): void
    {
        $organization = Organization::factory()->create();
        $this->eventWithLogo($organization, 'Desert Bloom');

        $compactMark = $this->attachmentFor($organization);
        $organization->forceFill([
            'branding_display_name' => 'Deep Harbor',
            'branding_compact_mark_attachment_id' => $compactMark->id,
        ])->save();

        // A node exists, but it names no event: this install is not running one.
        $this->lockLocalNodeTo($organization, null);

        $profile = app(BrandingResolver::class)->forOrganization($organization->fresh());

        $this->assertNull($profile->lockedEvent);
        $this->assertSame((string) $compactMark->id, $profile->chromeMarkAttachmentId());
    }

    public function test_a_lock_naming_another_organizations_event_resolves_to_no_event(): void
    {
        // A central node serves every organization it hosts. One organization's
        // chrome must never pick up another's event mark.
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $otherEvent = $this->eventWithLogo($otherOrganization, 'Someone Elses Event');

        $this->lockLocalNodeTo($otherOrganization, $otherEvent);

        $profile = app(BrandingResolver::class)->forOrganization($organization);

        $this->assertNull($profile->lockedEvent);
        $this->assertNull($profile->chromeMarkAttachmentId());
    }

    public function test_a_locked_event_without_a_logo_falls_back_to_the_organization_mark(): void
    {
        // "Locked to an event with no logo" and "not locked to an event" are
        // different states: the first still names an event, and only the mark
        // falls back.
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Desert Bloom']);

        $lockup = $this->attachmentFor($organization);
        $organization->forceFill([
            'branding_display_name' => 'Deep Harbor',
            'branding_full_lockup_attachment_id' => $lockup->id,
        ])->save();

        $this->lockLocalNodeTo($organization, $event);

        $profile = app(BrandingResolver::class)->forOrganization($organization->fresh());

        $this->assertNotNull($profile->lockedEvent);
        $this->assertFalse($profile->lockedEvent->hasLogo());
        $this->assertSame((string) $lockup->id, $profile->chromeMarkAttachmentId());
    }

    public function test_the_meridian_profile_never_carries_a_locked_event(): void
    {
        // BRAND-003: login, the magic-link landing, node first-run setup,
        // Orchid, and desktop chrome render from this profile, and none of them
        // may take an organization's or an event's identity.
        $organization = Organization::factory()->create();
        $event = $this->eventWithLogo($organization, 'Desert Bloom');
        $this->lockLocalNodeTo($organization, $event);

        $profile = BrandingProfile::meridian();

        $this->assertNull($profile->lockedEvent);
        $this->assertNull($profile->chromeMarkAttachmentId());
        $this->assertSame('Meridian', $profile->identityName());
    }

    public function test_the_read_payload_publishes_only_the_locked_event(): void
    {
        $organization = Organization::factory()->create();
        $event = $this->eventWithLogo($organization, 'Desert Bloom');
        Event::factory()->for($organization)->create(['name' => 'Winter Council']);
        $this->lockLocalNodeTo($organization, $event);

        $payload = $this->getJson("/api/organizations/{$organization->id}/branding")
            ->assertOk()
            ->json();

        $this->assertSame((string) $event->id, $payload['event']['event_id']);
        $this->assertSame('Desert Bloom', $payload['event']['name']);
        $this->assertNotNull($payload['event']['logo_url']);

        // The endpoint is unauthenticated, so it must not become a listing of
        // everything the organization is running.
        $this->assertStringNotContainsString('Winter Council', json_encode($payload));
    }

    public function test_the_offline_manifest_carries_the_locked_event_mark(): void
    {
        // BRAND-022: a device warming its cache needs the bytes its chrome will
        // actually render, and on an event-locked install that is the event's.
        $organization = Organization::factory()->create();
        $event = $this->eventWithLogo($organization, 'Desert Bloom');
        $this->lockLocalNodeTo($organization, $event);

        $manifest = $this->getJson("/branding/{$organization->id}/manifest.json")
            ->assertOk()
            ->json();

        $this->assertSame((string) $event->id, $manifest['locked_event_id']);

        $slots = array_column($manifest['assets'], 'slot');
        $this->assertContains(Attachment::BRANDING_SLOT_EVENT_LOGO, $slots);
    }

    private function eventWithLogo(Organization $organization, string $name): Event
    {
        $event = Event::factory()->for($organization)->create(['name' => $name]);
        $attachment = $this->attachmentFor($organization);

        $event->forceFill(['branding_logo_attachment_id' => $attachment->id])->save();

        return $event->fresh();
    }

    private function attachmentFor(Organization $organization): Attachment
    {
        // `attachments` is unique on (attachable_type, attachable_id,
        // filename), and a test that stores two marks against one organization
        // would otherwise collide on the factory's fixed name.
        static $sequence = 0;
        $sequence++;

        return Attachment::factory()->create([
            'attachable_type' => Attachment::MORPH_ORGANIZATION,
            'attachable_id' => $organization->id,
            'filename' => "branding_{$sequence}.png",
        ]);
    }

    /**
     * Make this install's node the one locked to `$event`.
     *
     * Other factories create local nodes as a side effect — the attachment
     * factory records an origin node — and `activeNode()` takes the newest
     * local one. A test that means "this install is locked to X" therefore has
     * to be the *only* local node, not merely the most recently created, or the
     * assertion silently depends on the order the fixtures were built in.
     */
    private function lockLocalNodeTo(Organization $organization, ?Event $event): Node
    {
        Node::query()->update(['is_local' => false]);

        return Node::factory()->create([
            'organization_id' => $organization->id,
            'event_id' => $event?->id,
            'is_local' => true,
        ]);
    }
}
