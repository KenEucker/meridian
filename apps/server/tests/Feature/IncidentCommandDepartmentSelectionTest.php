<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Events\IncidentCommandDepartmentSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class IncidentCommandDepartmentSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_default_ic_department_must_belong_to_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherDepartment = Department::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Incident Command department must belong to the selected organization.');

        app(IncidentCommandDepartmentSelectionService::class)
            ->configureOrganizationDefault($organization, $otherDepartment);
    }

    public function test_organization_default_ic_department_rejects_archived_department(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->archived()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Incident Command department must be an active department.');

        app(IncidentCommandDepartmentSelectionService::class)
            ->configureOrganizationDefault($organization, $department);
    }

    public function test_organization_default_ic_department_records_an_audit_event(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();

        $updated = app(IncidentCommandDepartmentSelectionService::class)
            ->configureOrganizationDefault($organization, $department, $actor, AuditEvent::SOURCE_ORCHID);

        $this->assertSame($department->id, $updated->default_ic_department_id);

        $audit = AuditEvent::query()->sole();
        $this->assertSame('organization.default_ic_department_changed', $audit->action);
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($department->id, $audit->department_id);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame(AuditEvent::SOURCE_ORCHID, $audit->source_context);
        $this->assertSame(['default_ic_department_id' => null], $audit->before_json);
        $this->assertSame(['default_ic_department_id' => $department->id], $audit->after_json);
    }

    public function test_event_ic_department_must_be_an_active_participating_department(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Incident Command department must be actively assigned to the event.');

        app(IncidentCommandDepartmentSelectionService::class)
            ->configureEventOverride($event, $department);
    }

    public function test_event_ic_department_rejects_archived_event_assignment(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        EventDepartmentAssignment::factory()->archived()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Incident Command department must be actively assigned to the event.');

        app(IncidentCommandDepartmentSelectionService::class)
            ->configureEventOverride($event, $department);
    }

    public function test_event_ic_department_records_an_audit_event(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);

        $updated = app(IncidentCommandDepartmentSelectionService::class)
            ->configureEventOverride($event, $department, $actor, AuditEvent::SOURCE_ORCHID);

        $this->assertSame($department->id, $updated->ic_department_id);

        $audit = AuditEvent::query()->sole();
        $this->assertSame('event.ic_department_changed', $audit->action);
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($event->id, $audit->event_id);
        $this->assertSame($department->id, $audit->department_id);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame(AuditEvent::SOURCE_ORCHID, $audit->source_context);
        $this->assertSame(['ic_department_id' => null], $audit->before_json);
        $this->assertSame(['ic_department_id' => $department->id], $audit->after_json);
    }

    public function test_effective_ic_department_uses_event_override_before_organization_default(): void
    {
        $organization = Organization::factory()->create();
        $defaultDepartment = Department::factory()->for($organization)->create();
        $overrideDepartment = Department::factory()->for($organization)->create();
        $organization->forceFill(['default_ic_department_id' => $defaultDepartment->id])->save();

        $event = Event::factory()->for($organization)->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $overrideDepartment->id,
        ]);

        $service = app(IncidentCommandDepartmentSelectionService::class);

        $this->assertTrue($service->effectiveDepartment($event)->is($defaultDepartment));

        $service->configureEventOverride($event, $overrideDepartment);

        $this->assertTrue($service->effectiveDepartment($event->refresh())->is($overrideDepartment));
    }

    public function test_reselecting_current_event_ic_department_is_a_no_op(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);

        $service = app(IncidentCommandDepartmentSelectionService::class);
        $service->configureEventOverride($event, $department);
        $service->configureEventOverride($event->refresh(), $department);

        $this->assertSame(1, AuditEvent::query()->where('action', 'event.ic_department_changed')->count());
    }
}
