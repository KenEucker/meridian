<?php

namespace Tests\Feature;

use App\Exceptions\FieldReportAppendException;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Node;
use App\Models\User;
use App\Services\FieldReports\FieldReportAppendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldReportAppendTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_append_without_changing_original_body(): void
    {
        Carbon::setTestNow('2027-07-04 20:30:00 UTC');
        [$report, $attributes] = $this->validAppend();

        $append = app(FieldReportAppendService::class)->append($attributes);

        $this->assertSame($attributes['id'], $append->id);
        $this->assertSame($report->id, $append->field_report_id);
        $this->assertSame('Additional detail observed later.', $append->body);
        $this->assertSame('accepted', $report->fresh()->sync_status);
        $this->assertSame('Original immutable body', $report->fresh()->body);
        $this->assertTrue($append->server_received_at->equalTo(now()));
        $this->assertDatabaseHas('field_report_appends', [
            'id' => $attributes['id'],
            'field_report_id' => $report->id,
            'appended_by_user_id' => $attributes['appended_by_user_id'],
            'body' => 'Additional detail observed later.',
        ]);
        $this->assertDatabaseHas('field_reports', [
            'id' => $report->id,
            'body' => 'Original immutable body',
        ]);
    }

    public function test_multiple_appends_are_preserved_in_timeline_order(): void
    {
        [$report, $first] = $this->validAppend();
        $second = $first;
        $second['id'] = (string) Str::uuid();
        $second['body'] = 'Second append body';
        $second['device_submitted_at'] = '2027-07-04T14:00:00Z';

        $service = app(FieldReportAppendService::class);
        $acceptedFirst = $service->append($first);
        $acceptedSecond = $service->append($second);

        $this->assertSame(
            [$acceptedFirst->id, $acceptedSecond->id],
            $report->fresh()->appends->pluck('id')->all(),
        );
        $this->assertSame('Original immutable body', $report->fresh()->body);
        $this->assertDatabaseCount('field_report_appends', 2);
    }

    public function test_blank_append_body_is_rejected_without_persisting(): void
    {
        [, $attributes] = $this->validAppend();
        $attributes['body'] = "   \n\t  ";

        $this->expectException(FieldReportAppendException::class);
        $this->expectExceptionMessage('append body text is required');

        try {
            app(FieldReportAppendService::class)->append($attributes);
        } finally {
            $this->assertDatabaseMissing('field_report_appends', ['id' => $attributes['id']]);
        }
    }

    public function test_non_author_append_is_rejected_without_persisting(): void
    {
        [$report, $attributes] = $this->validAppend();
        $otherUser = User::factory()->create();
        $attributes['appended_by_user_id'] = $otherUser->id;
        DeviceTrust::factory()->create([
            'user_id' => $otherUser->id,
            'device_id' => $attributes['origin_device_id'],
        ]);

        $this->expectException(FieldReportAppendException::class);
        $this->expectExceptionMessage('Only the original Field Report author may append');

        try {
            app(FieldReportAppendService::class)->append($attributes);
        } finally {
            $this->assertDatabaseMissing('field_report_appends', ['id' => $attributes['id']]);
            $this->assertSame('Original immutable body', $report->fresh()->body);
        }
    }

    public function test_untrusted_device_append_is_rejected_without_persisting(): void
    {
        [, $attributes] = $this->validAppend();
        $attributes['origin_device_id'] = Device::factory()->create()->id;

        $this->expectException(FieldReportAppendException::class);
        $this->expectExceptionMessage('origin device is not actively trusted');

        try {
            app(FieldReportAppendService::class)->append($attributes);
        } finally {
            $this->assertDatabaseMissing('field_report_appends', ['id' => $attributes['id']]);
        }
    }

    public function test_revoked_node_append_is_rejected_without_persisting(): void
    {
        [, $attributes] = $this->validAppend();
        $node = Node::query()->findOrFail($attributes['origin_node_id']);
        $node->forceFill(['revoked_at' => now()])->save();

        $this->expectException(FieldReportAppendException::class);
        $this->expectExceptionMessage('origin node is revoked');

        try {
            app(FieldReportAppendService::class)->append($attributes);
        } finally {
            $this->assertDatabaseMissing('field_report_appends', ['id' => $attributes['id']]);
        }
    }

    public function test_duplicate_append_uuid_is_idempotent(): void
    {
        [, $attributes] = $this->validAppend();
        $service = app(FieldReportAppendService::class);

        $first = $service->append($attributes);
        $retried = $service->append($attributes);

        $this->assertTrue($first->is($retried));
        $this->assertSame('Additional detail observed later.', $retried->body);
        $this->assertDatabaseCount('field_report_appends', 1);
    }

    public function test_duplicate_append_uuid_with_different_source_data_is_rejected(): void
    {
        [, $attributes] = $this->validAppend();
        $service = app(FieldReportAppendService::class);
        $accepted = $service->append($attributes);

        $attributes['body'] = 'Conflicting append text.';

        try {
            $service->append($attributes);
            $this->fail('A conflicting retry should not be accepted.');
        } catch (FieldReportAppendException $exception) {
            $this->assertStringContainsString('different source data', $exception->getMessage());
        }

        $this->assertDatabaseCount('field_report_appends', 1);
        $this->assertSame(
            'Additional detail observed later.',
            $accepted->fresh()->body,
        );
    }

    /**
     * @return array{FieldReport, array<string, string>}
     */
    private function validAppend(): array
    {
        $event = Event::factory()->create();
        $author = User::factory()->create();
        $device = Device::factory()->create();
        DeviceTrust::factory()->create([
            'user_id' => $author->id,
            'device_id' => $device->id,
        ]);
        $node = Node::factory()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
        ]);
        $report = FieldReport::factory()
            ->forEvent($event)
            ->forAuthor($author)
            ->receivedByServer()
            ->create([
                'body' => 'Original immutable body',
                'origin_device_id' => $device->id,
                'origin_node_id' => $node->id,
            ]);

        return [$report, [
            'id' => (string) Str::uuid(),
            'field_report_id' => $report->id,
            'appended_by_user_id' => $author->id,
            'body' => 'Additional detail observed later.',
            'device_submitted_at' => '2027-07-04T13:45:00Z',
            'origin_device_id' => $device->id,
            'origin_node_id' => $node->id,
        ]];
    }
}
