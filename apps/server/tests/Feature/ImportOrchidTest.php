<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * God-mode Orchid CSV import screens for users and teams (technical spec 22.2).
 */
class ImportOrchidTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_import_screens_describe_their_columns(): void
    {
        $user = $this->importUser();

        $users = $this->actingAs($user)->get(route('platform.imports.users'));
        $users->assertOk();
        $users->assertSee('Import Users');
        $users->assertSee('Required columns: email, name.', false);
        $users->assertSee('Preview');

        $teams = $this->actingAs($user)->get(route('platform.imports.teams'));
        $teams->assertOk();
        $teams->assertSee('Import Teams');
        $teams->assertSee('Required columns: organization_slug, department_code, name, code.', false);
    }

    public function test_a_console_user_without_the_import_permission_is_denied(): void
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.systems.users' => true,
                'platform.teams' => true,
            ],
        ]);

        $this->actingAs($user)->get(route('platform.imports.users'))->assertForbidden();
        $this->actingAs($user)->get(route('platform.imports.teams'))->assertForbidden();

        $this->actingAs($user)
            ->post(route('platform.imports.users', ['method' => 'import']), [
                'csv' => "email,name\nvera.staff@example.org,Vera Staff\n",
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'vera.staff@example.org']);
    }

    public function test_pasted_csv_imports_users_and_reports_each_row(): void
    {
        $user = $this->importUser();

        $response = $this->actingAs($user)
            ->from(route('platform.imports.users'))
            ->post(route('platform.imports.users', ['method' => 'import']), [
                'csv' => "email,name\nvera.staff@example.org,Vera Staff\nbroken,Broken Row\n",
            ]);

        $response->assertRedirect(route('platform.imports.users'));

        $this->assertDatabaseHas('users', ['email' => 'vera.staff@example.org']);

        $screen = $this->actingAs($user)->get(route('platform.imports.users'));
        $screen->assertOk();
        $screen->assertSee('Import result');
        $screen->assertSee('vera.staff@example.org');
        $screen->assertSee('Email address is not valid.');
    }

    public function test_an_uploaded_file_imports_teams(): void
    {
        $organization = Organization::factory()->create(['slug' => 'idaho-burners']);
        Department::factory()->create([
            'organization_id' => $organization->id,
            'code' => 'RANGERS',
        ]);

        $user = $this->importUser();

        $file = UploadedFile::fake()->createWithContent(
            'teams.csv',
            "organization_slug,department_code,name,code\nidaho-burners,RANGERS,Dirt,DIRT\n",
        );

        $this->actingAs($user)
            ->post(route('platform.imports.teams', ['method' => 'import']), ['file' => $file])
            ->assertRedirect(route('platform.imports.teams'));

        $this->assertSame(1, Team::query()->where('code', 'DIRT')->count());
    }

    public function test_a_preview_shows_outcomes_without_saving(): void
    {
        $user = $this->importUser();

        $this->actingAs($user)
            ->post(route('platform.imports.users', ['method' => 'preview']), [
                'csv' => "email,name\nvera.staff@example.org,Vera Staff\n",
            ])
            ->assertRedirect(route('platform.imports.users'));

        $this->assertDatabaseMissing('users', ['email' => 'vera.staff@example.org']);

        $screen = $this->actingAs($user)->get(route('platform.imports.users'));
        $screen->assertSee('Preview result');
        $screen->assertSee('Nothing was saved.', false);
    }

    public function test_a_file_missing_a_required_column_is_reported_without_importing(): void
    {
        $user = $this->importUser();

        $this->actingAs($user)
            ->post(route('platform.imports.users', ['method' => 'import']), [
                'csv' => "username,name\nvera,Vera Staff\n",
            ])
            ->assertRedirect(route('platform.imports.users'));

        $this->assertSame(1, User::query()->count());
    }

    private function importUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.imports' => true,
            ],
        ]);
    }
}
