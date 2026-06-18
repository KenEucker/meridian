<?php

namespace Tests\Feature;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAuthIdentitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_meridian_identity_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'current_volunteer_id'));
        $this->assertTrue(Schema::hasColumn('users', 'disabled_at'));
    }

    public function test_auth_identities_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('auth_identities'));
        $this->assertTrue(Schema::hasColumn('auth_identities', 'user_id'));
        $this->assertTrue(Schema::hasColumn('auth_identities', 'provider'));
        $this->assertTrue(Schema::hasColumn('auth_identities', 'provider_subject'));
        $this->assertTrue(Schema::hasColumn('auth_identities', 'provider_email'));
        $this->assertTrue(Schema::hasColumn('auth_identities', 'provider_email_verified'));
        $this->assertTrue(Schema::hasColumn('auth_identities', 'created_at'));
        $this->assertTrue(Schema::hasColumn('auth_identities', 'updated_at'));
    }

    public function test_user_has_many_auth_identities(): void
    {
        $user = User::factory()->create();

        $emailIdentity = AuthIdentity::factory()->for($user)->create([
            'provider' => AuthIdentity::PROVIDER_EMAIL,
            'provider_subject' => 'user@example.com',
        ]);

        $googleIdentity = AuthIdentity::factory()->for($user)->google()->create([
            'provider_email' => 'user@example.com',
        ]);

        $user->refresh()->load('authIdentities');

        $this->assertCount(2, $user->authIdentities);
        $this->assertTrue($user->authIdentities->contains($emailIdentity));
        $this->assertTrue($user->authIdentities->contains($googleIdentity));
    }

    public function test_auth_identity_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $identity = AuthIdentity::factory()->for($user)->create();

        $this->assertTrue($identity->user->is($user));
    }

    public function test_user_disabled_at_is_cast_and_queryable(): void
    {
        $disabledAt = now()->subDay();

        $user = User::factory()->create([
            'disabled_at' => $disabledAt,
        ]);

        $user->refresh();

        $this->assertTrue($user->isDisabled());
        $this->assertSame(
            $disabledAt->toDateTimeString(),
            $user->disabled_at->toDateTimeString()
        );
    }

    public function test_auth_identities_enforce_unique_provider_subject_per_provider(): void
    {
        AuthIdentity::factory()->create([
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'provider_subject' => 'google-subject-123',
        ]);

        $this->expectException(QueryException::class);

        AuthIdentity::factory()->create([
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'provider_subject' => 'google-subject-123',
        ]);
    }

    public function test_same_provider_subject_can_exist_on_different_providers(): void
    {
        $subject = 'shared-subject-123';

        AuthIdentity::factory()->create([
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'provider_subject' => $subject,
        ]);

        $discordIdentity = AuthIdentity::factory()->discord()->create([
            'provider_subject' => $subject,
        ]);

        $this->assertDatabaseHas('auth_identities', [
            'id' => $discordIdentity->id,
            'provider' => AuthIdentity::PROVIDER_DISCORD,
            'provider_subject' => $subject,
        ]);
    }

    public function test_deleting_user_cascades_auth_identities(): void
    {
        $user = User::factory()->create();
        $identity = AuthIdentity::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseMissing('auth_identities', [
            'id' => $identity->id,
        ]);
    }
}
