<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Device;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use App\Support\LocalFieldFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Field Report text and photo upload over the command API, as the mobile Field
 * application makes it (FR-001 through FR-012; AUTH-018; technical spec 11.4).
 *
 * The credential is a device-bound bearer token issued to the seeded fixture
 * user. Until M16.11 these requests carried a shared token configured on the
 * node, which authenticated every caller as that same fixture user without
 * anybody signing in; that middleware is gone, and this test is the regression
 * cover for the upload path under the credential that replaced it.
 */
class FieldReportCommandHttpTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('meridian:seed-local-field-fixture');
        Storage::fake('attachments');

        $this->token = app(ApiTokenIssuer::class)->issue(
            User::query()->findOrFail(LocalFieldFixture::USER_ID),
            Device::query()->findOrFail(LocalFieldFixture::DEVICE_ID),
            'Meridian Field',
        )->plainTextToken;
    }

    public function test_submit_and_upload_photo_with_a_device_bound_token(): void
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
            'Authorization' => 'Bearer '.$this->token,
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
            'Authorization' => 'Bearer '.$this->token,
        ])->assertCreated()
            ->assertJsonPath('id', $photoId);

        $attachment = Attachment::query()->findOrFail($photoId);
        $this->assertTrue(Storage::disk('attachments')->exists($attachment->storage_path));
    }

    /**
     * A Field Report filed on the author's own behalf carries no department and
     * no team, and the node accepts it.
     *
     * FR-003 records author, title, and text; FR-010 lets a Field Report exist
     * independently; technical spec 17.3 lists department/team context "if
     * available"; data/API 10.15 makes both columns nullable. Nothing about
     * filing a report depends on belonging to a department or working a team,
     * and this asserts the node agrees.
     */
    public function test_a_field_report_needs_no_department_or_team(): void
    {
        $reportId = (string) Str::uuid();

        $this->postJson('/api/commands/submit-field-report', [
            'id' => $reportId,
            'event_id' => LocalFieldFixture::EVENT_ID,
            'department_id' => null,
            'team_id' => null,
            'staff_id' => LocalFieldFixture::STAFF_ID,
            'temporary_local_number' => 'LOCAL-HTTP0002',
            'title' => 'Filed on my own behalf',
            'body' => 'No department, no team, no shift.',
            'device_submitted_at' => '2027-07-04T13:20:00Z',
            'origin_device_id' => LocalFieldFixture::DEVICE_ID,
            'origin_node_id' => LocalFieldFixture::NODE_ID,
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ])->assertCreated()
            ->assertJsonPath('sync_status', 'accepted');

        $report = FieldReport::query()->findOrFail($reportId);
        $this->assertNull($report->department_id);
        $this->assertNull($report->team_id);
    }

    /**
     * The team the client puts an on-shift author on resolves on the node.
     *
     * A report filed while checked into a shift carries that shift's team, so
     * the client's shift fixtures and `LocalFieldFixture` have to describe the
     * same teams. When they did not, every on-shift report was refused with
     * "Field Report team is not active in the supplied department" — the client
     * naming a team the server had never been told about.
     */
    public function test_the_fixture_on_shift_team_is_accepted_as_report_context(): void
    {
        $reportId = (string) Str::uuid();

        $this->postJson('/api/commands/submit-field-report', [
            'id' => $reportId,
            'event_id' => LocalFieldFixture::EVENT_ID,
            'department_id' => LocalFieldFixture::DEPARTMENT_ID,
            'team_id' => LocalFieldFixture::RANGERS_DIRT_TEAM_ID,
            'staff_id' => LocalFieldFixture::STAFF_ID,
            'temporary_local_number' => 'LOCAL-HTTP0003',
            'title' => 'Filed on a Dirt shift',
            'body' => 'Attributed to the team whose shift it was.',
            'device_submitted_at' => '2027-07-04T13:20:00Z',
            'origin_device_id' => LocalFieldFixture::DEVICE_ID,
            'origin_node_id' => LocalFieldFixture::NODE_ID,
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ])->assertCreated();

        $report = FieldReport::query()->findOrFail($reportId);
        $this->assertSame(LocalFieldFixture::DEPARTMENT_ID, $report->department_id);
        $this->assertSame(LocalFieldFixture::RANGERS_DIRT_TEAM_ID, $report->team_id);
    }

    public function test_the_command_api_rejects_a_missing_or_wrong_token(): void
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

    /**
     * The shared token no longer authenticates anything (M16.11).
     *
     * `local.field` accepted a token configured on the node and signed the
     * caller in as the seeded fixture user. Both the middleware and its
     * configuration are removed, so the same request is now an unauthenticated
     * one: setting the configuration a node used to carry changes nothing,
     * because nothing reads it, and the well-known development value is refused
     * like any other string that is not an issued token.
     */
    public function test_the_removed_shared_token_no_longer_authenticates(): void
    {
        config([
            'meridian.local_field_api.enabled' => true,
            'meridian.local_field_api.token' => 'local-field-dev-token',
            'meridian.local_field_api.user_id' => LocalFieldFixture::USER_ID,
        ]);

        foreach (['local-field-dev-token', 'test-local-field-token'] as $sharedToken) {
            $this->app['auth']->forgetGuards();

            $this->postJson('/api/commands/submit-field-report', [
                'id' => (string) Str::uuid(),
                'event_id' => LocalFieldFixture::EVENT_ID,
                'staff_id' => LocalFieldFixture::STAFF_ID,
                'title' => 'Shared token',
                'body' => 'Filed with the credential that used to work.',
                'device_submitted_at' => '2027-07-04T13:20:00Z',
                'origin_device_id' => LocalFieldFixture::DEVICE_ID,
                'origin_node_id' => LocalFieldFixture::NODE_ID,
            ], [
                'Authorization' => 'Bearer '.$sharedToken,
            ])->assertUnauthorized();
        }

        $this->assertSame(0, FieldReport::query()->count());
    }

    public function test_local_field_fixture_account_can_create_incidents_for_the_fixture_event(): void
    {
        $event = Event::query()->findOrFail(LocalFieldFixture::EVENT_ID);

        $this->assertSame(LocalFieldFixture::DEPARTMENT_ID, $event->ic_department_id);

        $this->postJson('/api/commands/create-incident', [
            'event_id' => LocalFieldFixture::EVENT_ID,
            'title' => 'Local fixture incident',
            'started_at' => '2027-07-04T14:00:00Z',
            'location_name' => 'Ranger HQ',
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ])->assertCreated()
            ->assertJsonPath('incident_number', 'INC-2027-000001')
            ->assertJsonPath('title', 'Local fixture incident')
            ->assertJsonPath('created_by_user_id', LocalFieldFixture::USER_ID);

        $incident = Incident::query()->firstOrFail();
        $this->assertSame(LocalFieldFixture::EVENT_ID, $incident->event_id);
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
