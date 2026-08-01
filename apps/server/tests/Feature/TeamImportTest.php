<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Imports\ImportException;
use App\Services\Imports\ImportRow;
use App\Services\Imports\TeamImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * God-mode CSV import for teams (technical spec 22.2).
 */
class TeamImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $rangers;

    private Department $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'name' => 'Northwood Collective',
            'slug' => 'northwood-collective',
        ]);

        $this->rangers = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Rangers',
            'code' => 'RANGERS',
        ]);

        $this->gate = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Gate',
            'code' => 'GATE',
        ]);
    }

    public function test_it_creates_teams_from_the_sample_fixture(): void
    {
        $actor = User::factory()->create();

        $result = $this->service()->import($this->fixture('teams-import-sample.csv'), $actor);

        $this->assertSame(3, $result->imported());
        $this->assertSame(0, $result->skipped());

        $this->assertDatabaseHas('teams', [
            'department_id' => $this->rangers->id,
            'code' => 'DIRT',
            'name' => 'Dirt',
            'description' => 'Field rangers walking the city',
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('teams', [
            'department_id' => $this->rangers->id,
            'code' => 'COMMAND',
            'description' => null,
        ]);
        $this->assertDatabaseHas('teams', [
            'department_id' => $this->gate->id,
            'code' => 'GREETERS',
            'description' => 'Gate greeters, perimeter',
        ]);
    }

    /**
     * Departments are named by organization slug plus department code, so the
     * same code in two organizations resolves to two different departments.
     */
    public function test_a_department_code_resolves_within_its_own_organization(): void
    {
        $other = Organization::factory()->create(['slug' => 'other-burners']);
        $otherRangers = Department::factory()->create([
            'organization_id' => $other->id,
            'code' => 'RANGERS',
        ]);

        $actor = User::factory()->create();

        $this->service()->import(
            "organization_slug,department_code,name,code\nother-burners,RANGERS,Dirt,DIRT\n",
            $actor,
        );

        $this->assertDatabaseHas('teams', ['department_id' => $otherRangers->id, 'code' => 'DIRT']);
        $this->assertDatabaseMissing('teams', ['department_id' => $this->rangers->id, 'code' => 'DIRT']);
    }

    public function test_rerunning_the_same_file_updates_instead_of_duplicating(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import($this->fixture('teams-import-sample.csv'), $actor);

        $result = $service->import(
            "Organization Slug,Department Code,Name,Code,Description\n"
            ."northwood-collective,rangers,Dirt Rangers,dirt,Field rangers walking the city\n",
            $actor,
        );

        $this->assertSame(0, $result->imported());
        $this->assertSame(1, $result->updated());
        $this->assertSame(1, Team::query()->where('department_id', $this->rangers->id)->where('code', 'dirt')->count());
        $this->assertSame(
            'Dirt Rangers',
            Team::query()->where('department_id', $this->rangers->id)->where('code', 'dirt')->value('name'),
        );
        $this->assertSame(2, $this->importedTeams()->where('department_id', $this->rangers->id)->count());
    }

    public function test_an_unchanged_row_is_skipped_rather_than_rewritten(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import($this->fixture('teams-import-sample.csv'), $actor);
        $result = $service->import($this->fixture('teams-import-sample.csv'), $actor);

        $this->assertSame(0, $result->imported());
        $this->assertSame(0, $result->updated());
        $this->assertSame(3, $result->skipped());
        $this->assertSame('Already up to date.', $result->rows[0]->reason);
    }

    /**
     * One unresolvable row must not abort the file.
     */
    public function test_unresolvable_rows_are_skipped_with_a_reason_and_good_rows_still_import(): void
    {
        $actor = User::factory()->create();

        $csv = "organization_slug,department_code,name,code\n"
            ."no-such-org,RANGERS,Dirt,DIRT\n"
            ."northwood-collective,NOPE,Dirt,DIRT\n"
            ."northwood-collective,RANGERS,,DIRT\n"
            ."northwood-collective,RANGERS,Dirt,DIRT\n"
            ."northwood-collective,RANGERS,Dirt Again,dirt\n";

        $result = $this->service()->import($csv, $actor);

        $this->assertSame(1, $result->imported());
        $this->assertSame(4, $result->skipped());

        $reasons = array_map(
            static fn (ImportRow $row): ?string => $row->reason,
            $result->rows,
        );

        $this->assertSame([
            'No department "RANGERS" in organization "no-such-org".',
            'No department "NOPE" in organization "northwood-collective".',
            'Missing team name or code.',
            null,
            'Duplicate of row 5 in this file.',
        ], $reasons);

        $this->assertSame(1, $this->importedTeams()->count());
    }

    public function test_an_archived_department_is_skipped(): void
    {
        $archived = Department::factory()->archived()->create([
            'organization_id' => $this->organization->id,
            'code' => 'DPW',
        ]);

        $actor = User::factory()->create();

        $result = $this->service()->import(
            "organization_slug,department_code,name,code\nnorthwood-collective,DPW,Build,BUILD\n",
            $actor,
        );

        $this->assertSame(0, $result->imported());
        $this->assertSame('Cannot create teams in an archived department.', $result->rows[0]->reason);
        $this->assertDatabaseMissing('teams', ['department_id' => $archived->id, 'code' => 'BUILD']);
    }

    public function test_a_preview_reports_outcomes_without_writing_anything(): void
    {
        $actor = User::factory()->create();

        $result = $this->service()->import(
            $this->fixture('teams-import-sample.csv'),
            $actor,
            preview: true,
        );

        $this->assertTrue($result->preview);
        $this->assertSame(3, $result->imported());
        $this->assertSame(0, $this->importedTeams()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'team.created')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'teams.imported')->count());
    }

    /**
     * Imports go through the same domain service the team screen uses, so an
     * imported team is audited the same way a hand-created one is.
     */
    public function test_it_records_the_domain_audit_events_and_a_run_summary(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import(
            "organization_slug,department_code,name,code\nnorthwood-collective,RANGERS,Dirt,DIRT\n",
            $actor,
        );
        $service->import(
            "organization_slug,department_code,name,code\nnorthwood-collective,RANGERS,Dirt Rangers,DIRT\n",
            $actor,
        );

        $created = AuditEvent::query()->where('action', 'team.created')->firstOrFail();
        $this->assertSame((string) $actor->id, (string) $created->actor_user_id);
        $this->assertSame(AuditEvent::SOURCE_ORCHID, $created->source_context);
        $this->assertSame((string) $this->rangers->id, (string) $created->department_id);

        $this->assertSame(1, AuditEvent::query()->where('action', 'team.updated')->count());

        $run = AuditEvent::query()->where('action', 'teams.imported')->firstOrFail();
        $this->assertSame(
            ['imported' => 1, 'updated' => 0, 'skipped' => 0, 'preview' => false],
            $run->after_json,
        );
    }

    public function test_a_file_without_the_required_columns_is_refused_whole(): void
    {
        $actor = User::factory()->create();

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The CSV file must include a "code" header column.');

        $this->service()->import(
            "organization_slug,department_code,name\nnorthwood-collective,RANGERS,Dirt\n",
            $actor,
        );
    }

    /**
     * Creating a department creates its default team, so counts here look only
     * at the teams an import could have produced.
     *
     * @return Builder<Team>
     */
    private function importedTeams(): Builder
    {
        return Team::query()->where('is_default', false);
    }

    private function service(): TeamImportService
    {
        return app(TeamImportService::class);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/'.$name));
    }
}
