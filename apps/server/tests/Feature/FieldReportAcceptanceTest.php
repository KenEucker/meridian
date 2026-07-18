<?php

namespace Tests\Feature;

use App\Exceptions\FieldReportAcceptanceException;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Node;
use App\Models\Staff;
use App\Models\User;
use App\Services\FieldReports\FieldReportAcceptanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldReportAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_accepts_device_created_report_and_assigns_fra_number(): void
    {
        Carbon::setTestNow('2027-07-04 20:30:00 UTC');
        [$event, $attributes] = $this->validSubmission();
        $event->forceFill([
            'starts_at' => Carbon::parse('2027-07-04 09:00:00', 'America/Los_Angeles')->utc(),
        ])->save();

        $report = app(FieldReportAcceptanceService::class)->accept($attributes);

        $this->assertSame($attributes['id'], $report->id);
        $this->assertSame('FRA-2027-000001', $report->fra_number);
        $this->assertSame('LOCAL-ABC12345', $report->temporary_local_number);
        $this->assertSame('Medical assist near Gate A', $report->title);
        $this->assertSame('Observed a medical assist near Gate A.', $report->body);
        $this->assertSame('accepted', $report->sync_status);
        $this->assertTrue($report->server_received_at->equalTo(now()));
        $this->assertTrue($report->device_submitted_at->equalTo(Carbon::parse($attributes['device_submitted_at'])));
        $this->assertDatabaseHas('field_reports', [
            'id' => $attributes['id'],
            'event_id' => $event->id,
            'fra_number' => 'FRA-2027-000001',
            'sync_status' => 'accepted',
        ]);
    }

    public function test_fra_sequences_are_monotonic_and_event_specific(): void
    {
        [$eventA, $first] = $this->validSubmission();
        [$eventB, $otherEvent] = $this->validSubmission();

        $second = $first;
        $second['id'] = (string) Str::uuid();
        $second['temporary_local_number'] = 'LOCAL-SECOND01';

        $service = app(FieldReportAcceptanceService::class);

        $acceptedFirst = $service->accept($first);
        $acceptedSecond = $service->accept($second);
        $acceptedOtherEvent = $service->accept($otherEvent);

        $yearA = $eventA->starts_at->copy()->setTimezone($eventA->timezone)->year;
        $yearB = $eventB->starts_at->copy()->setTimezone($eventB->timezone)->year;

        $this->assertSame(sprintf('FRA-%04d-000001', $yearA), $acceptedFirst->fra_number);
        $this->assertSame(sprintf('FRA-%04d-000002', $yearA), $acceptedSecond->fra_number);
        $this->assertSame(sprintf('FRA-%04d-000001', $yearB), $acceptedOtherEvent->fra_number);
    }

    public function test_duplicate_report_uuid_is_idempotent_and_does_not_consume_number(): void
    {
        [, $attributes] = $this->validSubmission();
        $service = app(FieldReportAcceptanceService::class);

        $first = $service->accept($attributes);
        $retried = $service->accept($attributes);

        $next = $attributes;
        $next['id'] = (string) Str::uuid();
        $next['temporary_local_number'] = 'LOCAL-NEXT0001';
        $acceptedNext = $service->accept($next);

        $this->assertTrue($first->is($retried));
        $this->assertSame('Medical assist near Gate A', $retried->title);
        $this->assertSame('Observed a medical assist near Gate A.', $retried->body);
        $this->assertSame(2, FieldReport::query()->count());
        $this->assertStringEndsWith('-000002', $acceptedNext->fra_number);
    }

    public function test_duplicate_uuid_with_different_source_data_is_rejected(): void
    {
        [, $attributes] = $this->validSubmission();
        $service = app(FieldReportAcceptanceService::class);
        $accepted = $service->accept($attributes);

        $attributes['body'] = 'Conflicting source text.';

        try {
            $service->accept($attributes);
            $this->fail('A conflicting retry should not be accepted.');
        } catch (FieldReportAcceptanceException $exception) {
            $this->assertStringContainsString('different source data', $exception->getMessage());
        }

        $this->assertDatabaseCount('field_reports', 1);
        $this->assertSame('Medical assist near Gate A', $accepted->fresh()->title);
        $this->assertSame(
            'Observed a medical assist near Gate A.',
            $accepted->fresh()->body,
        );
    }

    public function test_acceptance_trims_title_and_allows_duplicate_titles(): void
    {
        [, $first] = $this->validSubmission();
        [, $second] = $this->validSubmission();
        $service = app(FieldReportAcceptanceService::class);

        $first['title'] = '  Shared title  ';
        $second['title'] = 'Shared title';

        $acceptedFirst = $service->accept($first);
        $acceptedSecond = $service->accept($second);

        $this->assertSame('Shared title', $acceptedFirst->title);
        $this->assertSame('Shared title', $acceptedSecond->title);
        $this->assertNotSame($acceptedFirst->id, $acceptedSecond->id);
    }

    public function test_acceptance_rejects_missing_or_oversized_title(): void
    {
        [, $missing] = $this->validSubmission();
        $missing['title'] = '   ';

        try {
            app(FieldReportAcceptanceService::class)->accept($missing);
            $this->fail('Blank title should be rejected.');
        } catch (FieldReportAcceptanceException $exception) {
            $this->assertStringContainsString('title is required', $exception->getMessage());
        }

        $this->assertDatabaseMissing('field_reports', ['id' => $missing['id']]);

        [, $oversized] = $this->validSubmission();
        $oversized['title'] = str_repeat('a', 201);

        $this->expectException(FieldReportAcceptanceException::class);
        $this->expectExceptionMessage('at most 200 characters');

        try {
            app(FieldReportAcceptanceService::class)->accept($oversized);
        } finally {
            $this->assertDatabaseMissing('field_reports', ['id' => $oversized['id']]);
        }
    }

    public function test_duplicate_uuid_with_different_title_is_rejected(): void
    {
        [, $attributes] = $this->validSubmission();
        $service = app(FieldReportAcceptanceService::class);
        $accepted = $service->accept($attributes);

        $attributes['title'] = 'Different title';

        try {
            $service->accept($attributes);
            $this->fail('A conflicting title retry should not be accepted.');
        } catch (FieldReportAcceptanceException $exception) {
            $this->assertStringContainsString('different source data', $exception->getMessage());
        }

        $this->assertSame('Medical assist near Gate A', $accepted->fresh()->title);
    }

    public function test_acceptance_rejects_unlinked_staff_without_persisting_report(): void
    {
        [, $attributes] = $this->validSubmission();
        $attributes['staff_id'] = Staff::factory()->create()->id;

        $this->expectException(FieldReportAcceptanceException::class);
        $this->expectExceptionMessage('staff record is not linked');

        try {
            app(FieldReportAcceptanceService::class)->accept($attributes);
        } finally {
            $this->assertDatabaseMissing('field_reports', ['id' => $attributes['id']]);
        }
    }

    public function test_acceptance_rejects_untrusted_device_without_persisting_report(): void
    {
        [, $attributes] = $this->validSubmission();
        $attributes['origin_device_id'] = Device::factory()->create()->id;

        $this->expectException(FieldReportAcceptanceException::class);
        $this->expectExceptionMessage('origin device is not actively trusted');

        try {
            app(FieldReportAcceptanceService::class)->accept($attributes);
        } finally {
            $this->assertDatabaseMissing('field_reports', ['id' => $attributes['id']]);
        }
    }

    /**
     * @return array{Event, array<string, string|null>}
     */
    private function validSubmission(): array
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $staff->users()->attach($user);
        $device = Device::factory()->create();
        DeviceTrust::factory()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
        ]);
        $node = Node::factory()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
        ]);

        return [$event, [
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'department_id' => null,
            'team_id' => null,
            'submitted_by_user_id' => $user->id,
            'staff_id' => $staff->id,
            'temporary_local_number' => 'LOCAL-ABC12345',
            'title' => 'Medical assist near Gate A',
            'body' => 'Observed a medical assist near Gate A.',
            'device_submitted_at' => '2027-07-04T13:22:10Z',
            'origin_device_id' => $device->id,
            'origin_node_id' => $node->id,
        ]];
    }
}
