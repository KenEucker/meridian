<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Support\LocalFieldFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldReportCommandHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meridian.local_field_api.enabled' => true,
            'meridian.local_field_api.token' => 'test-local-field-token',
            'meridian.local_field_api.user_id' => LocalFieldFixture::USER_ID,
        ]);

        Artisan::call('meridian:seed-local-field-fixture');
        Storage::fake('attachments');
    }

    public function test_submit_and_upload_photo_via_local_field_api(): void
    {
        $reportId = (string) Str::uuid();
        $photoId = (string) Str::uuid();
        $bytes = $this->createJpegBytes(48, 36);
        $checksum = hash('sha256', $bytes);

        $this->postJson('/api/commands/submit-field-report', [
            'id' => $reportId,
            'event_id' => LocalFieldFixture::EVENT_ID,
            'department_id' => null,
            'team_id' => null,
            'staff_id' => LocalFieldFixture::STAFF_ID,
            'temporary_local_number' => 'LOCAL-HTTP0001',
            'title' => 'HTTP upload report',
            'body' => 'Accepted over the command API.',
            'device_submitted_at' => '2027-07-04T13:20:00Z',
            'origin_device_id' => LocalFieldFixture::DEVICE_ID,
            'origin_node_id' => LocalFieldFixture::NODE_ID,
        ], [
            'Authorization' => 'Bearer test-local-field-token',
        ])->assertCreated()
            ->assertJsonPath('id', $reportId)
            ->assertJsonPath('sync_status', 'accepted');

        $this->postJson('/api/commands/upload-field-report-photo', [
            'id' => $photoId,
            'field_report_id' => $reportId,
            'origin_device_id' => LocalFieldFixture::DEVICE_ID,
            'origin_node_id' => LocalFieldFixture::NODE_ID,
            'checksum_sha256' => $checksum,
            'declared_mime_type' => 'image/jpeg',
            'device_uploaded_at' => '2027-07-04T13:22:10Z',
            'bytes_base64' => base64_encode($bytes),
        ], [
            'Authorization' => 'Bearer test-local-field-token',
        ])->assertCreated()
            ->assertJsonPath('id', $photoId);

        $attachment = Attachment::query()->findOrFail($photoId);
        $this->assertTrue(Storage::disk('attachments')->exists($attachment->storage_path));
    }

    public function test_local_field_api_rejects_missing_or_wrong_token(): void
    {
        $this->postJson('/api/commands/submit-field-report', [
            'id' => (string) Str::uuid(),
            'event_id' => LocalFieldFixture::EVENT_ID,
            'staff_id' => LocalFieldFixture::STAFF_ID,
            'title' => 'Nope',
            'body' => 'Nope',
            'device_submitted_at' => '2027-07-04T13:20:00Z',
            'origin_device_id' => LocalFieldFixture::DEVICE_ID,
            'origin_node_id' => LocalFieldFixture::NODE_ID,
        ])->assertUnauthorized();

        $this->postJson('/api/commands/submit-field-report', [
            'id' => (string) Str::uuid(),
            'event_id' => LocalFieldFixture::EVENT_ID,
            'staff_id' => LocalFieldFixture::STAFF_ID,
            'title' => 'Nope',
            'body' => 'Nope',
            'device_submitted_at' => '2027-07-04T13:20:00Z',
            'origin_device_id' => LocalFieldFixture::DEVICE_ID,
            'origin_node_id' => LocalFieldFixture::NODE_ID,
        ], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized();
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
