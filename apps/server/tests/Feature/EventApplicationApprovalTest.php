<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\User;
use App\Services\Application\ApplicationApprovalException;
use App\Services\Application\EventApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EventApplicationApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_approving_submitted_application_creates_prospective_staff_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 10:15:00'));

        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $event = Event::factory()->for($organization)->create(['name' => 'Signal Camp 2026']);
        $reviewer = User::factory()->create(['name' => 'Olive Organizer']);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Casey Applicant',
            'applicant_email' => 'Casey.Applicant@Example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
            'reviewed_at' => null,
            'reviewed_by_user_id' => null,
            'decision_reason' => null,
        ]);

        $approved = app(EventApplicationService::class)->approve($application, $reviewer);

        $staff = Staff::query()->where('email', 'casey.applicant@example.org')->firstOrFail();
        $status = StaffOrganizationStatus::query()
            ->where('organization_id', $organization->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();

        $this->assertTrue($approved->staff->is($staff));
        $this->assertSame(EventApplication::STATUS_APPROVED, $approved->status);
        $this->assertSame($reviewer->id, $approved->reviewed_by_user_id);
        $this->assertSame('Approved at the organization level.', $approved->decision_reason);
        $this->assertSame('Casey Applicant', $staff->legal_name);
        $this->assertSame(StaffOrganizationStatus::STATUS_PROSPECTIVE, $status->status);
        $this->assertSame('Approved application for Signal Camp 2026.', $status->status_reason);
        $this->assertSame($reviewer->id, $status->status_changed_by_user_id);

        $applicationAudit = AuditEvent::query()
            ->where('action', 'event_application.approved')
            ->where('entity_id', $application->id)
            ->firstOrFail();

        $this->assertSame(AuditEvent::SOURCE_ORCHID, $applicationAudit->source_context);
        $this->assertSame($organization->id, $applicationAudit->organization_id);
        $this->assertSame($event->id, $applicationAudit->event_id);
        $this->assertSame(EventApplication::STATUS_SUBMITTED, $applicationAudit->before_json['status']);
        $this->assertSame(EventApplication::STATUS_APPROVED, $applicationAudit->after_json['status']);
        $this->assertSame($staff->id, $applicationAudit->after_json['staff_id']);

        $statusAudit = AuditEvent::query()
            ->where('action', 'staff_organization_status.created')
            ->where('entity_id', $status->id)
            ->firstOrFail();

        $this->assertNull($statusAudit->before_json);
        $this->assertSame(StaffOrganizationStatus::STATUS_PROSPECTIVE, $statusAudit->after_json['status']);
    }

    public function test_approval_reuses_matching_staff_and_moves_existing_status_to_prospective(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 11:30:00'));

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Emberfall 2026']);
        $reviewer = User::factory()->create();
        $staff = Staff::factory()->create([
            'email' => 'existing.person@example.org',
            'legal_name' => 'Existing Person',
        ]);
        $status = StaffOrganizationStatus::factory()
            ->for($organization)
            ->for($staff)
            ->create([
                'status' => StaffOrganizationStatus::STATUS_INACTIVE,
                'status_reason' => 'Inactive before reapplication.',
            ]);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Existing Person Updated',
            'applicant_email' => 'Existing.Person@Example.org',
        ]);

        $approved = app(EventApplicationService::class)->approve($application, $reviewer);

        $this->assertSame(1, Staff::query()->whereRaw('LOWER(email) = ?', ['existing.person@example.org'])->count());
        $this->assertSame($staff->id, $approved->staff_id);
        $this->assertSame(EventApplication::STATUS_APPROVED, $approved->status);
        $this->assertSame(StaffOrganizationStatus::STATUS_PROSPECTIVE, $status->refresh()->status);
        $this->assertSame('Approved application for Emberfall 2026.', $status->status_reason);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'staff_organization_status.changed',
            'entity_id' => $status->id,
            'actor_user_id' => $reviewer->id,
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'source_context' => AuditEvent::SOURCE_ORCHID,
        ]);
    }

    public function test_dns_backed_submitted_application_cannot_be_approved(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $reviewer = User::factory()->create();
        $staff = Staff::factory()->create(['email' => 'blocked@example.org']);
        StaffOrganizationStatus::factory()
            ->for($organization)
            ->for($staff)
            ->create(['status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF]);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_email' => 'BLOCKED@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
            'staff_id' => null,
        ]);

        $this->expectException(ApplicationApprovalException::class);

        try {
            app(EventApplicationService::class)->approve($application, $reviewer);
        } finally {
            $this->assertSame(EventApplication::STATUS_SUBMITTED, $application->refresh()->status);
            $this->assertNull($application->staff_id);
            $this->assertDatabaseMissing('audit_events', [
                'action' => 'event_application.approved',
                'entity_id' => $application->id,
            ]);
        }
    }

    public function test_non_submitted_application_cannot_be_approved(): void
    {
        $application = EventApplication::factory()->create([
            'status' => EventApplication::STATUS_AUTO_REJECTED_DNS,
        ]);

        $this->expectException(ApplicationApprovalException::class);

        app(EventApplicationService::class)->approve($application, User::factory()->create());
    }
}
