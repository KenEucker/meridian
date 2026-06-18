<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

class TeamOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_orchid_team_list_displays_teams(): void
    {
        $department = Department::factory()->create([
            'name' => 'Rangers',
        ]);

        Team::factory()->for($department)->create([
            'name' => 'Operators',
            'code' => 'OPERATORS',
        ]);

        $response = $this->actingAs($this->teamAdmin())->get(route('platform.teams'));

        $response->assertOk();
        $response->assertSee('Teams');
        $response->assertSee('Operators');
        $response->assertSee('OPERATORS');
        $response->assertSee('Rangers');
        $response->assertSee('Default');
    }

    public function test_orchid_team_detail_displays_edit_scaffold(): void
    {
        $team = Team::factory()->create([
            'name' => 'Logistics',
            'code' => 'LOGISTICS',
        ]);

        $response = $this->actingAs($this->teamAdmin())
            ->get(route('platform.teams.edit', $team));

        $response->assertOk();
        $response->assertSee('Edit Team');
        $response->assertSee('Logistics');
        $response->assertSee('LOGISTICS');
        $response->assertSee('meridian-admin.js');
        $response->assertSee('data-meridian-slug-target="team[code]"', false);
        $response->assertSee('data-meridian-slug-format="code"', false);
        $response->assertSee('Archive');
        $response->assertSee('Save');
        $response->assertSee('Cancel');
    }

    public function test_orchid_team_screen_requires_permission(): void
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('platform.teams'));

        $response->assertForbidden();
    }

    public function test_orchid_team_save_creates_team(): void
    {
        $department = Department::factory()->create();

        $response = $this->screen('platform.teams.create')
            ->actingAs($this->teamAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'team' => [
                    'department_id' => $department->id,
                    'name' => 'Operators',
                    'code' => 'OPERATORS',
                    'description' => 'Radio operators and dispatch support.',
                ],
            ]);

        $response->assertRedirect(route('platform.teams'));

        $this->assertDatabaseHas('teams', [
            'department_id' => $department->id,
            'name' => 'Operators',
            'code' => 'OPERATORS',
            'description' => 'Radio operators and dispatch support.',
            'is_default' => false,
        ]);
    }

    public function test_orchid_team_save_validates_unique_code_within_department(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();

        Team::factory()->for($department)->create([
            'code' => 'OPERATORS',
        ]);

        Team::factory()->for($otherDepartment)->create([
            'code' => 'OPERATORS',
        ]);

        $response = $this->screen('platform.teams.create')
            ->actingAs($this->teamAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'team' => [
                    'department_id' => $department->id,
                    'name' => 'Duplicate Team',
                    'code' => 'OPERATORS',
                ],
            ]);

        $response->assertSessionHasErrors('team.code');
    }

    public function test_orchid_team_archive_and_restore_preserves_team_record(): void
    {
        $team = Team::factory()->create();

        $archiveResponse = $this->screen('platform.teams.edit', [
            'team' => $team->id,
        ])
            ->actingAs($this->teamAdmin())
            ->withoutFollowingRedirects()
            ->method('archive');

        $archiveResponse->assertRedirect(route('platform.teams'));
        $this->assertTrue($team->refresh()->isArchived());

        $restoreResponse = $this->screen('platform.teams.edit', [
            'team' => $team->id,
        ])
            ->actingAs($this->teamAdmin())
            ->withoutFollowingRedirects()
            ->method('restore');

        $restoreResponse->assertRedirect(route('platform.teams'));
        $this->assertFalse($team->refresh()->isArchived());
    }

    public function test_orchid_team_archive_blocks_default_team(): void
    {
        $department = Department::factory()->create();
        $defaultTeam = $department->refresh()->defaultTeam;

        $response = $this->screen('platform.teams.edit', [
            'team' => $defaultTeam->id,
        ])
            ->actingAs($this->teamAdmin())
            ->withoutFollowingRedirects()
            ->method('archive');

        $response->assertRedirect(route('platform.teams.edit', $defaultTeam));
        $this->assertFalse($defaultTeam->refresh()->isArchived());
    }

    private function teamAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.teams' => true,
            ],
        ]);
    }
}
