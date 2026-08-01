<?php

namespace Tests\Feature;

use App\Exceptions\FieldReportPhotoUploadException;
use App\Models\Attachment;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Node;
use App\Models\Staff;
use App\Models\User;
use App\Services\FieldReports\FieldReportAcceptanceService;
use App\Services\FieldReports\FieldReportPhotoUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldReportPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploads_photo_after_text_acceptance_and_stores_blob_with_checksum(): void
    {
        Carbon::setTestNow('2027-07-04 13:22:10 UTC');
        Storage::fake('attachments');

        [$report, $context] = $this->acceptedReport();
        $bytes = $this->createJpegBytes(80, 60);
        $checksum = hash('sha256', $bytes);
        $photoId = (string) Str::uuid();

        $attachment = app(FieldReportPhotoUploadService::class)->upload([
            'id' => $photoId,
            'field_report_id' => $report->id,
            'uploaded_by_user_id' => $context['user_id'],
            'origin_device_id' => $context['device_id'],
            'origin_node_id' => $context['node_id'],
            'bytes' => $bytes,
            'checksum_sha256' => $checksum,
            'declared_mime_type' => 'image/jpeg',
            'device_uploaded_at' => '2027-07-04T13:22:10Z',
        ]);

        $this->assertSame($photoId, $attachment->id);
        $this->assertSame(Attachment::MORPH_FIELD_REPORT, $attachment->attachable_type);
        $this->assertSame($report->id, $attachment->attachable_id);
        $this->assertSame(hash('sha256', Storage::disk('attachments')->get($attachment->storage_path)), $attachment->checksum);
        $this->assertTrue(Storage::disk('attachments')->exists($attachment->storage_path));
        $this->assertMatchesRegularExpression(
            '/^EVENT-2027_FRA-2027-000001_2027-07-04T13-22-10Z_01\.(webp|jpg)$/',
            $attachment->filename,
        );
        $this->assertSame(1, $report->fresh()->attachments()->count());
        $this->assertSame('accepted', $report->sync_status);
    }

    public function test_duplicate_photo_uuid_is_idempotent(): void
    {
        Storage::fake('attachments');
        [$report, $context] = $this->acceptedReport();
        $bytes = $this->createJpegBytes(40, 30);
        $payload = [
            'id' => (string) Str::uuid(),
            'field_report_id' => $report->id,
            'uploaded_by_user_id' => $context['user_id'],
            'origin_device_id' => $context['device_id'],
            'origin_node_id' => $context['node_id'],
            'bytes' => $bytes,
            'checksum_sha256' => hash('sha256', $bytes),
            'declared_mime_type' => 'image/jpeg',
        ];

        $service = app(FieldReportPhotoUploadService::class);
        $first = $service->upload($payload);
        $retried = $service->upload($payload);

        $this->assertTrue($first->is($retried));
        $this->assertSame(1, Attachment::query()->count());
        $this->assertCount(1, Storage::disk('attachments')->allFiles());
    }

    public function test_rejects_checksum_mismatch_without_persisting_attachment(): void
    {
        Storage::fake('attachments');
        [$report, $context] = $this->acceptedReport();
        $bytes = $this->createJpegBytes(40, 30);
        $photoId = (string) Str::uuid();

        try {
            app(FieldReportPhotoUploadService::class)->upload([
                'id' => $photoId,
                'field_report_id' => $report->id,
                'uploaded_by_user_id' => $context['user_id'],
                'origin_device_id' => $context['device_id'],
                'origin_node_id' => $context['node_id'],
                'bytes' => $bytes,
                'checksum_sha256' => str_repeat('a', 64),
                'declared_mime_type' => 'image/jpeg',
            ]);
            $this->fail('Checksum mismatch should be rejected.');
        } catch (FieldReportPhotoUploadException $exception) {
            $this->assertStringContainsString('checksum does not match', $exception->getMessage());
        }

        $this->assertDatabaseMissing('attachments', ['id' => $photoId]);
        $this->assertSame([], Storage::disk('attachments')->allFiles());
    }

    public function test_enforces_max_two_photos_per_field_report(): void
    {
        Storage::fake('attachments');
        [$report, $context] = $this->acceptedReport();
        $service = app(FieldReportPhotoUploadService::class);

        foreach ([1, 2] as $slot) {
            $bytes = $this->createJpegBytes(32 + $slot, 24 + $slot);
            $service->upload([
                'id' => (string) Str::uuid(),
                'field_report_id' => $report->id,
                'uploaded_by_user_id' => $context['user_id'],
                'origin_device_id' => $context['device_id'],
                'origin_node_id' => $context['node_id'],
                'bytes' => $bytes,
                'checksum_sha256' => hash('sha256', $bytes),
                'declared_mime_type' => 'image/jpeg',
            ]);
        }

        $bytes = $this->createJpegBytes(50, 40);
        $this->expectException(FieldReportPhotoUploadException::class);
        $this->expectExceptionMessage('at most 2 photos');

        $service->upload([
            'id' => (string) Str::uuid(),
            'field_report_id' => $report->id,
            'uploaded_by_user_id' => $context['user_id'],
            'origin_device_id' => $context['device_id'],
            'origin_node_id' => $context['node_id'],
            'bytes' => $bytes,
            'checksum_sha256' => hash('sha256', $bytes),
            'declared_mime_type' => 'image/jpeg',
        ]);
    }

    public function test_rejects_non_author_upload(): void
    {
        Storage::fake('attachments');
        [$report, $context] = $this->acceptedReport();
        $other = User::factory()->create();
        DeviceTrust::factory()->create([
            'user_id' => $other->id,
            'device_id' => $context['device_id'],
        ]);
        $bytes = $this->createJpegBytes(40, 30);

        $this->expectException(FieldReportPhotoUploadException::class);
        $this->expectExceptionMessage('original Field Report author');

        app(FieldReportPhotoUploadService::class)->upload([
            'id' => (string) Str::uuid(),
            'field_report_id' => $report->id,
            'uploaded_by_user_id' => $other->id,
            'origin_device_id' => $context['device_id'],
            'origin_node_id' => $context['node_id'],
            'bytes' => $bytes,
            'checksum_sha256' => hash('sha256', $bytes),
            'declared_mime_type' => 'image/jpeg',
        ]);
    }

    public function test_attachments_table_has_documented_columns_and_orchid_table_is_renamed(): void
    {
        $this->assertTrue(Schema::hasTable('attachments'));
        $this->assertTrue(Schema::hasTable('orchid_attachments'));
        $this->assertFalse(Schema::hasColumn('orchid_attachments', 'checksum'));

        foreach ([
            'id',
            'attachable_type',
            'attachable_id',
            'uploaded_by_user_id',
            'filename',
            'mime_type',
            'byte_size',
            'storage_disk',
            'storage_path',
            'checksum',
            'metadata_json',
            'origin_device_id',
            'origin_node_id',
            'created_at',
            'stricken_at',
            'deleted_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('attachments', $column),
                "attachments should have a {$column} column.",
            );
        }
    }

    public function test_attachment_records_are_immutable(): void
    {
        Storage::fake('attachments');
        [$report, $context] = $this->acceptedReport();
        $bytes = $this->createJpegBytes(40, 30);
        $attachment = app(FieldReportPhotoUploadService::class)->upload([
            'id' => (string) Str::uuid(),
            'field_report_id' => $report->id,
            'uploaded_by_user_id' => $context['user_id'],
            'origin_device_id' => $context['device_id'],
            'origin_node_id' => $context['node_id'],
            'bytes' => $bytes,
            'checksum_sha256' => hash('sha256', $bytes),
            'declared_mime_type' => 'image/jpeg',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');
        $attachment->filename = 'mutated.webp';
        $attachment->save();
    }

    /**
     * @return array{FieldReport, array{user_id: string, device_id: string, node_id: string}}
     */
    private function acceptedReport(): array
    {
        $event = Event::factory()->create([
            'starts_at' => Carbon::parse('2027-07-04 09:00:00', 'America/Los_Angeles')->utc(),
            'timezone' => 'America/Los_Angeles',
        ]);
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

        $report = app(FieldReportAcceptanceService::class)->accept([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'department_id' => null,
            'team_id' => null,
            'submitted_by_user_id' => $user->id,
            'staff_id' => $staff->id,
            'temporary_local_number' => 'LOCAL-PHOTO01',
            'title' => 'Photo sync report',
            'body' => 'Text accepted before photos.',
            'device_submitted_at' => '2027-07-04T13:20:00Z',
            'origin_device_id' => $device->id,
            'origin_node_id' => $node->id,
        ]);

        return [$report, [
            'user_id' => $user->id,
            'device_id' => $device->id,
            'node_id' => $node->id,
        ]];
    }

    private function createJpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $color = imagecolorallocate($image, 40, 120, 200);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
