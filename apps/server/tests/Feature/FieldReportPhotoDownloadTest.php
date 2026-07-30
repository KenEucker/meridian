<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\FieldReports\FieldReportPhotoSignedUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FieldReportPhotoDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_ic_lead_can_download_photo_and_ic_operator_cannot(): void
    {
        Storage::fake('attachments');
        [$report, $attachment] = $this->reportWithStoredPhoto();

        $icLead = $this->userWithEventRole('ic_lead', $report->event);
        $icOperator = $this->userWithEventRole('ic_operator', $report->event);
        $icViewer = $this->userWithEventRole('ic_viewer', $report->event);
        $author = User::query()->findOrFail($report->submitted_by_user_id);

        $this->assertTrue($icLead->can('downloadPhoto', $report));
        $this->assertFalse($icOperator->can('downloadPhoto', $report));
        $this->assertFalse($icViewer->can('downloadPhoto', $report));
        $this->assertFalse($author->can('downloadPhoto', $report));

        $this->assertTrue($icLead->can('view', $report));
        $this->assertTrue($icOperator->can('view', $report));
        $this->assertTrue($author->can('view', $report));
    }

    public function test_signed_download_url_streams_for_ic_lead_and_forbids_operator(): void
    {
        Storage::fake('attachments');
        [$report, $attachment] = $this->reportWithStoredPhoto();
        $icLead = $this->userWithEventRole('ic_lead', $report->event);
        $icOperator = $this->userWithEventRole('ic_operator', $report->event);

        $downloadUrl = app(FieldReportPhotoSignedUrlService::class)
            ->downloadUrl($icLead, $attachment)
            ->url;

        $this->get($downloadUrl)
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="'.$attachment->filename.'"')
            ->assertHeader('content-type', $attachment->mime_type);

        $this->expectException(\RuntimeException::class);
        app(FieldReportPhotoSignedUrlService::class)->downloadUrl($icOperator, $attachment);
    }

    public function test_signed_preview_url_allows_viewers_but_unsigned_path_is_rejected(): void
    {
        Storage::fake('attachments');
        [$report, $attachment] = $this->reportWithStoredPhoto();
        $icViewer = $this->userWithEventRole('ic_viewer', $report->event);

        $previewUrl = app(FieldReportPhotoSignedUrlService::class)
            ->previewUrl($icViewer, $attachment)
            ->url;

        $this->get($previewUrl)
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename="'.$attachment->filename.'"');

        $this->get(route('field-report-photos.preview', ['attachment' => $attachment->id]))
            ->assertForbidden();
    }

    public function test_expired_signed_download_url_is_rejected(): void
    {
        Storage::fake('attachments');
        [$report, $attachment] = $this->reportWithStoredPhoto();
        $icLead = $this->userWithEventRole('ic_lead', $report->event);

        $expired = URL::temporarySignedRoute(
            'field-report-photos.download',
            now()->subMinute(),
            ['attachment' => $attachment->getKey()],
            absolute: false,
        );

        $this->get($expired)
            ->assertForbidden();
    }

    /**
     * @return array{FieldReport, Attachment}
     */
    private function reportWithStoredPhoto(): array
    {
        $author = User::factory()->create();
        $report = FieldReport::factory()
            ->forAuthor($author)
            ->receivedByServer()
            ->create([
                'fra_number' => 'FRA-2027-000111',
            ]);

        $path = 'field-reports/'.$report->event_id.'/sample.webp';
        Storage::disk('attachments')->put($path, 'fake-webp-bytes');

        $attachment = Attachment::factory()->forFieldReport($report)->create([
            'filename' => 'EVENT-2027_FRA-2027-000111_2027-07-04T13-22-10Z_01.webp',
            'mime_type' => 'image/webp',
            'byte_size' => strlen('fake-webp-bytes'),
            'storage_disk' => 'attachments',
            'storage_path' => $path,
            'checksum' => hash('sha256', 'fake-webp-bytes'),
        ]);

        return [$report, $attachment];
    }

    private function userWithEventRole(string $roleCode, Event $event): User
    {
        $organization = $event->organization ?? Organization::factory()->create();
        if ($event->organization_id === null) {
            $event->forceFill(['organization_id' => $organization->id])->save();
        }

        $department = $this->ensureEventIcDepartment($event, $organization);
        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => $event->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }

    private function ensureEventIcDepartment(Event $event, Organization $organization): Department
    {
        if ($event->ic_department_id !== null) {
            return Department::query()->findOrFail($event->ic_department_id);
        }

        $event->loadMissing(['icDepartment', 'organization.defaultIcDepartment']);

        $department = $event->organization?->defaultIcDepartment;

        if ($department !== null) {
            return $department;
        }

        $department = Department::factory()->for($organization)->create();
        $event->forceFill(['ic_department_id' => $department->id])->save();

        return $department;
    }
}
