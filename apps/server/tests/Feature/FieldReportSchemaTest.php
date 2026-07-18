<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Device;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Node;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FieldReportSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_reports_table_has_documented_columns(): void
    {
        $this->assertTrue(Schema::hasTable('field_reports'));

        foreach ([
            'id',
            'event_id',
            'department_id',
            'team_id',
            'submitted_by_user_id',
            'staff_id',
            'fra_number',
            'temporary_local_number',
            'body',
            'device_submitted_at',
            'server_received_at',
            'origin_device_id',
            'origin_node_id',
            'sync_status',
            'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('field_reports', $column),
                "field_reports should have a {$column} column.",
            );
        }
    }

    public function test_field_reports_have_no_mutable_or_stricken_columns(): void
    {
        foreach (['updated_at', 'deleted_at', 'stricken_at', 'archived_at'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('field_reports', $column),
                "field_reports.{$column} should not exist (FR-007 / FR-008).",
            );
        }
    }

    public function test_field_reports_use_uuid_primary_keys(): void
    {
        $report = FieldReport::factory()->create();

        $this->assertFalse($report->getIncrementing());
        $this->assertSame('string', $report->getKeyType());
        $this->assertTrue(Str::isUuid($report->getKey()));
    }

    public function test_field_report_belongs_to_event_author_staff_device_and_node(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        $team = $department->defaultTeam;
        $author = User::factory()->create();
        $staff = Staff::factory()->create();
        $device = Device::factory()->create();
        $node = Node::factory()->create();

        $report = FieldReport::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'team_id' => $team->id,
            'submitted_by_user_id' => $author->id,
            'staff_id' => $staff->id,
            'body' => 'Observed a medical assist near Gate A.',
            'origin_device_id' => $device->id,
            'origin_node_id' => $node->id,
            'fra_number' => null,
            'temporary_local_number' => 'LOCAL-1',
        ]);

        $report->refresh()->load([
            'event',
            'department',
            'team',
            'submittedByUser',
            'staff',
            'originDevice',
            'originNode',
        ]);

        $this->assertTrue($report->event->is($event));
        $this->assertTrue($report->department->is($department));
        $this->assertTrue($report->team->is($team));
        $this->assertTrue($report->submittedByUser->is($author));
        $this->assertTrue($report->staff->is($staff));
        $this->assertTrue($report->originDevice->is($device));
        $this->assertTrue($report->originNode->is($node));
        $this->assertSame('Observed a medical assist near Gate A.', $report->body);
        $this->assertSame('LOCAL-1', $report->temporary_local_number);
        $this->assertNull($report->fra_number);
        $this->assertNotNull($report->device_submitted_at);
        $this->assertNull($report->server_received_at);
        $this->assertNotNull($report->created_at);

        $this->assertTrue($event->fieldReports->contains($report));
        $this->assertTrue($author->submittedFieldReports->contains($report));
        $this->assertTrue($staff->fieldReports->contains($report));
        $this->assertTrue($department->fieldReports->contains($report));
        $this->assertTrue($team->fieldReports->contains($report));
    }

    public function test_field_reports_are_event_specific_and_author_scoped(): void
    {
        $organization = Organization::factory()->create();
        $eventA = Event::factory()->for($organization)->create();
        $eventB = Event::factory()->for($organization)->create();
        $author = User::factory()->create();
        $otherAuthor = User::factory()->create();

        $ownOnEventA = FieldReport::factory()->create([
            'event_id' => $eventA->id,
            'submitted_by_user_id' => $author->id,
            'body' => 'Author report on event A',
        ]);
        FieldReport::factory()->create([
            'event_id' => $eventA->id,
            'submitted_by_user_id' => $otherAuthor->id,
            'body' => 'Other author on event A',
        ]);
        FieldReport::factory()->create([
            'event_id' => $eventB->id,
            'submitted_by_user_id' => $author->id,
            'body' => 'Author report on event B',
        ]);

        $authorEventAIds = FieldReport::query()
            ->forEvent($eventA)
            ->forAuthor($author)
            ->pluck('id')
            ->all();

        $this->assertSame([$ownOnEventA->id], $authorEventAIds);
    }

    public function test_multiple_reports_may_exist_without_fra_number_on_same_event(): void
    {
        $event = Event::factory()->create();

        FieldReport::factory()->count(2)->create([
            'event_id' => $event->id,
            'fra_number' => null,
        ]);

        $this->assertDatabaseCount('field_reports', 2);
    }

    public function test_field_reports_cannot_be_updated(): void
    {
        $report = FieldReport::factory()->create([
            'body' => 'Original immutable body',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Field reports are immutable and cannot be updated.');

        $report->update(['body' => 'Tampered body']);
    }

    public function test_field_reports_cannot_be_deleted(): void
    {
        $report = FieldReport::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Field reports are immutable and cannot be deleted.');

        $report->delete();
    }

    public function test_field_report_appends_table_is_deferred(): void
    {
        $this->assertFalse(Schema::hasTable('field_report_appends'));
    }
}
