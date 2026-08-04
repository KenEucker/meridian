<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The staff profile self-service surface (M18.20; VOL-009, VOL-014 through
 * VOL-016, VOL-026; data/API 10.4).
 */
class StaffProfileSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_member_reads_own_profile(): void
    {
        [$user, $staff] = $this->userWithStaffProfile([
            'legal_name' => 'Avery Example',
            'preferred_name' => 'Avery',
            'handle' => 'avery-radio',
            'email' => 'avery@example.test',
            'phone' => '555-0100',
            'city' => 'Portland',
            'state' => 'OR',
            'date_of_birth' => '1990-04-01',
        ]);

        $this->actingAsClient($user)
            ->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonCount(1, 'profiles')
            ->assertJsonPath('profiles.0.id', (string) $staff->id)
            ->assertJsonPath('profiles.0.legal_name', 'Avery Example')
            ->assertJsonPath('profiles.0.preferred_name', 'Avery')
            ->assertJsonPath('profiles.0.handle', 'avery-radio')
            ->assertJsonPath('profiles.0.email', 'avery@example.test')
            ->assertJsonPath('profiles.0.phone', '555-0100')
            ->assertJsonPath('profiles.0.city', 'Portland')
            ->assertJsonPath('profiles.0.state', 'OR')
            ->assertJsonPath('profiles.0.date_of_birth', '1990-04-01')
            ->assertJsonPath('profiles.0.self_editable_fields', [
                'preferred_name', 'phone', 'city', 'state',
            ]);
    }

    public function test_profile_read_requires_a_credential(): void
    {
        $this->getJson('/api/me/profile')->assertUnauthorized();
    }

    public function test_login_with_no_staff_profile_reads_an_empty_list(): void
    {
        $user = User::factory()->create();

        $this->actingAsClient($user)
            ->getJson('/api/me/profile')
            ->assertOk()
            ->assertJsonPath('profiles', []);
    }

    public function test_staff_member_updates_self_service_fields_immediately(): void
    {
        [$user, $staff] = $this->userWithStaffProfile([
            'preferred_name' => 'Old Name',
            'phone' => '555-0100',
            'city' => 'Portland',
            'state' => 'OR',
        ]);

        $this->actingAsClient($user)
            ->postJson('/api/commands/update-my-profile', [
                'preferred_name' => '  New Name  ',
                'phone' => '555-0199',
                'city' => 'Eugene',
                'state' => 'OR',
            ])
            ->assertOk()
            ->assertJsonPath('profile.preferred_name', 'New Name')
            ->assertJsonPath('profile.phone', '555-0199')
            ->assertJsonPath('profile.city', 'Eugene')
            ->assertJsonPath('profile.state', 'OR');

        $this->assertDatabaseHas('staff', [
            'id' => $staff->id,
            'preferred_name' => 'New Name',
            'phone' => '555-0199',
            'city' => 'Eugene',
            'state' => 'OR',
        ]);
    }

    public function test_self_service_edit_audits_previous_and_new_values(): void
    {
        [$user, $staff] = $this->userWithStaffProfile([
            'preferred_name' => 'Old Name',
            'phone' => '555-0100',
            'city' => 'Portland',
            'state' => 'OR',
        ]);

        $this->actingAsClient($user)
            ->postJson('/api/commands/update-my-profile', [
                'preferred_name' => 'New Name',
                'city' => 'Eugene',
            ])
            ->assertOk();

        $audit = AuditEvent::query()
            ->where('action', 'staff.profile.self_updated')
            ->where('entity_id', (string) $staff->id)
            ->sole();

        $this->assertSame((string) $user->id, (string) $audit->actor_user_id);
        // VOL-026: the previous and new value of each changed field — and only
        // the changed fields, so the audit trail states what this edit did.
        $this->assertSame(
            ['preferred_name' => 'Old Name', 'city' => 'Portland'],
            $audit->before_json,
        );
        $this->assertSame(
            ['preferred_name' => 'New Name', 'city' => 'Eugene'],
            $audit->after_json,
        );
    }

    public function test_an_edit_that_changes_nothing_records_no_audit(): void
    {
        [$user, $staff] = $this->userWithStaffProfile([
            'preferred_name' => 'Same Name',
        ]);

        $this->actingAsClient($user)
            ->postJson('/api/commands/update-my-profile', [
                'preferred_name' => 'Same Name',
            ])
            ->assertOk();

        $this->assertSame(0, AuditEvent::query()
            ->where('action', 'staff.profile.self_updated')
            ->count());
    }

    public function test_staff_member_cannot_edit_anothers_profile(): void
    {
        [$user] = $this->userWithStaffProfile();
        $other = Staff::factory()->create(['preferred_name' => 'Not Yours']);

        $this->actingAsClient($user)
            ->postJson('/api/commands/update-my-profile', [
                'staff_id' => (string) $other->id,
                'preferred_name' => 'Hijacked',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('staff', [
            'id' => $other->id,
            'preferred_name' => 'Not Yours',
        ]);
    }

    public function test_submitted_identity_fields_are_refused_not_dropped(): void
    {
        [$user, $staff] = $this->userWithStaffProfile([
            'legal_name' => 'Avery Example',
            'email' => 'avery@example.test',
            'date_of_birth' => '1990-04-01',
        ]);

        foreach ([
            'legal_name' => 'Somebody Else',
            'email' => 'else@example.test',
            'date_of_birth' => '2001-01-01',
        ] as $field => $value) {
            $this->actingAsClient($user)
                ->postJson('/api/commands/update-my-profile', [
                    $field => $value,
                    // A legitimate edit alongside the refused field must not
                    // half-apply.
                    'preferred_name' => 'Should Not Apply',
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseHas('staff', [
            'id' => $staff->id,
            'legal_name' => 'Avery Example',
            'email' => 'avery@example.test',
        ]);
        $this->assertNotSame('Should Not Apply', $staff->refresh()->preferred_name);
        $this->assertSame('1990-04-01', $staff->date_of_birth?->toDateString());
    }

    public function test_handle_and_emergency_contact_are_refused(): void
    {
        [$user, $staff] = $this->userWithStaffProfile([
            'handle' => 'original-handle',
        ]);

        foreach ([
            'handle' => 'new-handle',
            'emergency_contact_name' => 'New Contact',
            'emergency_contact_phone' => '555-0000',
            'formerly_known_as' => 'A Mystery',
        ] as $field => $value) {
            $this->actingAsClient($user)
                ->postJson('/api/commands/update-my-profile', [$field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame('original-handle', $staff->refresh()->handle);
    }

    public function test_login_speaking_for_two_staff_records_must_name_one(): void
    {
        [$user] = $this->userWithStaffProfile(['preferred_name' => 'First']);
        $second = Staff::factory()->create(['preferred_name' => 'Second']);
        $user->staffProfiles()->attach($second->id);

        $this->actingAsClient($user)
            ->postJson('/api/commands/update-my-profile', [
                'preferred_name' => 'Renamed',
            ])
            ->assertStatus(422);

        $this->actingAsClient($user)
            ->postJson('/api/commands/update-my-profile', [
                'staff_id' => (string) $second->id,
                'preferred_name' => 'Renamed',
            ])
            ->assertOk()
            ->assertJsonPath('profile.preferred_name', 'Renamed');

        $this->assertDatabaseHas('staff', [
            'id' => $second->id,
            'preferred_name' => 'Renamed',
        ]);
    }

    public function test_blank_values_clear_the_nullable_fields(): void
    {
        [$user, $staff] = $this->userWithStaffProfile([
            'preferred_name' => 'Avery',
            'phone' => '555-0100',
        ]);

        $this->actingAsClient($user)
            ->postJson('/api/commands/update-my-profile', [
                'preferred_name' => '  ',
                'phone' => null,
            ])
            ->assertOk()
            ->assertJsonPath('profile.preferred_name', null)
            ->assertJsonPath('profile.phone', null);

        $staff->refresh();
        $this->assertNull($staff->preferred_name);
        $this->assertNull($staff->phone);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: Staff}
     */
    private function userWithStaffProfile(array $attributes = []): array
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create($attributes);
        $user->staffProfiles()->attach($staff->id);

        return [$user, $staff];
    }
}
