<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * CSV import for users (technical spec 22.2).
 *
 * The file carries identity only: `email` and `name`. It deliberately cannot
 * carry passwords, console permissions, roles, or the disabled flag. Meridian
 * has no password login, so an imported account is reachable only through the
 * normal magic-link/OAuth path, and granting console access from a spreadsheet
 * would put a permission decision somewhere no policy test can see it. Those
 * stay on the user screen where they are one deliberate act at a time.
 *
 * Rows match existing accounts on the normalized email address, so re-running
 * the same file updates instead of duplicating and an operator can safely
 * import a corrected export of the same list.
 */
final class UserImportService
{
    public const COLUMN_EMAIL = 'email';

    public const COLUMN_NAME = 'name';

    /**
     * @var list<string>
     */
    public const REQUIRED_COLUMNS = [
        self::COLUMN_EMAIL,
        self::COLUMN_NAME,
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  bool  $preview  Run the import and roll it back, reporting what a real run would do.
     *
     * @throws ImportException when the file itself cannot be read.
     */
    public function import(
        string $csv,
        User $actor,
        bool $preview = false,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): ImportResult {
        $records = CsvImportReader::read($csv, self::REQUIRED_COLUMNS);

        if (! $preview) {
            return $this->apply($records, $actor, false, $sourceContext);
        }

        // A preview is the real import inside a transaction that always rolls
        // back, so what an operator is shown before importing cannot drift
        // from what importing does — including the audit entries, which are
        // discarded with everything else.
        DB::beginTransaction();

        try {
            return $this->apply($records, $actor, true, $sourceContext);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  list<array{row: int, values: array<string, string>}>  $records
     */
    private function apply(
        array $records,
        User $actor,
        bool $preview,
        string $sourceContext,
    ): ImportResult {
        $rows = [];
        $seen = [];

        foreach ($records as $record) {
            $number = $record['row'];
            $email = Str::lower($record['values'][self::COLUMN_EMAIL] ?? '');
            $name = $record['values'][self::COLUMN_NAME] ?? '';

            if ($email === '') {
                $rows[] = ImportRow::skipped($number, '', 'Missing email address.');

                continue;
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $rows[] = ImportRow::skipped($number, $email, 'Email address is not valid.');

                continue;
            }

            if (isset($seen[$email])) {
                $rows[] = ImportRow::skipped($number, $email, sprintf(
                    'Duplicate of row %d in this file.',
                    $seen[$email],
                ));

                continue;
            }

            $seen[$email] = $number;

            if ($name === '') {
                $rows[] = ImportRow::skipped($number, $email, 'Missing name.');

                continue;
            }

            if (mb_strlen($name) > 255) {
                $rows[] = ImportRow::skipped($number, $email, 'Name must be 255 characters or fewer.');

                continue;
            }

            $rows[] = $this->applyRow($number, $email, $name, $actor, $sourceContext);
        }

        $result = new ImportResult($rows, $preview);

        $this->audit->record(
            action: 'users.imported',
            entityType: 'csv_import',
            entityId: (string) Str::uuid(),
            actorUser: $actor,
            after: $result->summary(),
            sourceContext: $sourceContext,
        );

        return $result;
    }

    private function applyRow(
        int $number,
        string $email,
        string $name,
        User $actor,
        string $sourceContext,
    ): ImportRow {
        $existing = User::query()->where('email', $email)->first();

        if ($existing === null) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                // Meridian has no password login; the column is not nullable,
                // so an imported account gets an unusable random secret the
                // same way magic-link account creation does. Email
                // verification is left unset on purpose: an import is not
                // proof that the address belongs to anyone, so the first
                // magic-link sign-in still has to establish that.
                'password' => Hash::make(Str::random(64)),
            ]);

            $this->audit->recordForEntity(
                entity: $user,
                action: 'user.imported',
                actorUser: $actor,
                after: $this->snapshot($user),
                sourceContext: $sourceContext,
            );

            return ImportRow::imported($number, $email);
        }

        if ($existing->name === $name) {
            return ImportRow::skipped($number, $email, 'Already up to date.');
        }

        $before = $this->snapshot($existing);

        $existing->forceFill(['name' => $name])->save();
        $existing->refresh();

        $this->audit->recordForEntity(
            entity: $existing,
            action: 'user.updated',
            actorUser: $actor,
            before: $before,
            after: $this->snapshot($existing),
            sourceContext: $sourceContext,
        );

        return ImportRow::updated($number, $email);
    }

    /**
     * @return array{id: string, name: string, email: string}
     */
    private function snapshot(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];
    }
}
