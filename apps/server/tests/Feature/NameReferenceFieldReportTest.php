<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\NameReferenceToken;
use App\Models\Node;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\FieldReports\FieldReportAcceptanceService;
use App\Services\FieldReports\FieldReportAppendService;
use App\Services\NameReferences\NameReferenceIndexService;
use App\Services\NameReferences\NameReferenceParser;
use App\Services\NameReferences\NameReferenceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class NameReferenceFieldReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_reference_tokens_table_has_documented_fields(): void
    {
        $this->assertTrue(Schema::hasTable('name_reference_tokens'));

        foreach ([
            'id',
            'source_type',
            'source_id',
            'field_report_id',
            'incident_id',
            'token',
            'normalized_token',
            'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('name_reference_tokens', $column),
                "Missing column: {$column}",
            );
        }

        $this->assertFalse(Schema::hasColumn('name_reference_tokens', 'updated_at'));
    }

    public function test_parser_extracts_tokens_until_whitespace_or_punctuation(): void
    {
        $parser = app(NameReferenceParser::class);

        $this->assertSame([
            ['token' => 'bucket', 'normalized_token' => 'bucket'],
            ['token' => 'blue-hat', 'normalized_token' => 'blue-hat'],
            ['token' => 'blue_hat', 'normalized_token' => 'blue_hat'],
            ['token' => 'ranger', 'normalized_token' => 'ranger'],
        ], $parser->parse('@bucket. saw @blue-hat and @blue_hat near @ranger bucket'));
    }

    public function test_parser_deduplicates_case_insensitively_and_preserves_first_casing(): void
    {
        $parser = app(NameReferenceParser::class);

        $this->assertSame([
            ['token' => 'Blue-Hat', 'normalized_token' => 'blue-hat'],
            ['token' => 'Gate_A', 'normalized_token' => 'gate_a'],
        ], $parser->parse('Spoke with @Blue-Hat then @blue-hat and @Gate_A'));

        $this->assertSame('blue-hat', $parser->normalizeQuery('@Blue-Hat'));
        $this->assertSame('blue-hat', $parser->normalizeQuery('Blue-Hat'));
        $this->assertSame('', $parser->normalizeQuery('   '));
        $this->assertSame('', $parser->normalizeQuery('@'));
    }

    public function test_parser_ignores_bracket_and_empty_name_reference_forms(): void
    {
        $parser = app(NameReferenceParser::class);

        $this->assertSame([], $parser->parse('No markers here.'));
        $this->assertSame([
            ['token' => 'example', 'normalized_token' => 'example'],
        ], $parser->parse('Contact user@example.com'));
        $this->assertSame([], $parser->parse('Unsupported @[Ranger Bucket] form'));
        $this->assertSame([], $parser->parse('Trailing @ alone'));
    }

    public function test_acceptance_indexes_name_references_without_changing_body(): void
    {
        Carbon::setTestNow('2027-07-04 20:30:00 UTC');
        [, $attributes] = $this->validSubmission(
            'Observed @Blue-Hat near @Gate_A with @blue-hat again.',
        );

        $report = app(FieldReportAcceptanceService::class)->accept($attributes);

        $this->assertSame(
            'Observed @Blue-Hat near @Gate_A with @blue-hat again.',
            $report->fresh()->body,
        );
        $this->assertDatabaseCount('name_reference_tokens', 2);
        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_FIELD_REPORT,
            'source_id' => $report->id,
            'field_report_id' => $report->id,
            'token' => 'Blue-Hat',
            'normalized_token' => 'blue-hat',
        ]);
        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_FIELD_REPORT,
            'source_id' => $report->id,
            'normalized_token' => 'gate_a',
            'token' => 'Gate_A',
        ]);
    }

    public function test_field_report_titles_are_not_parsed_for_name_references(): void
    {
        [, $attributes] = $this->validSubmission('Plain body without references.');
        $attributes['title'] = 'Title mentions @TitleToken and @Another';

        $report = app(FieldReportAcceptanceService::class)->accept($attributes);

        $this->assertSame('Title mentions @TitleToken and @Another', $report->title);
        $this->assertDatabaseCount('name_reference_tokens', 0);
        $this->assertDatabaseMissing('name_reference_tokens', [
            'normalized_token' => 'titletoken',
        ]);
        $this->assertDatabaseMissing('name_reference_tokens', [
            'normalized_token' => 'another',
        ]);
    }

    public function test_idempotent_acceptance_repairs_missing_name_reference_index(): void
    {
        [, $attributes] = $this->validSubmission('Spoke with @Ranger_1.');
        $service = app(FieldReportAcceptanceService::class);
        $report = $service->accept($attributes);

        NameReferenceToken::query()->delete();
        $this->assertDatabaseCount('name_reference_tokens', 0);

        $retried = $service->accept($attributes);

        $this->assertTrue($report->is($retried));
        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_FIELD_REPORT,
            'source_id' => $report->id,
            'normalized_token' => 'ranger_1',
            'token' => 'Ranger_1',
        ]);
    }

    public function test_append_indexes_name_references_without_changing_original_body(): void
    {
        [$report, $attributes] = $this->validAppend('Follow-up with @Camp-9 and @White_Truck.');

        $append = app(FieldReportAppendService::class)->append($attributes);

        $this->assertSame('Original immutable body', $report->fresh()->body);
        $this->assertSame('Follow-up with @Camp-9 and @White_Truck.', $append->body);
        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_FIELD_REPORT_APPEND,
            'source_id' => $append->id,
            'field_report_id' => $report->id,
            'normalized_token' => 'camp-9',
            'token' => 'Camp-9',
        ]);
        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_FIELD_REPORT_APPEND,
            'source_id' => $append->id,
            'normalized_token' => 'white_truck',
            'token' => 'White_Truck',
        ]);
    }

    public function test_idempotent_append_repairs_missing_name_reference_index(): void
    {
        [, $attributes] = $this->validAppend('Appended @Bucket.');
        $service = app(FieldReportAppendService::class);
        $append = $service->append($attributes);

        NameReferenceToken::query()
            ->where('source_type', NameReferenceToken::SOURCE_TYPE_FIELD_REPORT_APPEND)
            ->where('source_id', $append->id)
            ->delete();

        $retried = $service->append($attributes);

        $this->assertTrue($append->is($retried));
        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_FIELD_REPORT_APPEND,
            'source_id' => $append->id,
            'normalized_token' => 'bucket',
            'token' => 'Bucket',
        ]);
    }

    public function test_rebuild_regenerates_index_from_source_text(): void
    {
        [, $reportAttributes] = $this->validSubmission('Body mentions @Alpha.');
        $report = app(FieldReportAcceptanceService::class)->accept($reportAttributes);

        [, $appendAttributes] = $this->validAppend(
            'Append mentions @Beta.',
            $report,
            $reportAttributes['submitted_by_user_id'],
            $reportAttributes['origin_device_id'],
            $reportAttributes['origin_node_id'],
        );
        $append = app(FieldReportAppendService::class)->append($appendAttributes);

        NameReferenceToken::query()->delete();
        $this->assertDatabaseCount('name_reference_tokens', 0);

        $count = app(NameReferenceIndexService::class)->rebuild();

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_FIELD_REPORT,
            'source_id' => $report->id,
            'normalized_token' => 'alpha',
        ]);
        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_FIELD_REPORT_APPEND,
            'source_id' => $append->id,
            'normalized_token' => 'beta',
        ]);

        Artisan::call('name-references:rebuild');
        $this->assertDatabaseCount('name_reference_tokens', 2);
    }

    public function test_search_is_case_insensitive_and_accepts_optional_at_prefix(): void
    {
        [, $attributes] = $this->validSubmission('Saw @Blue-Hat at medical.');
        $report = app(FieldReportAcceptanceService::class)->accept($attributes);
        $author = User::query()->findOrFail($attributes['submitted_by_user_id']);
        $search = app(NameReferenceSearchService::class);

        $withAt = $search->searchFieldReports($author, '@blue-hat');
        $withoutAt = $search->searchFieldReports($author, 'Blue-Hat');

        $this->assertSame([$report->id], $withAt->pluck('id')->all());
        $this->assertSame([$report->id], $withoutAt->pluck('id')->all());
    }

    public function test_search_finds_reports_via_append_tokens(): void
    {
        [$report, $attributes] = $this->validAppend('Later saw @White_Truck.');
        app(FieldReportAppendService::class)->append($attributes);
        $author = User::query()->findOrFail($attributes['appended_by_user_id']);

        $results = app(NameReferenceSearchService::class)
            ->searchFieldReports($author, 'white_truck');

        $this->assertSame([$report->id], $results->pluck('id')->all());
    }

    public function test_search_hides_unauthorized_field_reports(): void
    {
        $event = Event::factory()->create();
        [, $authorAttributes] = $this->validSubmission('Author wrote @SharedName.', $event);
        $authorReport = app(FieldReportAcceptanceService::class)->accept($authorAttributes);

        [, $otherAttributes] = $this->validSubmission('Other wrote @SharedName.', $event);
        $otherReport = app(FieldReportAcceptanceService::class)->accept($otherAttributes);

        $author = User::query()->findOrFail($authorAttributes['submitted_by_user_id']);
        $otherAuthor = User::query()->findOrFail($otherAttributes['submitted_by_user_id']);
        $unrelated = User::factory()->create();
        $icUser = $this->userWithEventRole('ic_viewer', $event);
        $search = app(NameReferenceSearchService::class);

        $this->assertSame(
            [$authorReport->id],
            $search->searchFieldReports($author, 'SharedName')->pluck('id')->all(),
        );
        $this->assertSame(
            [$otherReport->id],
            $search->searchFieldReports($otherAuthor, '@SharedName')->pluck('id')->all(),
        );
        $this->assertSame([], $search->searchFieldReports($unrelated, 'SharedName')->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$authorReport->id, $otherReport->id],
            $search->searchFieldReports($icUser, 'sharedname')->pluck('id')->all(),
        );
    }

    public function test_search_does_not_leak_across_events_for_ic_roles(): void
    {
        $eventA = Event::factory()->create();
        $eventB = Event::factory()->create();
        [, $attributes] = $this->validSubmission('Event A saw @CrossEvent.', $eventA);
        $report = app(FieldReportAcceptanceService::class)->accept($attributes);
        $icOtherEvent = $this->userWithEventRole('ic_lead', $eventB);

        $results = app(NameReferenceSearchService::class)
            ->searchFieldReports($icOtherEvent, 'CrossEvent', $eventB);

        $this->assertSame([], $results->pluck('id')->all());
        $this->assertFalse($icOtherEvent->can('view', $report));
    }

    /**
     * @return array{Event, array<string, string|null>}
     */
    private function validSubmission(string $body = 'Observed a medical assist near Gate A.', ?Event $event = null): array
    {
        $event ??= Event::factory()->create();
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
            'temporary_local_number' => 'LOCAL-'.Str::upper(Str::random(8)),
            'title' => 'Field Report title',
            'body' => $body,
            'device_submitted_at' => '2027-07-04T13:22:10Z',
            'origin_device_id' => $device->id,
            'origin_node_id' => $node->id,
        ]];
    }

    /**
     * @return array{FieldReport, array<string, string>}
     */
    private function validAppend(
        string $body = 'Additional detail observed later.',
        ?FieldReport $report = null,
        ?string $authorId = null,
        ?string $deviceId = null,
        ?string $nodeId = null,
    ): array {
        if ($report === null) {
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
            $authorId = $author->id;
            $deviceId = $device->id;
            $nodeId = $node->id;
        }

        return [$report, [
            'id' => (string) Str::uuid(),
            'field_report_id' => $report->id,
            'appended_by_user_id' => (string) $authorId,
            'body' => $body,
            'device_submitted_at' => '2027-07-04T13:45:00Z',
            'origin_device_id' => (string) $deviceId,
            'origin_node_id' => (string) $nodeId,
        ]];
    }

    private function userWithEventRole(string $roleCode, Event $event): User
    {
        $organization = $event->organization;
        $department = $this->ensureEventIcDepartment($event);
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

    private function ensureEventIcDepartment(Event $event): Department
    {
        if ($event->ic_department_id !== null) {
            return Department::query()->findOrFail($event->ic_department_id);
        }

        $event->loadMissing('organization.defaultIcDepartment');

        if ($event->organization?->defaultIcDepartment !== null) {
            return $event->organization->defaultIcDepartment;
        }

        $department = Department::factory()->for($event->organization)->create();
        $event->forceFill(['ic_department_id' => $department->id])->save();

        return $department;
    }
}
