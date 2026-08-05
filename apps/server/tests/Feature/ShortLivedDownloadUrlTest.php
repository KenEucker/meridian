<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\FieldReport;
use App\Models\HoursWorked;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\PolicyDocument;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Credits\CreditCalculationService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Short-lived scoped download URLs (M16.12; CLIENT-019, CLIENT-020; technical
 * spec 11A.6; data/API 5.7).
 *
 * The two halves under test are the request — an authenticated client asks for
 * a URL and is refused one it could not use — and the navigation, which arrives
 * with no session and no bearer token because that is the whole reason the
 * pattern exists.
 */
class ShortLivedDownloadUrlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The path segment of every Alpha 1 export, which is also the report half
     * of both route names (M18.25; REPORT-001 through REPORT-005).
     *
     * @var list<string>
     */
    private const EXPORT_REPORTS = [
        'credential-eligibility',
        'shift-roster',
        'staff-contact',
        'hours-worked',
        'credits-earned',
    ];

    /**
     * Contact details the exclusion rules are searched for by value, so a file
     * that leaked one fails on the number itself rather than on a column name
     * somebody could rename.
     */
    private const STAFF_PHONE = '+1-208-555-0100';

    private const STAFF_EMERGENCY_CONTACT = 'Quinn Contact';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_authorized_client_asks_for_a_url_and_a_bare_navigation_retrieves_the_export(): void
    {
        $scenario = $this->exportScenario();

        $issued = $this->actingAsClient($scenario['organizer'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/credential-eligibility/download-url")
            ->assertOk()
            ->assertJsonStructure(['url', 'expires_at'])
            ->json();

        // CLIENT-019: nothing about the credential is in the link. The client
        // authenticated to obtain it, and the navigation that follows does not.
        $this->assertStringNotContainsString('Bearer', $issued['url']);
        $this->assertStringContainsString('signature=', $issued['url']);

        $response = $this->get($issued['url']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Vera Staff', (string) $response->getContent());
    }

    public function test_the_export_is_generated_for_and_audited_to_the_user_the_url_was_issued_to(): void
    {
        $scenario = $this->exportScenario();

        $issued = $this->actingAsClient($scenario['rangersLead'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/credential-eligibility/download-url")
            ->assertOk()
            ->json();

        $contents = (string) $this->get($issued['url'])->assertOk()->getContent();

        // A department lead's URL produces a department lead's file: the scope
        // follows the person named in the signature, not the anonymous browser.
        $this->assertStringContainsString('Vera Staff', $contents);
        $this->assertStringNotContainsString('Rita Revoked', $contents);

        $audit = AuditEvent::query()
            ->where('action', 'event_credential_eligibility.exported')
            ->sole();

        $this->assertSame((string) $scenario['rangersLead']->id, (string) $audit->actor_user_id);
        $this->assertSame('department', $audit->after_json['scope']);
    }

    public function test_an_unauthorized_client_is_refused_a_url_rather_than_handed_one_that_would_refuse_them(): void
    {
        $scenario = $this->exportScenario();

        $response = $this->actingAsClient($scenario['plainStaffUser'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/credential-eligibility/download-url");

        $response->assertForbidden();
        $this->assertArrayNotHasKey('url', $response->json());

        // A department lead may not narrow to a department they do not lead,
        // and finding that out at issuance is the point (CLIENT-020).
        $this->actingAsClient($scenario['rangersLead'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/credential-eligibility/download-url", [
                'department_id' => (string) $scenario['gate']->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'event_credential_eligibility.exported',
        ]);
    }

    public function test_an_unauthenticated_client_cannot_ask_for_a_url(): void
    {
        $scenario = $this->exportScenario();

        $this->postJson("/api/events/{$scenario['event']->id}/exports/credential-eligibility/download-url")
            ->assertUnauthorized();
    }

    public function test_a_url_expires(): void
    {
        $scenario = $this->exportScenario();

        $issued = $this->actingAsClient($scenario['organizer'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/credential-eligibility/download-url")
            ->assertOk()
            ->json();

        $this->travel(config('meridian.downloads.signed_url_expires_minutes') + 1)->minutes();

        $this->get($issued['url'])->assertForbidden();

        $this->travelBack();
    }

    public function test_a_department_narrowing_is_signed_into_the_url_and_cannot_be_edited_out(): void
    {
        $scenario = $this->exportScenario();

        $issued = $this->actingAsClient($scenario['organizer'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/credential-eligibility/download-url", [
                'department_id' => (string) $scenario['gate']->id,
            ])
            ->assertOk()
            ->json();

        $contents = (string) $this->get($issued['url'])->assertOk()->getContent();

        $this->assertStringContainsString('Rita Revoked', $contents);
        $this->assertStringNotContainsString('Vera Staff', $contents);

        // Widening the scope by hand invalidates the signature it was part of.
        $widened = str_replace('department_id='.$scenario['gate']->id.'&', '', $issued['url']);

        $this->assertNotSame($issued['url'], $widened);
        $this->get($widened)->assertForbidden();
    }

    public function test_a_permission_withdrawn_after_issuance_stops_the_download(): void
    {
        $scenario = $this->exportScenario();

        $issued = $this->actingAsClient($scenario['rangersLead'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/credential-eligibility/download-url")
            ->assertOk()
            ->json();

        TeamGrant::query()->update(['revoked_at' => now()]);

        $this->get($issued['url'])->assertForbidden();
    }

    /**
     * M18.25: the remaining four Alpha 1 exports on the same path.
     *
     * The credential eligibility cases above are the pattern under test; these
     * assert the other four reports reach it too, because a reporting surface
     * that offers five exports and can only download one of them is the gap
     * this task closes.
     */
    public function test_every_alpha_1_export_answers_the_download_url_path(): void
    {
        $scenario = $this->workedExportScenario();

        $expectations = [
            'credential-eligibility' => 'credential_status',
            'shift-roster' => 'assignment_status',
            'staff-contact' => 'staff_phone',
            'hours-worked' => 'minutes_worked',
            'credits-earned' => 'credit_multiplier',
        ];

        foreach ($expectations as $report => $header) {
            $issued = $this->actingAsClient($scenario['organizer'])
                ->postJson("/api/events/{$scenario['event']->id}/exports/{$report}/download-url")
                ->assertOk()
                ->assertJsonStructure(['url', 'expires_at'])
                ->json();

            $this->assertStringNotContainsString('Bearer', $issued['url'], $report);
            $this->assertStringContainsString('signature=', $issued['url'], $report);

            $response = $this->get($issued['url']);

            $response->assertOk();
            $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

            $contents = (string) $response->getContent();

            // The header proves the signed route reached the right generator
            // rather than any file at all, and the row proves the export ran
            // against real records instead of an empty scope.
            $this->assertStringContainsString($header, $contents, $report);
            $this->assertStringContainsString('Vera Staff', $contents, $report);
        }
    }

    public function test_an_unauthorized_client_is_refused_a_url_for_every_export(): void
    {
        $scenario = $this->workedExportScenario();

        foreach (self::EXPORT_REPORTS as $report) {
            $this->actingAsClient($scenario['plainStaffUser'])
                ->postJson("/api/events/{$scenario['event']->id}/exports/{$report}/download-url")
                ->assertForbidden();

            // A department lead may not narrow to a department they do not
            // lead, and being refused at issuance is the point (CLIENT-020).
            $this->actingAsClient($scenario['rangersLead'])
                ->postJson("/api/events/{$scenario['event']->id}/exports/{$report}/download-url", [
                    'department_id' => (string) $scenario['gate']->id,
                ])
                ->assertForbidden();
        }

        // Nothing was generated, so nothing was audited.
        $this->assertSame(0, AuditEvent::query()->where('action', 'like', 'event_%.exported')->count());
    }

    public function test_a_department_leads_signed_download_covers_their_own_department_only(): void
    {
        $scenario = $this->workedExportScenario();

        foreach (['shift-roster', 'staff-contact', 'hours-worked', 'credits-earned'] as $report) {
            $issued = $this->actingAsClient($scenario['rangersLead'])
                ->postJson("/api/events/{$scenario['event']->id}/exports/{$report}/download-url")
                ->assertOk()
                ->json();

            $contents = (string) $this->get($issued['url'])->assertOk()->getContent();

            // The scope follows the person the signature names, not the
            // anonymous browser that arrives with it (REPORT-007).
            $this->assertStringContainsString('Vera Staff', $contents, $report);
            $this->assertStringNotContainsString('Rita Revoked', $contents, $report);

            $audit = AuditEvent::query()
                ->where('action', 'like', 'event_%.exported')
                ->latest('created_at')
                ->firstOrFail();

            $this->assertSame((string) $scenario['rangersLead']->id, (string) $audit->actor_user_id, $report);
            $this->assertSame('department', $audit->after_json['scope'], $report);
        }
    }

    /**
     * REPORT-008, REPORT-009, REPORT-010 across the signed route.
     *
     * The exclusion rules are the export services' own and are tested with
     * them; what is new here is that a file reached by navigation obeys them,
     * because the navigation carries no authority of its own and the file it
     * produces depends entirely on whose name the signature holds.
     */
    public function test_the_field_exclusion_rules_survive_the_signed_route(): void
    {
        $scenario = $this->workedExportScenario();

        $rosterUrl = $this->actingAsClient($scenario['organizer'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/shift-roster/download-url")
            ->assertOk()
            ->json('url');

        $roster = (string) $this->get($rosterUrl)->assertOk()->getContent();

        $this->assertStringNotContainsString('emergency_contact', $roster);
        $this->assertStringNotContainsString(self::STAFF_PHONE, $roster);
        $this->assertStringNotContainsString(self::STAFF_EMERGENCY_CONTACT, $roster);

        // The lead of every exported department gets the emergency contact
        // columns (REPORT-009, VOL-012).
        $leadContactUrl = $this->actingAsClient($scenario['rangersLead'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/staff-contact/download-url")
            ->assertOk()
            ->json('url');

        $leadContacts = (string) $this->get($leadContactUrl)->assertOk()->getContent();

        $this->assertStringContainsString('emergency_contact_name', $leadContacts);
        $this->assertStringContainsString(self::STAFF_EMERGENCY_CONTACT, $leadContacts);

        // The organizer does not, including when narrowing to the one
        // department the lead exported: narrowing changes which rows are
        // exported and not the authority the caller came by (REPORT-010).
        $narrowedUrl = $this->actingAsClient($scenario['organizer'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/staff-contact/download-url", [
                'department_id' => (string) $scenario['rangers']->id,
            ])
            ->assertOk()
            ->json('url');

        $narrowedContacts = (string) $this->get($narrowedUrl)->assertOk()->getContent();

        $this->assertStringContainsString('Vera Staff', $narrowedContacts);
        $this->assertStringNotContainsString('emergency_contact', $narrowedContacts);
        $this->assertStringNotContainsString(self::STAFF_EMERGENCY_CONTACT, $narrowedContacts);
        // The one export permitted to carry a phone number still does
        // (REPORT-009).
        $this->assertStringContainsString(self::STAFF_PHONE, $narrowedContacts);
    }

    public function test_a_narrowing_and_a_withdrawn_role_both_hold_on_the_new_exports(): void
    {
        $scenario = $this->workedExportScenario();

        $issued = $this->actingAsClient($scenario['organizer'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/hours-worked/download-url", [
                'department_id' => (string) $scenario['gate']->id,
            ])
            ->assertOk()
            ->json();

        $contents = (string) $this->get($issued['url'])->assertOk()->getContent();

        $this->assertStringContainsString('Rita Revoked', $contents);
        $this->assertStringNotContainsString('Vera Staff', $contents);

        // Widening the scope by hand invalidates the signature it was part of.
        $widened = str_replace('department_id='.$scenario['gate']->id.'&', '', $issued['url']);

        $this->assertNotSame($issued['url'], $widened);
        $this->get($widened)->assertForbidden();

        $creditsUrl = $this->actingAsClient($scenario['rangersLead'])
            ->postJson("/api/events/{$scenario['event']->id}/exports/credits-earned/download-url")
            ->assertOk()
            ->json('url');

        TeamGrant::query()->update(['revoked_at' => now()]);

        $this->get($creditsUrl)->assertForbidden();
    }

    public function test_an_incident_pdf_url_is_issued_to_a_lead_refused_to_an_operator_and_scoped_to_one_incident(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $lead = $this->userWithEventRole('ic_lead', $event);
        $operator = $this->userWithEventRole('ic_operator', $event);

        $incident = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000042',
            'title' => 'Medical assist near Gate A',
            'created_by_user_id' => $lead->id,
        ]);
        $other = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000043',
            'title' => 'Radio relay check',
            'created_by_user_id' => $lead->id,
        ]);

        $this->actingAsClient($operator)
            ->postJson("/api/events/{$event->id}/incidents/{$incident->id}/pdf/download-url")
            ->assertForbidden();

        $issued = $this->actingAsClient($lead)
            ->postJson("/api/events/{$event->id}/incidents/{$incident->id}/pdf/download-url")
            ->assertOk()
            ->json();

        $response = $this->get($issued['url']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('INC-2027-000042', (string) $response->getContent());

        // CLIENT-020: one resource. Pointing the URL at the neighbouring
        // incident breaks the signature that named the first one.
        $repointed = str_replace((string) $incident->id, (string) $other->id, $issued['url']);

        $this->assertNotSame($issued['url'], $repointed);
        $this->get($repointed)->assertForbidden();
    }

    public function test_a_document_url_is_scoped_to_the_format_it_was_issued_for(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        $policy = PolicyDocument::factory()->create([
            'organization_id' => $organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Volunteer Conduct',
            'markdown_source' => 'Be excellent to each other.',
        ]);

        $issued = $this->actingAsClient($organizer)
            ->postJson("/api/policy-documents/{$policy->id}/export/markdown/download-url")
            ->assertOk()
            ->json();

        $response = $this->get($issued['url']);

        $response->assertOk();
        $this->assertStringContainsString('Be excellent to each other.', (string) $response->getContent());

        $repointed = str_replace('/export/markdown', '/export/pdf', $issued['url']);

        $this->assertNotSame($issued['url'], $repointed);
        $this->get($repointed)->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'policy_document.exported',
            'entity_id' => $policy->id,
            'actor_user_id' => $organizer->id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);
    }

    public function test_a_document_url_is_refused_to_a_user_who_may_not_export_the_document(): void
    {
        [$organization] = $this->organizationWithOrganizer();

        $policy = PolicyDocument::factory()->create([
            'organization_id' => $organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Volunteer Conduct',
            'markdown_source' => 'Be excellent to each other.',
        ]);

        $outsider = User::factory()->create();

        $this->actingAsClient($outsider)
            ->postJson("/api/policy-documents/{$policy->id}/export/markdown/download-url")
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'policy_document.exported',
        ]);
    }

    public function test_a_field_report_photo_follows_the_same_path_without_a_session(): void
    {
        Storage::fake('attachments');

        $author = User::factory()->create();
        $report = FieldReport::factory()
            ->forAuthor($author)
            ->receivedByServer()
            ->create(['fra_number' => 'FRA-2027-000111']);

        $path = 'field-reports/'.$report->event_id.'/sample.webp';
        Storage::disk('attachments')->put($path, 'fake-webp-bytes');

        $attachment = Attachment::factory()->forFieldReport($report)->create([
            'filename' => 'sample.webp',
            'mime_type' => 'image/webp',
            'byte_size' => strlen('fake-webp-bytes'),
            'storage_disk' => 'attachments',
            'storage_path' => $path,
            'checksum' => hash('sha256', 'fake-webp-bytes'),
        ]);

        $event = Event::query()->findOrFail($report->event_id);
        $lead = $this->userWithEventRole('ic_lead', $event);
        $operator = $this->userWithEventRole('ic_operator', $event);

        $issued = $this->actingAsClient($lead)
            ->postJson("/api/field-report-photos/{$attachment->id}/download-url")
            ->assertOk()
            ->assertJsonStructure(['url', 'expires_at'])
            ->json();

        // The Field Report photo pattern this task generalizes: the navigation
        // arrives with no session at all and is served anyway, because the
        // signature names the IC lead it was issued to.
        $this->get($issued['url'])
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="sample.webp"');

        // An operator may view a Field Report but not download its photos, so
        // there is no URL for them to be given (FR-013).
        $this->actingAsClient($operator)
            ->postJson("/api/field-report-photos/{$attachment->id}/download-url")
            ->assertForbidden();
    }

    /**
     * @return array{Organization, User}
     */
    private function organizationWithOrganizer(): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Organizers', 'code' => 'ORG']);
        $organization->forceFill(['organizers_department_id' => $department->id])->save();

        $team = Team::factory()->for($department)->create(['is_default' => true]);
        $user = $this->userOnTeam($team, 'organizer');

        return [$organization, $user];
    }

    private function eventWithIncidentCommandDepartment(): Event
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();

        return Event::factory()->for($organization)->create([
            'ic_department_id' => $department->id,
        ]);
    }

    private function userWithEventRole(string $roleCode, Event $event): User
    {
        $department = in_array($roleCode, ['ic_lead', 'ic_operator', 'ic_viewer'], true)
            ? $this->incidentCommandDepartment($event)
            : Department::factory()->for($event->organization)->create();

        $team = Team::factory()->for($department)->create();

        return $this->userOnTeam($team, $roleCode, $event);
    }

    /**
     * An IC role only counts on the event's own Incident Command department, so
     * an event that arrived without one gets one here.
     */
    private function incidentCommandDepartment(Event $event): Department
    {
        if ($event->ic_department_id !== null) {
            return Department::query()->findOrFail($event->ic_department_id);
        }

        $organization = $event->organization ?? Organization::factory()->create();

        if ($event->organization_id === null) {
            $event->forceFill(['organization_id' => $organization->id])->save();
        }

        $department = Department::factory()->for($organization)->create();
        $event->forceFill(['ic_department_id' => $department->id])->save();

        return $department;
    }

    private function userOnTeam(Team $team, string $roleCode, ?Event $event = null): User
    {
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for(Department::query()->findOrFail($team->department_id))
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => $event?->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }

    /**
     * A two-department event with one credential-holding staff member in each,
     * an organizer who may export all of it, and a Rangers lead who may export
     * only their own department.
     *
     * @return array<string, mixed>
     */
    private function exportScenario(): array
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective', 'slug' => 'northwood-collective']);
        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'minimum_staff_age' => null,
        ]);

        [$rangers, $rangersTeam] = $this->departmentWithDefaultTeam($organization, 'Rangers', 'RANGERS');
        [$gate, $gateTeam] = $this->departmentWithDefaultTeam($organization, 'Gate', 'GATE');
        [$organizers] = $this->departmentWithDefaultTeam($organization, 'Organizers', 'ORG');

        $organizer = $this->userWithDepartmentRole($organizers, 'organizer');
        $rangersLead = $this->userWithDepartmentRole($rangers, 'department_lead');

        $vera = $this->staff($organization, 'Vera Staff', 'vera', $rangers);
        $rita = $this->staff($organization, 'Rita Revoked', 'rita', $gate);

        $plainStaff = $this->staff($organization, 'Perry Plain', 'perry', $rangers);
        $plainStaffUser = User::factory()->create();
        $plainStaffUser->staffProfiles()->attach($plainStaff->id);

        $eligibility = app(CredentialEligibilityService::class);

        $this->signUp($this->shift($event, $rangers, $rangersTeam, 'Rangers Dirt Day'), $vera);
        $this->signUp($this->shift($event, $gate, $gateTeam, 'Gate Greeting Day'), $rita);
        $eligibility->recalculate($event, $vera);
        $eligibility->recalculate($event, $rita);

        return [
            'organization' => $organization,
            'event' => $event,
            'rangers' => $rangers,
            'rangersTeam' => $rangersTeam,
            'gate' => $gate,
            'gateTeam' => $gateTeam,
            'organizer' => $organizer,
            'rangersLead' => $rangersLead,
            'plainStaffUser' => $plainStaffUser,
            'vera' => $vera,
            'rita' => $rita,
        ];
    }

    /**
     * The same event after it has been worked: both departments assigned to it,
     * a finished shift in each with frozen hours, and the credit ledger those
     * hours were priced into.
     *
     * Four of the five exports read records the scenario above does not
     * produce — a staff contact list reads the departments actually working the
     * event, and hours and credits read work already done — so the event moves
     * behind the clock. That is not scene-setting: CREDIT-001 refuses a credit
     * calculation until the correction grace period has closed, and the grace
     * period is measured from the event's end.
     *
     * @return array<string, mixed>
     */
    private function workedExportScenario(): array
    {
        $scenario = $this->exportScenario();
        $event = $scenario['event'];

        foreach ([$scenario['rangers'], $scenario['gate']] as $department) {
            EventDepartmentAssignment::factory()->create([
                'event_id' => $event->id,
                'department_id' => $department->id,
            ]);
        }

        $startsAt = now()->subMonths(2)->setTime(9, 0);
        $endsAt = $startsAt->copy()->addDays(3)->setTime(18, 0);

        $event->forceFill([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'active_event_window_starts_at' => $startsAt->copy()->subDay(),
            'active_event_window_ends_at' => $endsAt->copy()->addDay(),
        ])->save();

        $policy = CreditPolicy::factory()
            ->for($scenario['organization'])
            ->multiplier('1.500')
            ->create(['name' => 'Standard Credit']);

        $scenario['organization']->forceFill(['default_credit_policy_id' => $policy->id])->save();

        $this->workedShift($event, $scenario['rangers'], $scenario['rangersTeam'], 'Rangers Dirt Night', $scenario['vera'], $startsAt);
        $this->workedShift($event, $scenario['gate'], $scenario['gateTeam'], 'Gate Opening', $scenario['rita'], $startsAt);

        app(CreditCalculationService::class)->calculateForEvent($event->refresh(), $scenario['organizer']);

        return $scenario;
    }

    /**
     * A shift somebody worked and was checked out of, with the hours record
     * frozen so a credit run will price it.
     */
    private function workedShift(
        Event $event,
        Department $department,
        Team $team,
        string $title,
        Staff $staff,
        CarbonInterface $startsAt,
    ): void {
        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(8),
            'capacity' => null,
        ]);

        $assignment = $this->signUp($shift, $staff);
        $endedAt = $startsAt->copy()->addMinutes(450);

        $record = AttendanceRecord::factory()->create([
            'shift_id' => $shift->id,
            'shift_assignment_id' => $assignment->id,
            'staff_id' => $staff->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_OUT,
            'checked_in_at' => $startsAt,
            'checked_out_at' => $endedAt,
        ]);

        HoursWorked::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'attendance_record_id' => $record->id,
            'actual_started_at' => $startsAt,
            'actual_ended_at' => $endedAt,
            'minutes_worked' => 450,
            'frozen_at' => $endedAt->copy()->addDays(20),
        ]);
    }

    /**
     * @return array{Department, Team}
     */
    private function departmentWithDefaultTeam(Organization $organization, string $name, string $code): array
    {
        $department = Department::factory()->for($organization)->create(['name' => $name, 'code' => $code]);
        $team = Team::factory()->for($department)->create(['name' => $name.' Default', 'is_default' => true]);

        return [$department, $team];
    }

    /**
     * A role holder gets their own team: a team grant applies to everyone on the
     * granted team, so granting on the default team would hand the same
     * authority to every ordinary member.
     */
    private function userWithDepartmentRole(Department $department, string $roleCode): User
    {
        $team = Team::factory()->for($department)->create([
            'name' => $department->name.' Leads',
            'is_default' => false,
        ]);

        return $this->userOnTeam($team, $roleCode);
    }

    private function staff(Organization $organization, string $legalName, string $handle, Department $department): Staff
    {
        // Contact details every staff member carries, so the exports that must
        // exclude them have something to exclude rather than passing on a blank
        // column.
        $staff = Staff::factory()->create([
            'legal_name' => $legalName,
            'handle' => $handle,
            'email' => $handle.'@northwood-collective.test',
            'phone' => self::STAFF_PHONE,
            'emergency_contact_name' => self::STAFF_EMERGENCY_CONTACT,
            'emergency_contact_phone' => '+1-208-555-0199',
        ]);

        StaffOrganizationStatus::query()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
            'status_reason' => 'Test setup.',
            'status_changed_at' => now(),
        ]);

        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => Team::query()
                ->where('department_id', $department->id)
                ->where('is_default', true)
                ->firstOrFail()->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        return $staff;
    }

    private function shift(Event $event, Department $department, Team $team, string $title): Shift
    {
        $startsAt = now()->addDays(10);

        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(8),
        ]);
    }

    private function signUp(Shift $shift, Staff $staff): ShiftAssignment
    {
        return ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
        ]);
    }
}
