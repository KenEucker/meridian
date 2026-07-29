<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Imports\ImportException;
use App\Services\Imports\ImportRow;
use App\Services\Imports\UserImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * God-mode CSV import for users (technical spec 22.2).
 */
class UserImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_users_from_the_sample_fixture(): void
    {
        $actor = User::factory()->create();

        $result = $this->service()->import($this->fixture('users-import-sample.csv'), $actor);

        $this->assertSame(3, $result->imported());
        $this->assertSame(0, $result->updated());
        $this->assertSame(0, $result->skipped());
        $this->assertFalse($result->preview);

        $this->assertDatabaseHas('users', [
            'email' => 'vera.staff@example.org',
            'name' => 'Vera Staff',
        ]);
        $this->assertDatabaseHas('users', ['email' => 'sam.shiftlead@example.org']);
        $this->assertDatabaseHas('users', ['email' => 'dana.departmentlead@example.org']);
    }

    /**
     * An imported account carries no console access and no usable password, so
     * a spreadsheet cannot grant anyone anything.
     */
    public function test_imported_users_get_no_permissions_and_no_verified_email(): void
    {
        $actor = User::factory()->create();

        $this->service()->import("email,name\nvera.staff@example.org,Vera Staff\n", $actor);

        $imported = User::query()->where('email', 'vera.staff@example.org')->firstOrFail();

        $this->assertEmpty($imported->permissions ?? []);
        $this->assertNull($imported->email_verified_at);
        $this->assertFalse($imported->hasAccess('platform.index'));
    }

    /**
     * Re-running a corrected export of the same list is the normal case, so
     * matching happens on the normalized email address rather than creating a
     * second account.
     */
    public function test_rerunning_the_same_file_updates_instead_of_duplicating(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import("email,name\nvera.staff@example.org,Vera Staff\n", $actor);
        $result = $service->import("Email,Name\n VERA.STAFF@example.org ,Vera Staff-Ranger\n", $actor);

        $this->assertSame(0, $result->imported());
        $this->assertSame(1, $result->updated());
        $this->assertSame(1, User::query()->where('email', 'vera.staff@example.org')->count());
        $this->assertSame(
            'Vera Staff-Ranger',
            User::query()->where('email', 'vera.staff@example.org')->value('name'),
        );
    }

    public function test_an_unchanged_row_is_skipped_rather_than_rewritten(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import("email,name\nvera.staff@example.org,Vera Staff\n", $actor);
        $result = $service->import("email,name\nvera.staff@example.org,Vera Staff\n", $actor);

        $this->assertSame(0, $result->imported());
        $this->assertSame(0, $result->updated());
        $this->assertSame(1, $result->skipped());
        $this->assertSame('Already up to date.', $result->rows[0]->reason);
    }

    /**
     * One bad row must not abort the file.
     */
    public function test_invalid_rows_are_skipped_with_a_reason_and_good_rows_still_import(): void
    {
        $actor = User::factory()->create();

        $csv = "email,name\n"
            ."not-an-email,Broken Row\n"
            .",Missing Email\n"
            ."vera.staff@example.org,\n"
            ."sam.shiftlead@example.org,Sam Shiftlead\n"
            ."SAM.SHIFTLEAD@example.org,Sam Again\n";

        $result = $this->service()->import($csv, $actor);

        $this->assertSame(1, $result->imported());
        $this->assertSame(4, $result->skipped());

        $reasons = array_map(
            static fn (ImportRow $row): ?string => $row->reason,
            $result->rows,
        );

        $this->assertSame([
            'Email address is not valid.',
            'Missing email address.',
            'Missing name.',
            null,
            'Duplicate of row 5 in this file.',
        ], $reasons);

        $this->assertSame(
            'Sam Shiftlead',
            User::query()->where('email', 'sam.shiftlead@example.org')->value('name'),
        );
        $this->assertDatabaseMissing('users', ['email' => 'vera.staff@example.org']);
    }

    public function test_a_preview_reports_outcomes_without_writing_anything(): void
    {
        $actor = User::factory()->create();
        $existing = User::factory()->create([
            'email' => 'sam.shiftlead@example.org',
            'name' => 'Sam Old',
        ]);

        $result = $this->service()->import(
            "email,name\nvera.staff@example.org,Vera Staff\nsam.shiftlead@example.org,Sam Shiftlead\n",
            $actor,
            preview: true,
        );

        $this->assertTrue($result->preview);
        $this->assertSame(1, $result->imported());
        $this->assertSame(1, $result->updated());

        $this->assertDatabaseMissing('users', ['email' => 'vera.staff@example.org']);
        $this->assertSame('Sam Old', $existing->fresh()?->name);
        $this->assertSame(0, AuditEvent::query()->where('action', 'user.imported')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'users.imported')->count());
    }

    public function test_it_records_audit_events_for_created_updated_and_the_run(): void
    {
        $actor = User::factory()->create();
        $service = $this->service();

        $service->import("email,name\nvera.staff@example.org,Vera Staff\n", $actor);
        $service->import("email,name\nvera.staff@example.org,Vera Ranger\n", $actor);

        $created = AuditEvent::query()->where('action', 'user.imported')->firstOrFail();
        $this->assertSame((string) $actor->id, (string) $created->actor_user_id);
        $this->assertSame(AuditEvent::SOURCE_ORCHID, $created->source_context);
        $this->assertSame('Vera Staff', $created->after_json['name'] ?? null);

        $updated = AuditEvent::query()->where('action', 'user.updated')->firstOrFail();
        $this->assertSame('Vera Staff', $updated->before_json['name'] ?? null);
        $this->assertSame('Vera Ranger', $updated->after_json['name'] ?? null);

        $runs = AuditEvent::query()->where('action', 'users.imported')->get();
        $this->assertCount(2, $runs);
        $this->assertSame(
            ['imported' => 1, 'updated' => 0, 'skipped' => 0, 'preview' => false],
            $runs->first()?->after_json,
        );
    }

    public function test_a_file_without_the_required_columns_is_refused_whole(): void
    {
        $actor = User::factory()->create();

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The CSV file must include a "email" header column.');

        $this->service()->import("username,name\nvera,Vera Staff\n", $actor);
    }

    public function test_an_empty_file_is_refused_whole(): void
    {
        $actor = User::factory()->create();

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The CSV file is empty.');

        $this->service()->import('', $actor);
    }

    private function service(): UserImportService
    {
        return app(UserImportService::class);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/'.$name));
    }
}
