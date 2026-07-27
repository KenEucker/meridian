<?php

namespace Tests\Feature;

use App\Models\SyncConflict;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

/**
 * God-mode Orchid queue for sync conflicts (technical spec 10.3; data/API
 * 14.2; UI contract `orchid.sync-conflicts`).
 */
class SyncConflictOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_god_mode_user_can_view_the_conflict_queue_grouped_by_entity_type(): void
    {
        $attendance = SyncConflict::factory()->forEntity('attendance_record')->create([
            'reason' => 'Attendance local and remote disagree.',
            'local_value_json' => ['status' => 'open'],
            'remote_value_json' => ['status' => 'no_show'],
        ]);
        $incident = SyncConflict::factory()->forEntity('incident')->create([
            'reason' => 'Incident title mismatch.',
        ]);

        $user = $this->godModeUser();

        $response = $this->actingAs($user)->get(route('platform.sync-conflicts'));

        $response->assertOk();
        $response->assertSee('Sync Conflicts');
        $response->assertSee('attendance_record');
        $response->assertSee('incident');
        $response->assertSee('Attendance local and remote disagree.');
        $response->assertSee('Incident title mismatch.');
        $response->assertSee('Open');

        $body = $response->getContent();
        $this->assertNotFalse($body);
        $this->assertLessThan(
            strpos($body, 'incident'),
            strpos($body, 'attendance_record'),
            'Conflicts should be ordered by entity type so reviewers can work one class at a time.',
        );

        $this->assertNotNull($attendance->id);
        $this->assertNotNull($incident->id);
    }

    public function test_god_mode_user_can_review_local_and_remote_values(): void
    {
        $conflict = SyncConflict::factory()->forEntity('attendance_record')->create([
            'reason' => 'Check-out times disagree.',
            'local_value_json' => ['checked_out_at' => '2026-07-27T17:00:00Z'],
            'remote_value_json' => ['checked_out_at' => '2026-07-27T18:00:00Z'],
        ]);

        $user = $this->godModeUser();

        $response = $this->actingAs($user)->get(route('platform.sync-conflicts.show', $conflict));

        $response->assertOk();
        $response->assertSee('Review Sync Conflict');
        $response->assertSee('attendance_record');
        $response->assertSee('Check-out times disagree.');
        $response->assertSee('Local value');
        $response->assertSee('Remote value');
        $response->assertSee('2026-07-27T17:00:00Z');
        $response->assertSee('2026-07-27T18:00:00Z');
        $response->assertSee('Back to queue');
        $response->assertDontSee('>Accept on-site<', false);
        $response->assertDontSee('>Accept central<', false);
    }

    public function test_sync_conflict_screens_require_god_mode_permission(): void
    {
        $conflict = SyncConflict::factory()->create();
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $this->actingAs($user)
            ->get(route('platform.sync-conflicts'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('platform.sync-conflicts.show', $conflict))
            ->assertForbidden();
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.sync-conflicts' => true,
            ],
        ]);
    }
}
