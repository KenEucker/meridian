<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\IncidentLink;
use App\Models\IncidentStaff;
use App\Models\IncidentTimelineEntry;
use App\Models\IncidentType;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Incidents\IncidentPdfExportService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentPdfExportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ic_lead_can_download_incident_pdf_with_metadata_and_audit(): void
    {
        Carbon::setTestNow('2027-07-04 21:30:00 UTC');

        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = $this->seededIncident($event, $actor);

        $response = $this->actingAs($actor)
            ->get("/api/events/{$event->id}/incidents/{$incident->id}/pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment; filename="incident-inc-2027-000042-medical-assist-near-gate-a.pdf"',
            (string) $response->headers->get('Content-Disposition'),
        );

        $pdf = $response->getContent();
        $this->assertIsString($pdf);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('Incident PDF Export', $pdf);
        $this->assertStringContainsString('IMS number: INC-2027-000042', $pdf);
        $this->assertStringContainsString('Title: Medical assist near Gate A', $pdf);
        $this->assertStringContainsString('State: On Scene', $pdf);
        $this->assertStringContainsString('Priority: Serious', $pdf);
        $this->assertStringContainsString('Incident types: Medical, Safety', $pdf);
        $this->assertStringContainsString('Responders: Vera Ranger', $pdf);
        $this->assertStringContainsString('Location: Gate A', $pdf);
        $this->assertStringContainsString('Exported at: 2027-07-04T21:30:00+00:00', $pdf);
        $this->assertStringContainsString('Patient stabilized near Gate A.', $pdf);
        $this->assertStringContainsString('INC-2027-000041', $pdf);
        $this->assertStringContainsString('FRA-2027-000010', $pdf);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'incident.exported',
            'entity_id' => $incident->id,
            'actor_user_id' => $actor->id,
            'event_id' => $event->id,
            'organization_id' => $event->organization_id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);

        $audit = AuditEvent::query()->where('action', 'incident.exported')->sole();
        $this->assertSame('pdf', $audit->after_json['format']);
        $this->assertSame('INC-2027-000042', $audit->after_json['incident_number']);
        $this->assertSame('2027-07-04T21:30:00+00:00', $audit->after_json['exported_at']);
    }

    public function test_ic_operator_and_viewer_cannot_print_incident_pdf(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $incident = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000050',
            'title' => 'Denied print sample',
        ]);

        foreach (['ic_operator', 'ic_viewer'] as $role) {
            $actor = $this->userWithEventRole($role, $event);

            $this->actingAs($actor)
                ->get("/api/events/{$event->id}/incidents/{$incident->id}/pdf")
                ->assertForbidden()
                ->assertJsonPath(
                    'message',
                    'Only Incident Command leads for this event may print incidents to PDF.',
                );

            $this->assertDatabaseMissing('audit_events', [
                'action' => 'incident.exported',
                'actor_user_id' => $actor->id,
            ]);
        }
    }

    public function test_non_ic_roles_and_wrong_event_cannot_print(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $incident = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000051',
            'title' => 'Scoped print sample',
        ]);
        $wrongEventLead = $this->userWithEventRole('ic_lead', $otherEvent);
        $organizer = $this->userWithRole('organizer', $event, eventScoped: false);
        $departmentLead = $this->userWithRole('department_lead', $event, eventScoped: false);

        $this->actingAs($wrongEventLead)
            ->get("/api/events/{$event->id}/incidents/{$incident->id}/pdf")
            ->assertForbidden();

        $this->actingAs($organizer)
            ->get("/api/events/{$event->id}/incidents/{$incident->id}/pdf")
            ->assertForbidden();

        $this->actingAs($departmentLead)
            ->get("/api/events/{$event->id}/incidents/{$incident->id}/pdf")
            ->assertForbidden();

        $this->actingAs($wrongEventLead)
            ->get("/api/events/{$otherEvent->id}/incidents/{$incident->id}/pdf")
            ->assertNotFound();

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_unauthenticated_print_is_rejected(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $incident = Incident::factory()->forEvent($event)->create();

        $this->get("/api/events/{$event->id}/incidents/{$incident->id}/pdf")
            ->assertUnauthorized();
    }

    public function test_domain_export_rejects_operators_without_writing_audit(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $operator = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000052',
            'title' => 'Domain denied print',
        ]);

        $this->expectException(AuthorizationException::class);

        try {
            app(IncidentPdfExportService::class)->export($incident, $operator);
        } finally {
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    private function seededIncident(Event $event, User $actor): Incident
    {
        $incident = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000042',
            'status' => Incident::STATUS_ON_SCENE,
            'priority_label' => Incident::PRIORITY_SERIOUS,
            'title' => 'Medical assist near Gate A',
            'location_name' => 'Gate A',
            'location_details' => 'North side of entry.',
            'created_by_user_id' => $actor->id,
            'started_at' => Carbon::parse('2027-07-04T18:00:00Z'),
        ]);

        $medical = IncidentType::factory()->create([
            'organization_id' => $event->organization_id,
            'name' => 'Medical',
        ]);
        $safety = IncidentType::factory()->create([
            'organization_id' => $event->organization_id,
            'name' => 'Safety',
        ]);
        $incident->incidentTypes()->attach($medical->id, [
            'id' => '11111111-1111-4111-8111-111111111201',
            'created_at' => Carbon::parse('2027-07-04T18:05:00Z'),
        ]);
        $incident->incidentTypes()->attach($safety->id, [
            'id' => '11111111-1111-4111-8111-111111111202',
            'created_at' => Carbon::parse('2027-07-04T18:06:00Z'),
        ]);

        $responder = Staff::factory()->create([
            'preferred_name' => 'Vera Ranger',
        ]);
        IncidentStaff::factory()->create([
            'incident_id' => $incident->id,
            'staff_id' => $responder->id,
            'relationship_label' => 'Responder',
        ]);

        $linked = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000041',
            'title' => 'Radio relay check',
            'status' => Incident::STATUS_MONITORING,
        ]);
        IncidentLink::factory()->forIncidents($incident, $linked)->create([
            'created_by_user_id' => $actor->id,
        ]);

        $fieldReport = FieldReport::factory()
            ->forEvent($event)
            ->receivedByServer()
            ->create([
                'fra_number' => 'FRA-2027-000010',
                'title' => 'Gate medical report',
                'body' => 'Caller requested medical assist.',
                'submitted_by_user_id' => $actor->id,
            ]);
        IncidentFieldReport::query()->create([
            'incident_id' => $incident->id,
            'field_report_id' => $fieldReport->id,
            'linked_by_user_id' => $actor->id,
            'linked_at' => now(),
        ]);

        IncidentTimelineEntry::factory()->create([
            'incident_id' => $incident->id,
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_INCIDENT_OPENED,
            'body' => null,
            'created_at' => Carbon::parse('2027-07-04T18:01:00Z'),
        ]);
        IncidentTimelineEntry::factory()->create([
            'incident_id' => $incident->id,
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
            'body' => 'Patient stabilized near Gate A.',
            'created_at' => Carbon::parse('2027-07-04T18:15:00Z'),
        ]);

        return $incident->fresh();
    }

    private function eventWithIncidentCommandDepartment(): Event
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();

        return Event::factory()->for($organization)->create([
            'ic_department_id' => $department->id,
            'starts_at' => Carbon::parse('2027-07-04 09:00:00', 'America/Los_Angeles')->utc(),
            'timezone' => 'America/Los_Angeles',
        ]);
    }

    private function userWithEventRole(string $roleCode, Event $event, bool $revoked = false): User
    {
        return $this->userWithRole($roleCode, $event, eventScoped: true, revoked: $revoked);
    }

    private function userWithRole(
        string $roleCode,
        Event $event,
        bool $eventScoped,
        bool $revoked = false,
    ): User {
        $department = $eventScoped && in_array($roleCode, ['ic_lead', 'ic_operator', 'ic_viewer'], true)
            ? Department::query()->findOrFail($event->ic_department_id)
            : Department::factory()->for($event->organization)->create();

        if (in_array($roleCode, ['organizer', 'lead_organizer'], true)) {
            $event->organization->forceFill(['organizers_department_id' => $department->id])->save();
        }

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

        $grantAttributes = [
            'team_id' => $team->id,
            'event_id' => $eventScoped ? $event->id : null,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ];

        if ($revoked) {
            TeamGrant::factory()->revoked()->create($grantAttributes);
        } else {
            TeamGrant::factory()->create($grantAttributes);
        }

        return $user;
    }
}
