<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Branding\BrandingPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Branding administration surfaces (M15A.5, M15A.6, M15A.7, M15A.11;
 * BRAND-004, BRAND-013, BRAND-015, BRAND-018 through BRAND-021, BRAND-023).
 */
class BrandingAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organizer_can_set_the_organization_palette_and_display_name(): void
    {
        [$organization, $organizer] = $this->organizationWith('organizer');

        $this->actingAs($organizer)
            ->postJson('/api/commands/update-organization-branding', [
                'organization_id' => $organization->id,
                'display_name' => '  Deep Harbor Collective  ',
                'palette' => $this->validPalette(),
            ])
            ->assertOk()
            ->assertJsonPath('display_name', 'Deep Harbor Collective')
            ->assertJsonPath('is_branded', true)
            ->assertJsonPath('palette.primary', '#123a5c')
            ->assertJsonPath('lettermark', 'DHC');

        $audit = AuditEvent::query()->where('action', 'branding.created')->sole();
        $this->assertSame($organizer->id, $audit->actor_user_id);
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertNull($audit->before_json['palette']);
        $this->assertSame('#123a5c', $audit->after_json['palette']['primary']);
    }

    public function test_a_lead_organizer_may_edit_organization_branding(): void
    {
        [$organization, $leadOrganizer] = $this->organizationWith('lead_organizer');

        $this->actingAs($leadOrganizer)
            ->postJson('/api/commands/update-organization-branding', [
                'organization_id' => $organization->id,
                'palette' => $this->validPalette(),
            ])
            ->assertOk();
    }

    public function test_a_department_lead_cannot_edit_organization_branding(): void
    {
        // BRAND-019 draws the line here: departments edit only their own
        // profile, and the organization palette is not theirs.
        [$organization, , $departmentLead] = $this->departmentWith('department_lead');

        $this->actingAs($departmentLead)
            ->postJson('/api/commands/update-organization-branding', [
                'organization_id' => $organization->id,
                'palette' => $this->validPalette(),
            ])
            ->assertForbidden();

        $this->assertNull($organization->fresh()->branding_palette_json);
    }

    public function test_ordinary_staff_cannot_edit_branding(): void
    {
        [$organization] = $this->departmentWith('department_lead');
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson('/api/commands/update-organization-branding', [
                'organization_id' => $organization->id,
                'palette' => $this->validPalette(),
            ])
            ->assertForbidden();
    }

    public function test_a_failing_palette_is_rejected_with_the_pair_and_both_ratios(): void
    {
        [$organization, $organizer] = $this->organizationWith('organizer');

        $palette = array_merge($this->validPalette(), ['muted_foreground' => '#c9cdd1']);

        $response = $this->actingAs($organizer)
            ->postJson('/api/commands/update-organization-branding', [
                'organization_id' => $organization->id,
                'palette' => $palette,
            ])
            ->assertStatus(422);

        $failures = $response->json('failures');

        $this->assertNotEmpty($failures);

        $muted = collect($failures)->firstWhere('pair', 'muted foreground on surface');

        $this->assertNotNull($muted);
        $this->assertSame('#c9cdd1', $muted['foreground']);
        $this->assertSame(4.5, $muted['required_ratio']);
        $this->assertLessThan(4.5, $muted['measured_ratio']);

        // BRAND-016: nothing was stored, and nothing was corrected.
        $this->assertNull($organization->fresh()->branding_palette_json);
    }

    public function test_the_preview_endpoint_reports_validity_without_saving(): void
    {
        [$organization, $organizer] = $this->organizationWith('organizer');

        $this->actingAs($organizer)
            ->postJson('/api/commands/preview-branding', [
                'organization_id' => $organization->id,
                'palette' => $this->validPalette(),
            ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('failures', []);

        $this->actingAs($organizer)
            ->postJson('/api/commands/preview-branding', [
                'organization_id' => $organization->id,
                'palette' => array_merge($this->validPalette(), ['border' => '#f2f4f6']),
            ])
            ->assertOk()
            ->assertJsonPath('valid', false);

        $this->assertNull($organization->fresh()->branding_palette_json);
        $this->assertSame(0, AuditEvent::query()->where('action', 'like', 'branding.%')->count());
    }

    public function test_a_department_lead_can_set_only_their_own_department_branding(): void
    {
        [$organization, $department, $departmentLead] = $this->departmentWith('department_lead');
        $otherDepartment = Department::factory()->for($organization)->create();

        $this->actingAs($departmentLead)
            ->postJson('/api/commands/update-department-branding', [
                'department_id' => $department->id,
                'accent' => '#1f5f4b',
                'surface' => '#eef6f2',
            ])
            ->assertOk()
            ->assertJsonPath('accent', '#1f5f4b')
            ->assertJsonPath('surface', '#eef6f2');

        $this->actingAs($departmentLead)
            ->postJson('/api/commands/update-department-branding', [
                'department_id' => $otherDepartment->id,
                'accent' => '#1f5f4b',
            ])
            ->assertForbidden();

        $this->assertNull($otherDepartment->fresh()->branding_accent_color);
    }

    public function test_department_branding_is_refused_when_the_organization_switch_is_off(): void
    {
        [$organization, $department, $departmentLead] = $this->departmentWith('department_lead');

        $organization->forceFill(['department_branding_enabled' => false])->save();

        $this->actingAs($departmentLead)
            ->postJson('/api/commands/update-department-branding', [
                'department_id' => $department->id,
                'accent' => '#1f5f4b',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'switched off'));
    }

    public function test_a_department_background_that_hides_state_is_refused(): void
    {
        // BRAND-017 read through the department surface: an unreadable
        // background is refused with the same evidence a palette gets.
        [, $department, $departmentLead] = $this->departmentWith('department_lead');

        $response = $this->actingAs($departmentLead)
            ->postJson('/api/commands/update-department-branding', [
                'department_id' => $department->id,
                'surface' => '#cc792f',
            ])
            ->assertStatus(422);

        $this->assertNotEmpty($response->json('failures'));
        $this->assertNull($department->fresh()->branding_surface_color);
    }

    public function test_a_logo_is_uploaded_replaced_and_removed_through_the_attachment_path(): void
    {
        Storage::fake('attachments');

        [$organization, $organizer] = $this->organizationWith('organizer');

        $first = $this->actingAs($organizer)
            ->post('/api/commands/upload-branding-asset', [
                'organization_id' => $organization->id,
                'slot' => Attachment::BRANDING_SLOT_FULL_LOCKUP,
                'logo' => UploadedFile::fake()->image('lockup.png', 320, 120),
            ])
            ->assertCreated()
            ->assertJsonPath('slot', Attachment::BRANDING_SLOT_FULL_LOCKUP);

        $firstId = (string) $first->json('attachment_id');
        $this->assertSame($firstId, (string) $organization->fresh()->branding_full_lockup_attachment_id);
        $this->assertSame('branding.asset_added', AuditEvent::query()->latest('id')->first()->action);

        $second = $this->actingAs($organizer)
            ->post('/api/commands/upload-branding-asset', [
                'organization_id' => $organization->id,
                'slot' => Attachment::BRANDING_SLOT_FULL_LOCKUP,
                'logo' => UploadedFile::fake()->image('replacement.png', 320, 120),
            ])
            ->assertCreated();

        $secondId = (string) $second->json('attachment_id');
        $this->assertNotSame($firstId, $secondId);
        $this->assertSame($secondId, (string) $organization->fresh()->branding_full_lockup_attachment_id);
        $this->assertSame('branding.asset_replaced', AuditEvent::query()->latest('id')->first()->action);

        // Replacing does not destroy the superseded asset; attachments are
        // immutable and are never deleted in Alpha 1.
        $this->assertDatabaseHas('attachments', ['id' => $firstId]);

        $this->actingAs($organizer)
            ->postJson('/api/commands/remove-branding-asset', [
                'organization_id' => $organization->id,
                'slot' => Attachment::BRANDING_SLOT_FULL_LOCKUP,
            ])
            ->assertOk()
            ->assertJsonPath('attachment_id', null);

        $this->assertNull($organization->fresh()->branding_full_lockup_attachment_id);
        $this->assertSame('branding.asset_removed', AuditEvent::query()->latest('id')->first()->action);
        $this->assertDatabaseHas('attachments', ['id' => $secondId]);
    }

    public function test_a_disallowed_logo_type_is_refused(): void
    {
        Storage::fake('attachments');

        [$organization, $organizer] = $this->organizationWith('organizer');

        // SVG is the obvious logo format and is deliberately excluded: the
        // asset is served inline and unauthenticated so it renders in email
        // and PDFs, and an SVG is a document that can carry script.
        $this->actingAs($organizer)
            ->post('/api/commands/upload-branding-asset', [
                'organization_id' => $organization->id,
                'slot' => Attachment::BRANDING_SLOT_COMPACT_MARK,
                'logo' => UploadedFile::fake()->createWithContent(
                    'mark.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><circle r="4"/></svg>',
                ),
            ])
            ->assertStatus(422);

        $this->assertNull($organization->fresh()->branding_compact_mark_attachment_id);
    }

    public function test_a_department_logo_cannot_be_uploaded_into_an_organization_slot(): void
    {
        Storage::fake('attachments');

        [, $department, $departmentLead] = $this->departmentWith('department_lead');

        $this->actingAs($departmentLead)
            ->post('/api/commands/upload-branding-asset', [
                'department_id' => $department->id,
                'slot' => Attachment::BRANDING_SLOT_FULL_LOCKUP,
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(422);
    }

    public function test_a_department_lead_can_upload_replace_and_remove_a_team_logo(): void
    {
        // BRAND-025: a team's only branding value, edited under the
        // department's branding authority rather than a permission of its own.
        Storage::fake('attachments');

        [, $department, $departmentLead] = $this->departmentWith('department_lead');
        $team = $this->teamIn($department, 'Dirt');

        $uploaded = $this->actingAs($departmentLead)
            ->post('/api/commands/upload-branding-asset', [
                'team_id' => $team->id,
                'slot' => Attachment::BRANDING_SLOT_TEAM_LOGO,
                'logo' => UploadedFile::fake()->image('dirt.png', 200, 200),
            ])
            ->assertCreated()
            ->assertJsonPath('slot', Attachment::BRANDING_SLOT_TEAM_LOGO);

        $attachmentId = (string) $uploaded->json('attachment_id');
        $this->assertSame($attachmentId, (string) $team->fresh()->branding_logo_attachment_id);
        $this->assertNotNull($team->fresh()->branding_updated_at);

        // The audit record is scoped to the team's department, not to no
        // department at all, so a department's branding history is complete.
        $added = AuditEvent::query()->where('action', 'branding.asset_added')->sole();
        $this->assertSame($department->id, $added->department_id);

        $replacement = $this->actingAs($departmentLead)
            ->post('/api/commands/upload-branding-asset', [
                'team_id' => $team->id,
                'slot' => Attachment::BRANDING_SLOT_TEAM_LOGO,
                'logo' => UploadedFile::fake()->image('dirt-v2.png', 200, 200),
            ])
            ->assertCreated();

        $this->assertNotSame($attachmentId, (string) $replacement->json('attachment_id'));
        $this->assertDatabaseHas('attachments', ['id' => $attachmentId]);

        $this->actingAs($departmentLead)
            ->postJson('/api/commands/remove-branding-asset', [
                'team_id' => $team->id,
                'slot' => Attachment::BRANDING_SLOT_TEAM_LOGO,
            ])
            ->assertOk()
            ->assertJsonPath('attachment_id', null);

        $this->assertNull($team->fresh()->branding_logo_attachment_id);
    }

    public function test_a_team_logo_cannot_be_uploaded_into_a_department_slot(): void
    {
        // The slot is the owner's, not the request's to choose. A team holds
        // only the team logo slot, and a department only the department one.
        Storage::fake('attachments');

        [, $department, $departmentLead] = $this->departmentWith('department_lead');
        $team = $this->teamIn($department, 'Dirt');

        $this->actingAs($departmentLead)
            ->post('/api/commands/upload-branding-asset', [
                'team_id' => $team->id,
                'slot' => Attachment::BRANDING_SLOT_DEPARTMENT_LOGO,
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(422);

        $this->actingAs($departmentLead)
            ->post('/api/commands/upload-branding-asset', [
                'department_id' => $department->id,
                'slot' => Attachment::BRANDING_SLOT_TEAM_LOGO,
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(422);

        $this->assertNull($team->fresh()->branding_logo_attachment_id);
        $this->assertNull($department->fresh()->branding_logo_attachment_id);
    }

    public function test_a_department_lead_cannot_set_a_logo_on_another_departments_team(): void
    {
        Storage::fake('attachments');

        [$organization, , $rangersLead] = $this->departmentWith('department_lead');
        $gate = Department::factory()->for($organization)->create([
            'name' => 'Gate',
            'code' => 'GATE',
        ]);
        $gateTeam = $this->teamIn($gate, 'Credentials');

        $this->actingAs($rangersLead)
            ->post('/api/commands/upload-branding-asset', [
                'team_id' => $gateTeam->id,
                'slot' => Attachment::BRANDING_SLOT_TEAM_LOGO,
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertForbidden();

        $this->assertNull($gateTeam->fresh()->branding_logo_attachment_id);
    }

    public function test_only_teams_with_a_logo_appear_in_the_branding_read_payload(): void
    {
        Storage::fake('attachments');

        [$organization, $department, $departmentLead] = $this->departmentWith('department_lead');
        $withLogo = $this->teamIn($department, 'Dirt');
        $this->teamIn($department, 'Greeters');

        $this->actingAs($departmentLead)
            ->post('/api/commands/upload-branding-asset', [
                'team_id' => $withLogo->id,
                'slot' => Attachment::BRANDING_SLOT_TEAM_LOGO,
                'logo' => UploadedFile::fake()->image('dirt.png'),
            ])
            ->assertCreated();

        $payload = $this->getJson("/api/organizations/{$organization->id}/branding")
            ->assertOk()
            ->json('teams');

        // A team with no logo renders a lettermark the client derives from the
        // name it already has, so it has no reason to be in the payload.
        $this->assertCount(1, $payload);
        $this->assertSame((string) $withLogo->id, $payload[0]['team_id']);
        $this->assertSame((string) $department->id, $payload[0]['department_id']);
        $this->assertSame('DI', $payload[0]['lettermark']);
        $this->assertNotNull($payload[0]['logo_url']);
    }

    public function test_an_organizer_can_set_an_event_logo_and_a_department_lead_cannot(): void
    {
        // BRAND-028: an event's mark is what most of its staff take the whole
        // product to be, and it spans every department in the event, so it is
        // organizer authority rather than any one department's.
        Storage::fake('attachments');

        [$organization, $organizer] = $this->organizationWith('organizer');
        $event = Event::factory()->for($organization)->create(['name' => 'Desert Bloom']);

        $this->actingAs($organizer)
            ->post('/api/commands/upload-branding-asset', [
                'event_id' => $event->id,
                'slot' => Attachment::BRANDING_SLOT_EVENT_LOGO,
                'logo' => UploadedFile::fake()->image('bloom.png', 256, 256),
            ])
            ->assertCreated()
            ->assertJsonPath('slot', Attachment::BRANDING_SLOT_EVENT_LOGO);

        $this->assertNotNull($event->fresh()->branding_logo_attachment_id);

        $departmentLead = $this->userWithRoleIn(
            Department::factory()->for($organization)->create(['code' => 'RANGERS2']),
            'department_lead',
        );

        $this->actingAs($departmentLead)
            ->post('/api/commands/upload-branding-asset', [
                'event_id' => $event->id,
                'slot' => Attachment::BRANDING_SLOT_EVENT_LOGO,
                'logo' => UploadedFile::fake()->image('other.png'),
            ])
            ->assertForbidden();
    }

    public function test_an_event_logo_cannot_be_uploaded_into_another_owners_slot(): void
    {
        Storage::fake('attachments');

        [$organization, $organizer] = $this->organizationWith('organizer');
        $event = Event::factory()->for($organization)->create();

        $this->actingAs($organizer)
            ->post('/api/commands/upload-branding-asset', [
                'event_id' => $event->id,
                'slot' => Attachment::BRANDING_SLOT_COMPACT_MARK,
                'logo' => UploadedFile::fake()->image('mark.png'),
            ])
            ->assertStatus(422);

        $this->actingAs($organizer)
            ->post('/api/commands/upload-branding-asset', [
                'organization_id' => $organization->id,
                'slot' => Attachment::BRANDING_SLOT_EVENT_LOGO,
                'logo' => UploadedFile::fake()->image('mark.png'),
            ])
            ->assertStatus(422);

        $this->assertNull($event->fresh()->branding_logo_attachment_id);
    }

    public function test_the_event_branding_list_is_organizer_only(): void
    {
        [$organization, $organizer] = $this->organizationWith('organizer');
        $event = Event::factory()->for($organization)->create(['name' => 'Desert Bloom']);

        $payload = $this->actingAs($organizer)
            ->getJson("/api/organizations/{$organization->id}/branding/events")
            ->assertOk()
            ->json('events');

        $row = collect($payload)->firstWhere('event_id', (string) $event->id);

        $this->assertNotNull($row);
        $this->assertSame('DB', $row['lettermark']);
        $this->assertNull($row['logo_url']);
        $this->assertFalse($row['archived']);

        // The unauthenticated profile read publishes only the locked event;
        // the roster of what an organization is running is behind a session.
        [, , $departmentLead] = $this->departmentWith('department_lead');

        $this->actingAs($departmentLead)
            ->getJson("/api/organizations/{$organization->id}/branding/events")
            ->assertForbidden();
    }

    public function test_branding_edits_are_blocked_during_the_active_event_window(): void
    {
        // BRAND-021: branding freezes with the rest of governance content.
        [$organization, $organizer] = $this->organizationWith('organizer');

        Event::factory()->for($organization)->create([
            'active_event_window_starts_at' => now()->subDay(),
            'active_event_window_ends_at' => now()->addDay(),
        ]);

        $this->actingAs($organizer)
            ->postJson('/api/commands/update-organization-branding', [
                'organization_id' => $organization->id,
                'palette' => $this->validPalette(),
            ])
            ->assertStatus(409);

        $this->assertNull($organization->fresh()->branding_palette_json);
    }

    public function test_branding_edits_are_refused_on_an_onsite_node(): void
    {
        // BRAND-021: central is authoritative for branding.
        [$organization, $organizer] = $this->organizationWith('organizer');

        Node::factory()->create([
            'node_role' => Node::ROLE_ONSITE,
            'is_local' => true,
        ]);

        $this->actingAs($organizer)
            ->postJson('/api/commands/update-organization-branding', [
                'organization_id' => $organization->id,
                'palette' => $this->validPalette(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'central node'));

        $this->assertNull($organization->fresh()->branding_palette_json);
    }

    public function test_turning_the_switch_off_preserves_what_departments_authored(): void
    {
        [$organization, $organizer] = $this->organizationWith('organizer');
        $department = Department::factory()->for($organization)->branded('#1f5f4b', '#eef6f2')->create();

        $this->actingAs($organizer)
            ->postJson('/api/commands/update-organization-branding', [
                'organization_id' => $organization->id,
                'department_branding_enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('department_branding_enabled', false);

        // The switch disables the override; it does not delete the values.
        $department->refresh();
        $this->assertSame('#1f5f4b', $department->branding_accent_color);
        $this->assertSame('#eef6f2', $department->branding_surface_color);
    }

    /**
     * @return array<string, string>
     */
    private function validPalette(): array
    {
        return array_merge(BrandingPalette::meridianDefault()->toArray(), [
            'primary' => '#123a5c',
            'secondary' => '#1f5f4b',
            'tertiary' => '#6b4f8a',
            'accent' => '#8c2f39',
            'canvas' => '#eef2f6',
            'surface' => '#ffffff',
            'foreground' => '#101418',
            'muted_foreground' => '#565f68',
            'border' => '#7c858d',
            'focus' => '#1b4f8f',
        ]);
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function organizationWith(string $roleCode): array
    {
        $organization = Organization::factory()->create();
        $organizersDepartment = Department::factory()->for($organization)->create([
            'name' => 'Organizers',
            'code' => 'ORGANIZERS',
        ]);
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        return [$organization, $this->userWithRoleIn($organizersDepartment, $roleCode)];
    }

    /**
     * @return array{0: Organization, 1: Department, 2: User}
     */
    private function departmentWith(string $roleCode): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create([
            'name' => 'Rangers',
            'code' => 'RANGERS',
        ]);

        return [$organization, $department, $this->userWithRoleIn($department, $roleCode)];
    }

    private function teamIn(Department $department, string $name): Team
    {
        return Team::factory()->for($department)->create([
            'name' => $name,
            'code' => strtoupper($name),
            'is_default' => false,
        ]);
    }

    private function userWithRoleIn(Department $department, string $roleCode): User
    {
        $team = Team::query()
            ->where('department_id', $department->id)
            ->where('is_default', true)
            ->first()
            ?? Team::factory()->for($department)->create(['is_default' => true]);

        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }
}
